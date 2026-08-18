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

        return true;
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
            $css_ver = file_exists(HOKTECH_WA_PLUGIN_DIR . 'assets/css/frontend-style.css') ? filemtime(HOKTECH_WA_PLUGIN_DIR . 'assets/css/frontend-style.css') : HOKTECH_WA_VERSION;
            $js_ver  = file_exists(HOKTECH_WA_PLUGIN_DIR . 'assets/js/frontend-otp.js') ? filemtime(HOKTECH_WA_PLUGIN_DIR . 'assets/js/frontend-otp.js') : HOKTECH_WA_VERSION;

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
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Cleanup transients
    delete_transient('hoktech_wa_sessions_cache');
});
