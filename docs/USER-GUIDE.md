# User Guide

## Plugin Navigation

Path: `Wbcom > Smart Upsell`

Tabs:

- Dashboard
- Order Bumps
- Upsells
- Funnels
- Analytics
- Settings

## Create an Offer (2-step flow)

## Step 1: Core Offer

1. Select offer type (`checkout`, `cart`, `post_purchase`).
2. Select product.
3. Set discount type (`fixed` or `percentage`) and value.
4. Set title/description.
5. Choose display type.

## Step 2: Rules and Delivery

1. Configure targeting rules (cart/customer/history).
2. Configure schedule (date, weekday, time).
3. Configure skip logic.
4. Save as `active`.

## Checkout Order Bump

Use when you want low-friction add-ons at checkout.

Common setup:

- Display: `checkbox`
- Position: before payment
- Rules: trigger by product/category/cart total

## Cart Bump (Funnels tab)

Use for pre-checkout upsell opportunities.

Display options:

- Inline suggestion
- Popup
- Grid/cross-sell style

## Post-Purchase Upsell

Use on order-received/thank-you flow.

Capabilities:

- One-click upsell attempt via tokenized gateway flow
- Skip action
- Custom thank-you copy

## Advanced Options

- A/B testing (variant B title/description/discount)
- Bundle suggestions (manual/FBT/category/tag)
- Coupon display and optional auto-apply
- Countdown mode (fixed/evergreen)
- Image toggle per offer

## Analytics

Tracks:

- Views
- Conversions
- Conversion rate
- Revenue generated
- Top offers

Use date and offer filters in `Analytics` tab to review performance.

## Shortcodes

- `[wbcom_suo_checkout_bump]`
- `[wbcom_suo_cart_bump]`
- `[wbcom_suo_upsell]`
- `[wbcom_suo_upsell order_id="123"]`
