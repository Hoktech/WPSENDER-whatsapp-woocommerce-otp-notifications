<?php
/**
 * sender Product WhatsApp Button
 * Displays a customizable WhatsApp inquiry/order button on single product pages and via shortcode
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_Product_Button {

    private $settings;

    public function __construct() {
        $this->settings = function_exists('hoktech_get_product_button_settings') ? hoktech_get_product_button_settings() : get_option('hoktech_wa_product_button', []);

        // Shortcodes (always registered even if global hook is disabled)
        add_shortcode('hoktech_whatsapp_button', [$this, 'render_shortcode']);
        add_shortcode('sender_whatsapp_button', [$this, 'render_shortcode']);

        // Check if button is enabled globally
        if (!empty($this->settings['enabled'])) {
            $this->init_product_hooks();
        }

        // Hide add to cart button if enabled in settings
        if (!empty($this->settings['enabled']) && !empty($this->settings['hide_add_to_cart'])) {
            add_action('wp_head', [$this, 'hide_add_to_cart_css']);
        }
    }

    /**
     * Initialize WooCommerce product page hooks based on configured position
     */
    private function init_product_hooks() {
        $position = $this->settings['button_position'] ?? 'floating_draggable';

        switch ($position) {
            case 'after_add_to_cart_btn':
                add_action('woocommerce_after_add_to_cart_button', [$this, 'render_button_inline'], 20);
                break;

            case 'after_add_to_cart_form':
                add_action('woocommerce_after_add_to_cart_form', [$this, 'render_button_block'], 20);
                break;

            case 'before_add_to_cart_form':
                add_action('woocommerce_before_add_to_cart_form', [$this, 'render_button_block'], 10);
                break;

            case 'after_price':
                add_action('woocommerce_single_product_summary', [$this, 'render_button_block'], 12);
                break;

            case 'after_summary':
                add_action('woocommerce_after_single_product_summary', [$this, 'render_button_block'], 5);
                break;

            case 'both_inline_and_floating':
                add_action('woocommerce_after_add_to_cart_button', [$this, 'render_button_inline'], 20);
                add_action('wp_footer', [$this, 'render_floating_button']);
                break;

            case 'floating_draggable':
            case 'floating_bottom_right':
            case 'floating_bottom_left':
                add_action('wp_footer', [$this, 'render_floating_button']);
                break;

            case 'shortcode_only':
                // Do not attach to automatic hooks
                break;

            default:
                add_action('wp_footer', [$this, 'render_floating_button']);
                break;
        }
    }

    /**
     * Render button inline (inside add to cart form)
     */
    public function render_button_inline() {
        global $product;
        if (!$product) {
            return;
        }
        echo $this->get_button_html($product, ['layout' => 'inline']);
    }

    /**
     * Render button as a full block
     */
    public function render_button_block() {
        global $product;
        if (!$product) {
            return;
        }
        echo $this->get_button_html($product, ['layout' => 'block']);
    }

    /**
     * Render floating draggable button on single product pages
     */
    public function render_floating_button() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        global $product;
        if (!$product) {
            $product = wc_get_product(get_the_ID());
        }
        if (!$product) {
            return;
        }
        $position = $this->settings['button_position'] ?? 'floating_bottom_right';
        $side_class = ($position === 'floating_bottom_left') ? 'hoktech-floating-left' : 'hoktech-floating-right';
        ?>
        <style id="hoktech-wa-floating-style">
        .hoktech-wa-floating-btn { position: fixed !important; bottom: 25px; right: 25px; z-index: 9999999 !important; margin: 0 !important; line-height: 1 !important; display: flex !important; align-items: center !important; justify-content: center !important; width: 60px !important; height: 60px !important; min-width: 60px !important; min-height: 60px !important; max-width: 60px !important; max-height: 60px !important; padding: 0 !important; border-radius: 50% !important; background-color: #25D366 !important; color: #ffffff !important; border: none !important; box-shadow: 0 4px 18px rgba(37, 211, 102, 0.45), 0 2px 8px rgba(0, 0, 0, 0.18) !important; user-select: none !important; -webkit-user-select: none !important; touch-action: manipulation; text-decoration: none !important; transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.25s ease !important; }
        .hoktech-wa-floating-btn.hoktech-draggable-btn { cursor: grab !important; cursor: -webkit-grab !important; touch-action: none !important; }
        .hoktech-wa-floating-btn.hoktech-fixed-btn { cursor: pointer !important; touch-action: auto !important; }
        .hoktech-wa-floating-btn.hoktech-floating-left { right: auto !important; left: 25px !important; }
        .hoktech-wa-floating-btn.hoktech-floating-right { left: auto !important; right: 25px !important; }
        .hoktech-wa-floating-btn:hover { transform: scale(1.1) !important; box-shadow: 0 8px 24px rgba(37, 211, 102, 0.55), 0 4px 10px rgba(0, 0, 0, 0.22) !important; color: #ffffff !important; }
        .hoktech-wa-floating-btn.is-dragging { cursor: grabbing !important; cursor: -webkit-grabbing !important; transform: scale(1.14) !important; box-shadow: 0 14px 32px rgba(37, 211, 102, 0.65), 0 6px 16px rgba(0, 0, 0, 0.3) !important; transition: none !important; }
        .hoktech-wa-floating-icon { display: flex !important; align-items: center !important; justify-content: center !important; width: 100% !important; height: 100% !important; pointer-events: none !important; }
        .hoktech-wa-floating-btn svg { width: 34px !important; height: 34px !important; fill: #ffffff !important; display: block !important; margin: auto !important; }
        .hoktech-wa-floating-tooltip { position: absolute !important; right: calc(100% + 12px) !important; top: 50% !important; transform: translateY(-50%) !important; background: #1e293b !important; color: #ffffff !important; padding: 6px 14px !important; border-radius: 8px !important; font-size: 13px !important; font-weight: 600 !important; white-space: nowrap !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; transition: opacity 0.2s ease !important; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2) !important; line-height: 1.4 !important; }
        .hoktech-wa-floating-btn.hoktech-floating-left .hoktech-wa-floating-tooltip { right: auto !important; left: calc(100% + 12px) !important; }
        .hoktech-wa-floating-btn:hover:not(.is-dragging) .hoktech-wa-floating-tooltip { opacity: 1 !important; visibility: visible !important; }
        @media (max-width: 768px) {
            .hoktech-wa-floating-btn { width: 55px !important; height: 55px !important; min-width: 55px !important; min-height: 55px !important; max-width: 55px !important; max-height: 55px !important; bottom: 80px !important; }
            .hoktech-wa-floating-btn.hoktech-floating-left { left: 18px !important; right: auto !important; }
            .hoktech-wa-floating-btn.hoktech-floating-right { right: 18px !important; left: auto !important; }
            .hoktech-wa-floating-btn svg { width: 30px !important; height: 30px !important; }
            .hoktech-wa-floating-tooltip { display: none !important; }
        }
        </style>
        <?php
        echo $this->get_button_html($product, [
            'layout'           => 'floating',
            'extra_class'      => $side_class,
            'is_floating_icon' => true,
        ]);
    }

    /**
     * Shortcode handler [hoktech_whatsapp_button]
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'id'    => 0,
            'text'  => '',
            'phone' => '',
            'class' => '',
            'shape' => '',
        ], $atts, 'hoktech_whatsapp_button');

        $product_id = !empty($atts['id']) ? absint($atts['id']) : get_the_ID();
        $product = wc_get_product($product_id);

        if (!$product) {
            return '';
        }

        $custom_args = [];
        if (!empty($atts['text'])) {
            $custom_args['button_text'] = sanitize_text_field($atts['text']);
        }
        if (!empty($atts['phone'])) {
            $custom_args['phone'] = sanitize_text_field($atts['phone']);
        }
        if (!empty($atts['class'])) {
            $custom_args['extra_class'] = sanitize_text_field($atts['class']);
        }
        if (!empty($atts['shape'])) {
            $custom_args['button_shape'] = sanitize_key($atts['shape']);
        }

        return $this->get_button_html($product, $custom_args);
    }

    /**
     * Generate complete HTML for WhatsApp product button
     */
    public function get_button_html($product, $args = []) {
        if (!$product instanceof WC_Product) {
            return '';
        }

        $settings = $this->settings;

        // Mobile only check
        $mobile_only = !empty($settings['show_on_mobile_only']);
        $mobile_class = $mobile_only ? 'hoktech-wa-mobile-only' : '';

        // Layout class
        $layout = $args['layout'] ?? 'inline';
        $extra_class = $args['extra_class'] ?? '';
        $is_floating = ($layout === 'floating' || !empty($args['is_floating_icon']));

        // Draggable check: only enable dragging for floating_draggable or both_inline_and_floating
        $position = $settings['button_position'] ?? 'floating_draggable';
        $is_floating = in_array($position, ['floating_draggable', 'floating_bottom_left', 'floating_bottom_right', 'both_inline_and_floating'], true) || ($layout === 'floating') || !empty($args['is_floating_icon']);
        $can_drag = in_array($position, ['floating_draggable', 'both_inline_and_floating'], true);
        if ($layout === 'floating' && !in_array($position, ['floating_bottom_left', 'floating_bottom_right'], true)) {
            $can_drag = true;
        }
        $is_draggable = $can_drag && (!isset($settings['draggable']) || !empty($settings['draggable']));
        $draggable_class = $is_draggable ? 'hoktech-draggable-btn' : 'hoktech-fixed-btn';

        // Button shape & options
        $button_shape = $args['button_shape'] ?? ($settings['btn_border_radius'] ?? ($is_floating ? 'circle' : 'rounded'));
        $button_text = !empty($args['button_text']) 
            ? $args['button_text'] 
            : (!empty($settings['button_text']) ? $settings['button_text'] : __('استفسار عبر واتساب', 'sender-notification'));

        $button_style = $settings['button_style'] ?? 'default';
        $show_icon    = !isset($settings['show_icon']) || !empty($settings['show_icon']);
        $open_new_tab = !isset($settings['open_in_new_tab']) || !empty($settings['open_in_new_tab']);
        $target_attr  = $open_new_tab ? ' target="_blank" rel="noopener noreferrer"' : '';

        // Custom colors if style is custom
        $custom_style_attr = '';
        if ($button_style === 'custom') {
            $bg_color   = !empty($settings['btn_bg_color']) ? sanitize_hex_color($settings['btn_bg_color']) : '#25D366';
            $text_color = !empty($settings['btn_text_color']) ? sanitize_hex_color($settings['btn_text_color']) : '#ffffff';
            $custom_style_attr = sprintf(' style="background-color: %s !important; color: %s !important; border-color: %s !important;"', esc_attr($bg_color), esc_attr($text_color), esc_attr($bg_color));
        }

        // Resolve target phone number
        $phone = !empty($args['phone']) ? $args['phone'] : $this->get_product_phone_number($product);
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);

        // Build dynamic message
        $template = !empty($settings['message_template']) 
            ? $settings['message_template'] 
            : "مرحباً {site_name}، أود الاستفسار عن هذا المنتج:\n📌 *{product_name}*\n💰 السعر: {product_price}\n🏷️ كود المنتج (SKU): {product_sku}\n🔗 الرابط: {product_url}";

        $message = $this->parse_product_message($template, $product);

        // WhatsApp direct URL (preserving exact line breaks)
        $encoded_msg = rawurlencode($message);
        $encoded_template = rawurlencode($template);
        $wa_url = 'https://api.whatsapp.com/send?phone=' . rawurlencode($clean_phone) . '&amp;text=' . $encoded_msg;

        // Product details for JS dynamic variation support
        $product_id   = $product->get_id();
        $product_sku  = $product->get_sku() ?: __('غير متوفر', 'sender-notification');
        $product_name = $product->get_name();
        $product_price = wp_strip_all_tags(wc_price($product->get_price()));
        $product_url  = $product->get_permalink();

        // SVG WhatsApp Icon (Crisp High-Res SVG)
        $svg_icon = '<svg class="hoktech-wa-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" width="32" height="32" aria-hidden="true" fill="currentColor"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>';

        // Floating Circular Draggable Output
        if ($is_floating || $button_shape === 'circle') {
            ob_start();
            ?>
            <a href="<?php echo $wa_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
               class="hoktech-wa-floating-btn <?php echo esc_attr($draggable_class); ?> <?php echo esc_attr($mobile_class); ?> <?php echo esc_attr($extra_class); ?>"
               id="hoktech-wa-floating-button"
               data-product-id="<?php echo esc_attr($product_id); ?>"
               data-product-name="<?php echo esc_attr($product_name); ?>"
               data-product-price="<?php echo esc_attr($product_price); ?>"
               data-product-sku="<?php echo esc_attr($product_sku); ?>"
               data-product-url="<?php echo esc_url($product_url); ?>"
               data-raw-phone="<?php echo esc_attr($clean_phone); ?>"
               data-base-template="<?php echo esc_attr($encoded_template); ?>"
               data-site-name="<?php echo esc_attr(get_bloginfo('name')); ?>"
               aria-label="<?php echo esc_attr($button_text); ?>"
               title="<?php echo esc_attr($button_text); ?>"
               <?php echo $custom_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
               <?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <span class="hoktech-wa-floating-icon">
                    <?php echo $svg_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </span>
                <?php if (!empty($button_text) && $button_text !== 'WhatsApp'): ?>
                    <span class="hoktech-wa-floating-tooltip"><?php echo esc_html($button_text); ?></span>
                <?php endif; ?>
            </a>
            <?php
            return ob_get_clean();
        }

        // Standard Inline / Block Output
        $wrapper_classes = [
            'hoktech-wa-product-btn-wrap',
            'hoktech-layout-' . sanitize_html_class($layout),
            'hoktech-shape-' . sanitize_html_class($button_shape),
            'hoktech-style-' . sanitize_html_class($button_style),
            $mobile_class,
            $extra_class
        ];
        $wrapper_class_str = esc_attr(trim(implode(' ', array_filter($wrapper_classes))));

        ob_start();
        ?>
        <div class="<?php echo $wrapper_class_str; ?>">
            <a href="<?php echo $wa_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
               class="hoktech-wa-product-btn"
               data-product-id="<?php echo esc_attr($product_id); ?>"
               data-product-name="<?php echo esc_attr($product_name); ?>"
               data-product-price="<?php echo esc_attr($product_price); ?>"
               data-product-sku="<?php echo esc_attr($product_sku); ?>"
               data-product-url="<?php echo esc_url($product_url); ?>"
               data-raw-phone="<?php echo esc_attr($clean_phone); ?>"
               data-base-template="<?php echo esc_attr($encoded_template); ?>"
               data-site-name="<?php echo esc_attr(get_bloginfo('name')); ?>"
               aria-label="<?php echo esc_attr($button_text); ?>"
               <?php echo $custom_style_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
               <?php echo $target_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
                <?php if ($show_icon): ?>
                    <span class="hoktech-wa-btn-icon"><?php echo $svg_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php endif; ?>
                <span class="hoktech-wa-btn-text"><?php echo esc_html($button_text); ?></span>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Replace dynamic product variables in message template
     */
    public function parse_product_message($template, $product) {
        if (!$product instanceof WC_Product) {
            return $template;
        }

        // Product data
        $product_name = $product->get_name();
        $product_sku  = $product->get_sku() ?: __('غير متوفر', 'sender-notification');
        $product_url  = $product->get_permalink();
        $product_id   = $product->get_id();
        $site_name    = get_bloginfo('name');

        // Price formatting
        $price = $product->get_price();
        $price_html = ($price !== '' && $price !== null) ? wp_strip_all_tags(wc_price($price)) : __('غير محدد', 'sender-notification');

        // Categories
        $categories_list = wc_get_product_category_list($product_id, ', ');
        $categories = !empty($categories_list) ? wp_strip_all_tags($categories_list) : '';

        // Short description
        $short_desc = wp_strip_all_tags($product->get_short_description());
        if (mb_strlen($short_desc) > 120) {
            $short_desc = mb_substr($short_desc, 0, 120) . '...';
        }

        $replacements = [
            '{product_name}'       => $product_name,
            '{product_price}'      => $price_html,
            '{product_sku}'        => $product_sku,
            '{product_url}'        => $product_url,
            '{product_id}'         => $product_id,
            '{product_category}'   => $categories,
            '{product_short_desc}' => $short_desc,
            '{site_name}'          => $site_name,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Resolve the phone number for WhatsApp message
     * Supports multi-vendor routing if enabled
     */
    public function get_product_phone_number($product) {
        $settings = $this->settings;
        $phone = '';

        // 1. If Multi-Vendor routing is enabled, check if product belongs to a vendor
        if (!empty($settings['use_vendor_phone'])) {
            $vendor_phone = $this->get_vendor_phone_from_product($product);
            if (!empty($vendor_phone)) {
                $phone = $vendor_phone;
            }
        }

        // 2. Fallback to settings product button phone
        if (empty($phone) && !empty($settings['phone'])) {
            $phone = $settings['phone'];
        }

        // 3. Fallback to OTP default phone or store phone if available
        if (empty($phone)) {
            $admin_notif = get_option('hoktech_wa_admin_notifications', []);
            if (!empty($admin_notif['phones'])) {
                $phones = explode("\n", $admin_notif['phones']);
                $phone = trim($phones[0] ?? '');
            }
        }

        // Apply country code if configured
        $default_country = $settings['default_country_code'] ?? '';
        if (!empty($default_country) && function_exists('hoktech_get_dial_code_by_country')) {
            $dial_code = hoktech_get_dial_code_by_country($default_country);
            $dial_digits = preg_replace('/[^0-9]/', '', $dial_code);
            $phone_digits = preg_replace('/[^0-9]/', '', $phone);

            if (!empty($dial_digits) && !empty($phone_digits) && strpos($phone_digits, $dial_digits) !== 0) {
                $phone_digits = ltrim($phone_digits, '0');
                $phone = $dial_digits . $phone_digits;
            }
        }

        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Get vendor phone number for a product (Dokan, WCFM, WC Vendors, Author)
     */
    private function get_vendor_phone_from_product($product) {
        $product_id = $product->get_id();
        $vendor_id = 0;

        // Dokan
        if (function_exists('dokan_get_vendor_by_product')) {
            $vendor = dokan_get_vendor_by_product($product_id);
            if ($vendor && method_exists($vendor, 'get_id')) {
                $vendor_id = (int) $vendor->get_id();
            }
        }

        // WCFM
        if (!$vendor_id && function_exists('wcfm_get_vendor_id_by_post')) {
            $vendor_id = (int) wcfm_get_vendor_id_by_post($product_id);
        }

        // WC Vendors
        if (!$vendor_id && class_exists('WCV_Vendors')) {
            $vendor_id = (int) WCV_Vendors::get_vendor_from_product($product_id);
        }

        // Fallback: Post Author
        if (!$vendor_id) {
            $post = get_post($product_id);
            if ($post && $post->post_author) {
                $vendor_id = (int) $post->post_author;
            }
        }

        if (!$vendor_id) {
            return '';
        }

        // Read vendor phone using vendor settings or user meta
        $vendor_settings = get_option('hoktech_wa_vendor_notifications', []);
        $custom_meta = $vendor_settings['phone_meta_key'] ?? '';
        $raw_phone = '';

        if (!empty($custom_meta)) {
            $raw_phone = get_user_meta($vendor_id, $custom_meta, true);
        }

        // Dokan store phone
        if (empty($raw_phone) && function_exists('dokan_get_store_info')) {
            $store_info = dokan_get_store_info($vendor_id);
            if (!empty($store_info['phone'])) {
                $raw_phone = $store_info['phone'];
            }
        }

        // WCFM store phone
        if (empty($raw_phone)) {
            $wcfm_settings = get_user_meta($vendor_id, 'wcfmmp_profile_settings', true);
            if (is_array($wcfm_settings) && !empty($wcfm_settings['phone'])) {
                $raw_phone = $wcfm_settings['phone'];
            }
        }

        // Billing phone or user mobile
        if (empty($raw_phone)) {
            $raw_phone = get_user_meta($vendor_id, 'billing_phone', true)
                      ?: get_user_meta($vendor_id, 'phone', true)
                      ?: get_user_meta($vendor_id, 'mobile', true)
                      ?: get_user_meta($vendor_id, 'whatsapp', true);
        }

        return preg_replace('/[^0-9]/', '', $raw_phone);
    }

    /**
     * Hide standard Add to Cart button when configured
     */
    public function hide_add_to_cart_css() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        echo '<style id="hoktech-hide-add-to-cart">.single_add_to_cart_button:not(.hoktech-wa-product-btn) { display: none !important; }</style>';
    }
}
