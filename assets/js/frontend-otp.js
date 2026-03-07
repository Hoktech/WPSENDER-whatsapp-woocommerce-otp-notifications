/**
 * HokTech Frontend OTP Verification
 * Supports both WooCommerce Blocks and Classic checkout
 */
(function ($) {
    'use strict';

    var otpSent = false;
    var otpTimer = null;
    var otpCooldown = 60; // seconds

    /**
     * Get the phone number from either Blocks or Classic checkout fields
     */
    function getCheckoutPhone() {
        // WooCommerce Blocks phone field
        var phone = $('input#phone, input[id*="phone"], input#shipping-phone').val();
        if (phone) return phone;

        // Classic checkout phone field
        phone = $('#billing_phone').val();
        if (phone) return phone;

        // Fallback: any input with name containing "phone"
        phone = $('input[name*="phone"]').first().val();
        return phone || '';
    }

    // ========== Checkout OTP ==========
    $(document).on('click', '#hoktech-send-checkout-otp', function () {
        var phone = getCheckoutPhone();
        if (!phone) {
            alert('يرجى إدخال رقم الهاتف في حقل الفاتورة أولاً');
            $('input[id*="phone"], #billing_phone').first().focus();
            return;
        }
        sendOTP(phone, 'checkout');
    });

    $(document).on('click', '#hoktech-verify-checkout-otp', function () {
        var phone = getCheckoutPhone();
        var code = $('#hoktech-otp-code').val();
        if (!code) return;
        verifyOTP(phone, code, 'checkout');
    });

    // ========== Registration OTP ==========
    $(document).on('click', '#hoktech-send-reg-otp', function () {
        var phone = $('#reg_phone').val();
        if (!phone) {
            alert('يرجى إدخال رقم الهاتف أولاً');
            $('#reg_phone').focus();
            return;
        }
        sendOTP(phone, 'registration');
    });

    $(document).on('click', '#hoktech-verify-reg-otp', function () {
        var phone = $('#reg_phone').val();
        var code = $('#hoktech-reg-otp-code').val();
        if (!code) return;
        verifyOTP(phone, code, 'registration');
    });

    // ========== Send OTP ==========
    function sendOTP(phone, context) {
        var $btn = context === 'checkout' ? $('#hoktech-send-checkout-otp') : $('#hoktech-send-reg-otp');
        var $status = context === 'checkout' ? $('#hoktech-otp-status') : $('#hoktech-reg-otp-status');
        var $inputSection = context === 'checkout' ? $('#hoktech-otp-input-section') : $('#hoktech-reg-otp-input');

        $btn.prop('disabled', true).text('جاري الإرسال...');
        $status.html('').hide();

        $.post(hoktechOTP.ajaxUrl, {
            action: 'hoktech_send_otp',
            nonce: hoktechOTP.nonce,
            phone: phone
        }, function (response) {
            if (response.success) {
                $status.html('<span style="color:#22c55e;">✅ ' + response.data.message + '</span>').show();
                $inputSection.slideDown();
                startCooldown($btn);
                otpSent = true;
            } else {
                $status.html('<span style="color:#ef4444;">❌ ' + (response.data?.message || 'فشل الإرسال') + '</span>').show();
                $btn.prop('disabled', false).text('إرسال رمز التحقق');
            }
        }).fail(function () {
            $status.html('<span style="color:#ef4444;">❌ حدث خطأ في الاتصال</span>').show();
            $btn.prop('disabled', false).text('إرسال رمز التحقق');
        });
    }

    // ========== Verify OTP ==========
    function verifyOTP(phone, code, context) {
        var $verifyBtn = context === 'checkout' ? $('#hoktech-verify-checkout-otp') : $('#hoktech-verify-reg-otp');
        var $status = context === 'checkout' ? $('#hoktech-otp-status') : $('#hoktech-reg-otp-status');
        var $verifiedInput = context === 'checkout' ? $('#hoktech-otp-verified') : $('#hoktech-reg-otp-verified');
        var $section = context === 'checkout' ? $('#hoktech-otp-checkout') : $('#hoktech-reg-otp-section');

        $verifyBtn.prop('disabled', true).text('جاري التحقق...');

        $.post(hoktechOTP.ajaxUrl, {
            action: 'hoktech_verify_otp',
            nonce: hoktechOTP.nonce,
            phone: phone,
            code: code
        }, function (response) {
            if (response.success) {
                // Update hidden field (for classic checkout form submission)
                $verifiedInput.val('1');

                // Add verified class (triggers CSS animations to hide inputs)
                $section.addClass('hoktech-otp-verified');

                // Replace status with prominent success banner
                $status.html(
                    '<div class="hoktech-otp-success-banner">' +
                        '<div class="hoktech-success-icon">✓</div>' +
                        '<span class="hoktech-success-text">تم التحقق بنجاح ✅</span>' +
                    '</div>'
                ).show();

                // Clear the cooldown timer
                if (otpTimer) {
                    clearInterval(otpTimer);
                }
            } else {
                $status.html('<span style="color:#ef4444;">❌ ' + (response.data?.message || 'رمز التحقق غير صحيح') + '</span>').show();
                $verifyBtn.prop('disabled', false).text('تأكيد');
            }
        }).fail(function () {
            $status.html('<span style="color:#ef4444;">❌ حدث خطأ</span>').show();
            $verifyBtn.prop('disabled', false).text('تأكيد');
        });
    }

    // ========== Cooldown Timer ==========
    function startCooldown($btn) {
        var seconds = otpCooldown;
        $btn.prop('disabled', true);

        otpTimer = setInterval(function () {
            seconds--;
            $btn.text('إعادة الإرسال (' + seconds + ')');

            if (seconds <= 0) {
                clearInterval(otpTimer);
                $btn.prop('disabled', false).text('إعادة إرسال الرمز');
            }
        }, 1000);
    }

})(jQuery);
