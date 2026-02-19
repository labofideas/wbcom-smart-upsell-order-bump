# FAQ

## Does this work with WooCommerce block checkout?

Yes. The plugin includes Store API integration for checkout bump data and acceptance handling.

## Can I use this without post-purchase upsells?

Yes. You can run checkout and cart bumps only.

## Do I need shortcodes?

Not required. They are optional for custom layouts/page builders.

## Which payment gateways support one-click upsell charging?

The plugin includes adapters for common tokenized gateway families (Stripe, WooPayments, PPCP) plus a generic fallback for tokenized gateways.

## Why is my offer not showing?

Most common reasons:

- Offer status is not `active`
- Rule conditions do not match current cart/customer
- Schedule window is not active
- Skip logic blocked the offer

## Can I disable analytics?

Yes. Use `Settings` to disable analytics/reporting behavior.

## Is Gutenberg support required?

Not mandatory for core features. The plugin already supports block-based checkout through Store API integration.
