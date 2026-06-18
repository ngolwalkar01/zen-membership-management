# Zen Membership Management

Customer-facing membership management plugin for Zenctuary.

## What it does

- Adds a `My Membership` WooCommerce My Account endpoint.
- Replaces the default Memberships list menu item with a direct single-membership detail page.
- Renders the current customer membership with linked subscription billing data when available.
- Shows status, start date, next payment date, cancellation deadline, included Zencoins, payment method, subscription totals, and related orders.
- Blocks customers from adding/purchasing multiple membership-granting products when they already have a current membership or already have a membership product in the cart.

## Dependencies

- WooCommerce
- WooCommerce Memberships
- WooCommerce Subscriptions for billing rows/actions
- Coin Booking Bridge for the `_cbb_coin_grant_amount` product/variation meta

## Notes

This plugin intentionally does not edit WooCommerce Memberships or WooCommerce Subscriptions templates directly.

The monthly cancellation deadline is currently displayed as 7 days before the next payment date. The policy behavior for after-deadline cancellations should be added as a second phase so the billing lifecycle can be tested independently.
