=== ThriveCart MemberPress Sync ===
Contributors: leonovdesign
Tags: thrivecart, memberpress, integration, webhook, subscription
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically sync ThriveCart subscription cancellations and refunds with MemberPress for accurate access control and statistics.

== Description ==

This plugin was created to address the functional gaps in the native ThriveCart and MemberPress integration, specifically handling subscription cancellations and refunds.

**The Problem:**
When users cancel or receive refunds in ThriveCart, the native integration doesn't properly update MemberPress. This results in:
* Users keeping access indefinitely after cancellation
* Inaccurate statistics and revenue reports
* Manual intervention required for each cancellation/refund

**The Solution:**
This plugin automatically processes ThriveCart webhook events and uses MemberPress native APIs to ensure proper access control and accurate statistics.

= Key Features =

* **Automatic Cancellations** - When users cancel or pause subscriptions in ThriveCart, they keep access until the end of their paid period, then access is automatically removed
* **Automatic Refunds** - When you issue a refund (full or partial), access is terminated immediately and the transaction is marked as refunded in MemberPress
* **Expiration Management** - Automatically sets expiration dates for Manual gateway transactions to prevent lifetime access issues
* **Accurate Statistics** - Uses MemberPress native APIs ensuring all cancellations and refunds appear correctly in reports and analytics
* **User Notifications** - Sends professional email notifications to users for both cancellations and refunds
* **Resume Support** - When users resume subscriptions in ThriveCart, access is automatically restored

= How It Works =

1. User cancels or gets refund in ThriveCart
2. ThriveCart sends webhook to your WordPress site
3. Plugin processes the webhook and updates MemberPress via native APIs
4. Access is controlled automatically (until end of period for cancellations, immediately for refunds)
5. User receives email notification
6. Statistics and reports update automatically

= Supported Events =

* `order.subscription_cancelled` - User cancels or pauses subscription
* `order.rebill_cancelled` - Recurring billing stopped
* `order.refund` - Full or partial refund issued
* `order.refunded` - Refund completed (alternative event)

= Requirements =

* WordPress 6.0 or higher
* PHP 7.4 or higher
* MemberPress plugin (active and configured)
* ThriveCart account with webhook access
* MemberPress REST API key

= Privacy & Data =

This plugin does not track users or collect any data. It only processes webhook events from ThriveCart and updates MemberPress accordingly. All communication is between your WordPress site, ThriveCart, and MemberPress.

== Installation ==

= Automatic Installation =

1. Go to Plugins > Add New in your WordPress admin
2. Search for "ThriveCart MemberPress Sync"
3. Click Install Now
4. Activate the plugin

= Manual Installation =

1. Download the plugin zip file
2. Go to Plugins > Add New > Upload Plugin
3. Choose the zip file and click Install Now
4. Activate the plugin

= Configuration =

1. **Get ThriveCart Secret Word**
   * In ThriveCart: Settings > API & Webhooks
   * Copy the "Secret word" value

2. **Configure Plugin**
   * Go to: MemberPress > ThriveCart Sync > General Settings
   * Paste the ThriveCart Secret Word
   * Add your MemberPress API Key (from MemberPress > Developer > REST API)
   * Save settings

3. **Set Up ThriveCart Webhook**
   * In ThriveCart: Settings > API & Webhooks > Webhooks & notifications
   * Click "+ Add webhook"
   * Name: MemberPress Sync
   * Webhook URL: (copy from plugin settings page)
   * Click "Add webhook"

4. **Map Products to Memberships**
   * Go to: ThriveCart Sync > Product Mappings
   * Enter ThriveCart Product ID for each membership
   * Save mappings

5. **Test the Integration**
   * Make a test purchase in ThriveCart (use Test Mode)
   * Cancel the test subscription
   * Check Recent Logs tab to verify webhook was received
   * Verify subscription was cancelled in MemberPress

== Frequently Asked Questions ==

= What happens when a user cancels their subscription? =

When a user cancels or pauses their subscription in ThriveCart, the plugin automatically sets the expiration date in MemberPress. The user keeps access until the end of their paid period (e.g., if they paid for a month, they have access for that full month). After expiration, access is automatically removed.

= What happens when I issue a refund? =

