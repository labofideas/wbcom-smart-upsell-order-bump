=== Wbcom Smart Upsell & Order Bump ===
Contributors: wbcomdesigns
Tags: woocommerce, upsell, order bump, checkout, aov
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Performance-first WooCommerce upsell plugin.

## Documentation

- [Installation Guide](docs/INSTALLATION.md)
- [User Guide](docs/USER-GUIDE.md)
- [Developer Guide](docs/DEVELOPER.md)
- [FAQ](docs/FAQ.md)
- [Changelog](docs/CHANGELOG.md)

## Upgraded architecture

- Offer CRUD now uses custom table: `{$wpdb->prefix}wbcom_suo_offers`
- Analytics tracking uses custom table: `{$wpdb->prefix}wbcom_suo_analytics`
- Legacy single-offer settings are auto-migrated into offers table on init if needed.

## Modules

- `OrderBumpEngine` (checkout + cart offer rendering and apply)
- `PostPurchaseUpsellEngine` (thank-you upsells)
- `SmartTargetingEngine` (cart/role/schedule/skip logic)
- `StoreApiSupport` (block checkout/cart endpoint extension)
- `OneClickPaymentManager` (token-based one-click charge integration hook)
- `AnalyticsModule` + dashboard summaries
- `PerformanceControl` (frontend assets only on cart/checkout/thank-you)

## Pro Features (implemented)

- A/B testing per offer (variant split, variant B copy/discount overrides)
- Auto-winner mode based on persisted variant stats and minimum view thresholds
- Smart bundles (manual IDs, frequently bought together, same category/tag match modes)
- Abandoned-cart bump via exit-intent modal trigger
- Coupon-based offers (display coupon + optional auto-apply)
- Countdown timers (fixed campaign or evergreen per visitor/order)
- Offer image toggle (per-offer product image render across checkout/cart/upsell)
- Behavior-specific display modes for checkout/cart (`checkbox`, `highlight`, `inline`, `popup`, `grid`)
- Advanced segmentation rules
  - Country-based offers
  - Device-based offers (mobile/desktop)
  - Purchase frequency threshold

## Admin UX

`Wbcom -> Smart Upsell` tabs:

- Dashboard
- Order Bumps (CRUD)
- Upsells (CRUD)
- Funnels (cart bumps CRUD)
- Analytics
- Settings

## Block checkout compatibility

The plugin registers Store API extension namespace `wbcom/suo` on cart and checkout endpoints.

- Exposes active checkout bump payload (`checkout_offer`)
- Accepts `accept_checkout_bump` extension flag and persists to order meta
- Order bump add-to-order flow then works for Store API checkout requests

## Shortcodes (optional fallback)

Use these when you want manual placement in custom templates/page builders:

- `[wbcom_suo_checkout_bump]` renders active checkout bump on checkout pages.
- `[wbcom_suo_cart_bump]` renders active cart bump on cart pages.
- `[wbcom_suo_upsell]` renders post-purchase upsell on order-received context.
- `[wbcom_suo_upsell order_id="123"]` renders upsell for a specific order ID.

## Benchmark Script

Run a lightweight benchmark and guardrail check:

```bash
wp eval-file wp-content/plugins/wbcom-smart-upsell-order-bump/scripts/perf-benchmark.php
```

## One-click auto-charge integration

Built-in processing now includes:

- Detects parent order gateway + tokenization support
- Resolves token id from order/customer tokens
- Attempts charge via gateway `process_payment()` using dedicated adapter payloads
  - Stripe family: `stripe`, `stripe_cc`, `wc_stripe`
  - WooPayments family: `woocommerce_payments`, `wcpay`
  - Woo PayPal Payments family: `ppcp-gateway`, `ppcp-credit-card-gateway`, `ppcp-axo-gateway`
  - Generic tokenized fallback for other gateways
- Exposes extension hooks for custom adapters where needed

## Notes

Gateway-specific one-click charging may need additional merchant-side gateway configuration.
