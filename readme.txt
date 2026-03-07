=== sender - Order Notifications & Messaging ===
Contributors: sender
Tags: messaging, chat, woocommerce, otp, notifications
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WooCommerce with sender to send automated order notifications and verify customer phone numbers via OTP.

== Description ==

sender for WooCommerce is a powerful plugin that integrates your store with the sender platform, enabling you to send instant messaging notifications to your customers. 

With sender, you can automatically notify customers about their order status updates, verify phone numbers using OTP during checkout or registration, and send custom chat messages directly from your WordPress dashboard.

**Features:**
* **Automated Order Notifications:** Send direct messages automatically when an order status changes (e.g., Processing, Completed, Cancelled).
* **Dynamic Status Support:** Fully supports all custom WooCommerce order statuses.
* **OTP Verification:** Verify customer phone numbers during WooCommerce checkout (Supports both Classic & Blocks Checkout) or WordPress registration to ensure genuine customers.
* **Custom Messages:** Send manual, custom messages to any number from the admin panel.
* **Easy Integration:** Connect easily via your sender account credentials or API key.

**Note:** This plugin requires an active account on [sender](https://www.wpsenderx.com) to function and send messages.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/sender-notification` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **sender** in the admin menu.
4. Log in using your sender account credentials or enter your API key manually.
5. Configure your notification and OTP settings.

== Frequently Asked Questions ==

= Do I need a sender account? =
Yes, you need an active account and sufficient balance/quota on the sender platform to send messages.

= Does this support WooCommerce Blocks checkout? =
Yes! The OTP verification feature is fully compatible with the modern WooCommerce Blocks checkout out-of-the-box, as well as the classic shortcode checkout.

== Screenshots ==

1. The main settings dashboard showing connection status.
2. Configuring automated notifications for different order statuses.
3. Setting up OTP verification for checkout and registration.

== Changelog ==

= 1.0.1 =
* Fixed issue with newline removal in notification messages and custom messages.
* Added support for sending product images as media attachments with order notifications.
* Formatted `{order_items}` to display as a line-by-line list.

= 1.0.0 =
* Initial release of sender - Order Notifications & Messaging.
