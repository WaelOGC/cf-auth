/* ═══════════════════════════════════════════════════
   CF Auth — User Menu JavaScript
   ═══════════════════════════════════════════════════ */

(function ($) {
    'use strict';

    const CF = window.CF_AUTH || {};

    // ── Local helpers (escHtml in cf-auth.js is IIFE-scoped) ───────────────────
    function escHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escAttr(text) {
        return escHtml(text);
    }

    function relativeTime(mysqlDatetime) {
        if (!mysqlDatetime) return '';
        const d = new Date(String(mysqlDatetime).replace(' ', 'T'));
        if (isNaN(d.getTime())) return '';
        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    // ── Toggle Dropdown ───────────────────────────────────────────────────────
    $(document).on('click', '#cf-user-btn', function (e) {
        e.stopPropagation();
        e.stopImmediatePropagation();
        const $btn      = $(this);
        const $dropdown = $('#cf-user-dropdown');
        const $backdrop = $('#cf-menu-backdrop');
        const isOpen    = $dropdown.hasClass('is-open');

        if (isOpen) {
            closeMenu();
        } else {
            closeNotifDropdown();
            openMenu($btn, $dropdown, $backdrop);
        }
    });

    function openMenu($btn, $dropdown, $backdrop) {
        $btn.addClass('is-open').attr('aria-expanded', 'true');
        $dropdown.addClass('is-open');
        $backdrop.addClass('is-open');
    }

    function closeMenu() {
        $('#cf-user-btn').removeClass('is-open').attr('aria-expanded', 'false');
        $('#cf-user-dropdown').removeClass('is-open');
        $('#cf-menu-backdrop').removeClass('is-open');
    }

    // ── Notification bell toggle ──────────────────────────────────────────────
    $(document).on('click', '#cf-notif-bell-btn', function (e) {
        e.stopPropagation();
        e.stopImmediatePropagation();
        const $btn = $(this);
        const $dd  = $('#cf-notif-dropdown');
        const isOpen = $dd.hasClass('is-open');
        if (isOpen) {
            closeNotifDropdown();
        } else {
            closeMenu();
            $btn.addClass('is-open').attr('aria-expanded', 'true');
            $dd.addClass('is-open');
            loadNotifications();
        }
    });

    function closeNotifDropdown() {
        $('#cf-notif-bell-btn').removeClass('is-open').attr('aria-expanded', 'false');
        $('#cf-notif-dropdown').removeClass('is-open');
    }

    // ── Close on backdrop click ───────────────────────────────────────────────
    $(document).on('click', '#cf-menu-backdrop', function () {
        closeMenu();
        closeNotifDropdown();
    });

    // ── Close on outside click ────────────────────────────────────────────────
    $(document).on('click', function (e) {
        const $t = $(e.target);
        if (!$t.closest('#cf-user-btn').length && !$t.closest('#cf-user-menu').length) {
            closeMenu();
        }
        if (!$t.closest('#cf-notif-bell-btn').length && !$t.closest('#cf-notif-bell').length) {
            closeNotifDropdown();
        }
    });

    // ── Close on Escape key ───────────────────────────────────────────────────
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            closeMenu();
            closeNotifDropdown();
        }
    });

    // ── Notifications: fetch + render ─────────────────────────────────────────
    function updateBadge(count) {
        const $badge = $('#cf-notif-badge');
        if (count > 0) {
            $badge.text(count > 99 ? '99+' : count).show();
        } else {
            $badge.hide();
        }
    }

    function renderNotifications(items, unreadCount) {
        updateBadge(unreadCount);
        const $list = $('#cf-notif-list');
        if (!items.length) {
            $list.html('<p class="cf-muted cf-notif-empty">' + 'No notifications yet.' + '</p>');
            return;
        }
        let h = '';
        items.forEach(function (n) {
            const unreadClass = n.is_read ? '' : ' cf-notif-item--unread';
            const href = n.link ? n.link : '#';
            h += '<a href="' + escAttr(href) + '" class="cf-notif-item' + unreadClass + '" data-id="' + n.id + '">' +
                    '<span class="cf-notif-item-title">' + escHtml(n.title) + '</span>' +
                    '<span class="cf-notif-item-msg">' + escHtml(n.message) + '</span>' +
                    '<span class="cf-notif-item-time">' + escHtml(relativeTime(n.created_at)) + '</span>' +
                 '</a>';
        });
        $list.html(h);
    }

    function loadNotifications() {
        $.post(CF.ajax_url, { action: 'cf_get_notifications', nonce: CF.nonce }, function (res) {
            if (!res.success) return;
            renderNotifications(res.data.notifications, res.data.unread_count);
        });
    }

    // ── Click a notification → mark read + navigate ───────────────────────────
    $(document).on('click', '.cf-notif-item', function (e) {
        const id = $(this).data('id');
        $.post(CF.ajax_url, { action: 'cf_mark_notifications_read', nonce: CF.nonce, notification_id: id });
        if ($(this).attr('href') === '#') e.preventDefault();
    });

    // ── Mark all read ─────────────────────────────────────────────────────────
    $(document).on('click', '#cf-notif-mark-all', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $.post(CF.ajax_url, { action: 'cf_mark_notifications_read', nonce: CF.nonce }, function () {
            $('.cf-notif-item--unread').removeClass('cf-notif-item--unread');
            updateBadge(0);
        });
    });

    // ── Logout from menu ──────────────────────────────────────────────────────
    $(document).on('click', '#cf-menu-logout', function () {
        const $btn = $(this);
        $btn.text('Signing out...').prop('disabled', true);

        $.post(CF.ajax_url, {
            action: 'cf_logout',
            nonce:  CF.nonce,
        }, function (res) {
            if (res.success) {
                window.location.href = res.data.redirect;
            }
        });
    });

    // ── Tab navigation from dropdown links ────────────────────────────────────
    // e.g. clicking "Favorites" goes to /cf-profile and opens Favorites tab
    $(document).on('click', '.cf-dropdown-item[href*="#"]', function (e) {
        const href   = $(this).attr('href');
        const hash   = href.split('#')[1];
        const page   = href.split('#')[0];
        const isProfile = window.location.pathname.replace(/\/$/, '') === page.replace(/\/$/, '').replace(window.location.origin, '');

        // If already on profile page, switch tab directly
        if (isProfile && hash) {
            e.preventDefault();
            closeMenu();
            // Trigger tab switch
            $('.cf-tab[data-tab="' + hash + '"]').trigger('click');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        // Otherwise let normal navigation happen
    });

    // ── Create backdrop element if not exists ─────────────────────────────────
    if (!$('#cf-menu-backdrop').length) {
        $('body').append('<div id="cf-menu-backdrop" class="cf-menu-backdrop"></div>');
    }

    // ── Badge on load + poll (logged-in only) ─────────────────────────────────
    if (CF.is_logged_in === '1') {
        loadNotifications();
        setInterval(function () {
            if ($('#cf-notif-dropdown').hasClass('is-open')) return;
            loadNotifications();
        }, 60000);
    }

})(jQuery);
