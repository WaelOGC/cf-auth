/* CF Auth v2.0 — Admin JavaScript */
(function ($) {
    'use strict';
    const CF = window.CF_ADMIN || {};

    function adminPost(action, data, ok, fail) {
        $.post(CF.ajax_url, Object.assign({ action, nonce: CF.nonce }, data))
         .done(r => r.success ? ok && ok(r.data) : fail && fail(r.data))
         .fail(() => fail && fail({ message: 'Request failed.' }));
    }

    function adminMsg(msg, type) {
        const $m = $('#cf-admin-msg');
        $m.removeClass('notice-success notice-error')
          .addClass(type==='success'?'notice-success':'notice-error')
          .find('p').text(msg);
        $m.show();
        setTimeout(() => $m.fadeOut(), 4000);
    }

    // ── Suspend / Activate ────────────────────────────
    $(document).on('click', '.cf-suspend-user', function () {
        const $b = $(this), uid = $b.data('id'), act = $b.data('action');
        if (!confirm((act==='suspend'?'Suspend':'Activate') + ' this member?')) return;
        adminPost('cf_admin_suspend_user', { user_id: uid, action_type: act }, function(d) {
            adminMsg(d.message, 'success');
            const $row = $('#cf-row-' + uid);
            $row.find('.cf-status').removeClass('cf-status-active cf-status-suspended cf-status-pending')
                .addClass('cf-status-' + d.new_status).text(d.new_status.charAt(0).toUpperCase() + d.new_status.slice(1));
            if (d.new_status === 'suspended') {
                $b.data('action','activate').attr('title','Activate').text('▶️');
            } else {
                $b.data('action','suspend').attr('title','Suspend').text('⏸️');
            }
        }, d => adminMsg(d.message||'Failed.','error'));
    });

    // ── Delete ────────────────────────────────────────
    $(document).on('click', '.cf-delete-user', function () {
        const $b = $(this), uid = $b.data('id');
        if (!confirm('Permanently delete this member? This cannot be undone.')) return;
        adminPost('cf_admin_delete_user', { user_id: uid }, function(d) {
            adminMsg(d.message, 'success');
            $('#cf-row-' + uid).fadeOut(300, function(){ $(this).remove(); });
        }, d => adminMsg(d.message||'Failed.','error'));
    });

    // ── Resend verification ───────────────────────────
    $(document).on('click', '.cf-resend-verify', function () {
        const $b = $(this), uid = $b.data('id');
        const orig = $b.text(); $b.text('📨').prop('disabled',true);
        adminPost('cf_admin_resend_verify', { user_id: uid },
            function(d) { adminMsg(d.message,'success'); $b.text('✓').prop('disabled',false); },
            function()  { $b.text(orig).prop('disabled',false); }
        );
    });

    // ── Edit Member Modal ─────────────────────────────
    $(document).on('click', '.cf-edit-user', function () {
        const uid = $(this).data('id');
        const $modal = $('#cf-edit-modal').show();
        $('#cf-edit-form').hide();
        $('#cf-edit-loading').show();

        adminPost('cf_admin_get_user', { user_id: uid }, function(d) {
            $('#cf-edit-loading').hide();
            const $f = $('#cf-edit-form');
            $f.find('[name="user_id"]').val(d.id);
            $f.find('[name="display_name"]').val(d.display_name);
            $f.find('[name="email"]').val(d.email);
            $f.find('[name="bio"]').val(d.bio);
            $f.find('[name="account_status"]').val(d.status);
            $f.find('[name="email_verified"]').prop('checked', d.verified);
            $('#cf-edit-avatar').attr('src', d.avatar);
            $('#cf-edit-provider-badge').text(d.provider.charAt(0).toUpperCase() + d.provider.slice(1));
            $('#cf-edit-joined').text('Joined: ' + d.joined);
            $f.show();
        }, function(d) {
            $('#cf-edit-loading').text(d.message || 'Failed to load user.');
        });
    });

    // Edit form submit
    $('#cf-edit-form').on('submit', function(e) {
        e.preventDefault();
        const $f = $(this), data = {};
        $f.serializeArray().forEach(({name,value}) => data[name]=value);
        data.email_verified = $f.find('[name="email_verified"]').is(':checked') ? '1' : '0';

        adminPost('cf_admin_update_user', data, function(d) {
            const $m = $('#cf-edit-msg');
            $m.removeClass('notice-error').addClass('notice-success').find('p').text(d.message);
            $m.show();
            setTimeout(() => $('#cf-edit-modal').hide(), 1500);
            adminMsg(d.message, 'success');
        }, function(d) {
            const $m = $('#cf-edit-msg');
            $m.removeClass('notice-success').addClass('notice-error').find('p').text(d.message||'Failed.');
            $m.show();
        });
    });

    // Close modal
    $(document).on('click', '.cf-modal-close, .cf-modal-backdrop', function() {
        $('#cf-edit-modal').hide();
        $('#cf-edit-msg').hide();
    });
    $(document).on('keydown', e => { if(e.key==='Escape') $('#cf-edit-modal').hide(); });

    // ── Settings form ─────────────────────────────────
    $('#cf-settings-form').on('submit', function(e) {
        e.preventDefault();
        const data = {};
        $(this).serializeArray().forEach(({name,value}) => data[name]=value);
        // Handle unchecked checkboxes only when they exist in the current form tab
        const $form = $(this);
        if ($form.find('[name="cf_auth_email_verification"]').length && !data['cf_auth_email_verification']) {
            data['cf_auth_email_verification'] = '0';
        }
        ['cf_auth_google_enabled','cf_auth_facebook_enabled','cf_auth_discord_enabled','cf_auth_twitter_enabled'].forEach(key => {
            if ($form.find('[name="' + key + '"]').length && !data[key]) data[key] = '0';
        });

        const $btn = $('#cf-save-btn').text('Saving...').prop('disabled',true);
        adminPost('cf_save_settings', data, function(d) {
            const $m = $('#cf-settings-msg');
            $m.removeClass('notice-error').addClass('notice-success').find('p').text(d.message).end().show();
            setTimeout(() => $m.fadeOut(), 3000);
            $btn.text('Save Settings').prop('disabled',false);
        }, function(d) {
            const $m = $('#cf-settings-msg');
            $m.removeClass('notice-success').addClass('notice-error').find('p').text(d.message||'Failed.').end().show();
            $btn.text('Save Settings').prop('disabled',false);
        });
    });

})(jQuery);
