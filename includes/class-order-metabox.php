<?php
/**
 * sender Order Meta Box
 * Adds a WhatsApp messaging panel inside the WooCommerce order edit page
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_Order_MetaBox {

    private $api;

    public function __construct() {
        $this->api = new HokTech_API_Client();

        // Add meta box for both classic and HPOS order screens
        add_action('add_meta_boxes', [$this, 'add_order_metabox']);

        // AJAX handler for sending message from meta box
        add_action('wp_ajax_hoktech_send_order_message', [$this, 'ajax_send_order_message']);

        // Enqueue scripts on order edit pages
        add_action('admin_enqueue_scripts', [$this, 'enqueue_order_scripts']);
    }

    /**
     * Add meta box to order edit page
     */
    public function add_order_metabox() {
        // Support both classic post type and HPOS screen
        $screen = $this->get_order_screen();

        add_meta_box(
            'hoktech_wa_order_message',
            __('📱 إرسال رسالة واتساب', 'sender-notification'),
            [$this, 'render_metabox'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Get the correct screen ID for orders (HPOS compatible)
     */
    private function get_order_screen() {
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return 'woocommerce_page_wc-orders';
        }
        return 'shop_order';
    }

    /**
     * Enqueue scripts on order edit pages
     */
    public function enqueue_order_scripts($hook) {
        // Check if we're on an order edit page
        $screen = get_current_screen();
        if (!$screen) return;

        $is_order_page = false;

        // Classic editor
        if ($screen->id === 'shop_order') {
            $is_order_page = true;
        }

        // HPOS editor
        if ($screen->id === 'woocommerce_page_wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit') {
            $is_order_page = true;
        }

        if (!$is_order_page) return;

        wp_enqueue_style('hoktech-wa-metabox', HOKTECH_WA_PLUGIN_URL . 'assets/css/admin-style.css', [], HOKTECH_WA_VERSION);
        wp_enqueue_script('hoktech-wa-metabox', HOKTECH_WA_PLUGIN_URL . 'assets/js/admin-script.js', ['jquery'], HOKTECH_WA_VERSION, true);
        wp_localize_script('hoktech-wa-metabox', 'hoktechWA', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hoktech_wa_nonce'),
            'strings' => [
                'connecting'         => __('جاري الاتصال...', 'sender-notification'),
                'connected'          => __('متصل', 'sender-notification'),
                'disconnected'       => __('غير متصل', 'sender-notification'),
                'sending'            => __('جاري الإرسال...', 'sender-notification'),
                'sent'               => __('تم الإرسال بنجاح', 'sender-notification'),
                'error'              => __('حدث خطأ', 'sender-notification'),
                'confirm_disconnect' => __('هل أنت متأكد من قطع الاتصال؟', 'sender-notification'),
            ]
        ]);
    }

    /**
     * Render the meta box content
     */
    public function render_metabox($post_or_order) {
        // Get the order object (HPOS compatible)
        if ($post_or_order instanceof WC_Order) {
            $order = $post_or_order;
        } else {
            $order = wc_get_order($post_or_order->ID);
        }

        if (!$order) {
            echo '<p>' . esc_html__('لا يمكن تحميل بيانات الطلب', 'sender-notification') . '</p>';
            return;
        }

        $phone = $order->get_billing_phone();
        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $order_id = $order->get_id();
        $is_connected = $this->api->is_connected();
        ?>
        <div class="hoktech-metabox-wrap" dir="rtl">
            <?php if (!$is_connected): ?>
                <div class="hoktech-metabox-notice warning">
                    <span>⚠️</span>
                    <?php esc_html_e('غير متصل بالمنصة. قم بالاتصال أولاً من إعدادات الإضافة.', 'sender-notification'); ?>
                </div>
            <?php else: ?>
                <?php if (empty($phone)): ?>
                    <div class="hoktech-metabox-notice warning">
                        <span>⚠️</span>
                        <?php esc_html_e('لا يوجد رقم هاتف لهذا العميل', 'sender-notification'); ?>
                    </div>
                <?php else: ?>
                    <div class="hoktech-metabox-customer">
                        <div class="hoktech-metabox-phone">
                            <span class="hoktech-metabox-phone-icon">📞</span>
                            <span class="hoktech-metabox-phone-number" dir="ltr"><?php echo esc_html($phone); ?></span>
                        </div>
                        <div class="hoktech-metabox-name"><?php echo esc_html($customer_name); ?></div>
                    </div>

                    <div class="hoktech-metabox-form">
                        <textarea
                            id="hoktech-order-message"
                            class="hoktech-metabox-textarea"
                            rows="4"
                            placeholder="<?php esc_attr_e('اكتب رسالتك هنا...', 'sender-notification'); ?>"
                        ></textarea>

                        <div class="hoktech-metabox-vars">
                            <small><?php esc_html_e('المتغيرات:', 'sender-notification'); ?></small>
                            <div class="hoktech-metabox-var-tags">
                                <code class="hoktech-var-tag" data-var="{order_id}">{order_id}</code>
                                <code class="hoktech-var-tag" data-var="{customer_name}">{customer_name}</code>
                                <code class="hoktech-var-tag" data-var="{order_total}">{order_total}</code>
                                <code class="hoktech-var-tag" data-var="{order_status}">{order_status}</code>
                                <code class="hoktech-var-tag" data-var="{site_name}">{site_name}</code>
                            </div>
                        </div>

                        <input type="hidden" id="hoktech-order-phone" value="<?php echo esc_attr($phone); ?>">
                        <input type="hidden" id="hoktech-order-id" value="<?php echo esc_attr($order_id); ?>">

                        <button type="button"
                                id="hoktech-send-order-msg"
                                class="button button-primary hoktech-metabox-send-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 6px; vertical-align: middle;">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                            <?php esc_html_e('إرسال الرسالة', 'sender-notification'); ?>
                        </button>

                        <div id="hoktech-order-msg-result" class="hoktech-metabox-result" style="display:none;"></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Send message from order meta box
     */
    public function ajax_send_order_message() {
        check_ajax_referer('hoktech_wa_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('صلاحيات غير كافية', 'sender-notification')]);
        }

        $phone    = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $message  = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $order_id = absint($_POST['order_id'] ?? 0);

        if (empty($phone) || empty($message)) {
            wp_send_json_error(['message' => __('رقم الهاتف والرسالة مطلوبان', 'sender-notification')]);
        }

        // If we have an order_id, replace template variables
        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order) {
                $message = $this->parse_template($message, $order);
            }
        }

        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);

        $result = $this->api->send_message($phone, $message);

        // Add order note
        if ($order_id > 0 && isset($order) && $order) {
            if ($result['success']) {
                $order->add_order_note(
                    sprintf(
                        __('📱 تم إرسال رسالة مخصصة للعميل (%s) من صفحة الطلب', 'sender-notification'),
                        $phone
                    )
                );
            } else {
                $order->add_order_note(
                    sprintf(
                        __('❌ فشل إرسال رسالة مخصصة: %s', 'sender-notification'),
                        $result['message'] ?? __('خطأ غير معروف', 'sender-notification')
                    )
                );
            }
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Replace template placeholders with order data
     */
    private function parse_template($template, $order) {
        $items_text = [];
        foreach ($order->get_items() as $item) {
            $items_text[] = '- ' . $item->get_name() . ' x' . $item->get_quantity();
        }

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
}
