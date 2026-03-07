<?php
/**
 * sender Custom Message
 * Send custom messages from the admin panel
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_Custom_Message {

    private $api;

    public function __construct() {
        $this->api = new HokTech_API_Client();
        add_action('wp_ajax_hoktech_send_custom_message', [$this, 'ajax_send_message']);
    }

    /**
     * AJAX: Send custom message
     */
    public function ajax_send_message() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $phone   = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

        if (empty($phone) || empty($message)) {
            wp_send_json_error(['message' => __('رقم الهاتف والرسالة مطلوبان', 'sender-notification')]);
        }

        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);

        $result = $this->api->send_message($phone, $message);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}
