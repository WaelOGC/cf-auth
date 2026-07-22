/* ═══════════════════════════════════════════════════
   CF Auth — Engagement Tracker (listening + reading)
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

    // Custom events the theme/player can dispatch:
    //   document.dispatchEvent(new CustomEvent('cf:player:playing', { detail: { postId: 123 } }))
    //   document.dispatchEvent(new CustomEvent('cf:player:pause'))
    document.addEventListener('cf:player:playing', function (e) {
        const id = e && e.detail && (e.detail.postId || e.detail.trackId || e.detail.post_id);
        if (id) {
            startListeningPings(id);
        }
    });
    document.addEventListener('cf:player:pause', stopListeningPings);
    document.addEventListener('cf:player:ended', clearListeningSession);

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
    });
    $(document).on('pause ended', 'audio, video', function () {
        stopListeningPings();
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

    // ── Reading pings (article pages; server verifies token + event timing) ───
    if (CFG.is_article === '1' && AUTH.is_logged_in === '1') {
        const articlePostId = parseInt(CFG.post_id, 10) || 0;
        let readingToken = '';
        let eventTimestamps = [];
        let lastEventPushMs = 0;
        let pingInFlight = false;

        function recordActivity() {
            const now = Date.now();
            // Throttle to at most one timestamp per second to keep the payload small.
            if (now - lastEventPushMs < 1000) {
                return;
            }
            lastEventPushMs = now;
            eventTimestamps.push(now);
        }

        function startReadingWindow() {
            if (!articlePostId) {
                return $.Deferred().reject().promise();
            }
            return post('cf_start_reading_window', { post_id: articlePostId })
                .then(function (r) {
                    if (r && r.success && r.data && r.data.token) {
                        readingToken = r.data.token;
                        return readingToken;
                    }
                    readingToken = '';
                    return $.Deferred().reject(r).promise();
                });
        }

        function sendReadingPing() {
            if (!articlePostId || !readingToken || pingInFlight) {
                return;
            }

            // Snapshot + clear before the request so concurrent events go to the next window.
            const token = readingToken;
            const events = eventTimestamps.slice();
            eventTimestamps = [];
            readingToken = '';
            pingInFlight = true;

            post('cf_track_reading_ping', {
                post_id: articlePostId,
                activity_token: token,
                events: JSON.stringify(events),
            })
                .always(function () {
                    pingInFlight = false;
                    // Fresh token for the next ~60s window (required before the next award).
                    startReadingWindow();
                });
        }

        // Any of these signals counts as "reading" for the current window.
        $(document).on('scroll.cfEngagement mousemove.cfEngagement keydown.cfEngagement', recordActivity);
        $(window).on('scroll.cfEngagement', recordActivity);

        // First window token on page load, then ping on the interval.
        startReadingWindow();
        setInterval(function () {
            if (!readingToken && !pingInFlight) {
                startReadingWindow();
                return;
            }
            sendReadingPing();
        }, pingMs);
    }

})(jQuery);
