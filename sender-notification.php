<?php
/**
 * Plugin Name: sender - Order Notifications & Messaging
 * Plugin URI: https://www.wpsenderx.com
 * Description: إضافة ووردبريس لربط متجر WooCommerce بمنصة sender للإشعارات - تنبيهات الطلبات، التحقق عبر OTP، ورسائل مخصصة
 * Version: 1.1.1
 * Author: sender
 * Text Domain: sender-notification
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent loading the plugin twice (e.g. two copies of the folder in /plugins)
if (defined('HOKTECH_WA_VERSION')) {
    return;
}

// Plugin constants
define('HOKTECH_WA_VERSION', '1.1.2');
define('HOKTECH_WA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HOKTECH_WA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HOKTECH_WA_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Sanitize textarea field keeping line breaks and spaces.
 * Bypasses the 'sanitize_textarea_field' filter to prevent third-party plugins from stripping newlines.
 */
if (!function_exists('hoktech_sanitize_textarea')) {
    function hoktech_sanitize_textarea($text, $keep_whitespace = false) {
        if (!is_string($text)) {
            return '';
        }
        // Normalize line breaks to \n
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);

        // Save leading and trailing whitespace if keeping them
        $leading = '';
        $trailing = '';
        if ($keep_whitespace) {
            if (preg_match('/^\s+/u', $text, $matches)) {
                $leading = $matches[0];
            }
            if (preg_match('/\s+$/u', $text, $matches)) {
                $trailing = $matches[0];
            }
        }

        // Strip HTML tags (like scripts/styles) but keep newlines
        $text = wp_strip_all_tags($text, false);

        // Clean invalid UTF-8 characters
        $text = wp_check_invalid_utf8($text);

        if ($keep_whitespace) {
            return $leading . $text . $trailing;
        }

        return trim($text);
    }
}

/**
 * Get Product WhatsApp Button settings safely with multi-layer cache & database fallbacks
 */
if (!function_exists('hoktech_get_product_button_settings')) {
    function hoktech_get_product_button_settings() {
        $defaults = [
            'enabled'              => false,
            'phone'                => '',
            'default_country_code' => 'EG',
            'button_text'          => __('استفسار عبر واتساب', 'sender-notification'),
            'button_position'      => 'floating_draggable',
            'button_style'         => 'default',
            'btn_bg_color'         => '#25D366',
            'btn_text_color'       => '#ffffff',
            'btn_border_radius'    => 'circle',
            'show_icon'            => true,
            'open_in_new_tab'      => true,
            'hide_add_to_cart'     => false,
            'show_on_mobile_only'  => false,
            'draggable'            => true,
            'use_vendor_phone'     => false,
            'message_template'     => "مرحباً {site_name}، أود الاستفسار عن هذا المنتج:\n📌 *{product_name}*\n💰 السعر: {product_price}\n🏷️ كود المنتج (SKU): {product_sku}\n🔗 الرابط: {product_url}",
        ];

        $val = get_option('hoktech_wa_product_button', null);

        if (is_string($val) && (strpos($val, '{') === 0 || strpos($val, '[') === 0)) {
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                $val = $decoded;
            }
        }

        // Direct DB fallback if get_option returned non-array
        if (!is_array($val)) {
            global $wpdb;
            if ($wpdb) {
                $row = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'hoktech_wa_product_button'));
                if ($row) {
                    $unserialized = maybe_unserialize($row);
                    if (is_array($unserialized)) {
                        $val = $unserialized;
                    } else {
                        $decoded = json_decode($row, true);
                        if (is_array($decoded)) {
                            $val = $decoded;
                        }
                    }
                }
            }
        }

        if (!is_array($val)) {
            return $defaults;
        }

        return wp_parse_args($val, $defaults);
    }
}

/**
 * Update Product WhatsApp Button settings safely
 */
