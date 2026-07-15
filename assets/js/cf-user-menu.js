/* ═══════════════════════════════════════════════════
   CF Auth — User Menu JavaScript
   ═══════════════════════════════════════════════════ */

(function ($) {
    'use strict';

    const CF = window.CF_AUTH || {};

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

    // ── Close on backdrop click ───────────────────────────────────────────────
    $(document).on('click', '#cf-menu-backdrop', closeMenu);

    // ── Close on outside click ────────────────────────────────────────────────
    $(document).on('click', function (e) {
        if ($(e.target).closest('#cf-user-btn').length) {
            return;
        }
        if (!$(e.target).closest('#cf-user-menu').length) {
            closeMenu();
        }
    });

    // ── Close on Escape key ───────────────────────────────────────────────────
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
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

})(jQuery);
