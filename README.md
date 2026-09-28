# Payzum for Paid Memberships Pro — Accept Crypto & Stablecoin Payments (USDC, USDT)

Accept **cryptocurrency and stablecoin payments** (USDC, USDT and more, multi-chain) for
[Paid Memberships Pro](https://www.paidmembershipspro.com) memberships through
[Payzum](https://payzum.com) — **non-custodial**: funds settle directly to your
own wallet, Payzum never takes custody. No chargebacks, no card networks, no
PCI surface.

- **Plugin:** `payzum-pmpro` · **Version:** 1.3.0 · **License:** GPL-2.0-or-later
- **Requires:** WordPress ≥ 5.6, Paid Memberships Pro, PHP ≥ 8.1

## How it works

1. The member picks the crypto option at checkout and pays on a hosted checkout
   page (QR code + deposit address, live status) — or in an embedded widget,
   see render modes below — where they choose the coin and chain. No wallet or
   card data touches your server; card and billing fields are stripped from
   the PMPro checkout when Payzum is selected.
2. Crypto confirmation is **asynchronous**, so the membership level is granted
   from Payzum's signed server-to-server IPN webhook, never from the member's
   browser return — a closed tab never loses a paid membership, and a partial
   payment never opens the gate.
3. Every webhook is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, replay window) before a single field of it is read.
   Redelivered webhooks are deduplicated and the settled amount and currency
   are re-checked against the order's initial payment before the level is
   granted — on a membership gateway that is the difference between a partial
   settlement and a full year of access.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon, Ethereum,
  Arbitrum, Base, Optimism, Tron, Solana and more), plus major cryptocurrencies.
- **Non-custodial** — payments settle to the site owner's own wallet.
- **Three render modes** — full redirect to the hosted checkout, a modal
  overlay, or an inline widget on the membership confirmation page.
- **Signed IPN webhooks** (HMAC-SHA-512) grant memberships server-side, with
  amount/currency verification and per-order locking against double delivery.
- **Free and trial levels** complete instantly without touching the API.
- **Fiat or crypto pricing** — price in your site currency and let Payzum
  convert, or denominate directly in a stablecoin.
- **Zero chargebacks** — crypto payments are final.

## Installation

**From the release zip (recommended).** Download
[`payzum-pmpro-1.3.0.zip`](https://github.com/payzum-dev/paid-memberships-pro-payzum/releases/latest), then in WordPress go to
**Plugins → Add New → Upload Plugin**, pick the zip and activate it (requires Paid Memberships Pro). The archive unpacks to
`payzum-pmpro/`, the folder name WordPress expects.

**From a clone.** This repository *is* the plugin, so its contents must land in a folder named
`payzum-pmpro`:

```bash
git clone https://github.com/payzum-dev/paid-memberships-pro-payzum.git payzum-pmpro
mv payzum-pmpro /path/to/wp-content/plugins/
```

Do not drop the repository in as `paid-memberships-pro-payzum` — WordPress keys the plugin off the folder name.

The official [`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php) SDK is
vendored, so no composer step is needed.

## Configuration

Go to **Memberships → Settings → Payment Gateway & SSL** and choose
**Crypto / Stablecoins (Payzum)**:

| Setting | Meaning |
|---|---|
| API key | From your [Payzum merchant dashboard](https://merchant.payzum.com) |
| Webhook secret | Verifies incoming payment webhooks (IPN) |
| Currency mode | `fiat` (default — Payzum converts from your site currency) or `crypto` (denominate directly in a stablecoin) |
| Render mode | `redirect` (default), `modal`, or `inline` widget |
| Environment | Production or staging (staging needs its own API key) |
| Debug log | Writes to the WordPress debug log, prefixed `[payzum]` |

The settings screen shows your site's **IPN URL**
(`https://<your-site>/?payzum_pmpro_ipn=1`) — paste it into the Payzum
dashboard webhook settings. The signature header is fixed; nothing to
configure. Both credentials render as password inputs.

The member chooses the coin on the Payzum checkout, limited to the allowlist
you configure in the Payzum dashboard (**Merchants → Settings → Accepted
tokens**) and enforced server-side.

## Order status mapping

| Payzum payment status | PMPro order | Membership |
|---|---|---|
| `finished` (amount and currency match the initial payment) | Success | Granted |
| `finished` (settled amount or currency does not match) | Review | Not granted — held for review |
| `partially_paid` | Pending (underpaid) | Not granted |
| `expired` | Error | Not granted |
| `failed` | Error | Not granted |
| anything else | Unchanged, note appended | Unchanged |

## FAQ

**Is Payzum custodial?**
No. Funds settle directly to your own wallet — Payzum never holds your money.

**Can Paid Memberships Pro accept USDT or USDC?**
Yes — with this plugin, members pay for their level in USDC, USDT or other
assets on the chains you allow, and the membership is granted automatically
from a signed webhook.

**Do members need an account or a specific wallet?**
No. They scan a QR or copy a deposit address from the Payzum checkout and pay
from any wallet.

**Can a partial payment unlock the membership?**
No. The level is granted only when the settled amount and currency match the
order's initial payment; anything else is held for review or left pending.

**What about recurring subscriptions?**
Crypto payments are one-time push payments, so this gateway covers the initial
payment of a level. Renewals are handled the same way — the member pays again
when due.

**What about chargebacks?**
There are none — crypto payments are final, which eliminates chargeback fraud
on memberships.

**What data is shared with Payzum?**
Only the payment amount, currency, the order code and your site's callback
URLs — no member personal data. Endpoints: `https://merchant.payzum.com`
(production), `https://staging.payzum.com` (staging).

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, donation and billing
platforms — WooCommerce, Easy Digital Downloads, GiveWP, Magento 2, PrestaShop,
Shopware 6, OpenCart, Zen Cart, nopCommerce, Ecwid, BigCommerce, Shopify, Wix,
Medusa, Vendure, Saleor, Sylius, WHMCS, Blesta, HostBill, ClientExec, pretix,
Frappe/ERPNext, Akaunting and django-payments — plus official SDKs for PHP,
Node.js/TypeScript, Python and Rust. Browse them all at
[github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants: accept USDC, USDT and other digital assets with settlement straight
to your own wallet, optional auto-conversion to stablecoins, and a single REST
API. API docs: [merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[GPL-2.0-or-later](LICENSE). Contributed and maintained by Payzum.
