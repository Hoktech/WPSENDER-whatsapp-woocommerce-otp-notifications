/**
 * HokTech Frontend OTP Verification + Country Code Selector
 * Supports both WooCommerce Blocks and Classic checkout
 */
(function ($) {
    'use strict';

    var otpSent = false;
    var otpTimer = null;
    var otpCooldown = 60; // seconds

    // ========== Country Code Selector ==========

    /**
     * Initialize country code selector on checkout page
     */
    function initCountrySelector() {
        if (!hoktechOTP.countrySelector || !hoktechOTP.countries) return;

        function tryInject() {
            // Find the billing phone field (Blocks or Classic)
            var $phoneField = $('input#phone, input[id*="shipping-phone"], input#billing_phone, input[name*="phone"]').first();
            if (!$phoneField.length) return false;

            // Don't inject twice
            if ($phoneField.closest('.hoktech-phone-wrapper').length) return true;

            // Build the selector HTML
            var defaultCode = hoktechOTP.defaultCountry || 'EG';
            var countries = hoktechOTP.countries;
            var defaultCountry = null;

            // Find default country data
            for (var i = 0; i < countries.length; i++) {
                if (countries[i].code === defaultCode) {
                    defaultCountry = countries[i];
                    break;
                }
            }
            if (!defaultCountry) defaultCountry = countries[0];

            // Try to detect from WooCommerce country dropdown
            var wcCountry = getWcCountry();
            if (wcCountry) {
                // Map IL to PS
                if (wcCountry === 'IL') wcCountry = 'PS';
                for (var j = 0; j < countries.length; j++) {
                    if (countries[j].code === wcCountry) {
                        defaultCountry = countries[j];
                        break;
                    }
                }
            }

            // Build options
            var optionsHtml = '';
            for (var k = 0; k < countries.length; k++) {
                var c = countries[k];
                var sel = (c.code === defaultCountry.code) ? ' selected' : '';
                optionsHtml += '<option value="' + c.code + '" data-dial="' + c.dial_code + '" data-flag="' + c.flag + '"' + sel + '>'
                    + c.flag + ' ' + c.name + ' (' + c.dial_code + ')</option>';
            }

            var selectorHtml =
                '<div class="hoktech-phone-wrapper">' +
                '<div class="hoktech-country-btn" id="hoktech-country-btn" tabindex="0" role="button" aria-haspopup="listbox" aria-label="اختر كود الدولة">' +
                '<span class="hoktech-country-flag">' + defaultCountry.flag + '</span>' +
                '<span class="hoktech-country-dial">' + defaultCountry.dial_code + '</span>' +
                '<svg class="hoktech-country-arrow" width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                '</div>' +
                '<div class="hoktech-country-dropdown" id="hoktech-country-dropdown" role="listbox" style="display:none;">' +
                '<div class="hoktech-country-search-wrap">' +
                '<input type="text" class="hoktech-country-search" id="hoktech-country-search" placeholder="ابحث عن دولة..." autocomplete="off" />' +
                '</div>' +
                '<div class="hoktech-country-list" id="hoktech-country-list">' +
                '</div>' +
                '</div>' +
                '<input type="hidden" id="hoktech-selected-country" name="hoktech_country_code" value="' + defaultCountry.code + '" />' +
                '<input type="hidden" id="hoktech-selected-dial" name="hoktech_dial_code" value="' + defaultCountry.dial_code + '" />' +
                '</div>';

            // Wrap the phone input
            var $parent = $phoneField.parent();
            $phoneField.detach();

            var $wrapper = $(selectorHtml);
            $wrapper.append($phoneField);
            $parent.prepend($wrapper);

            // Set phone input direction to LTR
            $phoneField.css({ 'direction': 'ltr', 'text-align': 'left', 'padding-left': '12px' });

            // Build the list items
            renderCountryList(countries, '');

            // Event: toggle dropdown
            $(document).on('click', '#hoktech-country-btn', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $dd = $('#hoktech-country-dropdown');
                if ($dd.is(':visible')) {
                    $dd.hide();
                } else {
                    $dd.show();
                    $('#hoktech-country-search').val('').focus();
                    renderCountryList(countries, '');
                }
            });

            // Event: search countries
            $(document).on('input', '#hoktech-country-search', function () {
                var query = $(this).val().toLowerCase();
                renderCountryList(countries, query);
            });

            // Event: select country from list
            $(document).on('click', '.hoktech-country-item', function () {
                var code = $(this).data('code');
                selectCountry(code, countries);
                $('#hoktech-country-dropdown').hide();
            });

            // Event: keyboard nav in search
            $(document).on('keydown', '#hoktech-country-search', function (e) {
                var $items = $('.hoktech-country-item:visible');
                var $active = $items.filter('.hoktech-country-active');
                if (e.keyCode === 40) { // Down
                    e.preventDefault();
                    if (!$active.length) {
                        $items.first().addClass('hoktech-country-active');
                    } else {
                        $active.removeClass('hoktech-country-active');
                        $active.next('.hoktech-country-item:visible').addClass('hoktech-country-active');
                    }
                } else if (e.keyCode === 38) { // Up
                    e.preventDefault();
                    if ($active.length) {
                        $active.removeClass('hoktech-country-active');
                        $active.prev('.hoktech-country-item:visible').addClass('hoktech-country-active');
                    }
                } else if (e.keyCode === 13) { // Enter
                    e.preventDefault();
                    if ($active.length) {
                        $active.trigger('click');
                    } else if ($items.length) {
                        $items.first().trigger('click');
                    }
                } else if (e.keyCode === 27) { // Escape
                    $('#hoktech-country-dropdown').hide();
                }
            });

            // Close dropdown on outside click
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.hoktech-phone-wrapper').length) {
                    $('#hoktech-country-dropdown').hide();
                }
            });

            // Listen to WooCommerce country changes
            $(document).on('change', '#billing_country, select[id*="country"], select[id*="region"]', function () {
                var newCountry = $(this).val();
                if (newCountry) {
                    if (newCountry === 'IL') newCountry = 'PS';
                    selectCountry(newCountry, countries);
                }
            });

            // WooCommerce Blocks: observe country changes via MutationObserver
            observeBlocksCountry(countries);

            initEgyptPhoneRestriction();

            return true;
        }

        // Try immediately, then retry for Blocks checkout
        if (!tryInject()) {
            var attempts = 0;
            var retryInterval = setInterval(function () {
                attempts++;
                if (tryInject() || attempts > 30) {
                    clearInterval(retryInterval);
                }
            }, 500);
        }
    }

    /**
     * Render country list items filtered by query
     */
    function renderCountryList(countries, query) {
        var $list = $('#hoktech-country-list');
        var html = '';
        var selectedCode = $('#hoktech-selected-country').val();

        for (var i = 0; i < countries.length; i++) {
            var c = countries[i];
            if (query && c.name.indexOf(query) === -1 && c.name_en.toLowerCase().indexOf(query) === -1
                && c.dial_code.indexOf(query) === -1 && c.code.toLowerCase().indexOf(query) === -1) {
                continue;
            }
            var active = (c.code === selectedCode) ? ' hoktech-country-selected' : '';
            html += '<div class="hoktech-country-item' + active + '" data-code="' + c.code + '" role="option">'
                + '<span class="hoktech-ci-flag">' + c.flag + '</span>'
                + '<span class="hoktech-ci-name">' + c.name + '</span>'
                + '<span class="hoktech-ci-dial">' + c.dial_code + '</span>'
                + '</div>';
        }

        if (!html) {
            html = '<div class="hoktech-country-empty">لا توجد نتائج</div>';
        }

        $list.html(html);
    }

    /**
     * Select a country by code
     */
    function selectCountry(code, countries) {
        for (var i = 0; i < countries.length; i++) {
            if (countries[i].code === code) {
                var c = countries[i];
                $('#hoktech-selected-country').val(c.code);
                $('#hoktech-selected-dial').val(c.dial_code);
                $('.hoktech-country-flag').text(c.flag);
                $('.hoktech-country-dial').text(c.dial_code);
                initEgyptPhoneRestriction();
                return;
            }
        }
    }

    /**
     * Get active country code
     */
    function getActiveCountry() {
        var c = $('#hoktech-selected-country').val() || getWcCountry();
        if (!c && typeof hoktechOTP !== 'undefined' && hoktechOTP.defaultCountry) {
            c = hoktechOTP.defaultCountry;
        }
        return c || 'EG';
    }

    /**
     * Clean and format input for Egyptian phone numbers (digits only, max 11 chars)
     */
    function cleanEgyptInput(val) {
        if (!val) return '';
        var digits = val.replace(/[^0-9]/g, '');
        // If user pasted with country code 201xxxxxxxxx (12 digits starting with 201)
        if (digits.length === 12 && digits.indexOf('201') === 0) {
            digits = '0' + digits.substring(2);
        }
        if (digits.length > 11) {
            digits = digits.substring(0, 11);
        }
        return digits;
    }

    /**
     * Show or clear inline error under phone field and add red border highlight
     */
    function setPhoneFieldError(errorMessage) {
        var $phoneField = $('input#phone, input[id*="shipping-phone"], input#billing_phone, input[name*="phone"], input#reg_phone').first();
        if (!$phoneField.length) return;

        var $wrapper = $phoneField.closest('.hoktech-phone-wrapper');
        var $targetContainer = $wrapper.length ? $wrapper : $phoneField;

        // Remove existing error message element if any
        $('.hoktech-phone-error-msg').remove();

        if (errorMessage) {
            $targetContainer.addClass('hoktech-has-error');
            $phoneField.addClass('hoktech-has-error');

            var errorHtml = '<div class="hoktech-phone-error-msg">' + errorMessage + '</div>';
            if ($wrapper.length) {
                $wrapper.after(errorHtml);
            } else {
                $phoneField.after(errorHtml);
            }
        } else {
            $targetContainer.removeClass('hoktech-has-error');
            $phoneField.removeClass('hoktech-has-error');
        }
    }

    /**
     * Apply phone number restrictions based on selected country (e.g., 11 digits for Egypt)
     */
    function initEgyptPhoneRestriction() {
        var $phoneFields = $('input#phone, input[id*="shipping-phone"], input#billing_phone, input[name*="phone"], input#reg_phone');
        if (!$phoneFields.length) return;

        $phoneFields.each(function () {
            var $field = $(this);

            function updateFieldState() {
                var country = getActiveCountry();
                if (country === 'EG') {
                    $field.attr('maxlength', '11');
                    var currentVal = $field.val();
                    if (currentVal) {
                        var cleaned = cleanEgyptInput(currentVal);
                        if (cleaned !== currentVal) {
                            $field.val(cleaned);
                        }
                    }
                } else {
                    $field.attr('maxlength', '15');
                    setPhoneFieldError(null);
                }
            }

            if (!$field.data('hoktech-bound')) {
                $field.data('hoktech-bound', true);

                $field.on('input propertychange paste keyup blur', function () {
                    var country = getActiveCountry();
                    if (country === 'EG') {
                        var raw = $(this).val();
                        var cleaned = cleanEgyptInput(raw);
                        if (raw !== cleaned) {
                            $(this).val(cleaned);
                        }
                        var digits = cleaned.replace(/[^0-9]/g, '');
                        if (digits.length === 0) {
                            setPhoneFieldError(null);
                        } else if (digits.length < 11) {
                            setPhoneFieldError('رقم الهاتف المصري يجب أن يتكون من 11 رقم (مثال: 01xxxxxxxx)');
                        } else if (digits.length === 11) {
                            if (digits.indexOf('01') !== 0) {
                                setPhoneFieldError('رقم الهاتف المصري يجب أن يبدأ بـ (01)');
                            } else {
                                setPhoneFieldError(null);
                            }
                        }
                    } else {
                        setPhoneFieldError(null);
                    }
                });
            }

            updateFieldState();
        });
    }

    /**
     * Get current WooCommerce country value
     */
    function getWcCountry() {
        // Classic checkout
        var val = $('#billing_country').val();
        if (val) return val;

        // Blocks checkout - try to read from the selector
        val = $('select[id*="country"]').first().val();
        if (val) return val;

        // Try combobox value
        var $comboInput = $('input[id*="country"]').first();
        if ($comboInput.length) {
            val = $comboInput.val();
            if (val && val.length === 2) return val;
        }

        return '';
    }

    /**
     * Observe WooCommerce Blocks country changes
     */
    function observeBlocksCountry(countries) {
        if (typeof MutationObserver === 'undefined') return;

        var observer = new MutationObserver(function () {
            var wc = getWcCountry();
            if (wc) {
                if (wc === 'IL') wc = 'PS';
                var currentSel = $('#hoktech-selected-country').val();
                if (wc !== currentSel) {
                    selectCountry(wc, countries);
                }
            }
            initEgyptPhoneRestriction();
        });

        // Observe the checkout form for changes
        var checkoutForm = document.querySelector('.wc-block-checkout, .woocommerce-checkout, #customer_details');
        if (checkoutForm) {
            observer.observe(checkoutForm, { childList: true, subtree: true, attributes: true, attributeFilter: ['value'] });
        }
    }

    // ========== Phone Formatting ==========

    /**
     * Get the phone number from either Blocks or Classic checkout fields
     * Formats with country code if selector is enabled
     */
    function getCheckoutPhone() {
        var phone = '';

        // WooCommerce Blocks phone field
        phone = $('input#phone, input[id*="phone"], input#shipping-phone').val();
        if (!phone) {
            // Classic checkout phone field
            phone = $('#billing_phone').val();
        }
        if (!phone) {
            // Fallback: any input with name containing "phone"
            phone = $('input[name*="phone"]').first().val();
        }
        phone = phone || '';

        // Format with country code if selector is active
        if (hoktechOTP.countrySelector && $('#hoktech-selected-dial').length) {
            phone = formatPhoneWithCountry(phone);
        }

        return phone;
    }

    /**
     * Format phone number with country dial code
     * Strips leading zero, prepends dial digits
     */
    function formatPhoneWithCountry(phone) {
        var dialCode = $('#hoktech-selected-dial').val();
        if (!dialCode) return phone;

        // Get only digits from dial code (e.g., "+20" -> "20")
        var dialDigits = dialCode.replace(/[^0-9]/g, '');

        // Get only digits from phone
        var phoneDigits = phone.replace(/[^0-9]/g, '');

        // If phone already starts with the dial code, return as-is
        if (phoneDigits.indexOf(dialDigits) === 0) {
            return phoneDigits;
        }

        // Strip leading zero(s) from local number
        phoneDigits = phoneDigits.replace(/^0+/, '');

        // Prepend country dial digits
        return dialDigits + phoneDigits;
    }

    // ========== Checkout OTP ==========
    $(document).on('click', '#hoktech-send-checkout-otp', function () {
        var country = getActiveCountry();
        var $phoneField = $('input#phone, input[id*="phone"], input#billing_phone, input[name*="phone"]').first();
        var rawPhone = $phoneField.val() || '';
        if (country === 'EG') {
            var digits = rawPhone.replace(/[^0-9]/g, '');
            if (digits.length !== 11 || digits.indexOf('01') !== 0) {
                var msg = (digits.length === 11 && digits.indexOf('01') !== 0)
                    ? 'رقم الهاتف المصري يجب أن يبدأ بـ (01)'
                    : 'رقم الهاتف المصري يجب أن يتكون من 11 رقم (مثال: 01xxxxxxxx)';
                setPhoneFieldError(msg);
                $phoneField.focus();
                return;
            }
        }
        setPhoneFieldError(null);
        var phone = getCheckoutPhone();
        if (!phone) {
            setPhoneFieldError('يرجى إدخال رقم الهاتف في حقل الفاتورة أولاً');
            $phoneField.focus();
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
        var country = getActiveCountry();
        var $phoneField = $('#reg_phone');
        var phone = $phoneField.val() || '';
        if (country === 'EG') {
            var digits = phone.replace(/[^0-9]/g, '');
            if (digits.length !== 11 || digits.indexOf('01') !== 0) {
                var msg = (digits.length === 11 && digits.indexOf('01') !== 0)
                    ? 'رقم الهاتف المصري يجب أن يبدأ بـ (01)'
                    : 'رقم الهاتف المصري يجب أن يتكون من 11 رقم (مثال: 01xxxxxxxx)';
                setPhoneFieldError(msg);
                $phoneField.focus();
                return;
            }
        }
        if (!phone) {
            setPhoneFieldError('يرجى إدخال رقم الهاتف أولاً');
            $phoneField.focus();
            return;
        }
        setPhoneFieldError(null);
        sendOTP(phone, 'registration');
    });

    $(document).on('click', '#hoktech-verify-reg-otp', function () {
        var phone = $('#reg_phone').val();
        var code = $('#hoktech-reg-otp-code').val();
        if (!code) return;
        verifyOTP(phone, code, 'registration');
    });

    // Validate Egyptian phone on checkout submission
    $(document).on('checkout_place_order', function () {
        var country = getActiveCountry();
        if (country === 'EG') {
            var $phoneField = $('input#phone, input[id*="phone"], input#billing_phone, input[name*="phone"]').first();
            var rawPhone = $phoneField.val() || '';
            var digits = rawPhone.replace(/[^0-9]/g, '');
            if (digits.length !== 11 || digits.indexOf('01') !== 0) {
                var msg = (digits.length === 11 && digits.indexOf('01') !== 0)
                    ? 'رقم الهاتف المصري يجب أن يبدأ بـ (01)'
                    : 'رقم الهاتف المصري يجب أن يتكون من 11 رقم (مثال: 01xxxxxxxx)';
                setPhoneFieldError(msg);
                if ($phoneField.length) {
                    $phoneField.focus();
                    $('html, body').animate({ scrollTop: $phoneField.offset().top - 120 }, 300);
                }
                return false;
            } else {
                setPhoneFieldError(null);
            }
        }
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

    // ========== Initialize ==========
    $(document).ready(function () {
        initCountrySelector();
        initEgyptPhoneRestriction();
    });

    // Also try on WooCommerce Blocks ready
    $(document.body).on('updated_checkout', function () {
        initCountrySelector();
        initEgyptPhoneRestriction();
    });

})(jQuery);
