<?php
/**
 * Plugin Name: Payzum Crypto & Stablecoin Gateway for Paid Memberships Pro
 * Plugin URI:  https://payzum.com
 * Description: Accept crypto and stablecoins (USDC/USDT, multi-chain) for PMPro memberships with Payzum. Members choose the coin on the Payzum checkout. Non-custodial — funds settle to your own wallet.
 * Version:     1.3.0
 * Author:      Payzum
 * Author URI:  https://payzum.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payzum-pmpro
 * Requires at least: 5.6
 * Requires PHP: 8.1
 * Requires Plugins: paid-memberships-pro
 *
 * Disclosure: contributed by Payzum. Opt-in gateway; does not change default behaviour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'PAYZUM_PMPRO_VERSION', '1.3.0' );
define( 'PAYZUM_PMPRO_PLUGIN_FILE', __FILE__ );
define( 'PAYZUM_PMPRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Boot once PMPro is loaded.
 *
 * Everything lives behind this check — including the IPN listener, which used to be registered
 * unconditionally and returned a fatal 500 (rather than a 4xx) when PMPro was inactive. Payzum retries
 * a delivery about six times either way — verified 2026-08-26, a 4xx does NOT stop the retries the
 * way the prose docs claim — so the listener answered a PHP fatal six times over. Booting behind
 * the guard makes it a clean 404 instead.
 */
add_action( 'plugins_loaded', 'payzum_pmpro_init', 11 );

function payzum_pmpro_init() {
	if ( ! class_exists( 'PMProGateway' ) ) {
		add_action( 'admin_notices', 'payzum_pmpro_missing_pmpro_notice' );
		return;
	}

	// The official payzum/payzum-php SDK, vendored. Namespaced (Payzum\*), so it coexists with
	// the other Payzum WordPress plugins' copies: whichever autoloader registers first serves it.
	require_once PAYZUM_PMPRO_PLUGIN_DIR . 'vendor/autoload.php';
	require_once PAYZUM_PMPRO_PLUGIN_DIR . 'includes/class-payzum-pmpro-plugin.php';
	require_once PAYZUM_PMPRO_PLUGIN_DIR . 'includes/class-pmprogateway-payzum.php';

	payzum_pmpro_maybe_upgrade();

	new Payzum_PMPro_Plugin();

	if ( class_exists( 'PMProGateway_payzum' ) ) {
		PMProGateway_payzum::init();
	}

	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'payzum_pmpro_settings_link' );
}

function payzum_pmpro_settings_link( $links ) {
	$url  = admin_url( 'admin.php?page=pmpro-paymentsettings' );
	$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'payzum-pmpro' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}

function payzum_pmpro_missing_pmpro_notice() {
	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'Payzum Crypto Payments requires Paid Memberships Pro to be installed and active.', 'payzum-pmpro' )
		. '</p></div>';
}

/**
 * One-time upgrade routine.
 *
 * 1.1.0 moved coin selection out of the plugin: the member now picks on the Payzum hosted checkout,
 * limited to the merchant's allowlist (Payzum dashboard -> Merchants -> Settings -> Accepted
 * tokens). The old single "Settlement currency" is therefore meaningless — and its default,
 * usdttrc20, carries a $100 network minimum, so an untouched install rejected every ordinary
 * membership with AMOUNT_BELOW_MINIMUM. Drop it rather than leave a setting that can only do harm.
 *
 * The IPN signature header is fixed by the platform, so the field that let a site owner get it
 * wrong goes too.
 */
function payzum_pmpro_maybe_upgrade() {
	if ( get_option( 'payzum_pmpro_version' ) === PAYZUM_PMPRO_VERSION ) {
		return;
	}

	// PMPro stores each option as its own `pmpro_<name>` WordPress option.
	foreach ( array( 'payzum_pay_currency', 'payzum_sig_header' ) as $key ) {
		delete_option( 'pmpro_' . $key );
	}

	update_option( 'payzum_pmpro_version', PAYZUM_PMPRO_VERSION );
}
