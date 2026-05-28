# ThriveCart MemberPress Sync

WordPress plugin that addresses functional gaps in the native ThriveCart ↔ MemberPress integration. Ensures recurring subscriptions give **time-limited access** (not lifetime), handles all payment lifecycle events, and provides a safety-net cron job to expire overdue subscriptions automatically.

## What this plugin does

The native ThriveCart → MemberPress integration creates transactions on purchase but does **not** set an `expires_at` date. Without an expiry, MemberPress treats access as lifetime — even if the customer stops paying. This plugin fixes that.

### Subscription lifecycle handled

| ThriveCart event | Action |
|---|---|
| `order.success` | Set `expires_at` on the MP transaction (payment date + billing period + 2h grace) |
| `order.subscription_payment` | Extend `expires_at` if the new date is later than the current one |
| `order.subscription_payment_failed` | Log only. No access change. Existing expiry clock continues. |
| `order.subscription_overdue` | Log only. No access change. Cron handles expiry. |
| `order.subscription_cancelled` | Cancel MP subscription via native API. Access until end of paid period. |
| `order.rebill_cancelled` | Same as above. |
| `order.refund` | Refund via MP native API. Access terminated immediately. |
| `order.refunded` | Same as above. |

### Cron safety net

A twice-daily WP Cron job scans active MP subscriptions. If the most recent complete transaction has a definite past `expires_at`, the subscription is cancelled automatically. Subscriptions with no expiry (lifetime) are never touched.

### Idempotency

Every event is deduplicated using a 7-day transient keyed on `event | order_id | subscription_id | payment_id`. ThriveCart retries are safe.

### Race condition handling

If the native TC→MP integration hasn't created the transaction yet when the webhook fires, the plugin schedules a retry 120 seconds later via WP Cron.

## Setup

### 1. Plugin settings

Go to **MemberPress → ThriveCart Sync → General Settings**:

- **ThriveCart Secret Word** — copy from ThriveCart: Settings → API & Webhooks → ThriveCart order validation → Secret word
- **MemberPress API Key** — copy from MemberPress → Developer → REST API
- **Admin Notification Email** — optional, receive email on cancellations/refunds

### 2. ThriveCart webhook

In ThriveCart: Settings → API & Webhooks → Webhooks & notifications → Add webhook

- **URL:** `https://yoursite.com/wp-json/ae/v1/thrivecart-hook`
- **Receive results as JSON:** leave unchecked (form-encoded is default and supported)

The plugin also accepts JSON if ThriveCart is configured to send it.

### 3. Product mappings

Go to **MemberPress → ThriveCart Sync → Product Mappings**.

Enter the ThriveCart Product ID for each MemberPress membership. Find the TC Product ID from the product URL in ThriveCart (e.g. `thrivecart.com/product/2` → ID is `2`).

## Verification flags

Several ThriveCart webhook payload field names cannot be confirmed without live webhook data. These are marked `@TC_PAYLOAD_VERIFY` in the code. After receiving live webhooks, check the Recent Logs tab and verify:

- Payment timestamp fields (`rebill.date`, `rebill.created`, `payment_date`, `order_date`, `created`, `date`)
- Payment/order ID fields used for idempotency (`order_id`, `id`, `rebill.payment_id`, `rebill.id`)
- Refund amount field (`refund.amount`) — assumed to be in cents, may be dollars
- MP API subscription response fields used by cron (`member_id`, `membership_id`)

## Changelog

### v3.0.0 (2026-05-28)
- **Fix (critical):** Recurring subscriptions now get time-limited access, not lifetime
- **Fix (critical):** `$post` undefined variable bug in cancellation handler
- **Add:** Handlers for `order.success` and `order.subscription_payment`
- **Add:** Handlers for `order.subscription_payment_failed` and `order.subscription_overdue`
- **Add:** Idempotency layer (7-day WP transients)
- **Add:** Twice-daily WP Cron safety net to expire overdue subscriptions
- **Add:** Payment retry cron (handles TC→MP race condition)
- **Fix:** Webhook body parsing supports JSON and form-encoded
- **Fix:** Secret never logged on authentication failure
- **Fix:** Raw POST data no longer logged (privacy/security)
- **Fix:** `sanitize_mappings()` preserves all fields (`tc_product_ids`, `active`, `payment_type`, `label`)
- **Fix:** Mapping save handler merges instead of overwriting existing fields
- **Fix:** `current_time('timestamp')` replaced with `time()` for UTC correctness

### v2.2.2 (2025-11-02)
- Fix: Lifetime access bug for cancelled subscriptions
- Add: Automatic expiration setting on cancellation

### v2.2.0 (2025-10-28)
- Add: Cancellations via MemberPress native API
- Add: Complete statistics tracking

### v2.1.5 (2025-10-22)
- Add: Full refund processing via MemberPress API
- Add: Partial refund support
