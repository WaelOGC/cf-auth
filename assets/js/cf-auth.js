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

    // ── Shared confirmation modal ─────────────────────
    // options: { title, message, confirmText, cancelText, danger }
    // Resolves true on confirm, false on cancel / backdrop / Escape.
    function cfConfirm(options) {
        const opts = Object.assign({
            title: '',
            message: '',
            confirmText: 'Confirm',
            cancelText: 'Cancel',
            danger: false,
        }, options || {});

        return new Promise(function (resolve) {
            const $existing = $('#cf-confirm-modal');
            if ($existing.length) $existing.remove();

            const confirmClass = opts.danger ? 'cf-btn cf-btn-danger' : 'cf-btn cf-btn-primary';
            const $modal = $(
                '<div id="cf-confirm-modal" class="cf-modal" role="dialog" aria-modal="true" aria-labelledby="cf-confirm-title">' +
                    '<div class="cf-modal-backdrop"></div>' +
                    '<div class="cf-modal-box">' +
                        '<div class="cf-modal-head">' +
                            '<h3 id="cf-confirm-title"></h3>' +
                        '</div>' +
                        '<div class="cf-modal-body">' +
                            '<p class="cf-modal-message"></p>' +
                        '</div>' +
                        '<div class="cf-modal-foot">' +
                            '<button type="button" class="cf-btn cf-btn-outline cf-confirm-cancel"></button>' +
                            '<button type="button" class="cf-confirm-ok"></button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );

            $modal.find('#cf-confirm-title').text(opts.title);
            $modal.find('.cf-modal-message').text(opts.message).toggle(!!opts.message);
            $modal.find('.cf-confirm-cancel').text(opts.cancelText);
            $modal.find('.cf-confirm-ok').attr('class', confirmClass + ' cf-confirm-ok').text(opts.confirmText);
            $('body').append($modal).addClass('cf-modal-open');

            const $ok = $modal.find('.cf-confirm-ok');
            $ok.trigger('focus');

            function close(result) {
                $(document).off('keydown.cfConfirm');
                $('body').removeClass('cf-modal-open');
                $modal.remove();
                resolve(result);
            }

            $modal.on('click', '.cf-confirm-ok', function () { close(true); });
            $modal.on('click', '.cf-confirm-cancel', function () { close(false); });
            $modal.on('click', '.cf-modal-backdrop', function () { close(false); });
            $(document).on('keydown.cfConfirm', function (e) {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close(false);
                }
            });
        });
    }

    let nonceRefreshPromise = null;

    function refreshNonces() {
        if (!nonceRefreshPromise) {
            nonceRefreshPromise = $.post(CF.ajax_url, { action: 'cf_refresh_nonces' })
                .then(function (r) {
                    if (!r || !r.success || !r.data || !r.data.auth_nonce) {
                        return $.Deferred().reject(r).promise();
                    }
                    CF.nonce = r.data.auth_nonce;
                    return r.data;
                })
                .always(function () {
                    nonceRefreshPromise = null;
                });
        }
        return nonceRefreshPromise;
    }

    function ajaxPost(action, data, retried) {
        const d = $.Deferred();
        $.post(CF.ajax_url, Object.assign({ action, nonce: CF.nonce }, data))
            .done(function (r) { d.resolve(r); })
            .fail(function (jqXHR) {
                if (!retried && jqXHR && jqXHR.status === 403) {
                    refreshNonces()
                        .done(function () {
                            ajaxPost(action, data, true).done(d.resolve).fail(d.reject);
                        })
                        .fail(function () { d.reject(jqXHR); });
                } else {
                    d.reject(jqXHR);
                }
            });
        return d.promise();
    }

    function post(action, data, ok, fail) {
        ajaxPost(action, data, false)
            .done(function (r) {
                r.success ? ok && ok(r.data) : fail && fail(r.data);
            })
            .fail(function () {
                fail && fail({ message: 'Connection error. Please try again.' });
            });
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
    const PROFILE_TABS = ['overview', 'favorites', 'history', 'playlists', 'settings'];

    $(document).on('click', '.cf-tab', function () {
        const tab = $(this).data('tab');
        $('.cf-tab').removeClass('active');
        $(this).addClass('active');
        $('.cf-tab-panel').hide();
        $('#cf-tab-' + tab).show();
        if (tab === 'history') loadHistory();
        history.replaceState(null, '', '#' + tab);
    });

    function activateTabFromHash() {
        if (!$('.cf-tab').length) return;
        const hash = window.location.hash.replace(/^#/, '');
        if (PROFILE_TABS.indexOf(hash) !== -1 && $('.cf-tab[data-tab="' + hash + '"]').length) {
            $('.cf-tab[data-tab="' + hash + '"]').trigger('click');
        }
    }

    activateTabFromHash();

    document.addEventListener('cf:page-loaded', function () {
        activateTabFromHash();
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
        cfConfirm({
            title: 'Sign out?',
            confirmText: 'Sign out',
            cancelText: 'Cancel',
        }).then(function (ok) {
            if (!ok) return;
            post('cf_logout', {}, d => location.href = d.redirect);
        });
    });

    // ── Listening history ─────────────────────────────
    function escHtml(text) {
        return $('<div>').text(text || '').html();
    }

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
                const cover = i.cover
                    ? `<img src="${escHtml(i.cover)}" alt="" class="cf-history-cover" width="36" height="36" loading="lazy" style="border-radius:4px;object-fit:cover;margin-right:10px;flex-shrink:0">`
                    : '';
                const title = `<a href="${escHtml(i.url)}" class="cf-history-track-link" style="color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${escHtml(i.title)}</a>`;
                h += `<div class="cf-history-item"><span class="cf-history-track" style="display:flex;align-items:center;flex:1;min-width:0">${cover}${title}</span><span class="cf-history-time">${escHtml(i.listened_at)}</span></div>`;
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

    // ── Favorites tab: unfavorite ─────────────────────
    function favoritesEmptyHtml() {
        const msg = $('#cf-tab-favorites').data('empty-msg') || 'No favorites yet. Start listening and save tracks you love.';
        return '<div class="cf-section-card cf-empty-state" id="cf-favorites-empty"><span class="cf-empty-icon">♥</span><p>' + msg + '</p></div>';
    }

    $(document).on('click', '.cf-fav-remove', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const id   = $btn.data('id');
        const type = $btn.data('type');
        const $card = $btn.closest('.cf-fav-card');
        $btn.prop('disabled', true);
        window.CF_Auth.toggleFavorite(id, type).then(function (d) {
            if (d.action === 'removed' || d.is_favorite === false) {
                const $section = $card.closest('.cf-fav-section');
                $card.remove();
                const $stats = $('.cf-stats-bar .cf-stat-num');
                const statIndex = { track: 0, album: 1, post: 2 };
                if ($stats.length >= 3 && statIndex[type] !== undefined) {
                    const $stat = $stats.eq(statIndex[type]);
                    $stat.text(Math.max(0, parseInt($stat.text(), 10) || 0) - 1);
                }
                if (!$section.find('.cf-fav-card').length) {
                    $section.remove();
                }
                if (!$('#cf-tab-favorites .cf-fav-card').length) {
                    $('#cf-tab-favorites').html(favoritesEmptyHtml());
                }
            }
            $btn.prop('disabled', false);
        }, function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Playlists tab: create ─────────────────────────
    function playlistCardHtml(p) {
        const cover = p.cover
            ? `<img src="${escHtml(p.cover)}" alt="" loading="lazy">`
            : '<span aria-hidden="true">🎵</span>';
        const badge = parseInt(p.is_public, 10) ? 'Public' : 'Private';
        const count = parseInt(p.item_count, 10) || 0;
        const countLabel = count === 1 ? '1 item' : count + ' items';
        return `<a href="${escHtml(p.share_url)}" class="cf-row-item" data-id="${p.id}">
            <div class="cf-row-thumb${p.cover ? '' : ' cf-row-thumb--empty'}">${cover}</div>
            <div class="cf-row-info">
                <span class="cf-row-title">${escHtml(p.name)}</span>
                <span class="cf-row-subtitle">${countLabel}</span>
            </div>
            <div class="cf-row-trailing">
                <span class="cf-row-pill">${badge}</span>
                <svg class="cf-row-chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
            </div>
        </a>`;
    }

    function playlistsEmptyHtml() {
        const msg = $('#cf-tab-playlists').data('empty-msg') || "You haven't created any playlists yet.";
        return '<div class="cf-section-card cf-empty-state" id="cf-playlists-empty"><span class="cf-empty-icon">🎵</span><p>' + msg + '</p></div>';
    }

    $('#cf-create-playlist-form').on('submit', function (e) {
        e.preventDefault();
        const $f = $(this);
        const $m = $('#cf-create-playlist-msg');
        const name = $.trim($f.find('[name="name"]').val());
        if (!name) return msg($m, 'Playlist name is required.', 'error');
        setLoading($f, true);
        window.CF_Auth.createPlaylist(name).then(function (p) {
            setLoading($f, false);
            msg($m, 'Playlist created!', 'success');
            $f[0].reset();
            $('#cf-playlists-empty').remove();
            let $grid = $('#cf-playlists-grid');
            if (!$grid.length) {
                $('#cf-tab-playlists .cf-playlists-create').after(
                    '<div class="cf-section-card"><h4 class="cf-section-title">Your Playlists</h4><div class="cf-row-list" id="cf-playlists-grid"></div></div>'
                );
                $grid = $('#cf-playlists-grid');
            }
            $grid.prepend(playlistCardHtml(p));
        }, function (d) {
            setLoading($f, false);
            msg($m, (d && d.message) || 'Could not create playlist.', 'error');
        });
    });

    // ── Playlist view page: manage controls ───────────
    function showPlaylistMsg(text, type) {
        const $m = $('#cf-playlist-manage-msg');
        if ($m.length) msg($m, text, type);
    }

    $(document).on('click', '#cf-playlist-rename-btn', function () {
        const $view = $('#cf-playlist-view');
        const id = $view.data('playlist-id');
        const name = $.trim($('#cf-playlist-rename-input').val());
        if (!name) return showPlaylistMsg('Playlist name is required.', 'error');
        window.CF_Auth.renamePlaylist(id, name).then(function (d) {
            $('#cf-playlist-title').text(d.name || name);
            showPlaylistMsg('Playlist renamed.', 'success');
        }, function (d) {
            showPlaylistMsg((d && d.message) || 'Could not rename playlist.', 'error');
        });
    });

    $(document).on('change', '#cf-playlist-public-toggle', function () {
        const $view = $('#cf-playlist-view');
        const id = $view.data('playlist-id');
        const isPublic = $(this).is(':checked') ? 1 : 0;
        window.CF_Auth.togglePlaylistVisibility(id, isPublic).then(function (d) {
            $view.data('is-public', d.is_public);
            if (d.share_url) $view.data('share-url', d.share_url);
            $('#cf-playlist-copy-link').prop('disabled', !parseInt(d.is_public, 10));
            showPlaylistMsg(parseInt(d.is_public, 10) ? 'Playlist is now public.' : 'Playlist is now private.', 'success');
        }, function (d) {
            showPlaylistMsg((d && d.message) || 'Could not update visibility.', 'error');
            $('#cf-playlist-public-toggle').prop('checked', !isPublic);
        });
    });

    $(document).on('click', '#cf-playlist-copy-link', function () {
        const url = $('#cf-playlist-view').data('share-url');
        if (!url) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                showPlaylistMsg('Share link copied!', 'success');
            });
        } else {
            const $tmp = $('<input>').val(url).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
            showPlaylistMsg('Share link copied!', 'success');
        }
    });

    $(document).on('click', '#cf-playlist-delete-btn', function () {
        cfConfirm({
            title: 'Delete this playlist?',
            message: 'This cannot be undone.',
            confirmText: 'Delete',
            cancelText: 'Cancel',
            danger: true,
        }).then(function (ok) {
            if (!ok) return;
            const id = $('#cf-playlist-view').data('playlist-id');
            window.CF_Auth.deletePlaylist(id).then(function () {
                location.href = CF.profile_url + '#playlists';
            }, function (d) {
                showPlaylistMsg((d && d.message) || 'Could not delete playlist.', 'error');
            });
        });
    });

    $(document).on('click', '.cf-playlist-item-remove', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const $card = $btn.closest('.cf-playlist-item-card');
        const playlistId = $btn.data('playlist-id');
        const itemId = $btn.data('id');
        const itemType = $btn.data('type');
        $btn.prop('disabled', true);
        window.CF_Auth.removeFromPlaylist(playlistId, itemId, itemType).then(function () {
            $card.remove();
            if (!$('#cf-playlist-items-grid .cf-playlist-item-card').length) {
                $('#cf-playlist-items-grid').closest('.cf-section-card').replaceWith(
                    '<div class="cf-section-card cf-empty-state" id="cf-playlist-items-empty"><span class="cf-empty-icon">🎵</span><p>This playlist is empty.</p></div>'
                );
                $('.cf-playlist-play-all').prop('disabled', true);
            }
            updatePlaylistPlayQueue();
        }, function () {
            $btn.prop('disabled', false);
        });
    });

    function getPlaylistPlayQueue() {
        const $el = $('#cf-playlist-queue');
        if (!$el.length) return [];
        try {
            const data = JSON.parse($el.text());
            return Array.isArray(data) ? data : [];
        } catch (e) {
            return [];
        }
    }

    function updatePlaylistPlayQueue() {
        const queue = [];
        $('#cf-playlist-items-grid .cf-playlist-item-card').each(function () {
            const $row = $(this);
            if ($row.data('type') !== 'track') return;
            const fileUrl = $row.data('file-url');
            if (!fileUrl) return;
            queue.push({
                id: parseInt($row.data('id'), 10),
                title: $row.data('title') || '',
                artist: $row.data('artist') || '',
                art: $row.data('cover') || '',
                url: fileUrl,
                fileUrl: fileUrl
            });
        });
        const $script = $('#cf-playlist-queue');
        if ($script.length) $script.text(JSON.stringify(queue));
        $('.cf-playlist-play-all').prop('disabled', !queue.length);
    }

    $(document).on('click', '.cf-playlist-play-all', function () {
        const queue = getPlaylistPlayQueue();
        if (!queue.length) return;
        if (typeof window.playTrack === 'function') {
            window.playTrack(null, null, null, null, null, queue, 0);
        }
    });

    // ── Delete account ────────────────────────────────
    function getAccountEmail() {
        const $email = $('.cf-hero-email');
        return $email.length ? $.trim($email.text()).toLowerCase() : '';
    }

    function updateDeleteAccountControls() {
        const $ack = $('#cf-delete-account-ack');
        const $email = $('#cf-delete-account-confirm-email');
        const $btn = $('#cf-delete-account-btn');
        if (!$ack.length) return;

        const acked = $ack.is(':checked');
        $email.prop('disabled', !acked);
        if (!acked) {
            $email.val('').removeClass('cf-input-mismatch');
            $btn.prop('disabled', true);
            return;
        }

        const typed = $.trim($email.val()).toLowerCase();
        const accountEmail = getAccountEmail();
        const matches = typed.length > 0 && typed === accountEmail;
        $email.toggleClass('cf-input-mismatch', typed.length > 0 && !matches);
        $btn.prop('disabled', !matches);
    }

    $(document).on('change', '#cf-delete-account-ack', updateDeleteAccountControls);
    $(document).on('input', '#cf-delete-account-confirm-email', updateDeleteAccountControls);

    $(document).on('click', '#cf-delete-account-btn', function () {
        const $btn = $(this);
        const $msg = $('#cf-delete-account-msg');
        cfConfirm({
            title: 'Delete account?',
            message: 'This is permanent. Continue?',
            confirmText: 'Delete account',
            cancelText: 'Cancel',
            danger: true,
        }).then(function (ok) {
            if (!ok) return;
            $btn.prop('disabled', true);
            post('cf_delete_account', {
                confirm_email: $('#cf-delete-account-confirm-email').val()
            }, function (d) {
                window.location.href = (d && d.redirect) ? d.redirect : '/';
            }, function (d) {
                msg($msg, (d && d.message) || 'Could not delete account.', 'error');
                updateDeleteAccountControls();
            });
        });
    });

    // ── Public API for music player ───────────────────
    window.CF_Auth = {
        toggleFavorite: (id, type) => new Promise((res, rej) =>
            ajaxPost('cf_toggle_favorite', { item_id: id, item_type: type }, false)
                .done(r => r.success ? res(r.data) : rej(r.data))
                .fail(() => rej({ message: 'Connection error. Please try again.' }))
        ),
        getFavorites: () => new Promise((res, rej) =>
            ajaxPost('cf_get_favorites', {}, false)
                .done(r => r.success ? res(r.data) : rej(r))
                .fail(rej)
        ),
        logListening: id => ajaxPost('cf_log_listening', { track_id: id }, false),
        createPlaylist: (name) => new Promise((res, rej) =>
            ajaxPost('cf_create_playlist', { name }, false)
                .done(r => r.success ? res(r.data) : rej(r.data))
                .fail(() => rej({ message: 'Connection error. Please try again.' }))
        ),
        getUserPlaylists: (itemId, itemType) => {
            const data = {};
            if (itemId && itemType) {
                data.item_id = itemId;
                data.item_type = itemType;
            }
            return new Promise((res, rej) =>
                ajaxPost('cf_get_user_playlists', data, false)
                    .done(r => r.success ? res(r.data) : rej(r.data))
                    .fail(() => rej({ message: 'Connection error. Please try again.' }))
            );
        },
        addToPlaylist: (playlistId, itemId, itemType) => new Promise((res, rej) =>
            ajaxPost('cf_add_to_playlist', { playlist_id: playlistId, item_id: itemId, item_type: itemType }, false)
                .done(r => r.success ? res(r.data) : rej(r.data))
                .fail(() => rej({ message: 'Connection error. Please try again.' }))
        ),
        removeFromPlaylist: (playlistId, itemId, itemType) => new Promise((res, rej) =>
            ajaxPost('cf_remove_from_playlist', { playlist_id: playlistId, item_id: itemId, item_type: itemType }, false)
                .done(r => r.success ? res(r.data) : rej(r.data))
                .fail(() => rej({ message: 'Connection error. Please try again.' }))
        ),
        deletePlaylist: (playlistId) => new Promise((res, rej) =>
            ajaxPost('cf_delete_playlist', { playlist_id: playlistId }, false)
                .done(r => r.success ? res(r.data) : rej(r.data))
                .fail(() => rej({ message: 'Connection error. Please try again.' }))
        ),
        renamePlaylist: (playlistId, name) => new Promise((res, rej) =>
            ajaxPost('cf_rename_playlist', { playlist_id: playlistId, name }, false)
                .done(r => r.success ? res(r.data) : rej(r.data))
                .fail(() => rej({ message: 'Connection error. Please try again.' }))
        ),
        togglePlaylistVisibility: (playlistId, isPublic) => new Promise((res, rej) =>
            ajaxPost('cf_toggle_playlist_visibility', { playlist_id: playlistId, is_public: isPublic }, false)
                .done(r => r.success ? res(r.data) : rej(r.data))
                .fail(() => rej({ message: 'Connection error. Please try again.' }))
        ),
        getPlaylistItems: (playlistId) => new Promise((res, rej) =>
            ajaxPost('cf_get_playlist_items', { playlist_id: playlistId }, false)
                .done(r => r.success ? res(r.data) : rej(r.data))
                .fail(() => rej({ message: 'Connection error. Please try again.' }))
        ),
    };

})(jQuery);
