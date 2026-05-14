# wc-wdk-payments

Self-custodial USDt payments for WooCommerce, powered by Tether's [Wallet Development Kit (WDK)](https://docs.wallet.tether.io).

> Status: **M1 proposal stage** — bounty submission for [WDK in Ecommerce](https://tether.dev/grants/bounties/). Not yet production-ready.

## Overview

`wc-wdk-payments` is a WooCommerce payment gateway plugin that lets merchants accept USDt from customer-controlled WDK wallets. Funds settle directly on-chain to a merchant address. No custodial intermediary, no chargebacks, no per-transaction processor fee.

The plugin polls the [WDK Indexer REST API](https://docs.wallet.tether.io/tools/indexer-api) for incoming transfers and advances WooCommerce orders through their standard lifecycle (`pending` → `on-hold` → `processing`) as confirmations are met.

## Supported networks

| Network | Asset | Indexer support |
|---|---|---|
| Tron | USDt (TRC-20) | ✓ |
| Polygon | USDt | ✓ |
| Arbitrum | USDt | ✓ |
| Ethereum | USDt (ERC-20) | ✓ |
| Sepolia | USDt (testnet) | ✓ |

TON and Bitcoin are deferred to a future milestone.

## Architecture

```
Customer storefront                       Merchant (WordPress + WooCommerce)
─────────────────────                     ─────────────────────────────────
       │                                          │
       │ pick "Pay with USDt"                     │ poll indexer for transfers
       ▼                                          ▼
   Payment page  ◄── deposit address + URI ── Plugin (Gateway, Poller, Indexer client)
       │                                          ▲
       │ scan / open in WDK wallet                │
       ▼                                          │
   WDK wallet ──── signed tx ────► Blockchain ────┘
                                   (Tron / EVM)
```

See [`docs/M1-proposal.md`](docs/M1-proposal.md) for the full architecture document.

## Tech stack

- **PHP 8.1+** — WooCommerce gateway implemented via `WC_Payment_Gateway`
- **Action Scheduler** — bundled with WooCommerce; used for resilient background polling
- **WDK Indexer REST API** — `https://wdk-api.tether.io`, accessed via x-api-key auth
- **WordPress 6.4+ / WooCommerce 8.0+** — HPOS (Custom Order Tables) declared compatible

## Standards compliance

- [BIP-32](https://github.com/bitcoin/bips/blob/master/bip-0032.mediawiki) — HD wallets (xpub-based address derivation)
- [BIP-44](https://github.com/bitcoin/bips/blob/master/bip-0044.mediawiki) — Multi-account hierarchy
- [EIP-681](https://eips.ethereum.org/EIPS/eip-681) — URL Format for Transaction Requests
- [BIP-21](https://github.com/bitcoin/bips/blob/master/bip-0021.mediawiki) — URI Scheme
- [WooCommerce Payment Gateway API](https://woocommerce.com/document/payment-gateway-api/)

## Repository layout

```
wc-wdk-payments/
├── wc-wdk-payments.php         Plugin bootstrap, HPOS declaration
├── includes/
│   ├── class-gateway.php       WC_Gateway_WDK_USDt — checkout integration
│   ├── class-poller.php        Action Scheduler poller, order state transitions
│   └── class-indexer-client.php  HTTP client for the WDK Indexer
├── templates/                  Customer-facing payment page templates
├── assets/                     CSS, JS, images
├── docs/
│   └── M1-proposal.md          Architecture + platform analysis
└── tests/                      PHPUnit tests
```

## Development

Local WordPress + WooCommerce via [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
npm install -g @wordpress/env
wp-env start
# WordPress is now at http://localhost:8888  (admin: admin / password)
```

The plugin is auto-mounted via `.wp-env.json`. Install WooCommerce from the admin plugin browser, then activate WDK Payments under WooCommerce → Settings → Payments.

## License

[Apache-2.0](LICENSE)

## Contributing

This project is a bounty submission in active development. Issues and ideas welcome; please hold off on PRs until M2 lands.
