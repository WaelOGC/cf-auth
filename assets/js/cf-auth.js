/* ═══════════════════════════════════════════════════
   CF Auth v2.0 — Frontend JavaScript
   Collective Finity
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';
    const CF = window.CF_AUTH || {};

    // ── Helpers ───────────────────────────────────────
    function msg($el, text, type) {
        $el.removeClass('is-error is-success')
           .addClass(type === 'error' ? 'is-error' : 'is-success')
           .html(text).show();
    }
    function setLoading($form, on) {
        $form.find('.cf-btn-text').toggle(!on);
        $form.find('.cf-btn-loader').toggle(on);
        $form.find('[type="submit"]').prop('disabled', on);
    }
    function post(action, data, ok, fail) {
        $.post(CF.ajax_url, Object.assign({ action, nonce: CF.nonce }, data))
         .done(function(r){ r.success ? ok && ok(r.data) : fail && fail(r.data); })
         .fail(function(){ fail && fail({ message: 'Connection error. Please try again.' }); });
    }

    // ── Password toggle ───────────────────────────────
    $(document).on('click', '.cf-toggle-password', function () {
        const $i = $(this).closest('.cf-input-wrap').find('input');
        const t  = $i.attr('type') === 'password' ? 'text' : 'password';
        $i.attr('type', t);
    });

    // ── Password strength ─────────────────────────────
    $(document).on('input', '#cf-reg-password', function () {
        const v = $(this).val();
        let s = 0;
        if (v.length >= 8)          s++;
        if (/[A-Z]/.test(v))        s++;
        if (/[0-9]/.test(v))        s++;
        if (/[^A-Za-z0-9]/.test(v)) s++;
        const w = ['0%','25%','50%','75%','100%'][s];
        const c = ['transparent','#ef4444','#f97316','#eab308','#22c55e'][s];
        const l = ['','Weak','Fair','Good','Strong'][s];
        $('.cf-strength-bar').css({ width: w, background: c });
        $('.cf-strength-label').text(l);
    });

    // ── Social auth ───────────────────────────────────
    $(document).on('click', '.cf-social-btn', function () {
        const $b = $(this).prop('disabled', true).css('opacity', 0.6);
        post('cf_social_init', { provider: $b.data('provider') },
            function(d){ window.location.href = d.redirect; },
            function(d){ alert(d.message || 'Not configured.'); $b.prop('disabled',false).css('opacity',1); }
        );
    });

    // ── Login ─────────────────────────────────────────
    $('#cf-login-form').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this), $m = $('#cf-login-message');
        setLoading($f, true);
        post('cf_login', {
            email:       $f.find('[name="email"]').val(),
            password:    $f.find('[name="password"]').val(),
            remember:    $f.find('[name="remember"]').is(':checked') ? 1 : 0,
            redirect_to: new URLSearchParams(location.search).get('redirect_to') || '',
        }, function(d){
            msg($m, d.message, 'success');
            setTimeout(() => location.href = d.redirect, 700);
        }, function(d){
            setLoading($f, false);
            msg($m, d.message, 'error');
            if (d.show_resend) $m.append(' <a href="#" class="cf-resend-login" data-id="'+(d.user_id||'')+'">Resend verification</a>');
        });
    });

    // ── Register ──────────────────────────────────────
    $('#cf-register-form').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this), $m = $('#cf-register-message');
        const pw = $f.find('[name="password"]').val();
        const co = $f.find('[name="confirm_password"]').val();
        if (pw !== co) return msg($m, 'Passwords do not match.', 'error');
        if (!$f.find('[name="terms"]').is(':checked')) return msg($m, 'Please accept the terms.', 'error');
        setLoading($f, true);
        post('cf_register', {
            display_name:     $f.find('[name="display_name"]').val(),
            email:            $f.find('[name="email"]').val(),
            password:         pw,
            confirm_password: co,
        }, function(d){
            msg($m, d.message, 'success');
            setTimeout(() => location.href = d.redirect, 1200);
        }, function(d){ setLoading($f,false); msg($m, d.message,'error'); });
    });

    // ── Forgot ────────────────────────────────────────
    $('#cf-forgot-form').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this), $m = $('#cf-forgot-message');
        setLoading($f, true);
        post('cf_forgot_password', { email: $f.find('[name="email"]').val() },
            function(d){ setLoading($f,false); msg($m,d.message,'success'); },
            function(d){ setLoading($f,false); msg($m,d.message,'error'); }
        );
    });

    // ── Reset ─────────────────────────────────────────
    $('#cf-reset-form').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this), $m = $('#cf-reset-message');
        setLoading($f, true);
        post('cf_reset_password', {
            token:            $f.find('[name="token"]').val(),
            password:         $f.find('[name="password"]').val(),
            confirm_password: $f.find('[name="confirm_password"]').val(),
        }, function(d){ msg($m,d.message,'success'); setTimeout(()=>location.href=d.redirect,1200); },
           function(d){ setLoading($f,false); msg($m,d.message,'error'); }
        );
    });

    // ── Profile tabs ──────────────────────────────────
    $(document).on('click', '.cf-tab', function () {
        const tab = $(this).data('tab');
        $('.cf-tab').removeClass('active');
        $(this).addClass('active');
        $('.cf-tab-panel').hide();
        $('#cf-tab-' + tab).show();
        if (tab === 'history') loadHistory();
    });

    // Go to settings tab from overview
    $(document).on('click', '.cf-go-settings', function () {
        $('.cf-tab[data-tab="settings"]').trigger('click');
    });

    // ── Avatar upload ─────────────────────────────────
    $('#cf-avatar-input').on('change', function () {
        const f = this.files[0];
        if (!f) return;
        if (f.size > 2*1024*1024) return alert('Max file size is 2MB.');
        const rd = new FileReader();
        rd.onload = e => $('#cf-avatar-img').attr('src', e.target.result);
        rd.readAsDataURL(f);
        const fd = new FormData();
        fd.append('action','cf_upload_avatar'); fd.append('nonce',CF.nonce); fd.append('avatar',f);
        $.ajax({ url: CF.ajax_url, type:'POST', data:fd, processData:false, contentType:false,
            success: r => r.success && $('#cf-avatar-img').attr('src', r.data.avatar_url)
        });
    });

    // ── Update profile ────────────────────────────────
    $('#cf-profile-form').on('submit', function (e) {
        e.preventDefault();
        const $f=$(this), $m=$('#cf-profile-message');
        setLoading($f,true);
        post('cf_update_profile', {
            display_name: $f.find('[name="display_name"]').val(),
            email:        $f.find('[name="email"]').val(),
            bio:          $f.find('[name="bio"]').val(),
        }, function(d){ setLoading($f,false); msg($m,d.message,'success'); },
           function(d){ setLoading($f,false); msg($m,d.message,'error'); }
        );
    });

    // ── Change password ───────────────────────────────
    $('#cf-change-password-form').on('submit', function (e) {
        e.preventDefault();
        const $f=$(this), $m=$('#cf-password-message');
        setLoading($f,true);
        post('cf_change_password', {
            current_password: $f.find('[name="current_password"]').val(),
            new_password:     $f.find('[name="new_password"]').val(),
            confirm_password: $f.find('[name="confirm_password"]').val(),
        }, function(d){ setLoading($f,false); msg($m,d.message,'success'); $f[0].reset(); },
           function(d){ setLoading($f,false); msg($m,d.message,'error'); }
        );
    });

    // ── Logout ────────────────────────────────────────
    $(document).on('click', '#cf-logout-btn, #cf-logout-btn-settings', function () {
        if (!confirm('Sign out?')) return;
        post('cf_logout', {}, d => location.href = d.redirect);
    });

    // ── Listening history ─────────────────────────────
    function loadHistory() {
        const $c = $('#cf-history-container');
        if ($c.data('loaded')) return;
        post('cf_get_listening_history', { limit:20 }, function(d){
            $c.data('loaded',true);
            if (!d.history || !d.history.length) {
                $c.html('<p class="cf-muted">No listening history yet.</p>'); return;
            }
            let h = '<div class="cf-history-list">';
            d.history.forEach(i => {
                h += `<div class="cf-history-item"><span class="cf-history-track">🎵 Track #${i.track_id}</span><span class="cf-history-time">${i.listened_at}</span></div>`;
            });
            $c.html(h + '</div>');
        }, () => $c.html('<p class="cf-muted">Could not load history.</p>'));
    }

    // ── Resend verify ─────────────────────────────────
    $(document).on('click', '#cf-resend-verify, #cf-resend-settings', function (e) {
        e.preventDefault();
        $.post(CF.ajax_url, { action:'cf_resend_verify', nonce:CF.nonce }, function(){
            const $m = $('#cf-resend-message, #cf-profile-message').first();
            msg($m, 'Verification email sent! Check your inbox.', 'success');
        });
    });
    $(document).on('click', '.cf-resend-login', function (e) {
        e.preventDefault();
        $.post(CF.ajax_url, { action:'cf_resend_verify', user_id:$(this).data('id'), nonce:CF.nonce },
            () => alert('Verification email sent!')
        );
    });

    // ── Public API for music player ───────────────────
    window.CF_Auth = {
        toggleFavorite: (id, type) => new Promise((res,rej) =>
            post('cf_toggle_favorite',{ item_id:id, item_type:type }, res, rej)
        ),
        logListening: id => post('cf_log_listening',{ track_id:id }),
    };

})(jQuery);