When you issue a refund (full or partial) in ThriveCart, the plugin immediately marks the transaction as "refunded" in MemberPress and terminates user access. The user receives an email notification, and the refund appears in your MemberPress statistics and reports.

= Does this work with Manual gateway transactions? =

Yes! The plugin specifically handles Manual gateway transactions by automatically setting expiration dates on cancellation. This prevents the common issue where users would have lifetime access after cancelling.

= Can users resume their subscription? =

Yes! When users resume their subscription in ThriveCart and make a payment, a new transaction is automatically created in MemberPress and access is restored.

= Will my statistics be accurate? =

Yes! The plugin uses MemberPress native APIs, ensuring that all cancellations and refunds appear correctly in your reports, statistics, and revenue tracking.

= Does this track my users? =

No. This plugin does not track users or collect any data. It only processes webhook events from ThriveCart and updates MemberPress accordingly.

= Where can I see what's happening? =

Go to ThriveCart Sync > Recent Logs to see all webhook events that have been received and how they were processed.

= What if something goes wrong? =

The plugin includes comprehensive logging. Check the Recent Logs tab to see exactly what happened. If you need help, you can download the logs and include them in your support request.

== Screenshots ==

1. General Settings - Configure ThriveCart Secret and MemberPress API Key
2. Product Mappings - Map ThriveCart products to MemberPress memberships
3. Webhook Status - Check system status and test webhook endpoint
4. Recent Logs - View all webhook events and how they were processed
5. Setup Manual - Complete setup instructions

== Changelog ==

= 2.2.2 (2025-11-02) =
* Fixed: Lifetime access bug for cancelled subscriptions
* Added: Automatic expiration setting on cancellation for Manual gateway transactions
* Added: Uses ThriveCart billing_period_end when available
* Added: Fallback expiration calculation
* Improved: Comprehensive logging for troubleshooting

= 2.2.1 (2025-10-30) =
* Fixed: Manual gateway cancellation logging clarity
* Updated: Simplified plugin name and description
* Improved: Admin interface with emoji icons
* Improved: Overall UI polish

= 2.2.0 (2025-10-28) =
* Added: Cancellations via MemberPress native API
* Added: Complete statistics tracking for cancellations
* Added: User email notifications for cancellations
* Improved: Pure integration layer architecture

= 2.1.5 (2025-10-22) =
* Added: Full refund processing via MemberPress API
* Added: Partial refund support
* Added: Immediate access termination on refund
* Added: User email notifications for refunds

= 2.0.0 =
* Initial release
* Basic cancellation handling

== Upgrade Notice ==

= 2.2.2 =
Critical update: Fixes lifetime access bug for cancelled subscriptions. Update immediately to prevent users from having indefinite access after cancellation.

= 2.2.0 =
Major update: Full MemberPress API integration for both refunds and cancellations. All statistics now tracked accurately.

== Support ==

For support, please visit:
* Plugin support forum on WordPress.org
* Documentation: Available in the Setup Manual tab within the plugin

== Third-Party Services ==

This plugin integrates with the following third-party services:

**ThriveCart**
* Purpose: Receives webhook notifications for subscription events
* Service URL: https://thrivecart.com/
* Privacy Policy: https://thrivecart.com/privacy/
* Terms of Use: https://thrivecart.com/terms-of-service/

**MemberPress**
* Purpose: Updates subscription and transaction data via REST API
* Service URL: https://memberpress.com/
* Privacy Policy: https://memberpress.com/privacy-policy/
* Note: MemberPress is a required plugin that must be installed on your WordPress site

By using this plugin, you acknowledge that:
* ThriveCart webhook events will be sent to your WordPress site
* This plugin will communicate with your MemberPress installation via REST API
* No data is sent to any external servers except those mentioned above
* All webhook events are logged locally on your WordPress installation

== Developer Notes ==

**Hooks & Filters**

Currently, this plugin does not provide custom hooks or filters. All functionality is self-contained and uses native MemberPress APIs.

**Source Code**

Development happens on GitHub. Source code and build tools are available at:
https://github.com/hottabov/thrivecart-memberpress-sync/

**Contributing**

Contributions are welcome! Please submit pull requests or issues on GitHub.

== License ==

This plugin is licensed under GPLv2 or later.
