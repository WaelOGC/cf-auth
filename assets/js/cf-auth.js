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
    const PROFILE_TABS = ['overview', 'favorites', 'history', 'playlists', 'rewards', 'settings'];
    const PAGE_SIZES = [10, 25, 50, 100];

    const tabPaging = {
        rewards: { page: 1, perPage: 10, statsLoaded: false },
    };

    $(document).on('click', '.cf-tab', function () {
        const tab = $(this).data('tab');
        $('.cf-tab').removeClass('active');
        $(this).addClass('active');
        $('.cf-tab-panel').hide();
        $('#cf-tab-' + tab).show();
        if (tab === 'rewards') loadRewards();
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

    // Preserve tab hash on server-side "Go to page" forms (GET strips hash otherwise).
    $(document).on('submit', '.cf-pagination-goto', function (e) {
        const tab = $(this).data('cf-tab');
        if (!tab) return;
        e.preventDefault();
        const $f = $(this);
        const action = ($f.attr('action') || window.location.pathname).split('#')[0].split('?')[0];
        const params = $f.serialize();
        window.location.href = action + (params ? ('?' + params) : '') + '#' + tab;
    });

    // ── Shared pagination UI (Rewards AJAX tab only) ──
    function getPaginationPages(current, total) {
        current = Math.max(1, parseInt(current, 10) || 1);
        total = Math.max(1, parseInt(total, 10) || 1);
        if (total <= 9) {
            return Array.from({ length: total }, function (_, i) { return i + 1; });
        }
        const pages = [];
        if (current <= 5) {
            for (let i = 1; i <= 8; i++) pages.push(i);
            pages.push('…');
            pages.push(total);
        } else if (current >= total - 4) {
            pages.push(1);
            pages.push('…');
            for (let i = total - 7; i <= total; i++) pages.push(i);
        } else {
            pages.push(1);
            pages.push('…');
            for (let i = current - 2; i <= current + 2; i++) pages.push(i);
            pages.push('…');
            pages.push(total);
        }
        return pages;
    }

    /**
     * Render pagination controls into $container.
     * onPageChange(page, perPage) is called when the user changes page or page size.
     */
    function renderPagination($container, currentPage, totalPages, perPage, onPageChange) {
        if (!$container || !$container.length) return;
        currentPage = Math.max(1, parseInt(currentPage, 10) || 1);
        totalPages = Math.max(0, parseInt(totalPages, 10) || 0);
        perPage = parseInt(perPage, 10) || 10;
        if (PAGE_SIZES.indexOf(perPage) === -1) perPage = 10;

        if (totalPages <= 0) {
            $container.empty().hide();
            return;
        }

        const pages = getPaginationPages(currentPage, totalPages);
        let html = '<div class="cf-pagination" role="navigation" aria-label="Pagination">';
        html += '<div class="cf-pagination-size"><label>Per page ';
        html += '<select class="cf-pagination-per-page">';
        PAGE_SIZES.forEach(function (n) {
            html += '<option value="' + n + '"' + (n === perPage ? ' selected' : '') + '>' + n + '</option>';
        });
        html += '</select></label></div>';

        html += '<div class="cf-pagination-pages">';
        pages.forEach(function (p) {
            if (p === '…') {
                html += '<span class="cf-pagination-ellipsis" aria-hidden="true">…</span>';
                return;
            }
            const active = p === currentPage ? ' is-active' : '';
            html += '<button type="button" class="cf-pagination-page' + active + '" data-page="' + p + '"' +
                (p === currentPage ? ' aria-current="page"' : '') + '>' + p + '</button>';
        });
        html += '</div>';

        html += '<div class="cf-pagination-goto">' +
            '<label>Go to <input type="number" class="cf-pagination-goto-input" min="1" max="' + totalPages + '" value="' + currentPage + '"></label>' +
            '<button type="button" class="cf-btn cf-btn-outline-sm cf-pagination-goto-btn">Go</button>' +
            '</div>';
        html += '</div>';

        $container.html(html).show();

        $container.off('change.cfPag click.cfPag');
        $container.on('change.cfPag', '.cf-pagination-per-page', function () {
            const nextPer = parseInt($(this).val(), 10) || 10;
            onPageChange(1, nextPer);
        });
        $container.on('click.cfPag', '.cf-pagination-page', function () {
            const p = parseInt($(this).data('page'), 10);
            if (!p || p === currentPage) return;
            onPageChange(p, perPage);
        });
        $container.on('click.cfPag', '.cf-pagination-goto-btn', function () {
            let p = parseInt($container.find('.cf-pagination-goto-input').val(), 10);
            if (!p || p < 1) p = 1;
            if (p > totalPages) p = totalPages;
            if (p === currentPage) return;
            onPageChange(p, perPage);
        });
        $container.on('keydown.cfPag', '.cf-pagination-goto-input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $container.find('.cf-pagination-goto-btn').trigger('click');
            }
        });
    }

    function ensurePaginationSlot($listParent) {
        let $pag = $listParent.children('.cf-pagination-wrap');
        if (!$pag.length) {
            $pag = $('<div class="cf-pagination-wrap"></div>');
            $listParent.append($pag);
        }
        return $pag;
    }

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

    // ── Helpers ───────────────────────────────────────
    function escHtml(text) {
        return $('<div>').text(text || '').html();
    }

    // ── Rewards tab (Xfinity + referrals) — AJAX (Favorites/History/Playlists are server-paginated) ──
    function loadRewards(force) {
        const $stats = $('#cf-referral-stats-container');
        const $hist  = $('#cf-xfinity-history-container');
        if (!$hist.length) return;
        if ($hist.data('loading')) return;
        if (!force && $hist.data('loaded')) return;

        const state = tabPaging.rewards;
        const needStats = !state.statsLoaded;

        $hist.data('loading', true);
        if (needStats) {
            $stats.html('<p class="cf-muted">Loading...</p>');
        }
        $hist.html('<p class="cf-muted">Loading...</p>');

        post('cf_get_xfinity_summary', {
            page: state.page,
            per_page: state.perPage,
        }, function (d) {
            $hist.data('loading', false);
            $hist.data('loaded', true);

            if (needStats || !state.statsLoaded) {
                state.statsLoaded = true;
                const s = d.referral_stats || {};
                $stats.html(
                    '<div class="cf-stats-bar" style="margin:0;border:none;padding:0">' +
                        '<div class="cf-stat"><span class="cf-stat-num">' + escHtml(String(s.total || 0)) + '</span><span class="cf-stat-lbl">Total</span></div>' +
                        '<div class="cf-stat-sep"></div>' +
                        '<div class="cf-stat"><span class="cf-stat-num">' + escHtml(String(s.confirmed || 0)) + '</span><span class="cf-stat-lbl">Confirmed</span></div>' +
                        '<div class="cf-stat-sep"></div>' +
                        '<div class="cf-stat"><span class="cf-stat-num">' + escHtml(String(s.pending || 0)) + '</span><span class="cf-stat-lbl">Pending</span></div>' +
                        '<div class="cf-stat-sep"></div>' +
                        '<div class="cf-stat"><span class="cf-stat-num">' + escHtml(String(s.under_review || 0)) + '</span><span class="cf-stat-lbl">Under review</span></div>' +
                    '</div>'
                );
            }

            const days = d.daily_summary || [];
            const totalDays = parseInt(d.total_days, 10) || 0;
            const totalPages = Math.max(1, Math.ceil(totalDays / state.perPage) || 1);
            if (state.page > totalPages && totalDays > 0) {
                state.page = totalPages;
                loadRewards(true);
                return;
            }

            if (!days.length) {
                $hist.html('<p class="cf-muted">No Xfinity activity yet.</p>');
                return;
            }

            let h = '<div class="cf-history-list">';
            days.forEach(function (day) {
                const mins = parseInt(day.listening_mins, 10) || 0;
                const earned = Number(day.xfinity_earned) || 0;
                const refCount = parseInt(day.referral_count, 10) || 0;
                const refXf = Number(day.referral_xfinity) || 0;
                const listenXf = Number(day.listening_xfinity);
                const listenAmt = isNaN(listenXf) ? Math.max(0, earned - refXf) : listenXf;

                let body = '';
                if (mins > 0 || listenAmt > 0) {
                    body += '<span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' +
                        'Listened ' + escHtml(String(mins)) + ' min → +' + escHtml(String(Number(listenAmt).toFixed(1))) + ' Xfinity' +
                        '</span>';
                }
                if (refCount > 0) {
                    const refLabel = refCount === 1 ? 'Referral confirmed' : (refCount + ' referrals confirmed');
                    body += '<span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px">' +
                        escHtml(refLabel) + ' → +' + escHtml(String(refXf.toFixed(1))) + ' Xfinity' +
                        '</span>';
                }
                if (!body) {
                    body = '<span style="display:block">+' + escHtml(String(earned.toFixed(1))) + ' Xfinity</span>';
                }

                h += '<div class="cf-history-item">' +
                    '<span class="cf-history-track" style="flex:1;min-width:0">' +
                        '<span class="cf-history-time" style="display:block;margin-bottom:2px">' + escHtml(day.date_label) + '</span>' +
                        body +
                    '</span>' +
                    '<span class="cf-badge cf-badge-gold">+' + escHtml(String(earned.toFixed(1))) + '</span>' +
                '</div>';
            });
            h += '</div>';
            $hist.html(h);
            const $pag = ensurePaginationSlot($hist);
            renderPagination($pag, state.page, totalPages, state.perPage, function (page, perPage) {
                state.page = page;
                state.perPage = perPage;
                loadRewards(true);
            });
        }, function () {
            $hist.data('loading', false);
            if (!state.statsLoaded) {
                $stats.html('<p class="cf-muted">Could not load referral stats.</p>');
            }
            $hist.html('<p class="cf-muted">Could not load activity.</p>');
        });
    }

    $(document).on('click', '#cf-copy-referral-link', function () {
        const $btn = $(this);
        const $input = $('#cf-referral-link-input');
        const text = $input.val() || '';
        const original = $btn.text();

        function copied() {
            $btn.text('Copied!');
            setTimeout(function () { $btn.text(original); }, 2000);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(copied).catch(function () {
                $input.trigger('select');
                try { document.execCommand('copy'); copied(); } catch (e) {}
            });
            return;
        }

        $input.trigger('focus').trigger('select');
        try { document.execCommand('copy'); copied(); } catch (e) {}
    });

    // ── Favorites tab: unfavorite (server-rendered list) ──
    function favoritesEmptyHtml() {
        const emptyMsg = $('#cf-tab-favorites').data('empty-msg') || 'No favorites yet. Start listening and save tracks you love.';
        return '<div class="cf-section-card cf-empty-state" id="cf-favorites-empty"><span class="cf-empty-icon">♥</span><p>' + emptyMsg + '</p></div>';
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
                $card.remove();
                const $stats = $('.cf-stats-bar .cf-stat-num');
                const statIndex = { track: 0, album: 1, post: 2 };
                if ($stats.length >= 3 && statIndex[type] !== undefined) {
                    const $stat = $stats.eq(statIndex[type]);
                    $stat.text(Math.max(0, parseInt($stat.text(), 10) || 0) - 1);
                }
                if (!$('#cf-favorites-list .cf-fav-card').length) {
                    // Last item on this page — reload so pagination/empty state stays correct.
                    window.location.reload();
                    return;
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
            ? '<img src="' + escHtml(p.cover) + '" alt="" loading="lazy">'
            : '<span aria-hidden="true">🎵</span>';
        const badge = parseInt(p.is_public, 10) ? 'Public' : 'Private';
        const count = parseInt(p.item_count, 10) || 0;
        const countLabel = count === 1 ? '1 item' : count + ' items';
        return '<a href="' + escHtml(p.share_url) + '" class="cf-row-item" data-id="' + p.id + '">' +
            '<div class="cf-row-thumb' + (p.cover ? '' : ' cf-row-thumb--empty') + '">' + cover + '</div>' +
            '<div class="cf-row-info">' +
                '<span class="cf-row-title">' + escHtml(p.name) + '</span>' +
                '<span class="cf-row-subtitle">' + countLabel + '</span>' +
            '</div>' +
            '<div class="cf-row-trailing">' +
                '<span class="cf-row-pill">' + badge + '</span>' +
                '<svg class="cf-row-chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>' +
            '</div>' +
        '</a>';
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
