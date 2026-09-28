=== Payzum Crypto & Stablecoin Gateway for Paid Memberships Pro ===
Contributors: payzum
Tags: paid-memberships-pro, pmpro, membership, cryptocurrency, stablecoin
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto and stablecoins (USDC/USDT, multi-chain) for Paid Memberships Pro with Payzum. Non-custodial — funds settle to your own wallet.

== Description ==

Payzum lets your Paid Memberships Pro site accept cryptocurrency and stablecoin payments
(USDC/USDT and more, across multiple chains) for membership levels. Payments are
**non-custodial**: funds settle directly to your own wallet — Payzum never takes custody.

At checkout the member is sent to a secure Payzum hosted checkout (QR + deposit address, live
status), or the checkout can be embedded in your confirmation page as an overlay or inline. The
membership level is granted automatically from a signed IPN webhook, so a closed browser tab never
loses a paid membership.

This is an add-on gateway: it requires **Paid Memberships Pro** to be installed and active, and it
does not change any of PMPro's default behaviour. Verified against PMPro 3.2.

Features:

* Crypto & stablecoin checkout for membership levels (USDC/USDT, multi-chain).
* Non-custodial — settles to your wallet.
* Hosted checkout — no card data or crypto handling on your server.
* Redirect, modal or inline render modes.
* Signed IPN webhooks (HMAC-SHA-512) verify every payment server-side.
* The settled amount and currency are checked against the order before the level is granted; a
  mismatch goes to PMPro's **review** status and grants nothing.
* The checkout form drops PMPro's card and billing fields when Payzum is selected, so a crypto
  buyer is not asked for a card number.
* Fiat pricing (Payzum converts) or crypto pricing (`pricing_mode: "direct"`).
* No chargebacks.

The member picks the coin on the Payzum checkout, limited to the tokens your merchant account
accepts. There is no coin selector in the plugin, by design.

**Recurring levels are not supported and are refused at checkout.** This gateway takes the initial
payment only; it never creates a subscription, because the Payzum REST API does not expose one.
Any level PMPro reports as recurring is blocked at registration with a message asking the member to
choose a one-time membership or another payment method — granting an auto-renewing level for a
single payment would hand out ongoing access for free. One-time levels, and fixed-term levels that
simply expire and are purchased again, work normally.

== Installation ==

1. Install and activate **Paid Memberships Pro** first. This plugin does nothing without it and
   will show an admin notice if it is missing.
2. Upload the `payzum-pmpro` folder to `/wp-content/plugins/`, or install the zip via
   Plugins → Add New → Upload.
3. Activate the plugin.
4. Go to Memberships → Settings → Payment Gateway & SSL and choose
   **Crypto / Stablecoins (Payzum)**. The gateway only appears in that list once its credentials
   are set, so save the fields below first if you do not see it.
5. Paste your **API key** and **Webhook secret** from your Payzum dashboard, and pick the
   **Environment** (Production — merchant.payzum.com, or Staging / sandbox — staging.payzum.com,
   which has separate API keys). Both credentials render as password fields.
6. Copy the **IPN URL** shown under the webhook secret into your Payzum webhook settings. The IPN
   signature header is fixed (`x-nowpayments-sig`) — no configuration needed.
7. Save, then buy a one-time level as a test to confirm the flow.

Your site needs **PHP 8.1 or newer**. The plugin bundles the official `payzum/payzum-php` SDK,
which uses enums and named arguments.

== External services ==

This plugin connects to the Payzum API to create payment invoices and receive payment
notifications. It is required for the gateway to work.

* What it sends: when a member chooses Payzum at checkout, the plugin sends the level's initial
  payment amount, the currency, the PMPro order code and your site's callback/return URLs to
  Payzum to create the invoice. Payment confirmations arrive as signed webhooks from Payzum; the
  plugin verifies their signature before granting the level. No member personal data is sent by
  the plugin.
* When: only when the gateway is selected and a member pays.
* Endpoints: `https://merchant.payzum.com` (production) or `https://staging.payzum.com`
  (staging), as selected in the plugin settings.
* Service provider: Payzum — [terms](https://payzum.com/terms), [privacy](https://payzum.com/privacy).

If your site sends a Content-Security-Policy, allow `merchant.payzum.com` in `script-src`,
`frame-src` and `connect-src` for the modal and inline render modes.

== Frequently Asked Questions ==

= Is it custodial? =
No. Funds settle directly to your own wallet.

= Which coins are supported? =
USDC/USDT and other crypto across supported chains. Which coins your site accepts is configured in
your Payzum dashboard (Merchants → Settings → Accepted tokens); the member picks one of those on
the Payzum checkout.

= Can I use it for recurring memberships? =
No. Levels with a billing cycle are refused at checkout with an explanatory message, because the
gateway charges the initial payment only and cannot set up a renewal. Use a one-time or fixed-term
level, or offer another payment method for recurring levels.

= What happens if the member underpays? =
The level is not granted. A `partially_paid` invoice leaves the order `pending`; a `finished`
invoice whose settled amount or currency does not match the initial payment moves the order to
PMPro's **review** status with an explanatory note. Overpayment is fine and grants the level
normally.

= Why does the order sit in "pending" after checkout? =
The gateway joins PMPro's pending-status gateways, so an order legitimately waits in `pending`
between checkout and the signed IPN while the payment confirms on-chain. It is not a failure.

= What about free or trial levels? =
A level whose initial payment is 0 is completed immediately without calling the API at all.

= Do I need to write code? =
No. Enter your API key and webhook secret and you are live.

== Changelog ==

= 1.3.1 =
* Plugin URI now points at the plugin's own repository, so it differs from the Author URI as
  the plugin directory requires. No functional change.

= 1.3.0 =
* The settled amount and currency are verified against the order's initial payment before the level
  is granted. A `finished` invoice that does not match moves the order to **review** and grants
  nothing.
* IPN deliveries are deduplicated by event id, and the whole transition runs under a per-order lock
  so two simultaneous deliveries cannot both grant the level.
* The gateway hides itself when it has no credentials, instead of offering a checkout that cannot
  settle.

= 1.2.0 =
* Rebuilt on the official `payzum/payzum-php` SDK (bundled in `vendor/`): HTTP client, webhook
  signature verification, decimal-exact amounts and the payment-status vocabulary now live in one
  tested library instead of plugin code.
* New Environment setting (production / staging) for end-to-end testing against the sandbox.
* Every invoice create carries an idempotency key, so a transport retry cannot mint a second
  invoice.
* Requires PHP 8.1 (the SDK's floor).

= 1.1.0 =
* Card and billing fields are stripped from the checkout when Payzum is selected. Without that,
  PMPro rendered a card form for a crypto gateway and then blocked the checkout on validating it.
* The member picks the coin on the Payzum hosted checkout (`pay_currency: "all"`); dropped the
  in-plugin settlement-currency setting and the IPN signature-header setting. The old default coin
  was USDT on Tron, whose $100 network minimum rejected every ordinary membership.
* Recurring levels are refused at checkout instead of being charged once.
* Added modal and inline render modes, and a crypto currency mode (`pricing_mode: "direct"`).
* An `expired` invoice writes PMPro's `error` status rather than the deprecated `cancelled`, which
  PMPro silently rewrote to `success`.

= 1.0.0 =
* Initial release: hosted-checkout membership gateway with signed IPN verification.
