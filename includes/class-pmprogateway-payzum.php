<?php
/**
 * Paid Memberships Pro gateway for Payzum.
 *
 * process() saves a pending order, creates the invoice with pay_currency:"all" and hands the member
 * to the Payzum checkout — off-site in redirect mode, or the confirmation page in modal / inline
 * mode, where Payzum_PMPro_Plugin mounts the widget.
 *
 * The membership level is granted from the signed IPN, never from the member's return, so a closed
 * browser tab cannot lose a paid membership — and a partial payment never opens the gate.
 *
 * Non-custodial: funds settle to the site owner's own wallet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMProGateway_payzum extends PMProGateway {

	public function __construct( $gateway = null ) {
		$this->gateway = $gateway;
		return $this->gateway;
	}

	public static function init() {
		add_filter( 'pmpro_gateways', array( 'PMProGateway_payzum', 'pmpro_gateways' ) );
		add_action( 'admin_notices', array( 'PMProGateway_payzum', 'missing_credentials_notice' ) );
		add_filter( 'pmpro_payment_options', array( 'PMProGateway_payzum', 'pmpro_payment_options' ) );
		add_filter( 'pmpro_payment_option_fields', array( 'PMProGateway_payzum', 'pmpro_payment_option_fields' ), 10, 2 );
		add_filter( 'pmpro_gateways_with_pending_status', array( 'PMProGateway_payzum', 'pending_status' ) );

		// Only when Payzum is the selected gateway: this is an off-site redirect gateway, so the
		// checkout must not render or require card and billing fields. Without these, PMPro shows a
		// Card Number / CVC form for a crypto gateway and then blocks the checkout on validating it.
		if ( 'payzum' === pmpro_getGateway() ) {
			add_filter( 'pmpro_include_billing_address_fields', '__return_false' );
			add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
			add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_payzum', 'pmpro_required_billing_fields' ) );
			add_filter( 'pmpro_registration_checks', array( 'PMProGateway_payzum', 'block_recurring_checkout' ) );
		}
	}

	/**
	 * Refuse a recurring level rather than charge it once and go quiet.
	 *
	 * This gateway takes the initial payment only — it never creates a subscription, because the
	 * Payzum REST API does not expose one. PMPro has no way for a gateway to declare that: unlike
	 * WooCommerce (`supports`), EDD Recurring and GiveWP, which all require a gateway to opt *in*
	 * to recurring, PMPro simply uses whichever gateway is configured. A member signing up for a
	 * level with a billing cycle would be charged once, keep access until it lapsed, and never be
	 * billed again — with nothing anywhere saying why.
	 *
	 * Blocking the checkout is the honest failure. The site owner sees it immediately; the member
	 * never pays for a subscription that was never going to renew.
	 *
	 * @param bool $okay whether PMPro should continue with the registration.
	 * @return bool
	 */
	public static function block_recurring_checkout( $okay ) {
		if ( ! $okay ) {
			return $okay;
		}

		global $pmpro_level;
		if ( empty( $pmpro_level ) || ! function_exists( 'pmpro_isLevelRecurring' ) ) {
			return $okay;
		}
		if ( ! pmpro_isLevelRecurring( $pmpro_level ) ) {
			return $okay;
		}

		Payzum_PMPro_Plugin::log( 'refused checkout: level ' . ( $pmpro_level->id ?? '?' ) . ' is recurring and Payzum charges the initial payment only' );

		pmpro_setMessage(
			__( 'This membership renews automatically, and crypto payments cannot be set up to renew. Please choose a one-time membership or another payment method.', 'payzum-pmpro' ),
			'pmpro_error'
		);
		return false;
	}

	/**
	 * Drop every billing and card field from PMPro's required list. The member enters nothing here —
	 * they pick a coin and pay on the Payzum checkout.
	 */
	public static function pmpro_required_billing_fields( $fields ) {
		foreach ( array(
			'bfirstname', 'blastname', 'baddress1', 'bcity', 'bstate', 'bzipcode',
			'bphone', 'bemail', 'bcountry', 'CardType', 'AccountNumber',
			'ExpirationMonth', 'ExpirationYear', 'CVV',
		) as $field ) {
			unset( $fields[ $field ] );
		}
		return $fields;
	}

	public static function pmpro_gateways( $gateways ) {
		// Do not offer the gateway until it can actually complete a payment.
		//
		// Without a webhook secret the IPN cannot be verified, so every genuine delivery is
		// rejected and the membership is never granted — the member pays and the site never knows.
		// That costs real money and gives the site owner no signal, so the gateway stays off the
		// list rather than accept a payment it cannot settle. Same for the API key, without which
		// the invoice cannot be created at all.
		if ( array() !== self::missing_credentials() ) {
			return $gateways;
		}

		$gateways['payzum'] = __( 'Crypto / Stablecoins (Payzum)', 'payzum-pmpro' );
		return $gateways;
	}

	/**
	 * Credentials that must be present before the gateway can take a payment.
	 *
	 * @return string[] Names of the missing settings, empty when everything is configured.
	 */
	public static function missing_credentials() {
		$missing = array();
		if ( '' === trim( (string) get_option( 'pmpro_payzum_api_key', '' ) ) ) {
			$missing[] = __( 'API key', 'payzum-pmpro' );
		}
		if ( '' === trim( (string) get_option( 'pmpro_payzum_webhook_secret', '' ) ) ) {
			$missing[] = __( 'webhook secret', 'payzum-pmpro' );
		}
		return $missing;
	}

	/** Admin notice naming what is missing — the checkout gives no clue on its own. */
	public static function missing_credentials_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$missing = self::missing_credentials();
		if ( array() === $missing ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Payzum cannot take payments.', 'payzum-pmpro' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of missing settings */
					__( 'Missing: %s. The gateway is hidden until these are set, because without them a member could pay an invoice that never settles.', 'payzum-pmpro' ),
					implode( ', ', $missing )
				)
			)
		);
	}

	/** Option names PMPro persists from the settings screen. */
	public static function pmpro_payment_options( $options ) {
		$payzum = array(
			'payzum_api_key',
			'payzum_webhook_secret',
			'payzum_environment',
			'payzum_currency_mode',
			'payzum_price_currency',
			'payzum_crypto_symbol',
			'payzum_crypto_network',
			'payzum_render_mode',
			'payzum_debug',
		);
		return array_merge( $payzum, $options );
	}

	/**
	 * Tell PMPro the order can sit in `pending` between checkout and the IPN. Without this PMPro
	 * treats a non-success order as a failure and the member sees an error on a payment that is
	 * merely still confirming on-chain.
	 */
	public static function pending_status( $gateways ) {
		$gateways[] = 'payzum';
		return $gateways;
	}

	/**
	 * Settings screen, under Memberships → Settings → Payment Gateway & SSL.
	 *
	 * There is deliberately no coin selector: which crypto a site accepts is a Payzum dashboard
	 * setting, and no API endpoint exposes a merchant's allowlist for the plugin to mirror.
	 */
	public static function pmpro_payment_option_fields( $values, $gateway ) {
		$hidden = 'payzum' !== $gateway ? ' style="display:none;"' : '';
		$row    = 'class="gateway gateway_payzum"' . $hidden;
		?>
		<tr class="pmpro_settings_divider gateway gateway_payzum"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<td colspan="2"><h2><?php esc_html_e( 'Payzum', 'payzum-pmpro' ); ?></h2></td>
		</tr>
		<?php // Credentials use password inputs. PMPro's own gateways render keys as plain text, but
		// a webhook secret readable over the shoulder — or in a screenshot — is worth deviating for. ?>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_api_key"><?php esc_html_e( 'API key', 'payzum-pmpro' ); ?></label></th>
			<td>
				<input type="password" autocomplete="off" id="payzum_api_key" name="payzum_api_key" size="60" value="<?php echo esc_attr( $values['payzum_api_key'] ?? '' ); ?>" />
				<p class="description"><?php esc_html_e( 'Your Payzum API key (64-hex). Dashboard → Merchants → API key.', 'payzum-pmpro' ); ?></p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_webhook_secret"><?php esc_html_e( 'Webhook secret', 'payzum-pmpro' ); ?></label></th>
			<td>
				<input type="password" autocomplete="off" id="payzum_webhook_secret" name="payzum_webhook_secret" size="60" value="<?php echo esc_attr( $values['payzum_webhook_secret'] ?? '' ); ?>" />
				<p class="description">
					<?php esc_html_e( 'The IPN signing secret from your Payzum webhook settings. Used to verify HMAC-SHA-512 signatures.', 'payzum-pmpro' ); ?><br />
					<?php esc_html_e( 'Paste this into your Payzum webhook settings:', 'payzum-pmpro' ); ?>
					<code><?php echo esc_html( Payzum_PMPro_Plugin::ipn_url() ); ?></code>
				</p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_environment"><?php esc_html_e( 'Environment', 'payzum-pmpro' ); ?></label></th>
			<td>
				<?php $environment = $values['payzum_environment'] ?? 'production'; ?>
				<select id="payzum_environment" name="payzum_environment">
					<option value="production" <?php selected( 'production', $environment ); ?>><?php esc_html_e( 'Production — merchant.payzum.com', 'payzum-pmpro' ); ?></option>
					<option value="staging" <?php selected( 'staging', $environment ); ?>><?php esc_html_e( 'Staging / sandbox — staging.payzum.com (separate API keys)', 'payzum-pmpro' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Staging is an isolated environment with its own API keys — a production key will not work there.', 'payzum-pmpro' ); ?></p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><?php esc_html_e( 'Accepted coins', 'payzum-pmpro' ); ?></th>
			<td>
				<p class="description">
					<?php
					echo wp_kses_post( __( 'Which crypto/stablecoins you accept is configured in your Payzum dashboard, under <strong>Merchants → Settings → Accepted tokens</strong>. The member picks one of those on the Payzum checkout — there is nothing to configure here.', 'payzum-pmpro' ) );
					?>
				</p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_currency_mode"><?php esc_html_e( 'Currency mode', 'payzum-pmpro' ); ?></label></th>
			<td>
				<?php $mode = $values['payzum_currency_mode'] ?? 'fiat'; ?>
				<select id="payzum_currency_mode" name="payzum_currency_mode">
					<option value="fiat" <?php selected( $mode, 'fiat' ); ?>><?php esc_html_e( 'Fiat — prices are in a normal currency (default)', 'payzum-pmpro' ); ?></option>
					<option value="crypto" <?php selected( $mode, 'crypto' ); ?>><?php esc_html_e( 'Crypto — prices are already denominated in a coin', 'payzum-pmpro' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Fiat: Payzum converts the level price to whichever coin the member picks. Crypto: your prices are already in a coin, so the member pays that exact amount with no conversion — and no coin choice.', 'payzum-pmpro' ); ?></p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_price_currency"><?php esc_html_e( 'Fiat price currency', 'payzum-pmpro' ); ?></label></th>
			<td>
				<input type="text" id="payzum_price_currency" name="payzum_price_currency" size="10" value="<?php echo esc_attr( $values['payzum_price_currency'] ?? '' ); ?>" placeholder="usd" />
				<p class="description"><?php echo wp_kses_post( __( 'Fiat mode only. Leave empty for the site currency, which is almost always right: the price is sent <strong>as-is</strong>, with no conversion, so a different code here re-denominates it rather than converting it.', 'payzum-pmpro' ) ); ?></p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_crypto_symbol"><?php esc_html_e( 'Crypto symbol', 'payzum-pmpro' ); ?></label></th>
			<td>
				<input type="text" id="payzum_crypto_symbol" name="payzum_crypto_symbol" size="10" maxlength="8" value="<?php echo esc_attr( $values['payzum_crypto_symbol'] ?? '' ); ?>" placeholder="usdc" />
				<p class="description"><?php echo wp_kses_post( __( 'Crypto mode only. The bare coin symbol your prices are in — <code>usdc</code>, <code>usdt</code>, <code>btc</code>. Not the network-suffixed ticker (<code>usdcmatic</code> is set via the field below).', 'payzum-pmpro' ) ); ?></p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_crypto_network"><?php esc_html_e( 'Crypto network', 'payzum-pmpro' ); ?></label></th>
			<td>
				<input type="text" id="payzum_crypto_network" name="payzum_crypto_network" size="16" value="<?php echo esc_attr( $values['payzum_crypto_network'] ?? '' ); ?>" placeholder="polygon" />
				<p class="description"><?php echo wp_kses_post( __( 'Crypto mode only. The chain for that symbol — <code>polygon</code>, <code>tron</code>, <code>ethereum</code>, <code>solana</code>… Leave empty for a native coin such as <code>btc</code>.', 'payzum-pmpro' ) ); ?></p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_render_mode"><?php esc_html_e( 'Render mode', 'payzum-pmpro' ); ?></label></th>
			<td>
				<?php $render = $values['payzum_render_mode'] ?? 'redirect'; ?>
				<select id="payzum_render_mode" name="payzum_render_mode">
					<option value="redirect" <?php selected( $render, 'redirect' ); ?>><?php esc_html_e( 'Redirect — send the member to the Payzum checkout (default)', 'payzum-pmpro' ); ?></option>
					<option value="modal" <?php selected( $render, 'modal' ); ?>><?php esc_html_e( 'Modal — open the checkout in an overlay on your site', 'payzum-pmpro' ); ?></option>
					<option value="inline" <?php selected( $render, 'inline' ); ?>><?php esc_html_e( 'Inline — embed the checkout in the confirmation page', 'payzum-pmpro' ); ?></option>
				</select>
				<p class="description"><?php echo wp_kses_post( __( 'Modal and inline load the Payzum widget on your membership-confirmation page. If your site sends a Content-Security-Policy header, allow <code>merchant.payzum.com</code> in <code>script-src</code>, <code>frame-src</code> and <code>connect-src</code>.', 'payzum-pmpro' ) ); ?></p>
			</td>
		</tr>
		<tr <?php echo $row; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top"><label for="payzum_debug"><?php esc_html_e( 'Debug log', 'payzum-pmpro' ); ?></label></th>
			<td>
				<input type="checkbox" id="payzum_debug" name="payzum_debug" value="1" <?php checked( ! empty( $values['payzum_debug'] ) ); ?> />
				<label for="payzum_debug"><?php esc_html_e( 'Log gateway events to the WordPress debug log, prefixed [payzum].', 'payzum-pmpro' ); ?></label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Create the pending order + Payzum invoice, then hand the member onward.
	 *
	 * @param MemberOrder $order
	 * @return bool false on failure; on success this redirects and never returns.
	 */
	public function process( &$order ) {
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}
		$order->payment_type = 'Payzum';
		$order->gateway      = 'payzum';
		$order->status       = 'pending';
		$order->saveOrder();

		$amount = (float) $order->InitialPayment;

		// A free or trial level has nothing to charge, and the API rejects price_amount <= 0. Grant
		// it here rather than sending the member to a checkout that cannot succeed.
		if ( $amount <= 0 ) {
			$order->status = 'success';
			$order->saveOrder();
			Payzum_PMPro_Plugin::log( 'order ' . $order->code . ' has no initial payment — completing without an invoice' );
			return true;
		}

		$pricing = Payzum_PMPro_Plugin::pricing_payload( $order->code );

		try {
			// The amount travels as a string end to end: the SDK writes it into the JSON as an
			// exact number. Casting to float would round it on the way out.
			$result = Payzum_PMPro_Plugin::payzum_client()->payments->create(
				priceAmount:      sprintf( '%.2F', $amount ),
				priceCurrency:    $pricing['price_currency'],
				payCurrency:      $pricing['pay_currency'],
				orderId:          Payzum_PMPro_Plugin::order_reference( $order ),
				orderDescription: Payzum_PMPro_Plugin::order_description( $order ),
				network:          $pricing['network'],
				ipnCallbackUrl:   Payzum_PMPro_Plugin::ipn_url(),
				successUrl:       Payzum_PMPro_Plugin::success_url( $order ),
				cancelUrl:        Payzum_PMPro_Plugin::cancel_url(),
				pricingMode:      $pricing['pricing_mode'],
				// The order code is random and unique to this order, so the key can be static
				// per order: it makes a transport retry safe without ever pinning a different
				// order to a stale invoice.
				idempotencyKey:   'pmpro-' . (string) $order->code,
			);
		} catch ( \Payzum\Errors\ApiException $e ) {
			// Branch on the typed code, never on the message.
			Payzum_PMPro_Plugin::log( 'create failed for order ' . $order->code . ' [' . $e->rawCode . ']: ' . $e->getMessage() );
			return $this->fail( $order, Payzum_PMPro_Plugin::buyer_notice_for( $e->rawCode ) );
		} catch ( \Payzum\Errors\PayzumException $e ) {
			Payzum_PMPro_Plugin::log( 'create failed for order ' . $order->code . ': ' . $e->getMessage() );
			return $this->fail( $order, __( 'Unable to start the crypto payment. Please try again or pick another method.', 'payzum-pmpro' ) );
		}

		$invoice_url = isset( $result['invoice_url'] ) ? (string) $result['invoice_url'] : '';
		if ( '' === $invoice_url ) {
			Payzum_PMPro_Plugin::log( 'create_payment returned no invoice_url for order ' . $order->code . ': ' . wp_json_encode( $result ) );
			return $this->fail( $order, __( 'Payzum did not return a checkout URL. Please try again.', 'payzum-pmpro' ) );
		}

		// Payzum returns the payment id as `payment_id` (alias `id` in older docs); store either.
		$pzid = '';
		if ( isset( $result['payment_id'] ) ) {
			$pzid = (string) $result['payment_id'];
		} elseif ( isset( $result['id'] ) ) {
			$pzid = (string) $result['id'];
		}
		if ( '' !== $pzid ) {
			update_pmpro_membership_order_meta( $order->id, '_payzum_payment_id', sanitize_text_field( $pzid ) );
		}
		// The invoice is a draft until the member picks a coin, and reads don't return the URL —
		// keep it so the order can link back to the checkout.
		update_pmpro_membership_order_meta( $order->id, '_payzum_invoice_url', esc_url_raw( $invoice_url ) );

		Payzum_PMPro_Plugin::log( 'invoice ' . $pzid . ' created for order ' . $order->code . ' (' . Payzum_PMPro_Plugin::render_mode() . ')' );

		if ( 'redirect' === Payzum_PMPro_Plugin::render_mode() ) {
			Payzum_PMPro_Plugin::redirect( $invoice_url, true );
		}

		// Modal / inline keep the member on this site: bounce to the confirmation page, which
		// Payzum_PMPro_Plugin turns into the widget host for this order.
		Payzum_PMPro_Plugin::redirect( Payzum_PMPro_Plugin::widget_host_url( $order ), false );

		return true; // not reached — redirect() exits.
	}

	/** Put the order into PMPro's error shape so the checkout shows the member a real message. */
	private function fail( &$order, $message ) {
		$order->status     = 'error';
		$order->errorcode  = 'payzum_error';
		$order->error      = $message;
		$order->shorterror = $message;
		$order->saveOrder();
		return false;
	}
}
