/**
 * HokTech Admin JavaScript
 */
(function ($) {
    'use strict';

    // ========== Tab Switching ==========
    $(document).on('click', '.hoktech-tab', function () {
        var tab = $(this).data('tab');

        // Update tab buttons
        $('.hoktech-tab').removeClass('active');
        $(this).addClass('active');

        // Update tab content
        $('.hoktech-tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');
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

    // ========== Save Notification Settings ==========
    $(document).on('submit', '#hoktech-notifications-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('button[type="submit"]');
        var $result = $('#hoktech-notifications-result');

        $btn.prop('disabled', true);

        $.post(hoktechWA.ajaxUrl, {
            action: 'hoktech_save_notifications',
            nonce: hoktechWA.nonce,
            notifications: collectNotificationData()
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

})(jQuery);

