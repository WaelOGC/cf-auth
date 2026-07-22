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

    /** Max seconds an activity window can earn without a real interaction. */
    const WINDOW_SECONDS = 1800;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_cf_track_listening_ping', [ $this, 'handle_listening_ping' ] );
        add_action( 'wp_ajax_cf_track_interaction',    [ $this, 'handle_interaction' ] );
        add_action( 'wp_enqueue_scripts',              [ $this, 'enqueue_scripts' ] );
    }

    public static function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_engagement_sessions';
    }

    /**
     * Per-user listening summary for "today" (site timezone), newest activity first.
     *
     * @return array<int, array{user_id:int, display_name:string, email:string,
     *   sessions_count:int, total_minutes:int, xfinity_today:float,
     *   last_activity_type:string, last_seen:string, is_currently_active:bool}>
     */
    public static function get_active_sessions_today() {
        global $wpdb;

        $table       = self::sessions_table();
        $today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
        // ~5 min cutoff ≈ several PING_MIN_INTERVAL (55s) windows — survives a couple missed pings.
        $cutoff      = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 5 * MINUTE_IN_SECONDS );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.user_id,
                    COUNT(*) AS sessions_count,
                    SUM(s.duration_seconds) AS total_seconds,
                    SUM(s.xfinity_earned) AS xfinity_today,
                    MAX(s.created_at) AS last_seen,
                    SUBSTRING_INDEX(GROUP_CONCAT(s.activity_type ORDER BY s.created_at DESC), ',', 1) AS last_activity_type
             FROM {$table} s
             WHERE s.created_at >= %s
               AND s.is_valid = 1
             GROUP BY s.user_id
             ORDER BY last_seen DESC",
            $today_start
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $user = get_userdata( (int) $row->user_id );
            $out[] = [
                'user_id'             => (int) $row->user_id,
                'display_name'        => $user ? $user->display_name : __( '(deleted user)', 'cf-auth' ),
                'email'               => $user ? $user->user_email : '',
                'sessions_count'      => (int) $row->sessions_count,
                'total_minutes'       => (int) round( (int) $row->total_seconds / 60 ),
                'xfinity_today'       => round( (float) $row->xfinity_today, 2 ),
                'last_activity_type'  => $row->last_activity_type,
                'last_seen'           => $row->last_seen,
                'is_currently_active' => $row->last_seen >= $cutoff,
            ];
        }

        return $out;
    }

    /**
     * Enqueue tracker JS only for logged-in users on pages where listening can occur.
     */
    public function enqueue_scripts() {
        if ( ! is_user_logged_in() || is_admin() ) {
            return;
        }

        // Skip dedicated auth pages — no player there.
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
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cf_auth_nonce' ),
            'post_id'  => is_singular() ? (int) get_queried_object_id() : 0,
            'ping_ms'  => 60000,
        ] );
    }

    public function handle_listening_ping() {
        $this->handle_ping( 'listening', CF_Xfinity::LISTENING_RATE_PER_MINUTE );
    }

    /**
     * Genuine player interaction — extends (or restarts) the 30-minute earning window.
     * Does not credit Xfinity by itself; subsequent listening pings do.
     * Server-throttled so it cannot be spammed to keep the window alive artificially.
     */
    public function handle_interaction() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ], 401 );
        }

        $user_id          = get_current_user_id();
        $activity_type    = sanitize_key( $_POST['activity_type'] ?? 'listening' );
        $interaction_type = sanitize_key( $_POST['interaction_type'] ?? '' );

        if ( $activity_type === '' ) {
            $activity_type = 'listening';
        }

        // Throttle so this can't be spammed as a way to keep the window alive artificially.
        // $interaction_type is accepted (pause|playing|ended|seek|volume|…) but not required to reset.
        $throttle_key = 'cf_eng_interact_' . $user_id . '_' . $activity_type;
        if ( ! get_transient( $throttle_key ) ) {
            set_transient( $throttle_key, 1, 30 );
            update_user_meta( $user_id, 'cf_eng_window_anchor_' . $activity_type, time() );
        }

        wp_send_json_success( [ 'reset' => true ] );
    }

    /**
     * Listening ping handler with 30-minute activity-window anti-cheat.
     *
     * Anchor meta `cf_eng_window_anchor_{$activity_type}` is the last moment we know
     * the user was genuinely present (playback start or a real interaction). Plain
     * pings do NOT advance the anchor — idle tabs stop earning once it goes stale.
     *
     * @param string $activity_type Currently only listening.
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

        // 30-minute activity window — anchor is last known-genuine moment (play-start or interaction).
        // Plain pings do NOT advance the anchor.
        $anchor_key = 'cf_eng_window_anchor_' . $activity_type;
        $anchor     = (int) get_user_meta( $user_id, $anchor_key, true );
        $now        = time();

        if ( ! $anchor ) {
            // First ping of a fresh session — grace period starts now.
            update_user_meta( $user_id, $anchor_key, $now );
        } elseif ( ( $now - $anchor ) > self::WINDOW_SECONDS ) {
            // 30+ minutes since the last known-genuine moment with zero interaction —
            // stop crediting. Anchor stays stale on purpose; only cf_track_interaction resets it.
            wp_send_json_success( [
                'awarded' => false,
                'reason'  => 'window_expired',
            ] );
        }
        // else: within the 30-minute window — credit normally, anchor NOT advanced by a plain ping.

        // Track last-seen IP for referral anti-cheat comparisons.
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( $ip !== '' ) {
            update_user_meta( $user_id, 'cf_last_ip', $ip );
        }

        $xfinity  = round( (float) $rate, 2 );
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

        // Notify only when the running balance crosses a whole Xfinity point
        // (transient holds last notified whole number so this doesn't fire every 60s).
        if ( $balance !== false && class_exists( 'CF_Notifications' ) ) {
            $threshold_key   = 'cf_eng_notif_threshold_' . $user_id;
            $stored          = get_transient( $threshold_key );
            $balance_before  = round( (float) $balance - (float) $xfinity, 2 );
            $last_notified   = ( false === $stored )
                ? (int) floor( $balance_before ) // seed so existing balances don't backfill-notify
                : (int) $stored;
            $current_whole = (int) floor( (float) $balance );

            if ( $current_whole > $last_notified ) {
                CF_Notifications::create_for_user(
                    $user_id,
                    'xfinity_earned',
                    __( 'You earned Xfinity', 'cf-auth' ),
                    sprintf( __( '+%s Xfinity from listening.', 'cf-auth' ), $xfinity ),
                    home_url( '/cf-profile#rewards' )
                );
                set_transient( $threshold_key, $current_whole, DAY_IN_SECONDS );
            } elseif ( false === $stored ) {
                set_transient( $threshold_key, $last_notified, DAY_IN_SECONDS );
            }
        }

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
