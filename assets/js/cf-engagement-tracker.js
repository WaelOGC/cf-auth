/* ═══════════════════════════════════════════════════
   CF Auth — Engagement Tracker (listening)
   Collective Finity
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    const CFG = window.CF_ENGAGEMENT || {};
    const AUTH = window.CF_AUTH || {};
    const ajaxUrl = CFG.ajax_url || AUTH.ajax_url || '';
    const pingMs = parseInt(CFG.ping_ms, 10) || 60000;

    function currentNonce() {
        // Prefer CF_AUTH.nonce — it is refreshed after 403 via cf_refresh_nonces.
        return AUTH.nonce || CFG.nonce || '';
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

    function sendListeningPing() {
        if (!listeningPostId || AUTH.is_logged_in !== '1') {
            return;
        }
        post('cf_track_listening_ping', { post_id: listeningPostId });
    }

    function startListeningPings(postId) {
        postId = parseInt(postId, 10) || 0;
        if (!postId || AUTH.is_logged_in !== '1') {
            return;
        }

        // Switch tracks: reset interval so the new post_id is credited.
        if (listeningPostId !== postId) {
            stopListeningPings();
            listeningPostId = postId;
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
    }

    // ── Interaction reporter (extends / restarts the 30-min earning window) ───
    // Throttled to at most once per 30 seconds so scrubbing doesn't spam AJAX.
    let lastInteractionMs = 0;
    const INTERACTION_THROTTLE_MS = 30000;

    function reportInteraction(type) {
        if (AUTH.is_logged_in !== '1') {
            return;
        }

        const now = Date.now();
        if (now - lastInteractionMs < INTERACTION_THROTTLE_MS) {
            return;
        }
        lastInteractionMs = now;

        post('cf_track_interaction', {
            activity_type: 'listening',
            interaction_type: type || 'click',
        });
    }

    // Custom events the theme/player can dispatch:
    //   document.dispatchEvent(new CustomEvent('cf:player:playing', { detail: { postId: 123 } }))
    //   document.dispatchEvent(new CustomEvent('cf:player:pause'))
    //   document.dispatchEvent(new CustomEvent('cf:player:ended'))
    //   document.dispatchEvent(new CustomEvent('cf:player:seeked', { detail: { postId: 123 } }))
    //   document.dispatchEvent(new CustomEvent('cf:player:volume', { detail: { postId: 123 } }))
    document.addEventListener('cf:player:playing', function (e) {
        const id = e && e.detail && (e.detail.postId || e.detail.trackId || e.detail.post_id);
        if (id) {
            startListeningPings(id);
        }
        reportInteraction('playing');
    });
    document.addEventListener('cf:player:pause', function () {
        stopListeningPings();
        reportInteraction('pause');
    });
    document.addEventListener('cf:player:ended', function () {
        clearListeningSession();
        reportInteraction('ended');
    });
    document.addEventListener('cf:player:seeked', function () {
        reportInteraction('seek');
    });
    document.addEventListener('cf:player:volume', function () {
        reportInteraction('volume');
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

    $(document).on('playing', 'audio, video', function () {
        const id = mediaPostId(this);
        if (id) {
            startListeningPings(id);
        }
        reportInteraction('playing');
    });
    $(document).on('pause', 'audio, video', function () {
        stopListeningPings();
        reportInteraction('pause');
    });
    $(document).on('ended', 'audio, video', function () {
        stopListeningPings();
        reportInteraction('ended');
    });
    $(document).on('seeked', 'audio, video', function () {
        reportInteraction('seek');
    });
    $(document).on('volumechange', 'audio, video', function () {
        reportInteraction('volume');
    });

    // Piggyback on existing CF_Auth.logListening(trackId) integration used by the player.
    function patchCfAuthApi() {
        if (!window.CF_Auth) {
            return;
        }
        const orig = window.CF_Auth.logListening;
        window.CF_Auth.logListening = function (id) {
            startListeningPings(id);
            return typeof orig === 'function' ? orig(id) : undefined;
        };
        window.CF_Auth.startListeningPings = startListeningPings;
        window.CF_Auth.stopListeningPings = stopListeningPings;
    }
    patchCfAuthApi();

})(jQuery);
