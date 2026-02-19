# Wbcom Smart Upsell & Order Bump - MVP Gap Checklist

Reference scope: Version 1.0 (MVP)

## A. Checkout Order Bumps
- Done: Select bump product.
- Done: Fixed/percentage discount.
- Done: Custom title/description.
- Done: Show image toggle (admin checkbox + frontend rendering for checkout/cart/upsell/exit-intent).
- Done: Display types (checkbox/highlight).
- Done: Display styles `inline/popup/grid` now render behavior-specific checkout layouts (including popup trigger/modal flow).
- Done: Position before payment / after summary.
- Done: Rules by cart product/category/total/user role.

## B. Post-Purchase One-Click Upsell
- Done: Offer after checkout success.
- Done: One-click add to order.
- Done: Auto-charge flow for tokenized gateways (Stripe path validated).
- Done: Skip button.
- Done: Custom thank-you message.
- Done: Rules by purchased product/category/order value.

## C. Cart Page Bumps
- Done: Cart bump appears on cart page.
- Done: Inline suggestion implemented.
- Done: Popup suggestion behavior.
- Done: Cross-sell style grid layout behavior.

## D. Smart Targeting Engine
- Done: Product in cart.
- Done: Category in cart.
- Done: Cart total range.
- Done: Quantity threshold.
- Done: User role.
- Done: Specific customer email.
- Done: Lifetime spend threshold.
- Done: First-time buyer.
- Done: Returning customer.
- Done: Purchased product before.
- Done: Purchased category before.

## E. Offer Scheduling
- Done: Start date / end date.
- Done: Weekday scheduling.
- Done: Time-based scheduling (HH:MM start/end).
- Done: Expiry behavior via schedule checks.

## F. Smart Skip Logic
- Done: Skip if product already in cart.
- Done: Skip if product purchased before.
- Done: Dismiss-limit logic implemented for post-purchase skip + checkout/cart dismiss UI actions.

## G. Lightweight Reporting
- Done: Offer views / conversions / conversion rate / revenue / top offers.
- Done: Date/offer/product filters added on analytics screen.

## H. Performance-First Architecture
- Done: Front assets loaded only on cart/checkout/order-received.
- Done: Custom DB tables for offers + analytics.
- Done: Modular loading.
- Done: Added benchmark helper script for asset-size and targeting-eval budget checks.

## I. Template System (Light)
- Done: Minimal / modern / highlight / banner style classes + admin default selector.
- Done: Live preview in admin settings.

## J. Security & Data Handling
- Done: Nonce checks.
- Done: Capability checks on admin actions.
- Done: Input sanitization and output escaping in core paths.
- Done: Upsell accept/skip order ownership validation.

## Priority Remaining MVP Work
1. Add chart widgets in analytics screen.
2. Add deeper automated E2E edge-case suite in CI.
3. Package release checklist + versioned changelog.
