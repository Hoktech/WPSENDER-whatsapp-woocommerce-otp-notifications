/**
 * HokTech Admin JavaScript
 */
(function ($) {
    'use strict';

    // ========== Tab Switching with Memory ==========
    function switchTab(tab) {
        if (!tab) return;
        var $targetBtn = $('.hoktech-tab[data-tab="' + tab + '"]');
        var $targetContent = $('#tab-' + tab);

        if ($targetBtn.length && $targetContent.length) {
            $('.hoktech-tab').removeClass('active');
            $targetBtn.addClass('active');

            $('.hoktech-tab-content').removeClass('active');
            $targetContent.addClass('active');

            if (tab === 'vendors' && $('#hoktech-vendor-session-select').length) {
                loadVendorSessions(false);
            }

            try {
                localStorage.setItem('hoktech_admin_active_tab', tab);
            } catch (err) {}
        }
    }

    $(document).on('click', '.hoktech-tab', function () {
        var tab = $(this).data('tab');
        switchTab(tab);
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, null, '#tab=' + tab);
        }
    });

    // Auto-restore active tab on load
    $(document).ready(function () {
        var tabToOpen = '';
        var match = window.location.href.match(/[?&]tab=([^&#]+)/);
        if (match) {
            tabToOpen = match[1];
        } else if (window.location.hash) {
            var hashMatch = window.location.hash.match(/tab=([^&]+)/);
            if (hashMatch) {
                tabToOpen = hashMatch[1];
            } else {
                tabToOpen = window.location.hash.replace('#', '');
            }
        }

        if (!tabToOpen) {
            try {
                tabToOpen = localStorage.getItem('hoktech_admin_active_tab');
            } catch (err) {}
        }

        if (tabToOpen) {
            switchTab(tabToOpen);
        }
    });

    // ========== Connection Method Tabs ==========
    $(document).on('click', '.hoktech-method-tab', function () {
        var method = $(this).data('method');

        $('.hoktech-method-tab').removeClass('active');
        $(this).addClass('active');

        $('.hoktech-method-content').removeClass('active');
        $('#method-' + method).addClass('active');
    });

    // ========== Login Form ==========
    $(document).on('submit', '#hoktech-login-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('.hoktech-btn-connect');
        var $result = $('#hoktech-login-result');
        var originalText = $btn.html();

        $btn.prop('disabled', true).html(
            '<span class="dashicons dashicons-update"></span> ' + hoktechWA.strings.connecting + '<span class="hoktech-loading"></span>'
        );
        $result.hide();

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_login',
            nonce: hoktechWA.nonce,
            api_url: $('#hoktech-api-url').val(),
            email: $('#hoktech-email').val(),
            password: $('#hoktech-password').val()
        }, function (response) {
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () {
                    location.reload();
                }, 1000);
            } else {
                $result.html('❌ ' + (response.data?.message || hoktechWA.strings.error)).removeClass('success').addClass('error').fadeIn();
                $btn.prop('disabled', false).html(originalText);
            }
        }).fail(function () {
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
            $btn.prop('disabled', false).html(originalText);
        });
    });

    // ========== Manual API Key Form ==========
    $(document).on('submit', '#hoktech-manual-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('.hoktech-btn-connect');
        var $result = $('#hoktech-manual-result');
        var originalText = $btn.html();

        $btn.prop('disabled', true).html(
            '<span class="dashicons dashicons-update"></span> ' + hoktechWA.strings.connecting + '<span class="hoktech-loading"></span>'
        );
        $result.hide();

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_manual_connect',
            nonce: hoktechWA.nonce,
            api_url: $('#hoktech-manual-url').val(),
            api_key: $('#hoktech-manual-key').val()
        }, function (response) {
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () {
                    location.reload();
                }, 1000);
            } else {
                $result.html('❌ ' + (response.data?.message || hoktechWA.strings.error)).removeClass('success').addClass('error').fadeIn();
                $btn.prop('disabled', false).html(originalText);
            }
        }).fail(function () {
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
            $btn.prop('disabled', false).html(originalText);
        });
    });

    // ========== Disconnect ==========
    $(document).on('click', '#hoktech-disconnect', function () {
        if (!confirm(hoktechWA.strings.confirm_disconnect)) return;

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_disconnect',
            nonce: hoktechWA.nonce
        }, function (response) {
            if (response.success) {
                location.reload();
            }
        });
    });

    // ========== Load Sessions ==========
    function loadSessions(force) {
        var $select = $('#hoktech-session-select');
        var currentSession = $('#hoktech-current-session').val();

        $select.html('<option value="">-- ' + hoktechWA.strings.connecting + ' --</option>');

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_get_sessions',
            nonce: hoktechWA.nonce,
            force: force ? 'true' : 'false'
        }, function (response) {
            if (response.success && response.data.sessions) {
                var sessions = response.data.sessions;
                var html = '<option value="">-- اختر جلسة --</option>';

                if (Array.isArray(sessions)) {
                    sessions.forEach(function (session) {
                        var sessionId = session.session_id || session.id;
                        var label = session.session_id || session.name || session.id;
                        var status = session.status || '';
                        var statusBadge = '';

                        if (status === 'connected' || status === 'CONNECTED') {
                            statusBadge = ' ✅';
                        } else if (status === 'disconnected' || status === 'DISCONNECTED') {
                            statusBadge = ' ❌';
                        }

                        var phone = session.phone_number || session.phoneNumber || '';
                        if (phone) label += ' (' + phone + ')';

                        var selected = (sessionId == currentSession) ? ' selected' : '';
                        html += '<option value="' + sessionId + '"' + selected + '>' + label + statusBadge + '</option>';
                    });
                }

                $select.html(html);
            } else {
                $select.html('<option value="">لا توجد جلسات</option>');
            }
        }).fail(function () {
            $select.html('<option value="">فشل تحميل الجلسات</option>');
        });
    }

    // Auto-load sessions when page loads (if connected)
    if ($('#hoktech-session-select').length) {
        loadSessions(false);
    }

    // Refresh sessions button
    $(document).on('click', '#hoktech-refresh-sessions', function () {
        loadSessions(true);
    });

    // Save session selection
    $(document).on('click', '#hoktech-save-session', function () {
        var $btn = $(this);
        var sessionId = $('#hoktech-session-select').val();

        $btn.prop('disabled', true);

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_save_session',
            nonce: hoktechWA.nonce,
            session_id: sessionId
        }, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $btn.html('<span class="dashicons dashicons-yes"></span> ' + hoktechWA.strings.sent);
                setTimeout(function () {
                    $btn.html('<span class="dashicons dashicons-saved"></span> حفظ');
                }, 2000);
            }
        });
    });

    // ========== Vendor Session Selector ==========
    function loadVendorSessions(force) {
        var $select = $('#hoktech-vendor-session-select');
        if (!$select.length) return;

        var currentSession = $('#hoktech-vendor-current-session').val();
        $select.html('<option value="">-- ' + hoktechWA.strings.connecting + ' --</option>');

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_get_sessions',
            nonce: hoktechWA.nonce,
            force: force ? 'true' : 'false'
        }, function (response) {
            if (response.success && response.data.sessions) {
                var sessions = response.data.sessions;
                var defaultLabel = currentSession
                    ? '-- تغيير الجلسة (محفوظة: ' + currentSession + ') --'
                    : '-- نفس جلسة العملاء (افتراضي) --';
                var html = '<option value="">' + defaultLabel + '</option>';

                if (Array.isArray(sessions)) {
                    sessions.forEach(function (session) {
                        var sessionId = session.session_id || session.id;
                        var label = session.session_id || session.name || session.id;
                        var status = session.status || '';
                        var statusBadge = (status === 'connected' || status === 'CONNECTED') ? ' ✅' :
                                          (status === 'disconnected' || status === 'DISCONNECTED') ? ' ❌' : '';
                        var phone = session.phone_number || session.phoneNumber || '';
                        if (phone) label += ' (' + phone + ')';
                        var selected = (sessionId == currentSession) ? ' selected' : '';
                        html += '<option value="' + sessionId + '"' + selected + '>' + label + statusBadge + '</option>';
                    });
                }

                $select.html(html);
            } else {
                $select.html('<option value="">لا توجد جلسات متاحة</option>');
            }
        }).fail(function () {
            $select.html('<option value="">فشل تحميل الجلسات</option>');
        });
    }

    // Auto-load vendor sessions on page load if the selector exists
    if ($('#hoktech-vendor-session-select').length) {
        loadVendorSessions(false);
    }

    // Refresh vendor sessions
    $(document).on('click', '#hoktech-vendor-refresh-sessions', function () {
        loadVendorSessions(true);
    });

    // Save vendor session
    $(document).on('click', '#hoktech-vendor-save-session', function () {
        var $btn = $(this);
        var $result = $('#hoktech-vendor-session-result');
        var originalText = $btn.html();
        var vendorSessionId = $('#hoktech-vendor-session-select').val();

        $btn.prop('disabled', true);
        $result.hide();

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_save_vendor_session',
            nonce: hoktechWA.nonce,
            vendor_session_id: vendorSessionId
        }, function (response) {
            $btn.prop('disabled', false).html(originalText);
            if (response.success) {
                $('#hoktech-vendor-current-session').val(vendorSessionId);
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 3000);
            } else {
                $result.html('❌ ' + (response.data?.message || hoktechWA.strings.error)).removeClass('success').addClass('error').fadeIn();
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(originalText);
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
        });
    });

    // ========== Save Notification Settings ==========
    $(document).on('submit', '#hoktech-notifications-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('button[type="submit"]');
        var $result = $('#hoktech-notifications-result');

        $btn.prop('disabled', true);

        var deliverySettings = {
            default_estimated_delivery: $('#hoktech-default-estimated-delivery').val() || '',
            custom_meta_key: $('#hoktech-custom-meta-key').val() || ''
        };

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_save_notifications',
            nonce: hoktechWA.nonce,
            notifications: collectNotificationData(),
            delivery_settings: deliverySettings
        }, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 3000);
            } else {
                $result.html('❌ ' + (response.data?.message || 'خطأ')).removeClass('success').addClass('error').fadeIn();
            }
        });
    });

    function collectNotificationData() {
        var data = {};
        $('#hoktech-notifications-form .hoktech-notification-item').each(function () {
            var $item = $(this);
            // Get the status from the enabled checkbox
            var $enabledCheckbox = $item.find('input[type="checkbox"][name$="[enabled]"]');
            var name = $enabledCheckbox.attr('name');
            if (!name) return; // Skip if no name

            var match = name.match(/notifications\[(.+?)\]/);
            if (match) {
                var status = match[1];
                var $sendImageCheckbox = $item.find('input[type="checkbox"][name$="[send_image]"]');
                data[status] = {
                    enabled: $enabledCheckbox.is(':checked') ? '1' : '',
                    send_image: $sendImageCheckbox.is(':checked') ? '1' : '',
                    message: $item.find('textarea').val()
                };
            }
        });
        return data;
    }

    // ========== Save OTP Settings ==========
    $(document).on('submit', '#hoktech-otp-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('button[type="submit"]');
        var $result = $('#hoktech-otp-result');

        $btn.prop('disabled', true);

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_save_otp_settings',
            nonce: hoktechWA.nonce,
            enable_checkout_otp: $(this).find('input[name="enable_checkout_otp"]').is(':checked') ? '1' : '',
            enable_registration_otp: $(this).find('input[name="enable_registration_otp"]').is(':checked') ? '1' : '',
            enable_country_selector: $(this).find('input[name="enable_country_selector"]').is(':checked') ? '1' : '',
            default_country_code: $(this).find('select[name="default_country_code"]').val(),
            otp_message: $(this).find('textarea[name="otp_message"]').val()
        }, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 3000);
            } else {
                $result.html('❌ ' + (response.data?.message || 'خطأ')).removeClass('success').addClass('error').fadeIn();
            }
        });
    });

    // ========== Send Custom Message ==========
    $(document).on('submit', '#hoktech-message-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('button[type="submit"]');
        var $result = $('#hoktech-message-result');
        var originalText = $btn.html();

        $btn.prop('disabled', true).html(
            '<span class="dashicons dashicons-update"></span> ' + hoktechWA.strings.sending + '<span class="hoktech-loading"></span>'
        );
        $result.hide();

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_send_custom_message',
            nonce: hoktechWA.nonce,
            phone: $('#hoktech-msg-phone').val(),
            message: $('#hoktech-msg-text').val()
        }, function (response) {
            $btn.prop('disabled', false).html(originalText);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                $('#hoktech-msg-text').val('');
                setTimeout(function () { $result.fadeOut(); }, 5000);
            } else {
                $result.html('❌ ' + (response.data?.message || hoktechWA.strings.error)).removeClass('success').addClass('error').fadeIn();
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(originalText);
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
        });
    });

    // ========== Order Meta Box: Send Message ==========
    $(document).on('click', '#hoktech-send-order-msg', function () {
        var $btn = $(this);
        var $result = $('#hoktech-order-msg-result');
        var originalText = $btn.html();
        var phone = $('#hoktech-order-phone').val();
        var message = $('#hoktech-order-message').val();
        var orderId = $('#hoktech-order-id').val();

        if (!message || !message.trim()) {
            $result.html('⚠️ الرجاء كتابة الرسالة').removeClass('success').addClass('error').fadeIn();
            return;
        }

        $btn.prop('disabled', true).html(
            '<span class="dashicons dashicons-update"></span> ' + hoktechWA.strings.sending + '<span class="hoktech-loading"></span>'
        );
        $result.hide();

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_send_order_message',
            nonce: hoktechWA.nonce,
            phone: phone,
            message: message,
            order_id: orderId
        }, function (response) {
            $btn.prop('disabled', false).html(originalText);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 5000);
            } else {
                $result.html('❌ ' + (response.data?.message || hoktechWA.strings.error)).removeClass('success').addClass('error').fadeIn();
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(originalText);
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
        });
    });

    // ========== Order Meta Box: Resend Customer Notification ==========
    $(document).on('click', '#hoktech-resend-customer-notif', function () {
        var $btn = $(this);
        var $result = $('#hoktech-resend-result');
        var originalText = $btn.html();
        var orderId = $('#hoktech-order-id').val();

        $btn.prop('disabled', true).html(
            '<span class="dashicons dashicons-update"></span> ' + hoktechWA.strings.sending + '<span class="hoktech-loading"></span>'
        );
        $result.hide();

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_resend_customer_notif',
            nonce: hoktechWA.nonce,
            order_id: orderId
        }, function (response) {
            $btn.prop('disabled', false).html(originalText);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 5000);
            } else {
                $result.html('❌ ' + (response.data?.message || hoktechWA.strings.error)).removeClass('success').addClass('error').fadeIn();
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(originalText);
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
        });
    });

    // ========== Order Meta Box: Resend Vendor Notification ==========
    $(document).on('click', '#hoktech-resend-vendor-notif', function () {
        var $btn = $(this);
        var $result = $('#hoktech-resend-result');
        var originalText = $btn.html();
        var orderId = $('#hoktech-order-id').val();

        $btn.prop('disabled', true).html(
            '<span class="dashicons dashicons-update"></span> ' + hoktechWA.strings.sending + '<span class="hoktech-loading"></span>'
        );
        $result.hide();

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_resend_vendor_notif',
            nonce: hoktechWA.nonce,
            order_id: orderId
        }, function (response) {
            $btn.prop('disabled', false).html(originalText);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 5000);
            } else {
                $result.html('❌ ' + (response.data?.message || hoktechWA.strings.error)).removeClass('success').addClass('error').fadeIn();
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(originalText);
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
        });
    });

    // ========== Order Meta Box: Insert Variable Tags ==========
    $(document).on('click', '.hoktech-var-tag', function () {
        var varText = $(this).data('var');
        var $textarea = $('#hoktech-order-message');
        var textarea = $textarea[0];
        var startPos = textarea.selectionStart;
        var endPos = textarea.selectionEnd;
        var currentVal = $textarea.val();

        $textarea.val(currentVal.substring(0, startPos) + varText + currentVal.substring(endPos));

        // Set cursor position after inserted text
        var newPos = startPos + varText.length;
        textarea.setSelectionRange(newPos, newPos);
        $textarea.focus();
    });

    // ========== Save Admin Notification Settings ==========
    $(document).on('submit', '#hoktech-admin-notifications-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('button[type="submit"]');
        var $result = $('#hoktech-admin-notif-result');

        $btn.prop('disabled', true);

        // Collect checked statuses
        var statuses = [];
        $(this).find('input[name="admin_statuses[]"]:checked').each(function () {
            statuses.push($(this).val());
        });

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_save_admin_notifications',
            nonce: hoktechWA.nonce,
            enabled: $(this).find('input[name="admin_notif_enabled"]').is(':checked') ? '1' : '',
            phones: $(this).find('textarea[name="admin_phones"]').val(),
            message: $(this).find('textarea[name="admin_message"]').val(),
            statuses: statuses
        }, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 3000);
            } else {
                $result.html('❌ ' + (response.data?.message || 'خطأ')).removeClass('success').addClass('error').fadeIn();
            }
        });
    });

    // ========== Save Vendor Notification Settings ==========
    $(document).on('submit', '#hoktech-vendor-notifications-form', function (e) {
        e.preventDefault();

        // خزّن reference للفورم قبل أي استدعاء async
        var $form   = $(this);
        var $btn    = $form.find('button[type="submit"]');
        var $result = $('#hoktech-vendor-notif-result');

        $btn.prop('disabled', true);

        // جمع الحالات المختارة
        var vendorStatuses = [];
        $form.find('input[name="vendor_statuses[]"]:checked').each(function () {
            vendorStatuses.push($(this).val());
        });

        // اقرأ كود الدولة من الحقل مباشرةً (ID أضمن من name)
        var dialCode = $('#hoktech-vendor-dial-code').val() || $form.find('input[name="default_dial_code"]').val() || '';

        $.post(hoktechWA.ajaxUrl, {
            action:              'hoktech_save_vendor_notifications',
            nonce:               hoktechWA.nonce,
            vendor_notif_enabled: $form.find('input[name="vendor_notif_enabled"]').is(':checked') ? '1' : '',
            phone_meta_key:      $form.find('input[name="phone_meta_key"]').val(),
            default_dial_code:   dialCode,
            vendor_message:      $form.find('textarea[name="vendor_message"]').val(),
            vendor_item_format:  $form.find('textarea[name="vendor_item_format"]').val(),
            vendor_statuses:     vendorStatuses
        }, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 3000);
            } else {
                $result.html('❌ ' + (response.data?.message || 'خطأ')).removeClass('success').addClass('error').fadeIn();
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
        });
    });

    // ========== Vendor Dial Code Preset Selector ==========
    $(document).on('change', '#hoktech-vendor-dial-preset', function () {
        var val = $(this).val();
        if (val) {
            $('#hoktech-vendor-dial-code').val(val);
        } else {
            $('#hoktech-vendor-dial-code').val('');
        }
    });

    $(document).on('input', '#hoktech-vendor-dial-code', function () {
        var val = $(this).val().replace(/[^0-9]/g, '');
        var $preset = $('#hoktech-vendor-dial-preset');
        if ($preset.find('option[value="' + val + '"]').length > 0) {
            $preset.val(val);
        } else {
            $preset.val('');
        }
    });

    // ========== Save Product WhatsApp Button Settings ==========
    function saveProductButtonSettings(e) {
        if (e) {
            e.preventDefault();
        }

        var $form   = $('#hoktech-product-button-form');
        var $btn    = $('#hoktech-product-btn-save');
        var $result = $('#hoktech-product-btn-result');
        var originalText = $btn.html();

        $btn.prop('disabled', true);
        $result.hide();

        var isEnabled = $('#hoktech-product-btn-enabled').is(':checked') || $form.find('input[name="product_btn_enabled"]').is(':checked');

        var postData = {
            action:                   'hoktech_save_product_button',
            nonce:                    hoktechWA.nonce,
            product_btn_enabled:      isEnabled ? '1' : '',
            product_btn_phone:        $('#hoktech-product-btn-phone').val(),
            default_country_code:     $('#hoktech-product-btn-country').val(),
            product_btn_text:         $('#hoktech-product-btn-text').val(),
            product_btn_position:     $('#hoktech-product-btn-position').val(),
            product_btn_style:        $('#hoktech-product-btn-style').val(),
            product_btn_bg_color:     $('#hoktech-product-btn-bg-color').val(),
            product_btn_text_color:   $('#hoktech-product-btn-text-color').val(),
            product_btn_border_radius:$('#hoktech-product-btn-shape').val(),
            product_btn_draggable:    $form.find('input[name="product_btn_draggable"]').is(':checked') ? '1' : '',
            product_btn_show_icon:    $form.find('input[name="product_btn_show_icon"]').is(':checked') ? '1' : '',
            product_btn_open_tab:     $form.find('input[name="product_btn_open_tab"]').is(':checked') ? '1' : '',
            product_btn_hide_cart:    $form.find('input[name="product_btn_hide_cart"]').is(':checked') ? '1' : '',
            product_btn_mobile_only:  $form.find('input[name="product_btn_mobile_only"]').is(':checked') ? '1' : '',
            product_btn_use_vendor:   $form.find('input[name="product_btn_use_vendor"]').is(':checked') ? '1' : '',
            product_btn_message:      $('#hoktech-product-btn-message').val()
        };

        $.post(hoktechWA.ajaxUrl, postData, function (response) {
            $btn.prop('disabled', false).html(originalText);
            if (response.success) {
                $result.html('✅ ' + response.data.message).removeClass('error').addClass('success').fadeIn();
                setTimeout(function () { $result.fadeOut(); }, 4000);
            } else {
                $result.html('❌ ' + (response.data?.message || 'خطأ')).removeClass('success').addClass('error').fadeIn();
            }
        }).fail(function () {
            $btn.prop('disabled', false).html(originalText);
            $result.html('❌ ' + hoktechWA.strings.error).removeClass('success').addClass('error').fadeIn();
        });
    }

    $(document).on('submit', '#hoktech-product-button-form', saveProductButtonSettings);

    // ========== Product Button: Insert Variable Tags ==========
    $(document).on('click', '.hoktech-insert-product-var', function () {
        var varText = $(this).data('var');
        var $textarea = $('#hoktech-product-btn-message');
        if (!$textarea.length) return;

        var textarea = $textarea[0];
        var startPos = textarea.selectionStart;
        var endPos   = textarea.selectionEnd;
        var currentVal = $textarea.val();

        $textarea.val(currentVal.substring(0, startPos) + varText + currentVal.substring(endPos));

        var newPos = startPos + varText.length;
        textarea.setSelectionRange(newPos, newPos);
        $textarea.focus();
    });

    // ========== Product Button: Style Change Toggle ==========
    $(document).on('change', '#hoktech-product-btn-style', function () {
        var style = $(this).val();
        if (style === 'custom') {
            $('#hoktech-custom-colors-row').css('display', 'grid').hide().fadeIn(200);
        } else {
            $('#hoktech-custom-colors-row').fadeOut(200);
        }
    });

    // ========== Product Button: Sync Color Pickers with Hex Fields ==========
    $(document).on('input change', '#hoktech-product-btn-bg-color', function () {
        $('#hoktech-product-btn-bg-hex').val($(this).val());
    });

    $(document).on('input change', '#hoktech-product-btn-text-color', function () {
        $('#hoktech-product-btn-text-hex').val($(this).val());
    });

})(jQuery);


