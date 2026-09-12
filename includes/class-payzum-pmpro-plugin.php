<?php
/**
 * Everything the Payzum PMPro integration does that is not the gateway class itself: the signed IPN
 * listener, the modal/inline widget host, and the helpers the gateway calls.
 *
 * Kept separate so the gateway — which `extends PMProGateway` and can therefore only be declared
 * once PMPro has loaded — stays the only part with that dependency.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\Payzum;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

class Payzum_PMPro_Plugin {

	/** Gateway id. */
	const ID = 'payzum';

	/** Query arg that routes an IPN here. */
	const IPN_ARG = 'payzum_pmpro_ipn';

	/** Order meta holding recently seen IPN event ids — retries reuse the id. */
	const EVENT_IDS_META = '_payzum_ipn_event_ids';

	/** How many past event ids to keep per order for deduplication. */
	const EVENT_IDS_KEEP = 20;

	/** Sentinel that defers the pay-currency choice to the member on the hosted checkout. */
	const PAY_CURRENCY_ANY = 'all';

	/**
	 * How far the settled amount may fall short of the initial payment before the order is held.
	 *
	 * Half a cent: the order amount is a decimal and price_amount arrives as a JSON number, so
	 * the two round differently and `==` on floats would reject good payments.
	 */
	const AMOUNT_TOLERANCE = 0.005;

	/** Seconds to wait for the per-order IPN lock before giving up and asking for a retry. */
	const LOCK_TIMEOUT = 10;

	/** Embeddable checkout widget (modal / inline render modes). */
	const WIDGET_URL = 'https://merchant.payzum.com/widget/v1/payzum.js';

	/** Query arg that turns the membership-confirmation page into the widget host. */
	const RENDER_ARG = 'payzum-pmpro';

	/** @var object|null order being rendered on the confirmation page (modal / inline) */
	private $render_order = null;

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_handle_ipn' ) );
		add_action( 'template_redirect', array( $this, 'maybe_prepare_render' ) );
		add_filter( 'the_content', array( $this, 'maybe_render_widget' ), 20 );
	}

	/* -------------------------------------------------------------- helpers used by the gateway */

	public static function ipn_url() {
		return add_query_arg( self::IPN_ARG, '1', home_url( '/' ) );
	}

	/** Configured render mode, falling back to the redirect flow for any unknown value. */
	public static function render_mode() {
		$mode = (string) pmpro_getOption( 'payzum_render_mode' );
		return in_array( $mode, array( 'redirect', 'modal', 'inline' ), true ) ? $mode : 'redirect';
	}

	/**
	 * The SDK entry point, pointed at the configured environment. Built per call rather than
	 * cached: the admin can save a new key or switch environment and the next request must
	 * honour it.
	 */
	public static function payzum_client() {
		$api_key = trim( (string) pmpro_getOption( 'payzum_api_key' ) );

		return 'staging' === (string) pmpro_getOption( 'payzum_environment' )
			? Payzum::sandbox( $api_key )
			: new Payzum( $api_key );
	}

	/** An actionable checkout message for the error codes a member can do something about. */
	public static function buyer_notice_for( $raw_code ) {
		switch ( $raw_code ) {
			case 'AMOUNT_BELOW_MINIMUM':
				return __( 'This amount is below the minimum for crypto payment. Please choose another payment method.', 'payzum-pmpro' );
			case 'CURRENCY_NOT_SUPPORTED':
			case 'NO_ELIGIBLE_CURRENCIES':
				return __( 'Crypto payment is not available for this currency or amount. Please choose another payment method.', 'payzum-pmpro' );
			default:
				return __( 'Unable to start the crypto payment. Please try again or pick another method.', 'payzum-pmpro' );
		}
	}

	/**
	 * PMPro order codes come from MemberOrder::getRandomCode(), which is already unguessable, so
	 * unlike the other carts there is nothing to append here.
	 */
	public static function order_reference( $order ) {
		return (string) $order->code;
	}

	/** Short human description shown on the Payzum hosted checkout (API caps this at 2000). */
	public static function order_description( $order ) {
		$level_name = '';
		if ( ! empty( $order->membership_id ) && function_exists( 'pmpro_getLevel' ) ) {
			$level = pmpro_getLevel( $order->membership_id );
			if ( $level && ! empty( $level->name ) ) {
				$level_name = $level->name;
			}
		}
		$text = sprintf(
			/* translators: 1: membership level name, 2: site name */
			__( '%1$s at %2$s', 'payzum-pmpro' ),
			$level_name ? $level_name : __( 'Membership', 'payzum-pmpro' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		return mb_substr( $text, 0, 2000 );
	}

	/** Where the member lands after paying. */
	public static function success_url( $order ) {
		return pmpro_url( 'confirmation', '?level=' . (int) $order->membership_id );
	}

	public static function cancel_url() {
		return pmpro_url( 'levels' );
	}

	/** The page that hosts the widget for modal / inline. */
	public static function widget_host_url( $order ) {
		return add_query_arg( self::RENDER_ARG, rawurlencode( (string) $order->code ), pmpro_url( 'confirmation' ) );
	}

	/**
	 * The pricing half of the create-payment body.
	 *
	 * Fiat (default): the member picks the coin on the Payzum checkout, so pay_currency is "all"
	 * and Payzum converts from the store currency.
	 *
	 * Crypto: prices are already denominated in a coin, so pricing_mode "direct" sends the total
	 * through unconverted. The API requires price_currency to equal the *bare* pay symbol
	 * (max 8 chars — `usdc`, not `usdcmatic`), with the chain in `network`.
	 *
	 * @param string $order_code only used for logging a misconfiguration.
	 * @return array<string, string>
	 */
	public static function pricing_payload( $order_code ) {
		if ( 'crypto' === (string) pmpro_getOption( 'payzum_currency_mode' ) ) {
			$symbol  = self::crypto_symbol();
			$network = strtolower( trim( (string) pmpro_getOption( 'payzum_crypto_network' ) ) );

			if ( '' !== $symbol ) {
				return array(
					'price_currency' => $symbol,
					'pay_currency'   => $symbol,
					'pricing_mode'   => 'direct',
					'network'        => '' !== $network ? $network : null,
				);
			}

			// Misconfigured: fall through to fiat rather than failing the member's checkout outright.
			self::log( 'currency_mode=crypto but no crypto_symbol configured — falling back to fiat for order ' . $order_code );
		}

		$configured = strtolower( trim( (string) pmpro_getOption( 'payzum_price_currency' ) ) );
		if ( '' === $configured ) {
			global $pmpro_currency;
			$configured = strtolower( (string) $pmpro_currency );
		}

		return array(
			'price_currency' => $configured,
			// "all" defers the coin choice to the member, limited to the merchant's allowlist.
			'pay_currency'   => self::PAY_CURRENCY_ANY,
			'pricing_mode'   => 'fiat',
			'network'        => null,
		);
	}

	/** Sanitised bare coin symbol for crypto mode ("usdc"), or '' when unset. */
	private static function crypto_symbol() {
		$symbol = strtolower( trim( (string) pmpro_getOption( 'payzum_crypto_symbol' ) ) );
		$symbol = preg_replace( '/[^a-z0-9]/', '', (string) $symbol );
		return (string) substr( (string) $symbol, 0, 8 );
	}

	public static function log( $message ) {
		if ( ! pmpro_getOption( 'payzum_debug' ) ) {
			return;
		}
		error_log( '[payzum] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Send the member onward, surviving the case where output has already started.
	 *
	 * A single stray notice or echo from any other active plugin flips headers_sent(), the Location
	 * header is dropped silently, and the member is left on a half-rendered page holding an invoice
	 * they never saw. Fall back to a client-side hop rather than lose the payment.
	 *
	 * @param string $url      where to send the member.
	 * @param bool   $external true for the off-site Payzum checkout, false for a page on this site.
	 */
	public static function redirect( $url, $external ) {
		if ( ! headers_sent() ) {
			if ( $external ) {
				// Deliberately wp_redirect() and not wp_safe_redirect(): the safe variant rejects
				// off-site hosts and would send the member to wp-admin instead.
				wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			} else {
				wp_safe_redirect( $url );
			}
			exit;
		}

		self::log( 'headers already sent — falling back to a client-side redirect to ' . $url );

		printf(
			'<meta http-equiv="refresh" content="0;url=%1$s">'
			. '<script>window.location.replace(%2$s);</script>'
			. '<p><a href="%1$s">%3$s</a></p>',
			esc_url( $url ),
			wp_json_encode( $url ),
			esc_html__( 'Continue to payment', 'payzum-pmpro' )
		);
		exit;
	}

	/* -------------------------------------------------------------------- modal / inline widget */

	/**
	 * On the confirmation page with ?payzum-pmpro=<order code>, resolve the order and enqueue the
	 * widget. Runs on template_redirect so wp_enqueue_script() still lands in the head.
	 */
	public function maybe_prepare_render() {
		if ( 'redirect' === self::render_mode() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::RENDER_ARG ] ) || ! class_exists( 'MemberOrder' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = sanitize_text_field( wp_unslash( $_GET[ self::RENDER_ARG ] ) );
		if ( '' === $code ) {
			return;
		}

		$order = new MemberOrder();
		$order->getMemberOrderByCode( $code );

		if ( empty( $order->id ) || self::ID !== $order->gateway ) {
			return;
		}
		if ( '' === (string) get_pmpro_membership_order_meta( $order->id, '_payzum_payment_id', true ) ) {
			return;
		}

		$this->render_order = $order;

		wp_enqueue_script( 'payzum-widget', self::WIDGET_URL, array(), PAYZUM_PMPRO_VERSION, true );
		wp_add_inline_script( 'payzum-widget', $this->widget_bootstrap( $order ) );
	}

	/**
	 * Replace the confirmation page's content with the widget mount point. Only ever fires for the
	 * one order resolved above, so the normal confirmation is untouched for everyone else.
	 */
	public function maybe_render_widget( $content ) {
		if ( null === $this->render_order || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$order       = $this->render_order;
		$container   = 'payzum-checkout-' . (int) $order->id;
		$invoice_url = (string) get_pmpro_membership_order_meta( $order->id, '_payzum_invoice_url', true );

		$html = sprintf(
			'<div id="%1$s" class="payzum-checkout" data-mode="%2$s"></div>',
			esc_attr( $container ),
			esc_attr( self::render_mode() )
		);

		// Fallback for members with JS disabled or a CSP that blocks the widget.
		if ( '' !== $invoice_url ) {
			$html .= sprintf(
				'<noscript><p><a class="pmpro_btn" href="%1$s">%2$s</a></p></noscript>',
				esc_url( $invoice_url ),
				esc_html__( 'Pay with crypto', 'payzum-pmpro' )
			);
		}

		return $html;
	}

	/**
	 * Inline JS that opens the widget once it has loaded.
	 *
	 * The callbacks are UI hints only — they move the member to the right page. Whether the order is
	 * actually paid, and therefore whether the membership level is granted, is decided solely by the
	 * signed IPN.
	 */
	private function widget_bootstrap( $order ) {
		$config = array(
			'mode'       => self::render_mode(),
			'paymentId'  => (string) get_pmpro_membership_order_meta( $order->id, '_payzum_payment_id', true ),
			'container'  => 'payzum-checkout-' . (int) $order->id,
			'returnUrl'  => self::success_url( $order ),
			'cancelUrl'  => self::cancel_url(),
			'invoiceUrl' => (string) get_pmpro_membership_order_meta( $order->id, '_payzum_invoice_url', true ),
		);

		// The widget script is loaded in the footer and may still be parsing; poll briefly rather
		// than assuming window.Payzum exists the moment this runs.
		return '(function(){'
			. 'var cfg=' . wp_json_encode( $config ) . ';'
			. 'var tries=0;'
			. 'function go(){'
			. 'if(!window.Payzum||!window.Payzum.open){'
			. 'if(++tries>100){if(cfg.invoiceUrl){window.location.href=cfg.invoiceUrl;}return;}'
			. 'return window.setTimeout(go,100);'
			. '}'
			. 'var opts={'
			. 'onSuccess:function(){window.location.href=cfg.returnUrl;},'
			. 'onPartial:function(){window.location.href=cfg.returnUrl;},'
			. 'onExpired:function(){window.location.href=cfg.cancelUrl;},'
			. 'onCancel:function(){window.location.href=cfg.cancelUrl;}'
			. '};'
			. 'if(cfg.mode==="inline"){'
			. 'var el=document.getElementById(cfg.container);'
			. 'if(el){window.Payzum.openInline(cfg.paymentId,el,opts);}'
			. '}else{'
			. 'window.Payzum.open(cfg.paymentId,opts);'
			. '}'
			. '}'
			. 'go();'
			. '})();';
	}

	/* ----------------------------------------------------------------------------- IPN listener */

	/** Route ?payzum_pmpro_ipn=1 to handle_ipn(). */
	public function maybe_handle_ipn() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::IPN_ARG ] ) ) {
			return;
		}
		$this->handle_ipn();
	}

	/**
	 * Handle a signed IPN.
	 *
	 * The SDK's Verifier does the dangerous parts: it reads the correct, fixed signature header
	 * itself (case-insensitively, CGI form included), verifies HMAC-SHA-512 over the RAW bytes in
	 * constant time, and enforces the 10-minute replay window on the signed event_at. On top of
	 * that this handler deduplicates by event id — delivery retries reuse it, so a second
	 * delivery must be a no-op, not a second grant.
	 *
	 * Verification deliberately uses `new Verifier($secret)` and not the Payzum entry class:
	 * verifying an already-paid IPN must not depend on the API key being configured.
	 */
	private function handle_ipn() {
		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $raw || false === $raw ) {
			$this->respond( 400, 'empty body' );
		}

		$secret = (string) pmpro_getOption( 'payzum_webhook_secret' );
		if ( '' === $secret ) {
			self::log( 'IPN received but no webhook secret configured.' );
			$this->respond( 500, 'not configured' );
		}

		$headers = $this->request_headers();

		try {
			$verifier = new Verifier( $secret );
			$data     = $verifier->verifyPaymentIpn( $raw, $headers );
		} catch ( SignatureException $e ) {
			self::log( 'IPN rejected (' . $e->reason . '): ' . $e->getMessage() );
			$this->respond( 401, 'bad signature' );
			return; // respond() exits; this keeps static analysis honest.
		} catch ( PayzumException $e ) {
			self::log( 'IPN body unusable: ' . $e->getMessage() );
			$this->respond( 400, 'bad json' );
			return;
		}

		$code   = isset( $data['order_id'] ) ? (string) $data['order_id'] : '';
		$status = isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '';

		$order = new MemberOrder();
		$order->getMemberOrderByCode( $code );

		if ( empty( $order->id ) ) {
			self::log( 'IPN for unknown order code: ' . $code );
			$this->respond( 404, 'order not found' );
		}

		// Everything from here to the release is one critical section: read the event ids, decide
		// the transition, write it, record the event id. Two deliveries carrying DIFFERENT event
		// ids both used to read "not success yet" and both granted the level — a membership
		// granted twice, with two welcome emails and a duplicated order history. The dedup check
		// belongs inside the lock too: on its own it only catches the same event id, and it is
		// itself a read-then-write.
		$lock = $this->acquire_order_lock( $order->id );
		if ( false === $lock ) {
			// Another delivery for this order is mid-transition. 503 rather than 200: this IPN is
			// not a duplicate, only late, and Payzum retrying it is the right outcome.
			self::log( 'IPN for order ' . $order->code . ' could not take the order lock — asking for a retry' );
			$this->respond( 503, 'busy' );
		}

		// Re-read the order now that the lock is held: it was loaded before we waited, so a
		// concurrent delivery may have settled it in the meantime and the copy in memory would
		// still say "pending". MemberOrder reads straight from the database, no cache in the way.
		$order = new MemberOrder();
		$order->getMemberOrderByCode( $code );

		// Retries and multi-transition deliveries reuse the event id; a replay must be a no-op.
		$event_id = (string) $verifier->eventId( $headers );
		if ( '' !== $event_id && $this->is_duplicate_event( $order->id, $event_id ) ) {
			self::log( 'IPN duplicate event ' . $event_id . ' for order ' . $order->code . ' — ignored' );
			$this->release_order_lock( $lock );
			$this->respond( 200, 'duplicate' );
		}

		// Once the member has picked a coin the IPN carries it — record it for the site owner.
		if ( ! empty( $data['pay_currency'] ) ) {
			update_pmpro_membership_order_meta( $order->id, '_payzum_pay_currency', sanitize_text_field( (string) $data['pay_currency'] ) );
		}

		self::log( 'IPN verified for order ' . $order->code . ' status=' . $status . ( '' !== $event_id ? ' event=' . $event_id : '' ) );
		$this->apply_status( $order, $status, $data );

		if ( '' !== $event_id ) {
			$this->remember_event( $order->id, $event_id );
		}

		$this->release_order_lock( $lock );
		$this->respond( 200, 'ok' );
	}

	/**
	 * Take a cross-request lock for this order, for the length of the IPN transition.
	 *
	 * GET_LOCK is the only lock WordPress can count on here. wp_cache_add() is request-local
	 * unless a persistent object cache is installed, so it would look like a lock on every site
	 * that has none and protect nothing — the failure mode this guard exists to prevent.
	 *
	 * @param int $order_id
	 * @return string|null|false lock name when acquired; false when it timed out (another
	 *                           delivery holds it); null when the database offers no lock, in
	 *                           which case the caller proceeds unlocked rather than refusing a
	 *                           payment it can still process.
	 */
	private function acquire_order_lock( $order_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		// Namespaced by DB_NAME: several WordPress installs can share one MySQL server, and
		// GET_LOCK names are scoped to the whole MySQL server, not to a database or a connection,
		// so every part of "which install, which site, which plugin, which record" has to be in
		// the name or unrelated stores block each other on a shared server.
		//
		// DB_NAME separates installs. $wpdb->prefix separates the sites of a multisite network
		// and the several WordPress installs people put in one database with different prefixes.
		// The 'pmpro-order' tag separates THIS plugin from the Payzum plugins for WooCommerce,
		// EDD and GiveWP: they all key by a small id, so without it PMPro order 500 and
		// WooCommerce order 500 on the same site hash to the same lock and 503 each other. Hashed
		// to stay inside GET_LOCK's 64-character limit.
		$name = 'payzum_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|pmpro-order|' . $order_id ), 0, 32 );

		// 1 = acquired, 0 = timed out, NULL = error (or a server without GET_LOCK).
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) );
		if ( '1' === (string) $got ) {
			return $name;
		}
		if ( null === $got ) {
			self::log( 'GET_LOCK unavailable — processing IPN for order ' . $order_id . ' without a lock' );
			return null;
		}
		return false;
	}

	/** Release a lock taken by acquire_order_lock(). Safe to call with null (nothing was taken). */
	private function release_order_lock( $name ) {
		global $wpdb;

		if ( ! is_string( $name ) || '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Request headers for the Verifier. getallheaders() when the SAPI provides it, otherwise the
	 * raw $_SERVER array — the Verifier accepts the CGI form (HTTP_X_NOWPAYMENTS_SIG) directly.
	 *
	 * @return array<string, string>
	 */
	private function request_headers() {
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				return $headers;
			}
		}
		return array_filter( $_SERVER, 'is_string' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw bytes needed for HMAC lookup; never echoed.
	}

	/** Whether this event id was already processed for the order. */
	private function is_duplicate_event( $order_id, $event_id ) {
		$seen = get_pmpro_membership_order_meta( $order_id, self::EVENT_IDS_META, true );
		return is_array( $seen ) && in_array( $event_id, $seen, true );
	}

	/** Record a processed event id, keeping only the most recent ones. */
	private function remember_event( $order_id, $event_id ) {
		$seen   = get_pmpro_membership_order_meta( $order_id, self::EVENT_IDS_META, true );
		$seen   = is_array( $seen ) ? $seen : array();
		$seen[] = $event_id;
		update_pmpro_membership_order_meta( $order_id, self::EVENT_IDS_META, array_slice( $seen, -self::EVENT_IDS_KEEP ) );
	}

	/**
	 * Map a Payzum payment_status onto the order and the membership. Idempotent — a repeated IPN for
	 * an already-successful order neither re-grants the level nor re-notes it.
	 *
	 * Only `finished` grants membership. A partial payment must not open the gate.
	 */
	private function apply_status( MemberOrder $order, $status, array $data ) {
		if ( 'success' === $order->status ) {
			return;
		}

		// The SDK's PaymentStatus models the five values the contract promises (waiting,
		// partially_paid, finished, expired, failed) and throws on anything else. The guard
		// degrades an unknown value to an order note instead of a 500: a contract change should
		// surface as a note to investigate, not as six failed delivery retries.
		try {
			$mapped = PaymentStatus::fromMerchant( $status );
		} catch ( PayzumException $e ) {
			self::log( 'IPN carried an unknown payment_status "' . $status . '" — contract change?' );
			$note = sprintf( /* translators: %s: status */ __( 'Payzum: status update — %s.', 'payzum-pmpro' ), $status );
			if ( false === strpos( (string) $order->notes, $note ) ) {
				$order->notes = trim( (string) $order->notes . "\n" . $note );
				$order->saveOrder();
			}
			return;
		}

		// Payzum retries a delivery up to five times, and fires on more than one transition, so
		// every branch has to be a no-op once the order already reads that way. Without this the
		// order collects the same note again on every retry.
		$current = (string) $order->status;

		switch ( $mapped->value ) {
			case 'finished':
				// `finished` only says the Payzum invoice settled — it says nothing about that
				// invoice having been for THIS order's initial payment. On a membership gateway
				// that is the worst place to trust a status: a settlement for a fraction of the
				// price would grant the FULL level, and the level is what has value here, not the
				// order row. Hold it for a human instead; 'review' is a first-class PMPro status
				// and grants nothing.
				$mismatch = self::settlement_mismatch( $order, $data );
				if ( null !== $mismatch ) {
					self::log( 'IPN finished for order ' . $order->code . ' rejected: ' . $mismatch );
					$note = sprintf(
						/* translators: %s: what did not match, e.g. "it settled 3.00 USD while the order is for 6.00 USD" */
						__( 'Payzum: reported as finished but %s — held for review, level NOT granted.', 'payzum-pmpro' ),
						$mismatch
					);
					if ( 'review' === $current && false !== strpos( (string) $order->notes, $note ) ) {
						return;
					}
					$order->status = 'review';
					$order->notes  = trim( (string) $order->notes . "\n" . $note );
					$order->saveOrder();
					return;
				}

				$pzid = '';
				if ( isset( $data['payment_id'] ) ) {
					$pzid = (string) $data['payment_id'];
				} elseif ( isset( $data['id'] ) ) {
					$pzid = (string) $data['id'];
				}
				if ( '' !== $pzid ) {
					$order->payment_transaction_id = sanitize_text_field( $pzid );
				}
				$order->status = 'success';
				$order->saveOrder();

				if ( function_exists( 'pmpro_changeMembershipLevel' ) && ! empty( $order->membership_id ) ) {
					pmpro_changeMembershipLevel( $order->membership_id, $order->user_id );
					self::log( 'granted level ' . $order->membership_id . ' to user ' . $order->user_id );
				}
				break;

			case 'partially_paid':
				// PMPro has no partial state. Leave the order pending — the level stays ungranted —
				// and record it so the site owner can see why.
				if ( false !== strpos( (string) $order->notes, 'Payzum: partial payment received' ) ) {
					return;
				}
				$order->notes = trim( (string) $order->notes . "\n" . __( 'Payzum: partial payment received — awaiting the remainder.', 'payzum-pmpro' ) );
				$order->saveOrder();
				break;

			case 'expired':
				// NOT 'cancelled'. PMPro deprecated that order status when subscriptions moved to
				// their own table, and MemberOrder::saveOrder() silently rewrites it to 'success' —
				// which on a membership gateway would mark an unpaid, expired invoice as paid.
				if ( 'error' === $current && false !== strpos( (string) $order->notes, 'Payzum: invoice expired' ) ) {
					return;
				}
				$order->status = 'error';
				$order->notes  = trim( (string) $order->notes . "\n" . __( 'Payzum: invoice expired before full payment.', 'payzum-pmpro' ) );
				$order->saveOrder();
				break;

			case 'failed':
				if ( 'error' === $current && false !== strpos( (string) $order->notes, 'Payzum: payment failed' ) ) {
					return;
				}
				$order->status = 'error';
				$order->notes  = trim( (string) $order->notes . "\n" . __( 'Payzum: payment failed.', 'payzum-pmpro' ) );
				$order->saveOrder();
				break;

			default:
				// waiting -> note only. (Reversing access on a refund is the site owner's
				// decision, and PMPro has its own flow for it.)
				$note = sprintf( /* translators: %s: status */ __( 'Payzum: status update — %s.', 'payzum-pmpro' ), $mapped->value );
				if ( false !== strpos( (string) $order->notes, $note ) ) {
					return;
				}
				$order->notes = trim( (string) $order->notes . "\n" . $note );
				$order->saveOrder();
				break;
		}
	}

	/**
	 * How the settled amount/currency differ from the order, as a human phrase, or null when they
	 * match.
	 *
	 * Compared against the same two values the create call was built from — InitialPayment (what
	 * the member was actually charged now; `total` can include a recurring leg PMPro is not
	 * charging today) and pricing_payload()'s price_currency rather than the raw site currency,
	 * because in crypto pricing mode the invoice is denominated in the coin symbol and the site
	 * currency would never match.
	 *
	 * An amount that cannot be read as a number is NOT a pass. price_amount is `required` in the
	 * contract, so null, "", an array or an object means either a contract break or a forged
	 * payload — and in both cases the one number that proves the invoice was raised for this
	 * order is missing. Skipping the comparison there would grant the membership level
	 * unverified, which is exactly how an attacker turns a 1-cent invoice into a paid level.
	 * Unverifiable is treated as mismatched.
	 *
	 * @param MemberOrder $order
	 * @param array       $data verified payload
	 * @return string|null
	 */
	private static function settlement_mismatch( MemberOrder $order, array $data ) {
		$has_amount    = isset( $data['price_amount'] ) && is_numeric( $data['price_amount'] );
		$paid_amount   = $has_amount ? (float) $data['price_amount'] : 0.0;
		$paid_currency = isset( $data['price_currency'] ) ? strtolower( trim( (string) $data['price_currency'] ) ) : '';

		$expected_amount = (float) $order->InitialPayment;
		if ( $expected_amount <= 0 ) {
			$expected_amount = (float) $order->total;
		}
		$pricing           = self::pricing_payload( $order->code );
		$expected_currency = strtolower( (string) $pricing['price_currency'] );

		if ( ! $has_amount ) {
			self::log( 'IPN for order ' . $order->code . ' carried no usable price_amount — settlement could not be verified, not crediting' );
			return sprintf(
				/* translators: 1: order amount, 2: order currency */
				__( 'it reported no readable settled amount, so it cannot be shown to cover the %1$s %2$s this order is for', 'payzum-pmpro' ),
				number_format( $expected_amount, 2, '.', '' ),
				strtoupper( $expected_currency )
			);
		}

		// Never `==` on floats, and never a strict "must be exact": an overpayment still pays for
		// the level, so only a SHORTFALL beyond half a cent counts.
		if ( ( $expected_amount - $paid_amount ) > self::AMOUNT_TOLERANCE ) {
			return sprintf(
				/* translators: 1: settled amount, 2: settled currency, 3: order amount, 4: order currency */
				__( 'it settled %1$s %2$s while the order is for %3$s %4$s', 'payzum-pmpro' ),
				number_format( $paid_amount, 2, '.', '' ),
				'' !== $paid_currency ? strtoupper( $paid_currency ) : strtoupper( $expected_currency ),
				number_format( $expected_amount, 2, '.', '' ),
				strtoupper( $expected_currency )
			);
		}

		// Case-insensitive: the API echoes the currency in whatever case it stored it.
		if ( '' !== $paid_currency && '' !== $expected_currency && $paid_currency !== $expected_currency ) {
			return sprintf(
				/* translators: 1: settled currency, 2: expected currency */
				__( 'it settled in %1$s while the order was invoiced in %2$s', 'payzum-pmpro' ),
				strtoupper( $paid_currency ),
				strtoupper( $expected_currency )
			);
		}

		return null;
	}

	private function respond( $code, $message ) {
		status_header( $code );
		nocache_headers();
		echo esc_html( $message );
		exit;
	}
}
