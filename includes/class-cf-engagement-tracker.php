<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Engagement_Tracker {

    private static $instance = null;

    /**
     * Anti-cheat: minimum seconds between accepted pings for the same user + activity type.
     * Frontend sends every 60s; 55s server floor blocks spam while allowing normal clock skew.
     */
    const PING_MIN_INTERVAL = 55;

    /** Seconds of engagement credited per accepted ping. */
    const PING_DURATION_SECONDS = 60;

    /** Extra seconds the reading-window token may live beyond one ping duration. */
    const READING_WINDOW_GRACE_SECONDS = 15;

    /** Minimum real client events required in a reading window. */
    const READING_MIN_EVENTS = 3;

    /** Minimum spread (ms) between earliest and latest event timestamps. */
    const READING_MIN_SPREAD_MS = 10000;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_cf_track_listening_ping', [ $this, 'handle_listening_ping' ] );
        add_action( 'wp_ajax_cf_track_reading_ping',   [ $this, 'handle_reading_ping' ] );
        add_action( 'wp_ajax_cf_start_reading_window', [ $this, 'handle_start_reading_window' ] );
        add_action( 'wp_enqueue_scripts',              [ $this, 'enqueue_scripts' ] );
    }

    public static function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_engagement_sessions';
    }

    /**
     * Enqueue tracker JS only for logged-in users on pages where engagement can occur
     * (articles for reading; anywhere else for the site-wide music player).
     */
    public function enqueue_scripts() {
        if ( ! is_user_logged_in() || is_admin() ) {
            return;
        }

        // Skip dedicated auth pages — no player / article reading there.
        if ( is_singular() ) {
            $post = get_post();
            if ( $post instanceof WP_Post ) {
                $auth_pages = [ 'cf-login', 'cf-register', 'cf-forgot-password', 'cf-reset-password', 'cf-verify-email' ];
                if ( in_array( $post->post_name, $auth_pages, true ) ) {
                    return;
                }
            }
        }

        wp_enqueue_script(
            'cf-engagement-tracker',
            CF_AUTH_URL . 'assets/js/cf-engagement-tracker.js',
            [ 'jquery', 'cf-auth-script' ],
            CF_AUTH_VERSION,
            true
        );

        wp_localize_script( 'cf-engagement-tracker', 'CF_ENGAGEMENT', [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'cf_auth_nonce' ),
            'post_id'      => is_singular() ? (int) get_queried_object_id() : 0,
            'is_article'   => ( is_singular( 'post' ) ? '1' : '0' ),
            'ping_ms'      => 60000,
        ] );
    }

    public function handle_listening_ping() {
        $this->handle_ping( 'listening', CF_Xfinity::LISTENING_RATE_PER_MINUTE );
    }

    /**
     * Issue a short-lived activity token for the next ~60s reading window.
     * The matching cf_track_reading_ping must present this token (once) to earn.
     */
    public function handle_start_reading_window() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ], 401 );
        }

        $user_id = get_current_user_id();
        $post_id = absint( $_POST['post_id'] ?? 0 );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid content.', 'cf-auth' ) ] );
        }

        $token = bin2hex( random_bytes( 16 ) );
        $ttl   = self::PING_DURATION_SECONDS + self::READING_WINDOW_GRACE_SECONDS;

        set_transient(
            $this->reading_window_transient_key( $user_id, $post_id ),
            [
                'token'  => $token,
                'events' => [],
            ],
            $ttl
        );

        wp_send_json_success( [
            'token'   => $token,
            'expires' => $ttl,
        ] );
    }

    /**
     * Reading ping: validate the per-window activity token + event timestamps
     * before awarding. Listening flow is unchanged (see handle_listening_ping).
     */
    public function handle_reading_ping() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ], 401 );
        }

        $user_id = get_current_user_id();
        $post_id = absint( $_POST['post_id'] ?? 0 );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid content.', 'cf-auth' ) ] );
        }

        $token  = sanitize_text_field( wp_unslash( $_POST['activity_token'] ?? '' ) );
        $events = $this->parse_reading_events( $_POST['events'] ?? [] );

        // Soft reject — no award, no detail that would help an attacker iterate.
        if ( ! $this->validate_reading_activity( $user_id, $post_id, $token, $events ) ) {
            wp_send_json_success( [ 'awarded' => false ] );
        }

        $this->handle_ping( 'reading', CF_Xfinity::READING_RATE_PER_MINUTE );
    }

    /**
     * LIMITATION (read before tightening further):
     * This check meaningfully blocks casual abuse — direct AJAX calls without a
     * prior cf_start_reading_window, replay of a captured ping (token is
     * single-use), and naive scripts that invent zero/clustered timestamps.
     * It does NOT stop a sophisticated bot that drives a real browser (or fully
     * simulates timed DOM events) and completes the start-window → collect →
     * ping handshake. There is no fully bulletproof server-side proof of
     * "a human was reading"; treat this as raising the bar, not as a seal.
     *
     * @param int    $user_id
     * @param int    $post_id
     * @param string $token
     * @param int[]  $events  Client Date.now() timestamps (ms).
     * @return bool
     */
    private function validate_reading_activity( $user_id, $post_id, $token, array $events ) {
        if ( $token === '' ) {
            return false;
        }

        $key  = $this->reading_window_transient_key( $user_id, $post_id );
        $data = get_transient( $key );

        // Consume immediately so a captured valid request cannot be replayed.
        delete_transient( $key );

        if ( ! is_array( $data ) || empty( $data['token'] ) ) {
            return false;
        }

        // (a) Token must match the one issued for this user + post window.
        if ( ! hash_equals( (string) $data['token'], $token ) ) {
            return false;
        }

        // (c) Enough distinct activity signals in the window.
        $events = array_values( array_unique( array_map( 'intval', $events ) ) );
        if ( count( $events ) < self::READING_MIN_EVENTS ) {
            return false;
        }

        sort( $events, SORT_NUMERIC );
        $min_ts = $events[0];
        $max_ts = $events[ count( $events ) - 1 ];
        $now_ms = (int) round( microtime( true ) * 1000 );
        $max_age_ms = ( self::PING_DURATION_SECONDS + self::READING_WINDOW_GRACE_SECONDS ) * 1000;

        // (d) Timestamps must be spread across the window, not batched in one burst.
        if ( ( $max_ts - $min_ts ) < self::READING_MIN_SPREAD_MS ) {
            return false;
        }

        // (e) All timestamps must be sane relative to "now" (no future, not too old).
        foreach ( $events as $ts ) {
            if ( $ts > $now_ms + 5000 ) { // 5s skew allowance
                return false;
            }
            if ( $ts < ( $now_ms - $max_age_ms ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    private function parse_reading_events( $raw ) {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( wp_unslash( $raw ), true );
            $raw     = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $raw ) ) {
            return [];
        }

        $out = [];
        foreach ( $raw as $ts ) {
            if ( is_numeric( $ts ) ) {
                $out[] = (int) $ts;
            }
        }
        return $out;
    }

    /**
     * @param int $user_id
     * @param int $post_id
     * @return string
     */
    private function reading_window_transient_key( $user_id, $post_id ) {
        return 'cf_read_win_' . absint( $user_id ) . '_' . absint( $post_id );
    }

    /**
     * Shared ping handler for listening and (post-validation) reading.
     *
     * @param string $activity_type listening|reading
     * @param float  $rate          Xfinity per minute for this activity.
     */
    private function handle_ping( $activity_type, $rate ) {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ], 401 );
        }

        $user_id = get_current_user_id();
        $post_id = absint( $_POST['post_id'] ?? 0 );

        if ( ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid content.', 'cf-auth' ) ] );
        }

        // Anti-cheat: rate-limit to max 1 accepted ping per PING_MIN_INTERVAL seconds
        // per user + activity type. Faster requests are ignored so spam cannot farm Xfinity.
        $rate_key = 'cf_eng_ping_' . $user_id . '_' . $activity_type;
        if ( get_transient( $rate_key ) ) {
            // Soft success — do not reward, do not tip off clients with a hard error.
            wp_send_json_success( [
                'awarded' => false,
                'reason'  => 'rate_limited',
            ] );
        }

        set_transient( $rate_key, 1, self::PING_MIN_INTERVAL );

        // Track last-seen IP for referral anti-cheat comparisons.
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( $ip !== '' ) {
            update_user_meta( $user_id, 'cf_last_ip', $ip );
        }

        $xfinity = round( (float) $rate, 2 );
        $is_valid = 1;

        global $wpdb;
        $wpdb->insert(
            self::sessions_table(),
            [
                'user_id'          => $user_id,
                'activity_type'    => $activity_type,
                'post_id'          => $post_id,
                'duration_seconds' => self::PING_DURATION_SECONDS,
                'xfinity_earned'   => $xfinity,
                'is_valid'         => $is_valid,
                'created_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%d', '%d', '%f', '%d', '%s' ]
        );

        $balance = CF_Xfinity::get_instance()->add_xfinity(
            $user_id,
            $xfinity,
            $activity_type,
            $post_id
        );

        // Confirm pending referral once the referred user shows real engagement.
        if ( class_exists( 'CF_Referral' ) ) {
            CF_Referral::get_instance()->confirm_referral( $user_id );
        }

        wp_send_json_success( [
            'awarded'        => true,
            'xfinity_earned' => $xfinity,
            'balance'        => $balance,
        ] );
    }
}
