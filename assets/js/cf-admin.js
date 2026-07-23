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

    // ── Xfinity Stats Modal ───────────────────────────
    $(document).on('click', '.cf-xfinity-stats', function () {
        const uid = $(this).data('id');
        const name = $(this).closest('tr').find('.cf-tbl-name').text().trim();
        $('#cf-xstats-name').text(name);
        $('#cf-xfinity-stats-modal').show();
        $('#cf-xstats-content').hide();
        $('#cf-xstats-loading').show().text('Loading...');

        adminPost('cf_admin_get_xfinity_stats', { user_id: uid }, function (d) {
            $('#cf-xstats-loading').hide();
            $('#cf-xstats-balance').text(Number(d.balance).toFixed(1));
            $('#cf-xstats-total').text(Number(d.total_earned).toFixed(1));
            $('#cf-xstats-mins').text(d.listening_mins_total);
            $('#cf-xstats-ref-total').text(d.referral_total);
            $('#cf-xstats-ref-confirmed').text(d.referral_confirmed);
            $('#cf-xstats-ref-pending').text(d.referral_pending);
            $('#cf-xstats-ref-xfinity').text(Number(d.referral_xfinity_total).toFixed(1));

            const $list = $('#cf-xstats-recent-list');
            if (!d.recent_days || !d.recent_days.length) {
                $list.html('<p style="color:#888">No activity in the last 7 days.</p>');
            } else {
                let h = '';
                d.recent_days.forEach(function (day) {
                    h += '<div class="cf-xstats-day-row">' +
                            '<span>' + day.date_label + '</span>' +
                            '<span>' + day.listening_mins + ' min → +' + Number(day.xfinity_earned).toFixed(1) + ' Xfinity</span>' +
                         '</div>';
                });
                $list.html(h);
            }
            $('#cf-xstats-content').show();
        }, function (d) {
            $('#cf-xstats-loading').text(d.message || 'Failed to load stats.');
        });
    });

    // Close modal (any visible .cf-modal)
    $(document).on('click', '.cf-modal-close, .cf-modal-backdrop', function() {
        $('.cf-modal:visible').hide();
        $('#cf-edit-msg').hide();
    });
    $(document).on('keydown', e => { if(e.key==='Escape') $('.cf-modal:visible').hide(); });

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

    // ── Active Sessions Today ─────────────────────────
    (function initSessionsToday() {
        const $root = $('#cf-activity-tab-sessions');
        if (!$root.length) return;

        const COLORS = {
            listening: '#2a78d6',
            browsing:  '#eb6834',
            reading:   '#1baf7a',
        };

        let sessions = Array.isArray(window.CF_SESSIONS_TODAY) ? window.CF_SESSIONS_TODAY.slice() : [];
        let chartInstance = null;
        let detailDonut = null;
        let openUserId = null;
        let chartType = 'stacked'; // grouped | stacked | donut | horizontal
        let currentPage = 1;
        let perPage = 10;
        let filterSearch = '';
        let filterActivity = 'all';
        let pageRows = []; // current page slice (for chart bar clicks)

        function escapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function findSession(uid) {
            uid = parseInt(uid, 10);
            return sessions.find(s => parseInt(s.user_id, 10) === uid) || null;
        }

        function filteredSessions() {
            const q = filterSearch.trim().toLowerCase();
            return sessions.filter(function (s) {
                if (filterActivity === 'listening' && !(parseInt(s.listening_minutes, 10) > 0)) return false;
                if (filterActivity === 'browsing' && !(parseInt(s.browsing_minutes, 10) > 0)) return false;
                if (filterActivity === 'reading' && !(parseInt(s.reading_minutes, 10) > 0)) return false;
                if (!q) return true;
                const name = String(s.display_name || '').toLowerCase();
                const email = String(s.email || '').toLowerCase();
                return name.indexOf(q) !== -1 || email.indexOf(q) !== -1;
            });
        }

        function paginatedSessions(list) {
            const total = list.length;
            const pages = Math.max(1, Math.ceil(total / perPage) || 1);
            if (currentPage > pages) currentPage = pages;
            if (currentPage < 1) currentPage = 1;
            const start = (currentPage - 1) * perPage;
            return {
                list: list.slice(start, start + perPage),
                total: total,
                pages: pages,
                page: currentPage,
            };
        }

        function renderMetrics(list) {
            // Always global totals across all users today (ignore filters/pagination).
            const totals = list.reduce((acc, s) => {
                acc.total += parseInt(s.total_minutes, 10) || 0;
                acc.listening += parseInt(s.listening_minutes, 10) || 0;
                acc.browsing += parseInt(s.browsing_minutes, 10) || 0;
                acc.reading += parseInt(s.reading_minutes, 10) || 0;
                return acc;
            }, { total: 0, listening: 0, browsing: 0, reading: 0 });

            $('#cf-metric-total').text(totals.total);
            $('#cf-metric-listening').text(totals.listening);
            $('#cf-metric-browsing').text(totals.browsing);
            $('#cf-metric-reading').text(totals.reading);

            const live = list.filter(s => s.is_currently_active || s.status === 'live').length;
            $('#cf-sessions-summary').text(list.length + ' members · ' + live + ' live now');
        }

        function renderTable(list) {
            const $tb = $('#cf-sessions-tbody');
            if (!list.length) {
                $tb.html('<tr class="cf-sessions-empty"><td colspan="7" class="cf-empty">No activity yet today.</td></tr>');
                return;
            }
            let html = '';
            list.forEach(function (s) {
                const live = s.is_currently_active || s.status === 'live';
                const badge = live
                    ? '<span class="cf-badge cf-badge-success">🟢 Live now</span>'
                    : '<span class="cf-badge cf-badge-neutral">Idle</span>';
                html += '<tr class="cf-sessions-row" data-user-id="' + s.user_id + '" tabindex="0">' +
                    '<td><div class="cf-tbl-name">' + escapeHtml(s.display_name) + '</div>' +
                    '<div class="cf-tbl-email">' + escapeHtml(s.email) + '</div></td>' +
                    '<td>' + badge + '</td>' +
                    '<td>' + (parseInt(s.listening_minutes, 10) || 0) + '</td>' +
                    '<td>' + (parseInt(s.browsing_minutes, 10) || 0) + '</td>' +
                    '<td>' + (parseInt(s.reading_minutes, 10) || 0) + '</td>' +
                    '<td>' + (parseInt(s.total_minutes, 10) || 0) + '</td>' +
                    '<td>' + Number(s.xfinity_today || 0).toFixed(2) + '</td>' +
                    '</tr>';
            });
            $tb.html(html);
        }

        function renderPagination(meta) {
            $('#cf-sessions-page-info').text('Page ' + meta.page + ' of ' + meta.pages +
                (meta.total ? ' · ' + meta.total + ' members' : ''));
            $('#cf-sessions-prev').prop('disabled', meta.page <= 1);
            $('#cf-sessions-next').prop('disabled', meta.page >= meta.pages);
        }

        function destroyMainChart() {
            if (chartInstance) {
                chartInstance.destroy();
                chartInstance = null;
            }
        }

        function openFromChartIndex(idx) {
            const s = pageRows[idx];
            if (s) openDetail(s.user_id);
        }

        function buildChartConfig(list) {
            const labels = list.map(s => s.display_name);
            const listening = list.map(s => parseInt(s.listening_minutes, 10) || 0);
            const browsing = list.map(s => parseInt(s.browsing_minutes, 10) || 0);
            const reading = list.map(s => parseInt(s.reading_minutes, 10) || 0);

            if (chartType === 'donut') {
                const totals = [
                    listening.reduce((a, b) => a + b, 0),
                    browsing.reduce((a, b) => a + b, 0),
                    reading.reduce((a, b) => a + b, 0),
                ];
                return {
                    type: 'doughnut',
                    data: {
                        labels: ['Listening', 'Browsing', 'Reading'],
                        datasets: [{
                            data: totals,
                            backgroundColor: [COLORS.listening, COLORS.browsing, COLORS.reading],
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                        // Aggregated view — no per-member drill-down from slices.
                    },
                };
            }

            const stacked = chartType === 'stacked';
            const horizontal = chartType === 'horizontal';
            const datasets = [
                { label: 'Listening', data: listening, backgroundColor: COLORS.listening, stack: stacked ? 'm' : undefined },
                { label: 'Browsing',  data: browsing,  backgroundColor: COLORS.browsing,  stack: stacked ? 'm' : undefined },
                { label: 'Reading',   data: reading,   backgroundColor: COLORS.reading,   stack: stacked ? 'm' : undefined },
            ];

            return {
                type: 'bar',
                data: { labels: labels, datasets: datasets },
                options: {
                    indexAxis: horizontal ? 'y' : 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: { mode: 'index', intersect: false },
                    },
                    scales: {
                        x: {
                            stacked: stacked,
                            beginAtZero: true,
                            ticks: horizontal ? { precision: 0 } : { autoSkip: true, maxRotation: 45 },
                            title: horizontal ? { display: true, text: 'Minutes' } : undefined,
                        },
                        y: {
                            stacked: stacked,
                            beginAtZero: true,
                            ticks: horizontal ? { autoSkip: true } : { precision: 0 },
                            title: horizontal ? undefined : { display: true, text: 'Minutes' },
                        },
                    },
                    onClick: function (_evt, elements) {
                        if (!elements || !elements.length) return;
                        openFromChartIndex(elements[0].index);
                    },
                },
            };
        }

        function renderMainChart(list) {
            const canvas = document.getElementById('cf-sessions-chart');
            const $empty = $('#cf-sessions-chart-empty');
            if (!canvas || typeof Chart === 'undefined') return;

            destroyMainChart();

            if (!list.length) {
                $empty.prop('hidden', false);
                return;
            }
            $empty.prop('hidden', true);
            chartInstance = new Chart(canvas, buildChartConfig(list));
        }

        function fillItemList($ul, items) {
            if (!items || !items.length) {
                $ul.html('<li class="cf-quiet">No activity</li>');
                return;
            }
            let html = '';
            items.forEach(function (it) {
                html += '<li><span>' + escapeHtml(it.title) + '</span><span>' +
                    (parseInt(it.minutes, 10) || 0) + ' min</span></li>';
            });
            $ul.html(html);
        }

        function renderDetailDonut(s) {
            const canvas = document.getElementById('cf-session-donut');
            if (!canvas || typeof Chart === 'undefined') return;

            const values = [
                parseInt(s.listening_minutes, 10) || 0,
                parseInt(s.browsing_minutes, 10) || 0,
                parseInt(s.reading_minutes, 10) || 0,
            ];
            const data = {
                labels: ['Listening', 'Browsing', 'Reading'],
                datasets: [{
                    data: values,
                    backgroundColor: [COLORS.listening, COLORS.browsing, COLORS.reading],
                    borderWidth: 0,
                }],
            };

            if (detailDonut) {
                detailDonut.data = data;
                detailDonut.update('none');
                return;
            }

            detailDonut = new Chart(canvas, {
                type: 'doughnut',
                data: data,
                options: {
                    responsive: true,
                    plugins: {
                        legend: { position: 'bottom' },
                    },
                },
            });
        }

        function openDetail(userId) {
            const s = findSession(userId);
            if (!s) return;
            openUserId = parseInt(s.user_id, 10);

            const live = s.is_currently_active || s.status === 'live';
            $('#cf-session-detail-name').text(s.display_name || 'Member');
            const $badge = $('#cf-session-detail-badge');
            $badge
                .removeClass('cf-badge-success cf-badge-neutral')
                .addClass(live ? 'cf-badge-success' : 'cf-badge-neutral')
                .text(live ? '🟢 Live now' : 'Idle');
            $('#cf-session-detail-last').text(s.last_activity ? ('Last activity: ' + s.last_activity) : '');

            fillItemList($('#cf-session-songs'), (s.items && s.items.songs) || []);
            fillItemList($('#cf-session-pages'), (s.items && s.items.pages) || []);
            fillItemList($('#cf-session-articles'), (s.items && s.items.articles) || []);
            renderDetailDonut(s);

            $('#cf-session-detail').show().attr('aria-hidden', 'false');
        }

        function closeDetail() {
            openUserId = null;
            $('#cf-session-detail').hide().attr('aria-hidden', 'true');
        }

        function renderView() {
            const filtered = filteredSessions();
            const page = paginatedSessions(filtered);
            pageRows = page.list;
            renderTable(page.list);
            renderPagination(page);
            renderMainChart(page.list);
        }

        function applySessions(list) {
            sessions = Array.isArray(list) ? list.slice() : [];
            window.CF_SESSIONS_TODAY = sessions;
            renderMetrics(sessions);
            renderView();
            if (openUserId) {
                const still = findSession(openUserId);
                if (still) openDetail(openUserId);
            }
        }

        function refreshSessions() {
            adminPost('cf_admin_get_sessions_today', {}, function (d) {
                applySessions(d.sessions || []);
            });
        }

        // Table row click / keyboard
        $(document).on('click', '.cf-sessions-row', function () {
            openDetail($(this).data('user-id'));
        });
        $(document).on('keydown', '.cf-sessions-row', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openDetail($(this).data('user-id'));
            }
        });

        // Close: explicit button only (Escape also closes for accessibility)
        $('#cf-session-detail-close').on('click', closeDetail);
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && openUserId) closeDetail();
        });

        // Chart type toggles
        $('#cf-sessions-chart-types').on('click', '.cf-chart-type-btn', function () {
            const type = $(this).data('chart-type');
            if (!type || type === chartType) return;
            chartType = type;
            $('#cf-sessions-chart-types .cf-chart-type-btn').removeClass('active');
            $(this).addClass('active');
            renderMainChart(pageRows);
        });

        // Filters
        $('#cf-sessions-filter-search').on('input', function () {
            filterSearch = $(this).val() || '';
            currentPage = 1;
            renderView();
        });
        $('#cf-sessions-filter-activity').on('change', function () {
            filterActivity = $(this).val() || 'all';
            currentPage = 1;
            renderView();
        });

        // Pagination
        $('#cf-sessions-per-page').on('change', function () {
            perPage = parseInt($(this).val(), 10) || 10;
            currentPage = 1;
            renderView();
        });
        $('#cf-sessions-prev').on('click', function () {
            if (currentPage <= 1) return;
            currentPage -= 1;
            renderView();
        });
        $('#cf-sessions-next').on('click', function () {
            currentPage += 1;
            renderView();
        });

        // Export dropdown
        const $exportBtn = $('#cf-sessions-export-btn');
        const $exportMenu = $('#cf-sessions-export-menu');

        $exportBtn.on('click', function (e) {
            e.stopPropagation();
            const open = !$exportMenu.prop('hidden');
            $exportMenu.prop('hidden', open);
            $exportBtn.attr('aria-expanded', open ? 'false' : 'true');
        });
        $(document).on('click', function () {
            $exportMenu.prop('hidden', true);
            $exportBtn.attr('aria-expanded', 'false');
        });
        $exportMenu.on('click', function (e) { e.stopPropagation(); });

        function exportCsv() {
            const header = [
                'Member', 'Email', 'Status', 'Listening (min)', 'Browsing (min)',
                'Reading (min)', 'Total (min)', 'Xfinity today', 'Last activity',
                'Songs', 'Pages', 'Articles',
            ];
            const rows = [header];
            // Export full dataset (not just current page/filter) so admins get everything.
            sessions.forEach(function (s) {
                const live = s.is_currently_active || s.status === 'live';
                const fmtItems = function (arr) {
                    return (arr || []).map(function (it) {
                        return (it.title || '') + ' (' + (it.minutes || 0) + ' min)';
                    }).join('; ');
                };
                rows.push([
                    s.display_name || '',
                    s.email || '',
                    live ? 'Live now' : 'Idle',
                    parseInt(s.listening_minutes, 10) || 0,
                    parseInt(s.browsing_minutes, 10) || 0,
                    parseInt(s.reading_minutes, 10) || 0,
                    parseInt(s.total_minutes, 10) || 0,
                    Number(s.xfinity_today || 0).toFixed(2),
                    s.last_activity || '',
                    fmtItems(s.items && s.items.songs),
                    fmtItems(s.items && s.items.pages),
                    fmtItems(s.items && s.items.articles),
                ]);
            });

            const csv = rows.map(function (row) {
                return row.map(function (cell) {
                    const v = String(cell == null ? '' : cell);
                    if (/[",\n]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
                    return v;
                }).join(',');
            }).join('\n');

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            const stamp = new Date().toISOString().slice(0, 10);
            a.href = url;
            a.download = 'cf-active-sessions-' + stamp + '.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }

        $exportMenu.on('click', 'button[data-format]', function () {
            const format = $(this).data('format');
            $exportMenu.prop('hidden', true);
            $exportBtn.attr('aria-expanded', 'false');

            if (format === 'csv') {
                exportCsv();
                return;
            }

            adminPost('cf_export_sessions_today', { format: format },
                function () { adminMsg('Export ready.', 'success'); },
                function (d) { adminMsg((d && d.message) || 'Export not yet implemented.', 'error'); }
            );
        });

        // Initial paint
        applySessions(sessions);
        setInterval(refreshSessions, 10000);
    })();

})(jQuery);
