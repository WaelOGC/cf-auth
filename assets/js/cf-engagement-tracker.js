/* ═══════════════════════════════════════════════════
   CF Auth — Engagement Tracker (listening / browsing / reading)
   Collective Finity
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    const CFG = window.CF_ENGAGEMENT || {};
    const AUTH = window.CF_AUTH || {};
    const ajaxUrl = CFG.ajax_url || AUTH.ajax_url || '';
    const pingMs = parseInt(CFG.ping_ms, 10) || 60000;

    function isLoggedIn() {
        const live = window.CF_AUTH || AUTH;
        return live.is_logged_in === '1';
    }

    function currentNonce() {
        // Prefer CF_AUTH.nonce — it is refreshed after 403 via cf_refresh_nonces.
        const live = window.CF_AUTH || AUTH;
        return live.nonce || CFG.nonce || '';
    }

    function post(action, data) {
        return $.post(ajaxUrl, Object.assign({
            action: action,
            nonce: currentNonce(),
        }, data || {}));
    }

    // ── Listening pings (every 60s while actively playing) ────────────────────
    let listeningTimer = null;
    let listeningPostId = 0;
    let listeningTitle = '';
    let listeningUrl = '';

    function sendListeningPing() {
        if (!listeningPostId || !isLoggedIn()) {
            return;
        }
        post('cf_track_listening_ping', {
            post_id: listeningPostId,
            item_title: listeningTitle || '',
            item_url: listeningUrl || '',
        });
    }

    function startListeningPings(postId, meta) {
        postId = parseInt(postId, 10) || 0;
        if (!postId || !isLoggedIn()) {
            return;
        }

        // Switch tracks: reset interval so the new post_id is credited.
        if (listeningPostId !== postId) {
            stopListeningPings();
            listeningPostId = postId;
            listeningTitle = (meta && meta.title) || '';
            listeningUrl = (meta && meta.url) || '';
        }

        if (listeningTimer) {
            return;
        }

        listeningTimer = setInterval(sendListeningPing, pingMs);
    }

    function stopListeningPings() {
        if (listeningTimer) {
            clearInterval(listeningTimer);
            listeningTimer = null;
        }
        // Keep last post_id so a resume without a new track id still works.
    }

    function clearListeningSession() {
        stopListeningPings();
        listeningPostId = 0;
        listeningTitle = '';
        listeningUrl = '';
    }

    // ── Interaction reporter (extends / restarts the 30-min earning window) ───
    // Throttled to at most once per 30 seconds so scrubbing doesn't spam AJAX.
    const lastInteractionMs = {};
    const INTERACTION_THROTTLE_MS = 30000;

    function reportInteraction(activityType, type) {
        if (!isLoggedIn()) {
            return;
        }

        activityType = activityType || 'listening';
        const now = Date.now();
        if ((now - (lastInteractionMs[activityType] || 0)) < INTERACTION_THROTTLE_MS) {
            return;
        }
        lastInteractionMs[activityType] = now;

        post('cf_track_interaction', {
            activity_type: activityType,
            interaction_type: type || 'click',
        });
    }

    // ── Page dwell: browsing / reading ────────────────────────────────────────
    // Same 60s heartbeat as listening, paused when the tab is hidden.
    // Reading = singular blog posts; everything else (pages, archives, home, CPTs) = browsing.
    // post_id may be 0 on archives — that must NOT block browsing pings.
    let pageTimer = null;
    // Default to browsing so a missing localize key still tracks dwell (reading is the special case).
    const pageActivityType = (CFG.page_activity_type === 'reading') ? 'reading' : 'browsing';
    const pagePostId = parseInt(CFG.post_id, 10) || 0;
    const pageTitle = CFG.item_title || (document.title || '') || (pageActivityType === 'reading' ? 'Article' : 'Browsing');
    const pageUrl = CFG.item_url || window.location.href;

    function pagePingAction() {
        return pageActivityType === 'reading' ? 'cf_track_reading_ping' : 'cf_track_browsing_ping';
    }

    function sendPagePing() {
        if (!isLoggedIn()) {
            return;
        }
        if (typeof document.visibilityState !== 'undefined' && document.visibilityState !== 'visible') {
            return;
        }
        post(pagePingAction(), {
            // Explicit 0 is fine for browsing archives — server allows it for non-listening.
            post_id: pagePostId,
            item_title: pageTitle,
            item_url: pageUrl,
            activity_type: pageActivityType,
        });
    }

    function startPagePings() {
        if (pageTimer || !isLoggedIn()) {
            return;
        }
        // Seed the 30-min window, then credit dwell every minute while visible.
        reportInteraction(pageActivityType, 'page_view');
        sendPagePing();
        pageTimer = setInterval(sendPagePing, pingMs);
    }

    function stopPagePings() {
        if (pageTimer) {
            clearInterval(pageTimer);
            pageTimer = null;
        }
    }

    function syncPagePingsWithVisibility() {
        if (typeof document.visibilityState === 'undefined') {
            startPagePings();
            return;
        }
        if (document.visibilityState === 'visible') {
            startPagePings();
        } else {
            stopPagePings();
        }
    }

    // Custom events the theme/player can dispatch:
    //   document.dispatchEvent(new CustomEvent('cf:player:playing', { detail: { postId: 123, title: '…' } }))
    //   document.dispatchEvent(new CustomEvent('cf:player:pause'))
    //   document.dispatchEvent(new CustomEvent('cf:player:ended'))
    //   document.dispatchEvent(new CustomEvent('cf:player:seeked', { detail: { postId: 123 } }))
    //   document.dispatchEvent(new CustomEvent('cf:player:volume', { detail: { postId: 123 } }))
    document.addEventListener('cf:player:playing', function (e) {
        const detail = (e && e.detail) || {};
        const id = detail.postId || detail.trackId || detail.post_id;
        if (id) {
            startListeningPings(id, { title: detail.title || '', url: detail.url || '' });
        }
        reportInteraction('listening', 'playing');
    });
    document.addEventListener('cf:player:pause', function () {
        stopListeningPings();
        reportInteraction('listening', 'pause');
    });
    document.addEventListener('cf:player:ended', function () {
        clearListeningSession();
        reportInteraction('listening', 'ended');
    });
    document.addEventListener('cf:player:seeked', function () {
        reportInteraction('listening', 'seek');
    });
    document.addEventListener('cf:player:volume', function () {
        reportInteraction('listening', 'volume');
    });

    // HTML5 media fallback: play/pause on <audio>/<video> with data-track-id / data-post-id.
    function mediaPostId(el) {
        if (!el || !el.getAttribute) {
            return 0;
        }
        return parseInt(
            el.getAttribute('data-track-id') ||
            el.getAttribute('data-post-id') ||
            el.getAttribute('data-id') ||
            '0',
            10
        ) || 0;
    }

    function mediaMeta(el) {
        if (!el || !el.getAttribute) {
            return { title: '', url: '' };
        }
        return {
            title: el.getAttribute('data-track-title') || el.getAttribute('title') || '',
            url: el.getAttribute('data-track-url') || '',
        };
    }

    $(document).on('playing', 'audio, video', function () {
        const id = mediaPostId(this);
        if (id) {
            startListeningPings(id, mediaMeta(this));
        }
        reportInteraction('listening', 'playing');
    });
    $(document).on('pause', 'audio, video', function () {
        stopListeningPings();
        reportInteraction('listening', 'pause');
    });
    $(document).on('ended', 'audio, video', function () {
        stopListeningPings();
        reportInteraction('listening', 'ended');
    });
    $(document).on('seeked', 'audio, video', function () {
        reportInteraction('listening', 'seek');
    });
    $(document).on('volumechange', 'audio, video', function () {
        reportInteraction('listening', 'volume');
    });

    // Piggyback on existing CF_Auth.logListening(trackId) integration used by the player.
    function patchCfAuthApi() {
        if (!window.CF_Auth) {
            return;
        }
        const orig = window.CF_Auth.logListening;
        window.CF_Auth.logListening = function (id, meta) {
            startListeningPings(id, meta || {});
            return typeof orig === 'function' ? orig(id) : undefined;
        };
        window.CF_Auth.startListeningPings = startListeningPings;
        window.CF_Auth.stopListeningPings = stopListeningPings;
    }
    patchCfAuthApi();

    // Kick off browsing/reading dwell tracking once the DOM is ready.
    $(function () {
        syncPagePingsWithVisibility();
        document.addEventListener('visibilitychange', syncPagePingsWithVisibility);
    });

})(jQuery);