if (!function_exists('hoktech_update_product_button_settings')) {
    function hoktech_update_product_button_settings($settings) {
        if (!is_array($settings)) {
            return false;
        }

        // Ensure 4-byte emojis don't break utf8 tables
        if (function_exists('wp_encode_emoji') && isset($settings['message_template'])) {
            $settings['message_template'] = wp_encode_emoji($settings['message_template']);
        }

        wp_cache_delete('hoktech_wa_product_button', 'options');
        wp_cache_delete('alloptions', 'options');

        update_option('hoktech_wa_product_button', $settings, 'yes');

        // Verify write or do direct DB update
        $verify = get_option('hoktech_wa_product_button', null);
        if (!is_array($verify)) {
            global $wpdb;
            if ($wpdb) {
                $wpdb->replace(
                    $wpdb->options,
                    [
                        'option_name'  => 'hoktech_wa_product_button',
                        'option_value' => maybe_serialize($settings),
                        'autoload'     => 'yes',
                    ],
                    ['%s', '%s', '%s']
                );
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('hoktech_wa_product_button', 'options');
            }
        }

        // Auto purge WordPress & plugin caches
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('wp_cache_clean_cache')) {
            global $file_prefix;
            wp_cache_clean_cache($file_prefix, true);
        }
        if (class_exists('autoptimizeCache')) {
            autoptimizeCache::clearall();
        }

        return true;
    }
}

/**
 * Get Estimated Delivery Settings
 */
if (!function_exists('hoktech_get_delivery_settings')) {
    function hoktech_get_delivery_settings() {
        $defaults = [
            'default_estimated_delivery' => 'من 10 لـ 15 يوم عمل للاستلام',
            'custom_meta_key'            => '',
        ];
        $val = get_option('hoktech_wa_delivery_settings', []);
        return wp_parse_args(is_array($val) ? $val : [], $defaults);
    }
}

/**
 * Extract a numeric comparison score (number of days) from a delivery string or date.
 * Handles Arabic numerals, ranges (e.g. 10-20), days, and timestamps.
 */
if (!function_exists('hoktech_parse_delivery_days_score')) {
    function hoktech_parse_delivery_days_score($val) {
        if (!is_scalar($val)) {
            return 0;
        }

        $str = (string) $val;

        // Convert Eastern Arabic numerals (٠-٩) to standard (0-9)
        $str = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $str
        );

        if (is_numeric(trim($str))) {
            return (float) trim($str);
        }

        // Check if date format (e.g. 2026-09-20)
        $timestamp = strtotime($str);
        if ($timestamp !== false && $timestamp > 1000000000) {
            $diff = $timestamp - current_time('timestamp');
            return (float) max(1, ceil($diff / 86400));
        }

        // Extract all numbers in string (e.g. "من 10 لـ 20 يوم عمل" -> [10, 20])
        if (preg_match_all('/\d+/', $str, $matches)) {
            $numbers = array_map('intval', $matches[0]);
            return (float) max($numbers);
        }

        return 0;
    }
}

/**
 * Extract the estimated delivery for a single WC_Product.
 *
 * @param WC_Product|int $product
 * @return string
 */
if (!function_exists('hoktech_get_product_estimated_delivery')) {
    function hoktech_get_product_estimated_delivery($product) {
        if (!$product) {
            return '';
        }
        if (is_numeric($product)) {
            $product = wc_get_product($product);
        }
        if (!$product || !is_a($product, 'WC_Product')) {
            return '';
        }

        $settings         = hoktech_get_delivery_settings();
        $default_delivery = trim($settings['default_estimated_delivery'] ?? '');
        $custom_meta_key  = trim($settings['custom_meta_key'] ?? '');

        $product_keys = [
            '_estimated_delivery',
            'estimated_delivery',
            '_estimated_delivery_days',
            'estimated_delivery_days',
            '_delivery_days',
            'delivery_days',
            '_delivery_time',
            'delivery_time',
            '_shipping_days',
            'shipping_days',
            '_estimated_days',
            'estimated_days',
            '_estimated_delivery_text',
            'estimated_delivery_text',
            '_min_delivery_days',
            '_max_delivery_days',
            '_estimated_delivery_min',
            '_estimated_delivery_max',
            '_delivery_estimate',
            '_expected_delivery',
            '_woo_estimated_delivery',
            '_wcfm_estimated_delivery',
            '_dokan_estimated_delivery',
            '_pi_delivery_date',
            '_orddd_delivery_date',
            '_delivery_date',
            'delivery_date',
        ];

        if (!empty($custom_meta_key)) {
            array_unshift($product_keys, $custom_meta_key);
        }

        $val = '';
        foreach ($product_keys as $key) {
            $v = $product->get_meta($key);
            if (!empty($v) && is_scalar($v)) {
                $val = trim((string) $v);
                break;
            }
        }

        // Check parent if variation
        if (empty($val) && method_exists($product, 'is_type') && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $parent = wc_get_product($parent_id);
                if ($parent) {
                    foreach ($product_keys as $key) {
                        $v = $parent->get_meta($key);
                        if (!empty($v) && is_scalar($v)) {
                            $val = trim((string) $v);
                            break;
                        }
                    }
                }
            }
        }

        // Check attributes
        if (empty($val) && method_exists($product, 'get_attribute')) {
            $attr_keys = [
                'pa_estimated-delivery',
                'pa_estimated_delivery',
                'pa_delivery-time',
                'pa_delivery_time',
                'pa_مدة-التوصيل',
                'pa_التوصيل',
            ];
            foreach ($attr_keys as $attr) {
                $v = $product->get_attribute($attr);
                if (!empty($v)) {
                    $val = trim((string) $v);
                    break;
                }
            }
        }

        if (empty($val)) {
            $val = $default_delivery;
        }

        if (is_numeric(trim($val))) {
            $days = (int) trim($val);
            $val = sprintf(_n('%d يوم', '%d أيام', $days, 'sender-notification'), $days);
        }

        return (string) apply_filters('hoktech_wa_product_estimated_delivery', $val, $product);
    }
}

