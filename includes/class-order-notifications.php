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
        $phone = preg_replace('/[^0-9]/', '', $phone);

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
