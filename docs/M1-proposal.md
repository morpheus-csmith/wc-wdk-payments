# WDK in Ecommerce — M1 Proposal

**Bounty:** WDK in Ecommerce
**Milestone:** M1 (Proposal & Platform Selection)
**Author:** github.com/morpheus-csmith
**Date:** 14 May 2026

---

## 1. Executive summary

This proposal commits to delivering a self-custodial USDt checkout flow as a **WooCommerce payment gateway plugin**, backed by a small **TypeScript SDK** (`@morpheus-csmith/wdk-checkout-core`) that any Node-based headless commerce stack (Medusa, Next.js commerce, custom Express backends) can also consume. The plugin is a thin PHP wrapper that calls the WDK Indexer REST API directly. The SDK exposes the same logic to JavaScript backends without duplicating it.

Three principles drive every design decision:

1. **Self-custodial throughout.** The merchant configures their own receive addresses. The plugin never sees, generates, or stores a private key. Funds settle on-chain directly to the merchant's wallet.
2. **Chain-pragmatic.** Lead with Tron USDt (where real-world USDt volume lives and gas is negligible). Support Polygon and Arbitrum (low gas, EVM credibility). Support Ethereum mainnet (canonical USDt). Demo on Sepolia testnet.
3. **Merchant-first onboarding.** A non-blockchain-native merchant can install the plugin, paste their addresses, paste a WDK Indexer API key, and accept their first payment in under 15 minutes. No seed phrases entered into WordPress, ever.

The deliverable in M3 is a working `wc-wdk-payments` plugin published to a public GitHub repository under Apache-2.0, with companion documentation and a 2–5 minute demo video showing a complete payment flow on Tron Nile testnet and Sepolia.

## 2. Why WooCommerce

The bounty allows "one or more" platforms and explicitly names "custom headless commerce" as in-scope. The choice between targeting Shopify, WooCommerce, and headless boils down to time-to-credible-demo and audience fit.

| Platform | Plugin model | Approval gate | Time-to-ship | Merchant audience |
|---|---|---|---|---|
| **WooCommerce** | Subclass `WC_Payment_Gateway`, register via hook, ship a directory | None for self-hosted; optional WP.org listing | Lowest | ~5M active stores; SMB and international; many already crypto-curious |
| **Shopify** | Payments App via Partner account, offsite or hosted-fields | Mandatory Shopify review; multi-week | Could blow M2 timeline | Higher AOV, but review gates the demo |
| **Magento 2** | Module via DI XML + payment method | None | Higher than Woo; smaller install base | Mid-market; less aligned with crypto adopters |
| **Custom headless** | npm package, framework-agnostic | None | Low if scoped right | Developer-first |

WooCommerce wins on three dimensions:

- **No approval gate.** The plugin can be live on a merchant's site immediately. Shopify's review process is non-trivial and not within the bounty's reward budget.
- **Largest crypto-adjacent merchant base.** WooCommerce is over-represented in the demographics most likely to want USDt rails: small international merchants, drop-shippers, content creators selling internationally, and emerging-market sellers.
- **Open gateway API.** `WC_Payment_Gateway` is a well-documented PHP base class. The integration surface is small, stable, and well-trodden.

The **headless SDK is delivered alongside** to satisfy the "or custom headless commerce" clause and to ensure adoption isn't gated on WordPress. The SDK is a thin TypeScript wrapper over `@tetherto/wdk-indexer-http` plus payment URI generation, address derivation helpers, and a polling loop. Any Node backend can import it.

Shopify is explicitly **out of scope** for this submission. The Payments App review process is real and slow, and forcing the timeline through it would degrade the rest of the work. A Shopify port is a natural follow-up.

## 3. Network selection