/**
 * Calculate / extract the estimated delivery for an order.
 * If the order contains multiple items with different delivery estimates,
 * it automatically calculates and selects the maximum duration (الأيام الأكثر).
 *
 * @param WC_Order $order
 * @param array|null $specific_items Optional line items to filter for (e.g. vendor products)
 * @return string
 */
if (!function_exists('hoktech_get_order_estimated_delivery')) {
    function hoktech_get_order_estimated_delivery($order, $specific_items = null) {
        if (!$order || !is_a($order, 'WC_Order')) {
            return '';
        }

        $settings         = hoktech_get_delivery_settings();
        $default_delivery = trim($settings['default_estimated_delivery'] ?? '');
        $custom_meta_key  = trim($settings['custom_meta_key'] ?? '');

        // List of candidate meta keys to check across products & line items
        $product_keys = [
            '_estimated_delivery',
            'estimated_delivery',
            '_estimated_delivery_days',
            'estimated_delivery_days',
            '_delivery_days',
            'delivery_days',
            '_delivery_time',
            'delivery_time',
            '_shipping_days',
            'shipping_days',
            '_estimated_days',
            'estimated_days',
            '_estimated_delivery_text',
            'estimated_delivery_text',
            '_min_delivery_days',
            '_max_delivery_days',
            '_estimated_delivery_min',
            '_estimated_delivery_max',
            '_delivery_estimate',
            '_expected_delivery',
            '_woo_estimated_delivery',
            '_wcfm_estimated_delivery',
            '_dokan_estimated_delivery',
            '_pi_delivery_date',
            '_orddd_delivery_date',
            '_delivery_date',
            'delivery_date',
        ];

        if (!empty($custom_meta_key)) {
            array_unshift($product_keys, $custom_meta_key);
        }

        $max_days_score     = -1;
        $best_delivery_text = '';
        $found_any          = false;

        $items_to_check = ($specific_items !== null) ? $specific_items : $order->get_items();

        foreach ($items_to_check as $item) {
            $item_val = '';

            // 1. Check Order Item Meta
            if (is_object($item) && method_exists($item, 'get_meta')) {
                foreach ($product_keys as $key) {
                    $val = $item->get_meta($key);
                    if (!empty($val) && is_scalar($val)) {
                        $item_val = (string) $val;
                        break;
                    }
                }

                // Check common human-readable item meta labels
                if (empty($item_val)) {
                    $item_meta_labels = [
                        'Estimated Delivery',
                        'Delivery Date',
                        'وقت التوصيل',
                        'مدة التوصيل',
                        'تاريخ التوصيل',
                    ];
                    foreach ($item_meta_labels as $lbl) {
                        $val = $item->get_meta($lbl);
                        if (!empty($val) && is_scalar($val)) {
                            $item_val = (string) $val;
                            break;
                        }
                    }
                }
            }

            // 2. Check Product & Variation Meta
            $product = null;
            if (is_object($item) && method_exists($item, 'get_product')) {
                $product = $item->get_product();
            } elseif (is_array($item) && !empty($item['product_id'])) {
                $product = wc_get_product($item['product_id']);
            }

            if ($product && empty($item_val)) {
                // Check direct product meta
                foreach ($product_keys as $key) {
                    $val = $product->get_meta($key);
                    if (!empty($val) && is_scalar($val)) {
                        $item_val = (string) $val;
                        break;
                    }
                }

                // Check parent product if variation
                if (empty($item_val) && method_exists($product, 'is_type') && $product->is_type('variation')) {
                    $parent_id = $product->get_parent_id();
                    if ($parent_id) {
                        $parent = wc_get_product($parent_id);
                        if ($parent) {
                            foreach ($product_keys as $key) {
                                $val = $parent->get_meta($key);
                                if (!empty($val) && is_scalar($val)) {
                                    $item_val = (string) $val;
                                    break;
                                }
                            }
                        }
                    }
                }

                // Check product attributes
                if (empty($item_val) && method_exists($product, 'get_attribute')) {
                    $attr_keys = [
                        'pa_estimated-delivery',
                        'pa_estimated_delivery',
                        'pa_delivery-time',
                        'pa_delivery_time',
                        'pa_مدة-التوصيل',
                        'pa_التوصيل',
                    ];
                    foreach ($attr_keys as $attr) {
                        $val = $product->get_attribute($attr);
                        if (!empty($val)) {
                            $item_val = (string) $val;
                            break;
                        }
                    }
                }
            }

            if (!empty($item_val)) {
                $found_any = true;
                $score = hoktech_parse_delivery_days_score($item_val);
                if ($score > $max_days_score) {
                    $max_days_score     = $score;
                    $best_delivery_text = trim($item_val);
                }
            }
        }

        // 3. Check Order-level Meta if no product-level meta found
        if (!$found_any) {
            $order_meta_keys = [
                '_estimated_delivery',
                'estimated_delivery',
                '_delivery_date',
                'delivery_date',
                '_order_delivery_date',
                '_estimated_delivery_date',
                'estimated_delivery_date',
                '_expected_delivery',
                'expected_delivery',
                '_delivery_time',
                'delivery_time',
                '_delivery_days',
                'delivery_days',
            ];
            if (!empty($custom_meta_key)) {
                array_unshift($order_meta_keys, $custom_meta_key);
            }

            foreach ($order_meta_keys as $omk) {
                $val = $order->get_meta($omk);
                if (!empty($val) && is_scalar($val)) {
                    $best_delivery_text = trim((string) $val);
                    $found_any = true;
                    break;
                }
            }
        }

        // 4. Fallback to default setting
        if (empty($best_delivery_text)) {
            $best_delivery_text = $default_delivery;
        }

        // Format cleanly if the result is pure numeric
        if (is_numeric(trim($best_delivery_text))) {
            $days = (int) trim($best_delivery_text);
            $best_delivery_text = sprintf(_n('%d يوم', '%d أيام', $days, 'sender-notification'), $days);
        }

        $result = apply_filters('hoktech_wa_order_estimated_delivery', $best_delivery_text, $order);
        return (string) apply_filters('hoktech_wa_estimated_delivery', $result, $order);
    }
}


