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
        let chartType = 'stacked';
        let currentPage = 1;
        let perPage = 10;
        let filterSearch = '';
        let pageRows = [];

        function escapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function detailUrl(userId) {
            const base = (CF.session_detail_base || '') + '&user_id=' + encodeURIComponent(userId);
            return base;
        }

        function filteredSessions() {
            const q = filterSearch.trim().toLowerCase();
            return sessions.filter(function (s) {
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

        function countryLabel(s) {
            const flag = s.country_flag || '';
            const name = s.country_name || '';
            const combined = (flag + ' ' + name).trim();
            return combined || '—';
        }

        function renderTable(list) {
            const $tb = $('#cf-sessions-tbody');
            if (!list.length) {
                $tb.html('<tr class="cf-sessions-empty"><td colspan="6" class="cf-empty">No activity yet today.</td></tr>');
                return;
            }
            let html = '';
            list.forEach(function (s) {
                const live = s.is_currently_active || s.status === 'live';
                const badge = live
                    ? '<span class="cf-badge cf-badge-success">🟢 Live now</span>'
                    : '<span class="cf-badge cf-badge-neutral">Idle</span>';
                const ip = s.ip_address ? escapeHtml(s.ip_address) : '—';
                html += '<tr class="cf-sessions-row" data-user-id="' + s.user_id + '">' +
                    '<td><div class="cf-tbl-name">' + escapeHtml(s.display_name) + '</div></td>' +
                    '<td><div class="cf-tbl-email">' + escapeHtml(s.email) + '</div></td>' +
                    '<td>' + escapeHtml(countryLabel(s)) + '</td>' +
                    '<td><code class="cf-ip">' + ip + '</code></td>' +
                    '<td>' + badge + '</td>' +
                    '<td class="cf-col-actions">' +
                    '<a class="cf-session-view-link" href="' + escapeHtml(detailUrl(s.user_id)) +
                    '" title="Deep Analyst" aria-label="View Deep Analyst">👁️</a></td>' +
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
            if (s) window.location.href = detailUrl(s.user_id);
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
        }

        function refreshSessions() {
            adminPost('cf_admin_get_sessions_today', {}, function (d) {
                applySessions(d.sessions || []);
            });
        }

        $('#cf-sessions-chart-types').on('click', '.cf-chart-type-btn', function () {
            const type = $(this).data('chart-type');
            if (!type || type === chartType) return;
            chartType = type;
            $('#cf-sessions-chart-types .cf-chart-type-btn').removeClass('active');
            $(this).addClass('active');
            renderMainChart(pageRows);
        });

        $('#cf-sessions-filter-search').on('input', function () {
            filterSearch = $(this).val() || '';
            currentPage = 1;
            renderView();
        });

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

        // Export dropdown — server-side admin-post.php download (reliable vs Blob).
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

        function exportViaServer(format) {
            try {
                const base = CF.export_sessions_url || '';
                if (!base) {
                    throw new Error('Export URL missing.');
                }
                const url = base +
                    (base.indexOf('?') >= 0 ? '&' : '?') +
                    'format=' + encodeURIComponent(format) +
                    '&nonce=' + encodeURIComponent(CF.nonce || '');
                console.log('[cf-auth] sessions export navigate', format, url);
                window.location.href = url;
            } catch (err) {
                console.error('[cf-auth] sessions export failed', err);
                adminMsg((err && err.message) || 'Export failed.', 'error');
            }
        }

        // Kept as a hardened Blob fallback for live diagnosis if admin-post is blocked.
        function exportCsvBlobFallback() {
            try {
                const header = ['Member', 'Email', 'Country', 'IP address'];
                const rows = [header];
                sessions.forEach(function (s) {
                    rows.push([
                        s.display_name || '',
                        s.email || '',
                        countryLabel(s) === '—' ? '' : countryLabel(s),
                        s.ip_address || '',
                    ]);
                });
                const csv = rows.map(function (row) {
                    return row.map(function (cell) {
                        const v = String(cell == null ? '' : cell);
                        if (/[",\n]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
                        return v;
                    }).join(',');
                }).join('\n');

                console.log('[cf-auth] Blob CSV create', { bytes: csv.length, rows: rows.length });
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const stamp = new Date().toISOString().slice(0, 10);
                a.href = url;
                a.download = 'cf-active-sessions-' + stamp + '.csv';
                document.body.appendChild(a);
                console.log('[cf-auth] Blob CSV click', a.download);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
            } catch (err) {
                console.error('[cf-auth] Blob CSV failed', err);
                adminMsg((err && err.message) || 'CSV export failed.', 'error');
            }
        }

        $exportMenu.on('click', 'button[data-format]', function () {
            const format = $(this).data('format');
            $exportMenu.prop('hidden', true);
            $exportBtn.attr('aria-expanded', 'false');
            exportViaServer(format || 'csv');
            // Soft secondary attempt for CSV only if someone needs Blob diagnostics:
            if (format === 'csv' && window.CF_FORCE_BLOB_CSV) {
                exportCsvBlobFallback();
            }
        });

        applySessions(sessions);
        setInterval(refreshSessions, 10000);
    })();

    // ── Deep Analyst (session-detail) ─────────────────
    (function initDeepAnalyst() {
        const $root = $('#cf-session-detail-page');
        if (!$root.length) return;

        const COLORS = {
            listening: '#2a78d6',
            browsing:  '#eb6834',
            reading:   '#1baf7a',
            xfinity:   '#2a78d6',
        };

        const userId = parseInt($root.data('user-id') || window.CF_SESSION_DETAIL_USER_ID || 0, 10);
        let detail = null;
        const charts = {};
        const chartTypes = {
            listening: 'stacked',
            browsing: 'stacked',
            reading: 'stacked',
            total: 'stacked',
            xfinity: 'stacked',
        };
        const filters = { listening: '', browsing: '', reading: '' };

        function escapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function iconFlag(yes, yesIcon, noIcon) {
            return yes ? yesIcon : (noIcon || '—');
        }

        function destroyChart(key) {
            if (charts[key]) {
                charts[key].destroy();
                charts[key] = null;
            }
        }

        function buildItemChart(canvasId, key, items, color, valueKey) {
            const canvas = document.getElementById(canvasId);
            if (!canvas || typeof Chart === 'undefined') return;
            destroyChart(key);

            valueKey = valueKey || 'minutes';
            const labels = items.map(function (it) {
                const t = String(it.title || '');
                return t.length > 28 ? t.slice(0, 25) + '…' : t;
            });
            const values = items.map(function (it) { return Number(it[valueKey]) || 0; });
            const type = chartTypes[key] || 'stacked';

            if (!items.length) {
                return;
            }

            if (type === 'donut') {
                charts[key] = new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: values,
                            backgroundColor: labels.map(function (_, i) {
                                const palette = [COLORS.listening, COLORS.browsing, COLORS.reading, '#888', '#555'];
                                return palette[i % palette.length];
                            }),
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                    },
                });
                return;
            }

            const horizontal = type === 'horizontal';
            charts[key] = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: valueKey === 'xfinity' ? 'Xfinity' : 'Minutes',
                        data: values,
                        backgroundColor: color,
                    }],
                },
                options: {
                    indexAxis: horizontal ? 'y' : 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true },
                        y: { beginAtZero: true },
                    },
                },
            });
        }

        function buildTotalChart(d) {
            const canvas = document.getElementById('cf-analyst-total-chart');
            if (!canvas || typeof Chart === 'undefined') return;
            destroyChart('total');

            const values = [
                parseInt(d.total && d.total.listening, 10) || 0,
                parseInt(d.total && d.total.browsing, 10) || 0,
                parseInt(d.total && d.total.reading, 10) || 0,
            ];
            const labels = ['Listening', 'Browsing', 'Reading'];
            const colors = [COLORS.listening, COLORS.browsing, COLORS.reading];
            const type = chartTypes.total || 'stacked';

            if (type === 'donut') {
                charts.total = new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                        labels: labels,
                        datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } },
                    },
                });
                return;
            }

            const horizontal = type === 'horizontal';
            const stacked = type === 'stacked';
            charts.total = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: ['Today'],
                    datasets: labels.map(function (label, i) {
                        return {
                            label: label,
                            data: [values[i]],
                            backgroundColor: colors[i],
                            stack: stacked ? 't' : undefined,
                        };
                    }),
                },
                options: {
                    indexAxis: horizontal ? 'y' : 'x',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } },
                    scales: {
                        x: { stacked: stacked, beginAtZero: true },
                        y: { stacked: stacked, beginAtZero: true },
                    },
                },
            });
        }

        function filterItems(items, q, fields) {
            q = (q || '').trim().toLowerCase();
            if (!q) return items.slice();
            return items.filter(function (it) {
                return fields.some(function (f) {
                    return String(it[f] || '').toLowerCase().indexOf(q) !== -1;
                });
            });
        }

        function renderListening(d) {
            const songs = filterItems((d.listening && d.listening.songs) || [], filters.listening, ['title']);
            $('#cf-analyst-listen-mins').text((d.listening && d.listening.minutes) || 0);
            $('#cf-analyst-listen-count').text((d.listening && d.listening.songs_count) || 0);

            let html = '';
            if (!songs.length) {
                html = '<tr><td colspan="5" class="cf-empty">No listening activity today.</td></tr>';
            } else {
                songs.forEach(function (s) {
                    html += '<tr>' +
                        '<td>' + escapeHtml(s.title) + '</td>' +
                        '<td>' + (parseInt(s.minutes, 10) || 0) + '</td>' +
                        '<td>' + iconFlag(s.liked, '❤️') + '</td>' +
                        '<td>' + iconFlag(s.commented, '💬') + '</td>' +
                        '<td>' + iconFlag(s.shared, '🔗') + '</td>' +
                        '</tr>';
                });
            }
            $('#cf-analyst-listen-table tbody').html(html);
            buildItemChart('cf-analyst-listen-chart', 'listening', songs, COLORS.listening, 'minutes');
        }

        function renderBrowsing(d) {
            const pages = filterItems((d.browsing && d.browsing.pages) || [], filters.browsing, ['title', 'url']);
            $('#cf-analyst-browse-mins').text((d.browsing && d.browsing.minutes) || 0);
            $('#cf-analyst-browse-count').text((d.browsing && d.browsing.pages_count) || 0);

            let html = '';
            if (!pages.length) {
                html = '<tr><td colspan="4" class="cf-empty">No browsing activity today.</td></tr>';
            } else {
                pages.forEach(function (p) {
                    const country = ((p.country_flag || '') + ' ' + (p.country_name || '')).trim() || '—';
                    html += '<tr>' +
                        '<td>' + escapeHtml(p.title) +
                        (p.url ? '<div class="cf-tbl-email">' + escapeHtml(p.url) + '</div>' : '') +
                        '</td>' +
                        '<td>' + (parseInt(p.minutes, 10) || 0) + '</td>' +
                        '<td>' + escapeHtml(country) + '</td>' +
                        '<td>' + escapeHtml(p.city || '—') + '</td>' +
                        '</tr>';
                });
            }
            $('#cf-analyst-browse-table tbody').html(html);
            buildItemChart('cf-analyst-browse-chart', 'browsing', pages, COLORS.browsing, 'minutes');
        }

        function renderReading(d) {
            const articles = filterItems((d.reading && d.reading.articles) || [], filters.reading, ['title']);
            $('#cf-analyst-read-mins').text((d.reading && d.reading.minutes) || 0);
            $('#cf-analyst-read-count').text((d.reading && d.reading.articles_count) || 0);

            let html = '';
            if (!articles.length) {
                html = '<tr><td colspan="5" class="cf-empty">No reading activity today.</td></tr>';
            } else {
                articles.forEach(function (a) {
                    html += '<tr>' +
                        '<td>' + escapeHtml(a.title) + '</td>' +
                        '<td>' + (parseInt(a.minutes, 10) || 0) + '</td>' +
                        '<td>' + iconFlag(a.liked, '❤️') + '</td>' +
                        '<td>' + iconFlag(a.commented, '💬') + '</td>' +
                        '<td>' + iconFlag(a.shared, '🔗') + '</td>' +
                        '</tr>';
                });
            }
            $('#cf-analyst-read-table tbody').html(html);
            buildItemChart('cf-analyst-read-chart', 'reading', articles, COLORS.reading, 'minutes');
        }

        function renderTotal(d) {
            const t = d.total || {};
            $('#cf-analyst-total-mins').text(t.minutes || 0);
            $('#cf-analyst-pct-listen').text((t.pct_listening || 0) + '%');
            $('#cf-analyst-pct-browse').text((t.pct_browsing || 0) + '%');
            $('#cf-analyst-pct-read').text((t.pct_reading || 0) + '%');
            buildTotalChart(d);
        }

        function renderXfinity(d) {
            const x = d.xfinity || {};
            $('#cf-analyst-xfinity').text(Number(x.today || 0).toFixed(4));
            const songs = (x.songs || []).map(function (s) {
                return { title: s.title, xfinity: s.xfinity };
            });
            buildItemChart('cf-analyst-xfinity-chart', 'xfinity', songs, COLORS.xfinity, 'xfinity');
        }

        function applyDetail(d) {
            detail = d;
            const live = d.is_currently_active || d.status === 'live';
            $('#cf-analyst-name').text(d.display_name || 'Member');
            $('#cf-analyst-email').text(d.email || '');
            $('#cf-analyst-badge')
                .removeClass('cf-badge-success cf-badge-neutral')
                .addClass(live ? 'cf-badge-success' : 'cf-badge-neutral')
                .text(live ? '🟢 Live now' : 'Idle');
            const country = ((d.country_flag || '') + ' ' + (d.country_name || '')).trim();
            $('#cf-analyst-country').text(country || (d.ip_address ? ('IP ' + d.ip_address) : '—'));

            renderListening(d);
            renderBrowsing(d);
            renderReading(d);
            renderTotal(d);
            renderXfinity(d);

            $('#cf-analyst-loading').prop('hidden', true);
            $('#cf-analyst-body').prop('hidden', false);
        }

        function loadDetail() {
            $('#cf-analyst-loading').prop('hidden', false).text('Loading engagement data…');
            $('#cf-analyst-body').prop('hidden', true);
            adminPost('cf_admin_get_session_detail', { user_id: userId }, function (d) {
                applyDetail(d);
            }, function (err) {
                $('#cf-analyst-loading').text((err && err.message) || 'Failed to load.');
            });
        }

        $('#cf-analyst-refresh').on('click', loadDetail);

        $('#cf-analyst-listen-search').on('input', function () {
            filters.listening = $(this).val() || '';
            if (detail) renderListening(detail);
        });
        $('#cf-analyst-browse-search').on('input', function () {
            filters.browsing = $(this).val() || '';
            if (detail) renderBrowsing(detail);
        });
        $('#cf-analyst-read-search').on('input', function () {
            filters.reading = $(this).val() || '';
            if (detail) renderReading(detail);
        });

        $root.on('click', '.cf-chart-type-toggles .cf-chart-type-btn', function () {
            const $btn = $(this);
            const group = $btn.closest('.cf-chart-type-toggles').data('chart-group');
            const type = $btn.data('chart-type');
            if (!group || !type || chartTypes[group] === type) return;
            chartTypes[group] = type;
            $btn.siblings().removeClass('active');
            $btn.addClass('active');
            if (!detail) return;
            if (group === 'listening') renderListening(detail);
            else if (group === 'browsing') renderBrowsing(detail);
            else if (group === 'reading') renderReading(detail);
            else if (group === 'total') renderTotal(detail);
            else if (group === 'xfinity') renderXfinity(detail);
        });

        const $exportBtn = $('#cf-analyst-export-btn');
        const $exportMenu = $('#cf-analyst-export-menu');
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

        $exportMenu.on('click', 'button[data-format]', function () {
            const format = $(this).data('format') || 'csv';
            const scope = $(this).data('scope') || 'user';
            $exportMenu.prop('hidden', true);
            $exportBtn.attr('aria-expanded', 'false');
            try {
                const base = CF.export_detail_url || '';
                if (!base) throw new Error('Export URL missing.');
                const url = base +
                    (base.indexOf('?') >= 0 ? '&' : '?') +
                    'format=' + encodeURIComponent(format) +
                    '&scope=' + encodeURIComponent(scope) +
                    '&user_id=' + encodeURIComponent(userId) +
                    '&nonce=' + encodeURIComponent(CF.nonce || '');
                console.log('[cf-auth] analyst export navigate', scope, format, url);
                window.location.href = url;
            } catch (err) {
                console.error('[cf-auth] analyst export failed', err);
                adminMsg((err && err.message) || 'Export failed.', 'error');
            }
        });

        loadDetail();
    })();

})(jQuery);
