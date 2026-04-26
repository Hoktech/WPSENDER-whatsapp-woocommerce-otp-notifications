<?php
/**
 * sender Order Notifications
 * Sends direct messaging notifications on WooCommerce order status changes
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_Order_Notifications {

    private $api;

    public function __construct() {
        $this->api = new HokTech_API_Client();
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 10, 4);
    }

    /**
     * Handle order status change
     */
    public function on_status_changed($order_id, $old_status, $new_status, $order) {
        // Ignore sub-orders (multi-vendor support) to only send for the main order
        if ($order->get_parent_id() > 0) {
            return;
        }

        // Prevent duplicate firing for the exact same status transition
        if ($old_status === $new_status) {
            return;
        }

        // Prevent duplicate sending for the same status on this order
        $meta_key = '_hoktech_wa_sent_status_' . $new_status;
        if (get_post_meta($order_id, $meta_key, true)) {
            return;
        }

        // Check if connected
        if (!$this->api->is_connected()) {
            return;
        }

        // Get notification settings
        $settings = get_option('hoktech_wa_notification_settings', []);
        $status_setting = $settings[$new_status] ?? null;

        // Check if notification is enabled for this status
        if (!$status_setting || empty($status_setting['enabled']) || empty($status_setting['message'])) {
            return;
        }

        // Get billing phone
        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            $this->log_notification($order_id, $new_status, '', 'failed', __('رقم الهاتف غير موجود في الطلب', 'sender-notification'));
            return;
        }

        // Clean phone number - remove spaces, dashes, plus sign
        $phone = preg_replace('/[^0-9+]/', '', $phone);

        // Format phone with country code if country selector is enabled
        $otp_settings = get_option('hoktech_wa_otp_settings', []);
        if (!empty($otp_settings['enable_country_selector']) && function_exists('hoktech_wc_country_to_dial')) {
            $billing_country = $order->get_billing_country();
            if (!empty($billing_country)) {
                $dial_code = hoktech_wc_country_to_dial($billing_country);
                $dial_digits = preg_replace('/[^0-9]/', '', $dial_code);
                $phone_digits = preg_replace('/[^0-9]/', '', $phone);

                // If phone doesn't already start with the dial code, format it
                if (!empty($dial_digits) && strpos($phone_digits, $dial_digits) !== 0) {
                    // Strip leading zero from local number
                    $phone_digits = ltrim($phone_digits, '0');
                    $phone = $dial_digits . $phone_digits;
                } else {
                    $phone = $phone_digits;
                }
            } else {
                $phone = preg_replace('/[^0-9]/', '', $phone);
            }
        } else {
            $phone = preg_replace('/[^0-9]/', '', $phone);
        }

        // Build message from template
        $message = $this->parse_template($status_setting['message'], $order);

        // Prepare media if enabled
        $media = null;
        if (!empty($status_setting['send_image'])) {
            $media = $this->get_order_first_product_image($order);
        }

        // Send via API
        $result = $this->api->send_message($phone, $message, null, $media);

        // Log the notification
        $this->log_notification(
            $order_id,
            $new_status,
            $phone,
            $result['success'] ? 'sent' : 'failed',
            $result['message'] ?? ''
        );

        // Add order note
        if ($result['success']) {
            // Mark as sent to prevent duplicates
            update_post_meta($order_id, $meta_key, true);

            $order->add_order_note(
                sprintf(
                    /* translators: 1: Phone number, 2: Order status */
                    __('✅ تم إرسال إشعار للعميل (%1$s) - حالة: %2$s', 'sender-notification'),
                    $phone,
                    $new_status
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: Error message */
                    __('❌ فشل إرسال الإشعار: %s', 'sender-notification'),
                    $result['message'] ?? __('خطأ غير معروف', 'sender-notification')
                )
            );
        }

        // Send admin notifications
        $this->send_admin_notifications($order, $new_status);
    }

    /**
     * Send notifications to admin phone numbers
     */
    private function send_admin_notifications($order, $new_status) {
        $admin_settings = get_option('hoktech_wa_admin_notifications', []);

        // Check if admin notifications are enabled
        if (empty($admin_settings['enabled'])) {
            return;
        }

        // Check if this status is enabled for admin notifications
        $enabled_statuses = $admin_settings['statuses'] ?? [];
        if (!in_array($new_status, $enabled_statuses, true)) {
            return;
        }

        // Get admin phone numbers
        $admin_phones_raw = $admin_settings['phones'] ?? '';
        if (empty($admin_phones_raw)) {
            return;
        }

        // Parse phone numbers (separated by newlines or commas)
        $admin_phones = preg_split('/[\n,]+/', $admin_phones_raw);
        $admin_phones = array_map('trim', $admin_phones);
        $admin_phones = array_filter($admin_phones);

        if (empty($admin_phones)) {
            return;
        }

        // Get admin message template
        $admin_message_template = $admin_settings['message'] ?? '';
        if (empty($admin_message_template)) {
            $admin_message_template = '🔔 طلب جديد #{order_id}' . "\n" .
                'العميل: {customer_name}' . "\n" .
                'المبلغ: {order_total}' . "\n" .
                'الحالة: {order_status}' . "\n" .
                '{site_name}';
        }

        // Parse the template
        $admin_message = $this->parse_template($admin_message_template, $order);

        // Send to each admin phone
        $sent_count = 0;
        $fail_count = 0;

        foreach ($admin_phones as $admin_phone) {
            $clean_phone = preg_replace('/[^0-9]/', '', $admin_phone);
            if (empty($clean_phone)) {
                continue;
            }

            $result = $this->api->send_message($clean_phone, $admin_message);

            if ($result['success']) {
                $sent_count++;
            } else {
                $fail_count++;
            }
        }

        // Add order note about admin notifications
        if ($sent_count > 0) {
            $order->add_order_note(
                sprintf(
                    __('👥 تم إرسال إشعار الإدارة إلى %d رقم بنجاح', 'sender-notification'),
                    $sent_count
                )
            );
        }
        if ($fail_count > 0) {
            $order->add_order_note(
                sprintf(
                    __('⚠️ فشل إرسال إشعار الإدارة إلى %d رقم', 'sender-notification'),
                    $fail_count
                )
            );
        }
    }

    /**
     * Get the first product image from the order as base64 array
     */
    private function get_order_first_product_image($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $image_id = $product->get_image_id();
                if ($image_id) {
                    $image_path = get_attached_file($image_id);
                    if ($image_path && file_exists($image_path)) {
                        $mime_type = wp_check_filetype($image_path)['type'];
                        if (!$mime_type) {
                            $mime_type = 'image/jpeg';
                        }
                        
                        $data = file_get_contents($image_path);
                        if ($data !== false) {
                            return [
                                'mimetype' => $mime_type,
                                'data'     => base64_encode($data),
                                'filename' => basename($image_path)
                            ];
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Replace template placeholders with order data
     */
    private function parse_template($template, $order) {
        // Get order items as text
        $items_text = [];
        foreach ($order->get_items() as $item) {
            $items_text[] = '- ' . $item->get_name() . ' x' . $item->get_quantity();
        }

        // Clean order total from WooCommerce HTML spans and decode currency symbols
        $clean_total = html_entity_decode(wp_strip_all_tags($order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8');

        $replacements = [
            '{order_id}'       => $order->get_order_number(),
            '{customer_name}'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '{order_total}'    => $clean_total,
            '{order_status}'   => wc_get_order_status_name($order->get_status()),
            '{site_name}'      => get_bloginfo('name'),
            '{order_items}'    => implode("\n", $items_text),
            '{billing_phone}'  => $order->get_billing_phone(),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Log notification attempt
     */
    private function log_notification($order_id, $status, $phone, $result, $message = '') {
        $log = get_option('hoktech_wa_notification_log', []);

        // Keep last 100 entries
        if (count($log) >= 100) {
            $log = array_slice($log, -99);
        }

        $log[] = [
            'order_id'  => $order_id,
            'status'    => $status,
            'phone'     => $phone,
            'result'    => $result,
            'message'   => $message,
            'timestamp' => current_time('mysql'),
        ];

        update_option('hoktech_wa_notification_log', $log);
    }
}