/**
 * Main Plugin Class
 */
final class HokTech_sender {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-api-client.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-async-sender.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-admin-settings.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-order-notifications.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-vendor-notifications.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-otp-verification.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-product-button.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-custom-message.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/class-order-metabox.php';
        require_once HOKTECH_WA_PLUGIN_DIR . 'includes/country-codes.php';
    }

    private function init_hooks() {
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

        // Declare WooCommerce HPOS & Blocks compatibility
        add_action('before_woocommerce_init', [$this, 'declare_wc_compatibility']);

        // Standard initialization for components on init
        add_action('init', [$this, 'init_components'], 11);
    }

    /**
     * Declare compatibility with WooCommerce features (HPOS, Blocks)
     */
    public function declare_wc_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Admin and messaging can load normally
        new HokTech_Admin_Settings();
        new HokTech_Custom_Message();

        // WooCommerce dependent components
        if (class_exists('WooCommerce')) {
            // Async sender must be registered first so the AJAX endpoint exists
            new HokTech_Async_Sender();
            new HokTech_Order_Notifications();
            new HokTech_Vendor_Notifications();
            new HokTech_OTP_Verification();
            new HokTech_Product_Button();
            new HokTech_Order_MetaBox();
        }
    }

    public function admin_assets($hook) {
        if (strpos($hook, 'sender-notification') === false) {
            return;
        }
        wp_enqueue_style('hoktech-wa-admin', HOKTECH_WA_PLUGIN_URL . 'assets/css/admin-style.css', [], HOKTECH_WA_VERSION);
        $admin_js_ver = HOKTECH_WA_VERSION . '-' . filemtime(HOKTECH_WA_PLUGIN_DIR . 'assets/js/admin-script.js');
        wp_enqueue_script('hoktech-wa-admin', HOKTECH_WA_PLUGIN_URL . 'assets/js/admin-script.js', ['jquery'], $admin_js_ver, true);
        wp_localize_script('hoktech-wa-admin', 'hoktechWA', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('hoktech_wa_nonce'),
            'strings' => [
                'connecting'   => __('جاري الاتصال...', 'sender-notification'),
                'connected'    => __('متصل', 'sender-notification'),
                'disconnected' => __('غير متصل', 'sender-notification'),
                'sending'      => __('جاري الإرسال...', 'sender-notification'),
                'sent'         => __('تم الإرسال بنجاح', 'sender-notification'),
                'error'        => __('حدث خطأ', 'sender-notification'),
                'confirm_disconnect' => __('هل أنت متأكد من قطع الاتصال؟', 'sender-notification'),
            ]
        ]);
    }

    public function frontend_assets() {
        $otp_settings = get_option('hoktech_wa_otp_settings', []);
        $product_settings = function_exists('hoktech_get_product_button_settings') ? hoktech_get_product_button_settings() : get_option('hoktech_wa_product_button', []);
        $country_selector_enabled = !empty($otp_settings['enable_country_selector']);
        $otp_enabled = !empty($otp_settings['enable_checkout_otp']) || !empty($otp_settings['enable_registration_otp']);
        $product_btn_enabled = !empty($product_settings['enabled']);
        $is_checkout = function_exists('is_checkout') && is_checkout();
        $is_product  = function_exists('is_product') && is_product();

        if ($otp_enabled || $country_selector_enabled || $product_btn_enabled || $is_checkout || $is_product) {
            $css_ver = HOKTECH_WA_VERSION . '.' . (file_exists(HOKTECH_WA_PLUGIN_DIR . 'assets/css/frontend-style.css') ? filemtime(HOKTECH_WA_PLUGIN_DIR . 'assets/css/frontend-style.css') : time());
            $js_ver  = HOKTECH_WA_VERSION . '.' . (file_exists(HOKTECH_WA_PLUGIN_DIR . 'assets/js/frontend-otp.js') ? filemtime(HOKTECH_WA_PLUGIN_DIR . 'assets/js/frontend-otp.js') : time());

            wp_enqueue_style('hoktech-wa-frontend', HOKTECH_WA_PLUGIN_URL . 'assets/css/frontend-style.css', [], $css_ver);
            wp_enqueue_script('hoktech-wa-frontend', HOKTECH_WA_PLUGIN_URL . 'assets/js/frontend-otp.js', ['jquery'], $js_ver, true);

            $localize_data = [
                'ajaxUrl'        => admin_url('admin-ajax.php'),
                'nonce'          => wp_create_nonce('hoktech_otp_nonce'),
                'defaultCountry' => $otp_settings['default_country_code'] ?? 'EG',
            ];

            // Pass country codes data if available
            if (function_exists('hoktech_get_country_codes')) {
                $localize_data['countrySelector'] = $country_selector_enabled;
                $localize_data['countries']       = hoktech_get_country_codes();
            }

            wp_localize_script('hoktech-wa-frontend', 'hoktechOTP', $localize_data);
        }
    }
}

