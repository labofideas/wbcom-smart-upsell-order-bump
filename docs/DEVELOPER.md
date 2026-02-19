# Developer Guide

## Architecture

Core modules:

- `includes/modules/order-bump/class-order-bump-engine.php`
- `includes/modules/upsell/class-post-purchase-upsell-engine.php`
- `includes/modules/targeting/class-smart-targeting-engine.php`
- `includes/modules/display/class-offer-display-manager.php`
- `includes/modules/analytics/class-analytics-module.php`
- `includes/modules/analytics/class-analytics-repository.php`
- `includes/modules/offers/class-offer-repository.php`
- `includes/modules/performance/class-performance-control.php`
- `includes/modules/store-api/class-store-api-support.php`
- `includes/modules/payments/class-one-click-payment-manager.php`

## Data Storage

Custom tables:

- `{$wpdb->prefix}wbcom_suo_offers`
- `{$wpdb->prefix}wbcom_suo_analytics`

Legacy option migration is handled automatically by `OfferRepository::maybe_migrate_legacy_settings()`.

## Security Model

- Capability checks in admin actions.
- Nonce verification for form and action handlers.
- Input sanitization (`sanitize_text_field`, `sanitize_key`, `absint`, etc.).
- Escaped output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).

## Performance Model

- Frontend assets load only on cart/checkout/thank-you contexts.
- No global frontend CSS enqueue.
- Repository-level object-cache usage for read-heavy queries.
- Optional analytics/reporting disable via settings.

## Store API Support

Namespace: `wbcom/suo`

- Adds checkout bump payload to Store API responses.
- Accepts checkout bump extension flag.
- Persists acceptance to order meta.

## One-click Payment Flow

- Resolves parent order gateway.
- Validates tokenization support.
- Resolves reusable token from order/customer context.
- Attempts gateway-specific `process_payment()` with adapter payload.

## Extension Hooks

- `wbcom_suo_gateway_post_payload`
- `wbcom_suo_before_gateway_process_payment`

Use these to support custom gateway payload shapes.

## QA Commands

Plugin check:

```bash
wp plugin check wbcom-smart-upsell-order-bump/wbcom-smart-upsell-order-bump.php
```

Performance benchmark:

```bash
wp eval-file wp-content/plugins/wbcom-smart-upsell-order-bump/scripts/perf-benchmark.php
```
