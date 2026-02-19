# Installation Guide

## Requirements

- WordPress 6.x+
- WooCommerce 6.x+
- PHP 8.0+

## Install (Admin UI)

1. Go to `Plugins > Add New > Upload Plugin`.
2. Upload the plugin zip.
3. Activate the plugin.
4. Make sure WooCommerce is active.

## Install (Manual)

1. Copy plugin folder to:
   `wp-content/plugins/wbcom-smart-upsell-order-bump`
2. Activate from `Plugins` screen.

## First-Time Setup

1. Open `Wbcom > Smart Upsell`.
2. Go to `Settings` and keep analytics enabled (recommended).
3. Create your first offer from:
   - `Order Bumps` (checkout)
   - `Funnels` (cart)
   - `Upsells` (post-purchase)

## Recommended Defaults

- Start with one checkout bump and one post-purchase upsell.
- Use `display_type=checkbox` for minimal checkout friction.
- Set clear targeting rules before enabling many offers.
- Enable schedule windows for campaign-based offers.

## Troubleshooting

- If menu is missing: ensure WooCommerce is active.
- If offers are not showing: check offer status is `active` and rules match cart/order.
- If one-click upsell payment fails: confirm gateway tokenization support.