// Initialize plugin
HokTech_sender::instance();

// Activation hook
register_activation_hook(__FILE__, function () {
    // Set default options
    if (!get_option('hoktech_wa_connection')) {
        update_option('hoktech_wa_connection', [
            'api_url'    => '',
            'api_key'    => '',
            'session_id' => '',
        ]);
    }
    if (!get_option('hoktech_wa_notification_settings')) {
        update_option('hoktech_wa_notification_settings', [
            'pending'    => ['enabled' => false, 'message' => 'مرحباً {customer_name}، تم استلام طلبك رقم #{order_id} بقيمة {order_total}. سنقوم بمعالجته قريباً. شكراً لتسوقك من {site_name}'],
            'processing' => ['enabled' => true,  'message' => 'مرحباً {customer_name}، طلبك رقم #{order_id} قيد المعالجة الآن. سنعلمك فور شحنه. {site_name}'],
            'completed'  => ['enabled' => true,  'message' => 'مرحباً {customer_name}، طلبك رقم #{order_id} تم تسليمه بنجاح! شكراً لثقتك بنا. {site_name}'],
            'cancelled'  => ['enabled' => false, 'message' => 'مرحباً {customer_name}، تم إلغاء طلبك رقم #{order_id}. إذا كان هذا خطأ، تواصل معنا. {site_name}'],
            'refunded'   => ['enabled' => false, 'message' => 'مرحباً {customer_name}، تم استرداد المبلغ لطلبك رقم #{order_id}. {site_name}'],
            'on-hold'    => ['enabled' => false, 'message' => 'مرحباً {customer_name}، طلبك رقم #{order_id} قيد الانتظار. يرجى إتمام الدفع لمتابعة المعالجة. {site_name}'],
            'failed'     => ['enabled' => false, 'message' => 'مرحباً {customer_name}، فشل طلبك رقم #{order_id}. يرجى المحاولة مرة أخرى أو التواصل معنا. {site_name}'],
        ]);
    }
    if (!get_option('hoktech_wa_otp_settings')) {
        update_option('hoktech_wa_otp_settings', [
            'enable_checkout_otp'     => false,
            'enable_registration_otp' => false,
            'enable_country_selector' => false,
            'default_country_code'    => 'EG',
            'otp_message'             => 'رمز التحقق الخاص بك هو: {otp_code} - {site_name}',
        ]);
    }
    if (!get_option('hoktech_wa_vendor_notifications')) {
        update_option('hoktech_wa_vendor_notifications', [
            'enabled'        => false,
            'statuses'       => ['processing'],
            'phone_meta_key' => '',
            'message'        => "🏪 مرحباً {vendor_name}،\nلديك طلب جديد رقم #{order_id} من العميل {customer_name}\n\nالمنتجات الخاصة بك:\n{vendor_items}\n\nإجمالي منتجاتك: {vendor_items_total}\n\nيرجى التجهيز في أقرب وقت ✅\n{site_name}",
        ]);
    }
    if (!get_option('hoktech_wa_product_button')) {
        update_option('hoktech_wa_product_button', [
            'enabled'              => true,
            'phone'                => '',
            'default_country_code' => 'EG',
            'button_text'          => 'استفسار عبر واتساب',
            'button_position'      => 'after_add_to_cart_btn',
            'button_style'         => 'default',
            'btn_bg_color'         => '#25D366',
            'btn_text_color'       => '#ffffff',
            'btn_border_radius'    => 'rounded',
            'show_icon'            => true,
            'open_in_new_tab'      => true,
            'hide_add_to_cart'     => false,
            'show_on_mobile_only'  => false,
            'use_vendor_phone'     => false,
            'message_template'     => "مرحباً {site_name}، أود الاستفسار عن هذا المنتج:\n📌 *{product_name}*\n💰 السعر: {product_price}\n🏷️ كود المنتج (SKU): {product_sku}\n🔗 الرابط: {product_url}",
        ]);
    }
    if (!get_option('hoktech_wa_delivery_settings')) {
        update_option('hoktech_wa_delivery_settings', [
            'default_estimated_delivery' => 'من 10 لـ 15 يوم عمل للاستلام',
            'custom_meta_key'            => '',
        ]);
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Cleanup transients
    delete_transient('hoktech_wa_sessions_cache');
});
