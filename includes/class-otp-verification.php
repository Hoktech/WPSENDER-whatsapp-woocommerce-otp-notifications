<?php
/**
 * sender OTP Verification
 * Adds phone verification via OTP at WooCommerce checkout and WordPress registration
 * Supports BOTH WooCommerce Blocks checkout AND Classic checkout shortcode
 */

if (!defined('ABSPATH')) {
    exit;
}

class HokTech_OTP_Verification {

    private $api;

    public function __construct() {
        $this->api = new HokTech_API_Client();

        $settings = get_option('hoktech_wa_otp_settings', []);

        // Checkout OTP — works with both Blocks and Classic checkout
        if (!empty($settings['enable_checkout_otp']) && $this->api->is_connected()) {
            // Render OTP field via wp_footer so it works for BOTH Blocks and Classic
            add_action('wp_footer', [$this, 'render_checkout_otp_field']);

            // Classic checkout validation hooks
            add_action('woocommerce_checkout_process', [$this, 'validate_checkout_otp']);
            add_action('woocommerce_after_checkout_validation', [$this, 'validate_checkout_otp_array'], 10, 2);

            // WooCommerce Blocks (Store API) validation hook
            add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'validate_blocks_checkout_otp'], 10, 2);

            // Block the order from being created if OTP not verified (works for Blocks)
            add_action('woocommerce_checkout_order_created', [$this, 'check_otp_on_order_created']);
        }

        // Registration OTP
        if (!empty($settings['enable_registration_otp']) && $this->api->is_connected()) {
            add_action('woocommerce_register_form', [$this, 'add_registration_otp_field']);
            add_filter('woocommerce_registration_errors', [$this, 'validate_registration_otp'], 10, 3);
        }

        // AJAX handlers (work for both checkout types)
        add_action('wp_ajax_hoktech_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_hoktech_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_hoktech_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_hoktech_verify_otp', [$this, 'ajax_verify_otp']);
    }

    /**
     * Check if OTP has been verified for the current session (server-side)
     */
    private function is_otp_verified() {
        // Check WooCommerce session first (preferred)
        if (function_exists('WC') && WC()->session) {
            $verified = WC()->session->get('hoktech_otp_verified');
            if ($verified === 'yes') {
                return true;
            }
        }

        // Fallback: check PHP session cookie
        if (isset($_COOKIE['hoktech_otp_verified']) && $_COOKIE['hoktech_otp_verified'] === '1') {
            return true;
        }

        // Fallback: check hidden form field (classic checkout only)
        $nonce = isset($_POST['hoktech_otp_nonce']) ? sanitize_text_field(wp_unslash($_POST['hoktech_otp_nonce'])) : '';
        if (isset($_POST['hoktech_otp_verified']) && !empty($nonce) && wp_verify_nonce($nonce, 'hoktech_otp_nonce') && $_POST['hoktech_otp_verified'] === '1') {
            return true;
        }

        return false;
    }

    /**
     * Mark OTP as verified in the server session
     */
    private function mark_otp_verified() {
        // Store in WooCommerce session
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('hoktech_otp_verified', 'yes');
        }
    }

    /**
     * Clear OTP verification after successful order
     */
    private function clear_otp_verification() {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('hoktech_otp_verified', null);
        }
    }

    /**
     * Render OTP field on checkout page via wp_footer (works for BOTH Blocks and Classic)
     */
    public function render_checkout_otp_field() {
        // Only render on the checkout page
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        // Output the OTP HTML
        $this->add_checkout_otp_field();

        // Inline JS to move the OTP div into the correct position
        ?>
        <script>
        (function() {
            function placeOTP() {
                var otpEl = document.getElementById('hoktech-otp-checkout');
                if (!otpEl) return;

                // WooCommerce Blocks: insert after payment options
                var blocksPayment = document.querySelector('.wc-block-checkout__actions, .wp-block-woocommerce-checkout-actions-block, .wc-block-components-checkout-place-order-button');
                if (blocksPayment) {
                    blocksPayment.parentNode.insertBefore(otpEl, blocksPayment);
                    otpEl.style.display = '';
                    return true;
                }

                // WooCommerce Blocks: try the payment methods section
                var blocksPaymentMethods = document.querySelector('.wc-block-checkout__payment-method, .wp-block-woocommerce-checkout-payment-block');
                if (blocksPaymentMethods) {
                    blocksPaymentMethods.parentNode.insertBefore(otpEl, blocksPaymentMethods.nextSibling);
                    otpEl.style.display = '';
                    return true;
                }

                // Classic checkout: insert after #payment
                var classicPayment = document.querySelector('#payment');
                if (classicPayment) {
                    classicPayment.parentNode.insertBefore(otpEl, classicPayment.nextSibling);
                    otpEl.style.display = '';
                    return true;
                }

                return false;
            }

            // Try immediately
            if (!placeOTP()) {
                // Blocks checkout renders async, retry after short delays
                var attempts = 0;
                var retryInterval = setInterval(function() {
                    attempts++;
                    if (placeOTP() || attempts > 20) {
                        clearInterval(retryInterval);
                    }
                }, 500);
            }
        })();
        </script>
        <?php
    }

    /**
     * Add OTP verification field HTML (works for both Blocks and Classic)
     */
    public function add_checkout_otp_field() {
        // Skip if already verified in this session
        $already_verified = $this->is_otp_verified();
        ?>
        <div id="hoktech-otp-checkout" class="hoktech-otp-section <?php echo $already_verified ? 'hoktech-otp-verified' : ''; ?>">
            <h3><?php esc_html_e('التحقق من رقم الهاتف', 'sender-notification'); ?></h3>
            <p class="hoktech-otp-description"><?php esc_html_e('يرجى التحقق من رقم هاتفك لإتمام الطلب', 'sender-notification'); ?></p>
            <?php if ($already_verified): ?>
                <div id="hoktech-otp-status" class="hoktech-otp-status">
                    <span style="color:#22c55e;">✅ <?php esc_html_e('تم التحقق بنجاح', 'sender-notification'); ?></span>
                </div>
            <?php else: ?>
                <div class="hoktech-otp-actions">
                    <button type="button" id="hoktech-send-checkout-otp" class="button hoktech-otp-btn">
                        <?php esc_html_e('إرسال رمز التحقق', 'sender-notification'); ?>
                    </button>
                </div>
                <div id="hoktech-otp-input-section" style="display:none;">
                    <div class="hoktech-otp-input-group">
                        <input type="text" id="hoktech-otp-code" name="hoktech_otp_code" maxlength="6" placeholder="<?php esc_attr_e('أدخل رمز التحقق', 'sender-notification'); ?>" class="hoktech-otp-input">
                        <button type="button" id="hoktech-verify-checkout-otp" class="button hoktech-otp-verify-btn">
                            <?php esc_html_e('تأكيد', 'sender-notification'); ?>
                        </button>
                    </div>
                    <p class="hoktech-otp-timer"></p>
                </div>
                <div id="hoktech-otp-status" class="hoktech-otp-status"></div>
            <?php endif; ?>
            <?php wp_nonce_field('hoktech_otp_nonce', 'hoktech_otp_nonce'); ?>
            <input type="hidden" id="hoktech-otp-verified" name="hoktech_otp_verified" value="<?php echo $already_verified ? '1' : '0'; ?>">
        </div>
        <?php
    }

    /**
     * Validate OTP at checkout — Classic checkout (woocommerce_checkout_process)
     */
    public function validate_checkout_otp() {
        $settings = get_option('hoktech_wa_otp_settings', []);
        if (empty($settings['enable_checkout_otp'])) {
            return;
        }

        if (!$this->is_otp_verified()) {
            wc_add_notice(__('يرجى التحقق من رقم هاتفك قبل إتمام الطلب', 'sender-notification'), 'error');
        }
    }

    /**
     * Validate OTP at checkout — Classic checkout fallback (woocommerce_after_checkout_validation)
     */
    public function validate_checkout_otp_array($data, $errors) {
        $settings = get_option('hoktech_wa_otp_settings', []);
        if (empty($settings['enable_checkout_otp'])) {
            return;
        }

        if (!$this->is_otp_verified()) {
            $errors->add('otp_error', __('يرجى التحقق من رقم هاتفك قبل إتمام الطلب', 'sender-notification'));
        }
    }

    /**
     * Validate OTP — WooCommerce Blocks checkout (Store API)
     */
    public function validate_blocks_checkout_otp($order, $request) {
        $settings = get_option('hoktech_wa_otp_settings', []);
        if (empty($settings['enable_checkout_otp'])) {
            return;
        }

        if (!$this->is_otp_verified()) {
            throw new \Exception(esc_html__('يرجى التحقق من رقم هاتفك قبل إتمام الطلب', 'sender-notification'));
        }
    }

    /**
     * Final check when order is created (works as safety net for both checkout types)
     */
    public function check_otp_on_order_created($order) {
        $settings = get_option('hoktech_wa_otp_settings', []);
        if (empty($settings['enable_checkout_otp'])) {
            return;
        }

        // If verified, clear the session so next order requires new verification
        if ($this->is_otp_verified()) {
            $this->clear_otp_verification();
        }
    }

    /**
     * Add OTP field to registration form
     */
    public function add_registration_otp_field() {
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
            <?php wp_nonce_field('hoktech_otp_nonce', 'hoktech_otp_nonce'); ?>
            <label for="reg_phone"><?php esc_html_e('رقم الهاتف', 'sender-notification'); ?>&nbsp;<span class="required">*</span></label>
            <?php 
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $phone_val = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : ''; 
            ?>
            <input type="tel" class="woocommerce-Input woocommerce-Input--text input-text" name="phone" id="reg_phone" value="<?php echo esc_attr($phone_val); ?>" required />
        </p>
        <div id="hoktech-reg-otp-section" class="hoktech-otp-section">
            <button type="button" id="hoktech-send-reg-otp" class="button hoktech-otp-btn"><?php esc_html_e('إرسال رمز التحقق', 'sender-notification'); ?></button>
            <div id="hoktech-reg-otp-input" style="display:none;">
                <input type="text" name="hoktech_reg_otp_code" id="hoktech-reg-otp-code" maxlength="6" placeholder="<?php esc_attr_e('رمز التحقق', 'sender-notification'); ?>" class="hoktech-otp-input">
                <button type="button" id="hoktech-verify-reg-otp" class="button hoktech-otp-verify-btn"><?php esc_html_e('تأكيد', 'sender-notification'); ?></button>
            </div>
            <input type="hidden" name="hoktech_reg_otp_verified" id="hoktech-reg-otp-verified" value="0">
            <div id="hoktech-reg-otp-status" class="hoktech-otp-status"></div>
        </div>
        <?php
    }

    /**
     * Validate OTP on registration
     */
    public function validate_registration_otp($errors, $username, $email) {
        $settings = get_option('hoktech_wa_otp_settings', []);
        if (empty($settings['enable_registration_otp'])) {
            return $errors;
        }

        $nonce = isset($_POST['hoktech_otp_nonce']) ? sanitize_text_field(wp_unslash($_POST['hoktech_otp_nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'hoktech_otp_nonce')) {
            $errors->add('otp_error', __('فشل التحقق من أمان الجلسة.', 'sender-notification'));
            return $errors;
        }

        $verified = isset($_POST['hoktech_reg_otp_verified']) ? sanitize_text_field(wp_unslash($_POST['hoktech_reg_otp_verified'])) : '0';

        if ($verified !== '1') {
            $errors->add('otp_error', __('يرجى التحقق من رقم هاتفك لإكمال التسجيل', 'sender-notification'));
        }

        return $errors;
    }

    /**
     * AJAX: Send OTP
     */
    public function ajax_send_otp() {
        check_ajax_referer('hoktech_otp_nonce', 'nonce');

        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));

        if (empty($phone)) {
            wp_send_json_error(['message' => __('رقم الهاتف مطلوب', 'sender-notification')]);
        }

        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Get custom OTP message
        $settings = get_option('hoktech_wa_otp_settings', []);
        $custom_message = !empty($settings['otp_message']) ? $settings['otp_message'] : null;

        $result = $this->api->send_otp($phone, $custom_message);

        if ($result['success']) {
            // Store phone in transient for verification
            $session_key = 'hoktech_otp_' . md5($phone . wp_get_session_token());
            set_transient($session_key, $phone, 10 * MINUTE_IN_SECONDS);

            wp_send_json_success(['message' => __('تم إرسال رمز التحقق إلى رقمك', 'sender-notification')]);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX: Verify OTP
     * Stores verification state in WooCommerce session (server-side)
     */
    public function ajax_verify_otp() {
        check_ajax_referer('hoktech_otp_nonce', 'nonce');

        $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $code  = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));

        if (empty($phone) || empty($code)) {
            wp_send_json_error(['message' => __('رقم الهاتف ورمز التحقق مطلوبان', 'sender-notification')]);
        }

        $phone = preg_replace('/[^0-9]/', '', $phone);

        $result = $this->api->verify_otp($phone, $code);

        if ($result['success']) {
            // Store verification in WooCommerce session (server-side)
            $this->mark_otp_verified();

            wp_send_json_success(['message' => __('تم التحقق بنجاح', 'sender-notification')]);
        } else {
            wp_send_json_error($result);
        }
    }
}
