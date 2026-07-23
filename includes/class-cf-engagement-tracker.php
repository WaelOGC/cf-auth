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

    /** Allowed activity_type values stored in cf_engagement_sessions. */
    const ACTIVITY_TYPES = [ 'listening', 'browsing', 'reading' ];

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_cf_track_listening_ping', [ $this, 'handle_listening_ping' ] );
        add_action( 'wp_ajax_cf_track_browsing_ping',  [ $this, 'handle_browsing_ping' ] );
        add_action( 'wp_ajax_cf_track_reading_ping',   [ $this, 'handle_reading_ping' ] );
        add_action( 'wp_ajax_cf_track_interaction',    [ $this, 'handle_interaction' ] );
        add_action( 'wp_enqueue_scripts',              [ $this, 'enqueue_scripts' ] );
    }

    public static function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_engagement_sessions';
    }

    /**
     * Per-user activity summary for "today" (site timezone), newest activity first.
     *
     * @return array<int, array{
     *   user_id:int, display_name:string, email:string, status:string,
     *   sessions_today:int, sessions_count:int,
     *   listening_minutes:int, browsing_minutes:int, reading_minutes:int, total_minutes:int,
     *   xfinity_today:float, last_activity:string, last_seen:string,
     *   last_activity_type:string, is_currently_active:bool,
     *   items:array{songs:array, pages:array, articles:array}
     * }>
     */
    public static function get_active_sessions_today() {
        global $wpdb;

        $table       = self::sessions_table();
        $today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
        // ~5 min cutoff ≈ several PING_MIN_INTERVAL (55s) windows — survives a couple missed pings.
        $cutoff      = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 5 * MINUTE_IN_SECONDS );
        $now_ts      = current_time( 'timestamp' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.user_id,
                    s.activity_type,
                    s.item_title,
                    s.item_url,
                    s.post_id,
                    s.duration_seconds,
                    s.xfinity_earned,
                    s.created_at
             FROM {$table} s
             WHERE s.created_at >= %s
               AND s.is_valid = 1
             ORDER BY s.created_at DESC",
            $today_start
        ) );

        /** @var array<int, array> $by_user */
        $by_user = [];

        foreach ( (array) $rows as $row ) {
            $uid  = (int) $row->user_id;
            $type = sanitize_key( $row->activity_type );
            if ( ! in_array( $type, self::ACTIVITY_TYPES, true ) ) {
                $type = 'listening';
            }

            if ( ! isset( $by_user[ $uid ] ) ) {
                $user = get_userdata( $uid );
                $by_user[ $uid ] = [
                    'user_id'             => $uid,
                    'display_name'        => $user ? $user->display_name : __( '(deleted user)', 'cf-auth' ),
                    'email'               => $user ? $user->user_email : '',
                    'status'              => 'idle',
                    'sessions_today'      => 0,
                    'sessions_count'      => 0, // backward-compat alias
                    'listening_minutes'   => 0,
                    'browsing_minutes'    => 0,
                    'reading_minutes'     => 0,
                    'total_minutes'       => 0,
                    'xfinity_today'       => 0.0,
                    'last_activity'       => '',
                    'last_seen'           => $row->created_at,
                    'last_activity_type'  => $type,
                    'is_currently_active' => false,
                    'items'               => [
                        'songs'    => [],
                        'pages'    => [],
                        'articles' => [],
                    ],
                    // Internal accumulators (seconds + item maps) stripped before return.
                    '_seconds' => [
                        'listening' => 0,
                        'browsing'  => 0,
                        'reading'   => 0,
                    ],
                    '_items' => [
                        'songs'    => [],
                        'pages'    => [],
                        'articles' => [],
                    ],
                ];
            }

            $entry =& $by_user[ $uid ];
            $entry['sessions_today']++;
            $entry['sessions_count'] = $entry['sessions_today'];
            $entry['xfinity_today'] += (float) $row->xfinity_earned;
            $entry['_seconds'][ $type ] += (int) $row->duration_seconds;

            if ( $row->created_at > $entry['last_seen'] ) {
                $entry['last_seen']          = $row->created_at;
                $entry['last_activity_type'] = $type;
            }

            $title = trim( (string) $row->item_title );
            if ( $title === '' && (int) $row->post_id > 0 ) {
                $resolved = get_the_title( (int) $row->post_id );
                $title    = is_string( $resolved ) ? trim( $resolved ) : '';
            }
            if ( $title === '' ) {
                $title = __( '(untitled)', 'cf-auth' );
            }

            $url = trim( (string) $row->item_url );
            if ( $url === '' && (int) $row->post_id > 0 ) {
                $permalink = get_permalink( (int) $row->post_id );
                $url       = is_string( $permalink ) ? $permalink : '';
            }

            $bucket = 'pages';
            if ( $type === 'listening' ) {
                $bucket = 'songs';
            } elseif ( $type === 'reading' ) {
                $bucket = 'articles';
            }

            $key = strtolower( $title );
            if ( ! isset( $entry['_items'][ $bucket ][ $key ] ) ) {
                $entry['_items'][ $bucket ][ $key ] = [
                    'title'   => $title,
                    'url'     => $url,
                    'seconds' => 0,
                ];
            }
            $entry['_items'][ $bucket ][ $key ]['seconds'] += (int) $row->duration_seconds;
            if ( $url !== '' && $entry['_items'][ $bucket ][ $key ]['url'] === '' ) {
                $entry['_items'][ $bucket ][ $key ]['url'] = $url;
            }

            unset( $entry );
        }

        $out = [];
        foreach ( $by_user as $entry ) {
            $listening_m = (int) round( $entry['_seconds']['listening'] / 60 );
            $browsing_m  = (int) round( $entry['_seconds']['browsing'] / 60 );
            $reading_m   = (int) round( $entry['_seconds']['reading'] / 60 );

            $items = [
                'songs'    => [],
                'pages'    => [],
                'articles' => [],
            ];
            foreach ( [ 'songs', 'pages', 'articles' ] as $bucket ) {
                foreach ( $entry['_items'][ $bucket ] as $item ) {
                    $items[ $bucket ][] = [
                        'title'   => $item['title'],
                        'url'     => $item['url'],
                        'minutes' => max( 1, (int) round( $item['seconds'] / 60 ) ),
                    ];
                }
                // Highest minutes first within each section.
                usort( $items[ $bucket ], static function ( $a, $b ) {
                    return $b['minutes'] <=> $a['minutes'];
                } );
            }

            $is_active = $entry['last_seen'] >= $cutoff;

            $out[] = [
                'user_id'             => $entry['user_id'],
                'display_name'        => $entry['display_name'],
                'email'               => $entry['email'],
                'status'              => $is_active ? 'live' : 'idle',
                'sessions_today'      => $entry['sessions_today'],
                'sessions_count'      => $entry['sessions_count'],
                'listening_minutes'   => $listening_m,
                'browsing_minutes'    => $browsing_m,
                'reading_minutes'     => $reading_m,
                'total_minutes'       => $listening_m + $browsing_m + $reading_m,
                'xfinity_today'       => round( (float) $entry['xfinity_today'], 2 ),
                // Intentionally uses human_time_diff() — do not replace with timezone math.
                'last_activity'       => human_time_diff( strtotime( $entry['last_seen'] ), $now_ts ) . ' ago',
                'last_seen'           => $entry['last_seen'],
                'last_activity_type'  => $entry['last_activity_type'],
                'is_currently_active' => $is_active,
                'items'               => $items,
            ];
        }

        usort( $out, static function ( $a, $b ) {
            return strcmp( $b['last_seen'], $a['last_seen'] );
        } );

        return $out;
    }

    /**
     * Enqueue tracker JS only for logged-in users on pages where engagement can occur.
     */
    public function enqueue_scripts() {
        if ( ! is_user_logged_in() || is_admin() ) {
            return;
        }

        // Skip dedicated auth pages — no player / meaningful page dwell there.
        if ( is_singular() ) {
            $post = get_post();
            if ( $post instanceof WP_Post ) {
                $auth_pages = [ 'cf-login', 'cf-register', 'cf-forgot-password', 'cf-reset-password', 'cf-verify-email' ];
                if ( in_array( $post->post_name, $auth_pages, true ) ) {
                    return;
                }
            }
        }

        $page_activity = self::detect_page_activity_type();
        // Singular pages/CPTs still get a post_id for labeling; archives/home may be 0.
        // Browsing/reading pings must NOT require a post_id (see handle_ping).
        $post_id       = is_singular() ? (int) get_queried_object_id() : 0;
        $item_title    = '';
        $item_url      = '';

        if ( $post_id ) {
            $item_title = get_the_title( $post_id );
            $item_url   = (string) get_permalink( $post_id );
        } else {
            $item_title = wp_get_document_title();
            // Prefer the real request URL so archive/home dwell is labeled correctly.
            $item_url = home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
        }

        if ( $item_title === '' ) {
            $item_title = ( $page_activity === 'reading' )
                ? __( 'Article', 'cf-auth' )
                : __( 'Browsing', 'cf-auth' );
        }

        $tracker_js = CF_AUTH_DIR . 'assets/js/cf-engagement-tracker.js';
        $tracker_ver = CF_AUTH_VERSION . '.' . ( file_exists( $tracker_js ) ? filemtime( $tracker_js ) : '0' );

        wp_enqueue_script(
            'cf-engagement-tracker',
            CF_AUTH_URL . 'assets/js/cf-engagement-tracker.js',
            [ 'jquery', 'cf-auth-script' ],
            $tracker_ver,
            true
        );

        wp_localize_script( 'cf-engagement-tracker', 'CF_ENGAGEMENT', [
            'ajax_url'           => admin_url( 'admin-ajax.php' ),
            'nonce'              => wp_create_nonce( 'cf_auth_nonce' ),
            'post_id'            => $post_id,
            'ping_ms'            => 60000,
            // Always a concrete type so the front-end dwell timer never no-ops.
            'page_activity_type' => $page_activity,
            'item_title'         => $item_title,
            'item_url'           => $item_url,
        ] );
    }

    /**
     * Classify the current front-end request for page-dwell tracking.
     * Blog articles (built-in post type, singular) → reading; everything else → browsing
     * (pages, archives, home, search, custom post types, etc.).
     *
     * @return string 'reading'|'browsing'
     */
    private static function detect_page_activity_type() {
        if ( is_singular( 'post' ) ) {
            return 'reading';
        }
        return 'browsing';
    }

    public function handle_listening_ping() {
        $this->handle_ping( 'listening', CF_Xfinity::LISTENING_RATE_PER_MINUTE );
    }

    public function handle_browsing_ping() {
        // Time-on-page only — browsing does not earn Xfinity (listening-only rewards).
        $this->handle_ping( 'browsing', 0 );
    }

    public function handle_reading_ping() {
        // Time-on-article only — reading does not earn Xfinity (listening-only rewards).
        $this->handle_ping( 'reading', 0 );
    }

    /**
     * Genuine player / page interaction — extends (or restarts) the 30-minute earning window.
     * Does not credit Xfinity by itself; subsequent pings do (listening only).
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

        if ( ! in_array( $activity_type, self::ACTIVITY_TYPES, true ) ) {
            $activity_type = 'listening';
        }

        // Throttle so this can't be spammed as a way to keep the window alive artificially.
        // $interaction_type is accepted (pause|playing|ended|seek|volume|page_view|…) but not required to reset.
        $throttle_key = 'cf_eng_interact_' . $user_id . '_' . $activity_type;
        if ( ! get_transient( $throttle_key ) ) {
            set_transient( $throttle_key, 1, 30 );
            update_user_meta( $user_id, 'cf_eng_window_anchor_' . $activity_type, time() );
        }

        wp_send_json_success( [ 'reset' => true, 'interaction_type' => $interaction_type ] );
    }

    /**
     * Engagement ping handler with 30-minute activity-window anti-cheat.
     *
     * Anchor meta `cf_eng_window_anchor_{$activity_type}` is the last moment we know
     * the user was genuinely present (playback start, page view, or a real interaction).
     * Plain pings do NOT advance the anchor — idle tabs stop earning once it goes stale.
     *
     * @param string $activity_type listening|browsing|reading.
     * @param float  $rate          Xfinity per minute for this activity (0 = track only).
     */
    private function handle_ping( $activity_type, $rate ) {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ], 401 );
        }

        if ( ! in_array( $activity_type, self::ACTIVITY_TYPES, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid activity.', 'cf-auth' ) ] );
        }

        $user_id    = get_current_user_id();
        $post_id    = absint( $_POST['post_id'] ?? 0 );
        $item_title = sanitize_text_field( wp_unslash( $_POST['item_title'] ?? '' ) );
        $item_url   = esc_url_raw( wp_unslash( $_POST['item_url'] ?? '' ) );

        // Listening still requires a track/post id; browsing/reading may be archives (post_id = 0).
        if ( $activity_type === 'listening' && ! $post_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid content.', 'cf-auth' ) ] );
        }

        if ( $item_title === '' && $post_id ) {
            $resolved   = get_the_title( $post_id );
            $item_title = is_string( $resolved ) ? $resolved : '';
        }

        if ( $item_url === '' && $post_id ) {
            $permalink = get_permalink( $post_id );
            $item_url  = is_string( $permalink ) ? $permalink : '';
        }

        // Archives / home often send post_id = 0 — still persist a readable label.
        if ( $item_title === '' ) {
            $item_title = ( $activity_type === 'reading' )
                ? __( 'Article', 'cf-auth' )
                : ( $activity_type === 'browsing' ? __( 'Browsing', 'cf-auth' ) : __( '(untitled)', 'cf-auth' ) );
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

        // 30-minute activity window — anchor is last known-genuine moment (play-start, page view, or interaction).
        // Plain pings do NOT advance the anchor.
        $anchor_key = 'cf_eng_window_anchor_' . $activity_type;
        $anchor     = (int) get_user_meta( $user_id, $anchor_key, true );
        $now        = time();

        if ( ! $anchor ) {
            // First ping of a fresh session — grace period starts now.
            update_user_meta( $user_id, $anchor_key, $now );
        } elseif ( ( $now - $anchor ) > self::WINDOW_SECONDS ) {
            if ( $activity_type === 'listening' ) {
                // Listening stays strict: only cf_track_interaction (player events) may restart.
                wp_send_json_success( [
                    'awarded' => false,
                    'reason'  => 'window_expired',
                ] );
            }
            // Browsing/reading: a dwell ping from a visible tab is itself presence —
            // restart the window (avoids a race with the async page_view interaction).
            update_user_meta( $user_id, $anchor_key, $now );
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
                // Prefer empty strings over NULL — more reliable across wpdb/MySQL versions.
                'item_title'       => $item_title,
                'item_url'         => $item_url,
                'post_id'          => $post_id,
                'duration_seconds' => self::PING_DURATION_SECONDS,
                'xfinity_earned'   => $xfinity,
                'is_valid'         => $is_valid,
                'created_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%s' ]
        );

        $balance = false;
        // Only listening awards Xfinity; browsing/reading are time-tracking only.
        if ( $xfinity > 0 ) {
            $balance = CF_Xfinity::get_instance()->add_xfinity(
                $user_id,
                $xfinity,
                $activity_type,
                $post_id ?: null
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
        }

        // Confirm pending referral once the referred user shows real engagement.
        if ( class_exists( 'CF_Referral' ) ) {
            CF_Referral::get_instance()->confirm_referral( $user_id );
        }

        wp_send_json_success( [
            'awarded'        => $xfinity > 0,
            'xfinity_earned' => $xfinity,
            'balance'        => $balance,
        ] );
    }
}
