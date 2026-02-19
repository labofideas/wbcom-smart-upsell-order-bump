# Changelog

## 1.0.1

- Improved admin UI layout for Order Bumps, Upsells, Funnels, and Settings with better spacing and hierarchy.
- Fixed admin responsive behavior to prevent clipped/cut content on narrower desktop widths and zoomed views.
- Added stronger overflow safety and grid fallbacks for offer/settings screens.
- Updated plugin/readme version metadata to `1.0.1` for asset cache busting and release consistency.
- Hardened analytics SQL handling and filtering with prepared-query patterns and stricter sanitization.
- Improved offer repository and uninstall table handling with safer SQL construction and naming convention compliance.
- Re-validated plugin quality checks and admin smoke tests prior to packaging.

## 1.0.0

- Initial public release of Wbcom Smart Upsell & Order Bump.
- Added checkout order bumps with rules and templates.
- Added cart page bumps (inline/popup/grid).
- Added post-purchase one-click upsell flow.
- Added smart targeting and scheduling engine.
- Added skip logic and lightweight analytics dashboard.
- Added Store API compatibility for block checkout.
- Added A/B testing, coupon options, countdown options, and smart bundles.
- Added performance guardrails and benchmark helper script.
- Added shortcodes for manual placement.
- Hardened security/sanitization/escaping and passed plugin checks.