The demo and reference implementation will support four networks at M3, all of which are confirmed-supported by the WDK Indexer per the [Indexer API supported chains list](https://docs.wallet.tether.io/tools/indexer-api).

| Network | Asset | Why |
|---|---|---|
| **Tron** | USDt (TRC-20) | Dominant network for real-world USDt payment volume; effectively zero gas for the customer when using TRX or gas-free variants; the highest-utility network for actual merchant adoption. |
| **Polygon** | USDt (ERC-20) | Sub-cent gas, EVM tooling, broad wallet support. Best demo network for credibility-vs-cost balance. |
| **Arbitrum** | USDt | Canonical L2 for EVM USDt; demonstrates L2 coverage. |
| **Ethereum** | USDt (ERC-20) | Required for credibility; gas cost is the customer's problem, not the plugin's. |
| **Sepolia** | USDt (testnet) | Used in the demo video and end-to-end tests. |

TON and Bitcoin are **deferred** to a future milestone. Both are supported by the Indexer but their wallet flows (TON's hashed memos, Bitcoin's UTXO model) require integration patterns distinct enough from EVM/Tron that bundling them into M2 would dilute focus. They are listed in the README as the natural next networks.

Solana is **not included** in M3 despite appearing in the Indexer's `/chains` response example. The supported-chains table in the official docs does not list Solana USDt, and committing to it without confirmation would risk a demo failure. The roadmap acknowledges it as a target pending Tether confirmation.

## 4. Architecture

### 4.1 Components

```
┌──────────────────────────────┐          ┌──────────────────────────────────┐
│ Customer                     │          │ Merchant (WordPress + WooCommerce)│
│                              │          │                                  │
│  Storefront checkout page    │          │  wc-wdk-payments plugin          │
│         │                    │          │   ├── Gateway (WC_Payment_       │
│         │ pick "Pay with     │          │   │    Gateway subclass)        │
│         │  USDt"             │          │   ├── Address resolver          │
│         ▼                    │          │   ├── Payment URI builder       │
│  Payment page                │          │   ├── Poller (Action Scheduler) │
│   ├── QR code                │          │   └── Webhook handler           │
│   ├── Address + amount       │          │           │                      │
│   └── Status (polled)        │          │           │                      │
│         │                    │          │           │                      │
└─────────┼────────────────────┘          └───────────┼──────────────────────┘
          │                                            │
          │ scan QR / open deep link                   │ poll for incoming
          │                                            │ transfers
          ▼                                            ▼
  ┌────────────────────────┐                ┌────────────────────────┐
  │ Customer's WDK wallet  │                │ WDK Indexer REST API   │
  │ (mobile, browser ext)  │                │ wdk-api.tether.io      │
  └─────────┬──────────────┘                └────────────────────────┘
            │                                            ▲
            │ broadcast signed tx                       │
            ▼                                            │
       ┌──────────────────────────────────────────────────┐
       │ Blockchain (Tron / Polygon / Arbitrum / Ethereum) │
       └──────────────────────────────────────────────────┘
```

### 4.2 The flow

1. Customer clicks **Pay with USDt** at checkout. WooCommerce creates an order in `pending` status and routes to the gateway's `process_payment()` method.
2. Gateway resolves a per-order deposit address (see §4.3) and an expected amount in base units (e.g. `24970000` for 24.97 USDt at 6 decimals).
3. Gateway redirects the customer to a custom payment page rendered by the plugin. The page shows:
   - The deposit address as a QR code (encoded as an EIP-681 or BIP-21 payment URI so WDK-aware wallets parse the chain, token, recipient, and amount automatically)
   - The exact amount expected
   - A live status indicator that polls the order via WC REST
4. Customer opens their WDK-powered wallet (mobile app, browser extension, or any wallet that handles the URI scheme), confirms, and signs.
5. The transaction is broadcast to the network and eventually mined.
6. The plugin's poller — a WordPress **Action Scheduler** background job — hits the WDK Indexer `GET /api/v1/{blockchain}/{token}/{address}/token-transfers` endpoint at a tapering cadence. When a transfer matching `(to == merchant_address, amount == expected, timestamp > order_created_at)` appears, the order transitions from `wc-pending` to `wc-on-hold`, then to `wc-processing` once the configured confirmation depth is met.
7. The customer's payment page picks up the status change via its WC REST poll and renders the confirmation screen.

### 4.3 Address strategy

Two strategies are supported; the merchant chooses at setup.

**Strategy A — Static address with amount uniqueness (default).** The merchant pastes one receive address per chain. Each order's expected amount is augmented with a small random suffix (e.g. base amount 25.00 USDt becomes 25.000147). The poller filters incoming transfers by exact amount, which uniquely identifies the order. This is the simplest onboarding path and requires zero key handling.

- *Collision risk:* with a 6-decimal suffix and reasonable order volume (say, ≤ 100 open orders), collision probability is negligible. The plugin enforces uniqueness by checking active orders before fixing the suffix; if a collision would occur, it rotates the suffix by one base unit.
- *Privacy:* all customer payments go to one address, which is on-chain-visible. Documented in the security section.

**Strategy B — xpub-derived per-order addresses.** The merchant generates a BIP-32 extended public key from their WDK wallet's seed and pastes it into plugin settings. The plugin derives a fresh receive address per order at index `m/<purpose>'/<coin>'/0'/0/<order_id>`. The poller watches that specific address.

- *Onboarding cost:* the merchant must know how to export an xpub from their WDK wallet. The plugin documentation walks through this for the React Native starter wallet and the browser extension starter.
- *Benefit:* unique address per order, no amount-suffix trick, no privacy leakage to a single address.

Strategy A is the default because it fits the bounty's "non-blockchain-specialist merchant" criterion. Strategy B is documented as the production-grade path.

The address derivation logic in Strategy B uses public-only BIP-32 derivation — the xpub never leaves the merchant's machine in private form, and the plugin can derive child *public keys and addresses* but cannot spend.

### 4.4 Confirmation strategy

Confirmation depth is per-chain, configurable, with sensible defaults:

| Chain | Default confirmations | Approx. wall-clock |
|---|---|---|
| Tron | 19 | ~57 seconds |
| Polygon | 64 | ~2 minutes |
| Arbitrum | 1 (L2 sequencer) | ~1 second after batch |
| Ethereum | 12 | ~2.5 minutes |

The Indexer surfaces transfers once they're indexed, but does not directly report a per-transfer confirmation count. The plugin computes confirmations as `(current_chain_head - transfer.blockNumber)`, fetching the chain head from a public RPC endpoint configured per chain (sensible defaults shipped, override available).

If the indexer reports a transfer but the chain head fetch fails, the plugin treats the transfer as `seen_unconfirmed` and continues polling. If both reach the confirmation threshold, the order advances.

### 4.5 Polling cadence and rate limits

The WDK Indexer rate-limits `token-transfers` at 8 requests per 10 seconds per address endpoint. The plugin enforces a per-store polling budget and uses the batch endpoint where possible:

| Stage | Cadence | Duration |
|---|---|---|
| Fast | every 5s | first 2 minutes after order creation |
| Medium | every 15s | minutes 2–10 |
| Slow | every 60s | minutes 10–60 |
| Trickle | every 5 min | hours 1–24 |
| Auto-cancel | — | order moves to `wc-cancelled` at 24h |

When more than one order is pending against the same address (Strategy A), the plugin batches them into a single `POST /api/v1/batch/token-transfers` call, reducing request volume by an order of magnitude at scale.

### 4.6 Refunds

Refunds are **out of scope** for this bounty per the explicit exclusions. The plugin documents a manual refund procedure: the order page surfaces the customer's `from` address from the indexed transfer and includes a "Refund manually" helper that pre-fills a sending-page link for the merchant's WDK wallet (deep link with chain + recipient + amount). The plugin does not itself initiate or sign a refund transaction.

A future enhancement, noted in the README, is a refund flow that uses an xpub-paired private key held outside WordPress (e.g. a separate WDK-powered service). This would not require the plugin to hold keys.

## 5. Demo plan

The 2–5 minute demo video covers, in order:

1. **Install** the plugin into a fresh WooCommerce store (~20 seconds).
2. **Configure** with a Tron Nile testnet address and a WDK Indexer API key (~30 seconds).
3. **Create** a test product and place an order from a customer account (~20 seconds).
4. **Pay** from a WDK-powered wallet on Tron Nile (~45 seconds, including confirmation wait).
5. **Show** order transitioning through `wc-pending` → `wc-on-hold` → `wc-processing` in the WooCommerce admin (~15 seconds).
6. **Repeat** the flow on Sepolia with USDt-ERC-20 to show multi-chain support (~60 seconds).

Total: ~3 minutes 10 seconds. Recorded with OBS, no music, voiceover walking through what's happening on screen.

## 6. Risks and open questions

This section is deliberately honest; flagging the unknowns early is better than discovering them in M2.

| Risk | Mitigation |
|---|---|
| Indexer rate limits at production scale may not match real merchant traffic | The polling budget is conservative and uses batch endpoints. M3 documentation includes guidance on requesting elevated limits from Tether for production merchants. |
| Customer needs native gas (ETH, MATIC, TRX) to send USDt | Out of scope per bounty exclusions. The customer-facing payment page surfaces a helpful note when applicable. Tron's gas-free variant (`@tetherto/wdk-wallet-tron-gasfree`) and TON gasless (`@tetherto/wdk-wallet-ton-gasless`) are listed in the future-work section. |
| Static-address + amount-suffix collisions at very high open-order counts | The collision check at order creation forces unique suffixes. Beyond ~1000 concurrent open orders, Strategy B (xpub-derived addresses) is recommended; the plugin nudges merchants toward it via an admin notice. |
| Solana indexing status uncertain | Solana is excluded from M3 deliverables. Roadmap entry. |
| EIP-681 / BIP-21 URI handling varies by wallet | The QR code displays both the parseable URI and the human-readable address + amount, so any wallet works (parseable URIs are an optimization, not a requirement). |
| Customer abandons payment page before transaction is broadcast | The order auto-cancels at 24h. No funds are at risk because no payment was made. |
| Customer pays the wrong amount | Detected by the poller; order moves to `wc-on-hold` with a manual-review flag and an admin note containing the actual vs expected amount. |
| Customer pays to the wrong chain | Cannot be auto-detected reliably. Documentation emphasizes the chain selection step at checkout. |

## 7. Plan for M2 and M3

### M2 — Core payment flow (40% of grant)

Working checkout integration with WDK transaction tracking on at least one EVM chain and Tron. Concrete deliverables:

- `class WC_Gateway_WDK_USDt extends WC_Payment_Gateway`, implementing `process_payment()`, `get_icon()`, `is_available()`, `payment_fields()`, and `admin_options()`
- Payment URI builder supporting EIP-681 (EVM) and Tron's URI scheme
- Address resolver with both Strategy A and Strategy B
- Action Scheduler–backed poller with the cadence schedule in §4.5
- Order state machine wired into WC's `wc-pending` → `wc-on-hold` → `wc-processing` transitions
- TypeScript SDK published to npm as `@morpheus-csmith/wdk-checkout-core@0.1.0`, with the same logic as the PHP plugin
- A working end-to-end test on Tron Nile and Sepolia, scripted

### M3 — Final delivery (40% of grant)

- Admin settings UI in WooCommerce (currently scaffolded in M2, polished in M3)
- Customer-facing payment page (responsive, mobile-first)
- Documentation site published as GitHub Pages from a `docs/` directory: getting-started, merchant setup walkthrough with screenshots, architecture overview (which is largely this M1 doc, updated), security considerations, troubleshooting
- A `CONTRIBUTING.md` covering the dev environment and how to test against a local WordPress instance
- Demo video uploaded to YouTube (unlisted), linked from the README
- Apache-2.0 license, `SECURITY.md`, conventional commits, GitHub Actions CI running PHPUnit + Vitest

## 8. Standards compliance

The plugin and SDK adhere to:

- **[BIP-32](https://github.com/bitcoin/bips/blob/master/bip-0032.mediawiki)** — Hierarchical deterministic wallets (used for xpub-based address derivation in Strategy B)
- **[BIP-44](https://github.com/bitcoin/bips/blob/master/bip-0044.mediawiki)** — Multi-account hierarchy
- **[EIP-681](https://eips.ethereum.org/EIPS/eip-681)** — URL Format for Transaction Requests (payment URIs for EVM chains)
- **[BIP-21](https://github.com/bitcoin/bips/blob/master/bip-0021.mediawiki)** — URI Scheme (the basis Tron and others mirror)
- **WooCommerce Payment Gateway API** — [official docs](https://woocommerce.com/document/payment-gateway-api/)
- **[WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)** — enforced via PHPCS in CI

## 9. About me

I'm a software engineer / development platform manager. My relevant background for this bounty is in backend web development with PHP, WordPress, and TypeScript. I've spent the last several months studying WDK's source and documentation in preparation for this proposal. I'm submitting solo and have cleared time on my schedule specifically to ship M1 through M3 on the bounty's timeline.
Portfolio: github.com/morpheus-csmith

---

**Repository:** `github.com/morpheus-csmith/wc-wdk-payments`
**License:** Apache-2.0
**Contact:** csmith.pmtech@gmail.com
