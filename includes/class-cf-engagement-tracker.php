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

    /** Allowed share item_type values. */
    const SHARE_ITEM_TYPES = [ 'post', 'track', 'album' ];

    /** Allowed share platform values. */
    const SHARE_PLATFORMS = [ 'facebook', 'twitter', 'linkedin', 'whatsapp', 'copy', 'native' ];

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
        add_action( 'wp_ajax_cf_track_share',          [ $this, 'handle_track_share' ] );
        add_action( 'wp_enqueue_scripts',              [ $this, 'enqueue_scripts' ] );
    }

    public static function sessions_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_engagement_sessions';
    }

    public static function shares_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_shares';
    }

    // ── Geo helpers ───────────────────────────────────────────────────────────

    /**
     * Resolve country/city for an IP via ip-api.com (cached 7 days).
     * Private/local IPs skip the lookup and return null fields.
     *
     * @param string $ip
     * @return array{country_code:?string,country_name:?string,city:?string}
     */
    public static function get_geo_for_ip( $ip ) {
        $empty = [
            'country_code' => null,
            'country_name' => null,
            'city'         => null,
        ];

        $ip = trim( (string) $ip );
        if ( $ip === '' || self::is_private_or_local_ip( $ip ) ) {
            return $empty;
        }

        $cache_key = 'cf_geo_' . md5( $ip );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && array_key_exists( 'country_code', $cached ) ) {
            return [
                'country_code' => $cached['country_code'] ?? null,
                'country_name' => $cached['country_name'] ?? null,
                'city'         => $cached['city'] ?? null,
            ];
        }

        $url      = 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,countryCode,city';
        $response = wp_remote_get( $url, [
            'timeout' => 4,
            'headers' => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $empty;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || ! is_array( $body ) || ( $body['status'] ?? '' ) !== 'success' ) {
            // Cache failures briefly to avoid hammering the free API on bad IPs.
            set_transient( $cache_key, $empty, HOUR_IN_SECONDS );
            return $empty;
        }

        $result = [
            'country_code' => isset( $body['countryCode'] ) ? sanitize_text_field( $body['countryCode'] ) : null,
            'country_name' => isset( $body['country'] ) ? sanitize_text_field( $body['country'] ) : null,
            'city'         => isset( $body['city'] ) ? sanitize_text_field( $body['city'] ) : null,
        ];

        set_transient( $cache_key, $result, 7 * DAY_IN_SECONDS );
        return $result;
    }

    /**
     * Convert ISO 3166-1 alpha-2 country code to flag emoji (Regional Indicator Symbols).
     *
     * @param string $code
     * @return string
     */
    public static function country_code_to_flag( $code ) {
        $code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $code ) );
        if ( strlen( $code ) !== 2 ) {
            return '';
        }

        $flag = '';
        for ( $i = 0; $i < 2; $i++ ) {
            $cp = 0x1F1E6 + ( ord( $code[ $i ] ) - ord( 'A' ) );
            if ( function_exists( 'mb_chr' ) ) {
                $flag .= mb_chr( $cp, 'UTF-8' );
            } else {
                // UTF-8 encode a supplementary-plane codepoint without mbstring.
                $flag .= html_entity_decode( '&#' . $cp . ';', ENT_NOQUOTES, 'UTF-8' );
            }
        }
        return $flag;
    }

    /**
     * @param string $ip
     * @return bool
     */
    public static function is_private_or_local_ip( $ip ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return true;
        }
        // FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE fails for private/reserved → treat as private.
        return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /**
     * Client IP from the current request (sanitized).
     *
     * @return string
     */
    public static function get_request_ip() {
        return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    }

    // ── Active sessions (main tab) ────────────────────────────────────────────

    /**
     * Per-user activity summary for "today" (site timezone), newest activity first.
     *
     * @return array<int, array>
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
                    s.ip_address,
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
                    'sessions_count'      => 0,
                    'listening_minutes'   => 0,
                    'browsing_minutes'    => 0,
                    'reading_minutes'     => 0,
                    'total_minutes'       => 0,
                    'xfinity_today'       => 0.0,
                    'last_activity'       => '',
                    'last_seen'           => $row->created_at,
                    'last_activity_type'  => $type,
                    'is_currently_active' => false,
                    'ip_address'          => '',
                    'country_code'        => null,
                    'country_name'        => null,
                    'city'                => null,
                    'country_flag'        => '',
                    'items'               => [
                        'songs'    => [],
                        'pages'    => [],
                        'articles' => [],
                    ],
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

            // Prefer the most recently seen non-empty IP for the member row.
            $row_ip = trim( (string) ( $row->ip_address ?? '' ) );
            if ( $row_ip !== '' && $entry['ip_address'] === '' ) {
                $entry['ip_address'] = $row_ip;
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
                    'post_id' => (int) $row->post_id,
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
                        'post_id' => (int) ( $item['post_id'] ?? 0 ),
                        'minutes' => max( 1, (int) round( $item['seconds'] / 60 ) ),
                    ];
                }
                usort( $items[ $bucket ], static function ( $a, $b ) {
                    return $b['minutes'] <=> $a['minutes'];
                } );
            }

            $is_active = $entry['last_seen'] >= $cutoff;

            $geo = self::get_geo_for_ip( $entry['ip_address'] );

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
                'last_activity'       => human_time_diff( strtotime( $entry['last_seen'] ), $now_ts ) . ' ago',
                'last_seen'           => $entry['last_seen'],
                'last_activity_type'  => $entry['last_activity_type'],
                'is_currently_active' => $is_active,
                'ip_address'          => $entry['ip_address'],
                'country_code'        => $geo['country_code'],
                'country_name'        => $geo['country_name'],
                'city'                => $geo['city'],
                'country_flag'        => self::country_code_to_flag( (string) ( $geo['country_code'] ?? '' ) ),
                'items'               => $items,
            ];
        }

        usort( $out, static function ( $a, $b ) {
            return strcmp( $b['last_seen'], $a['last_seen'] );
        } );

        return $out;
    }

    /**
     * Deep Analyst payload for a single user (today, site timezone).
     *
     * @param int $user_id
     * @return array|null
     */
    public static function get_session_detail( $user_id ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return null;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return null;
        }

        $table       = self::sessions_table();
        $today_start = current_time( 'Y-m-d' ) . ' 00:00:00';
        $cutoff      = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 5 * MINUTE_IN_SECONDS );
        $now_ts      = current_time( 'timestamp' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_type, item_title, item_url, post_id, duration_seconds,
                    xfinity_earned, ip_address, created_at
             FROM {$table}
             WHERE user_id = %d
               AND created_at >= %s
               AND is_valid = 1
             ORDER BY created_at DESC",
            $user_id,
            $today_start
        ) );

        $fav_tracks = get_user_meta( $user_id, 'cf_favorite_tracks', true );
        $fav_posts  = get_user_meta( $user_id, 'cf_favorite_posts', true );
        if ( ! is_array( $fav_tracks ) ) {
            $fav_tracks = [];
        }
        if ( ! is_array( $fav_posts ) ) {
            $fav_posts = [];
        }
        $fav_tracks = array_map( 'absint', $fav_tracks );
        $fav_posts  = array_map( 'absint', $fav_posts );

        $shared_track_ids = self::get_shared_item_ids( $user_id, 'track' );
        $shared_post_ids  = self::get_shared_item_ids( $user_id, 'post' );

        $seconds = [ 'listening' => 0, 'browsing' => 0, 'reading' => 0 ];
        $xfinity = 0.0;
        $last_seen = '';
        $latest_ip = '';

        // Songs / articles: collapse by post_id (or title key).
        $songs    = [];
        $articles = [];
        // Pages: do NOT collapse across different IPs — key by title|url|ip.
        $pages = [];

        foreach ( (array) $rows as $row ) {
            $type = sanitize_key( $row->activity_type );
            if ( ! in_array( $type, self::ACTIVITY_TYPES, true ) ) {
                $type = 'listening';
            }

            $seconds[ $type ] += (int) $row->duration_seconds;
            $xfinity += (float) $row->xfinity_earned;

            if ( $row->created_at > $last_seen ) {
                $last_seen = $row->created_at;
            }

            $row_ip = trim( (string) ( $row->ip_address ?? '' ) );
            if ( $row_ip !== '' && $latest_ip === '' ) {
                $latest_ip = $row_ip;
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

            $post_id = (int) $row->post_id;
            $dur     = (int) $row->duration_seconds;
            $xf      = (float) $row->xfinity_earned;

            if ( $type === 'listening' ) {
                $key = $post_id > 0 ? 'id:' . $post_id : 't:' . strtolower( $title );
                if ( ! isset( $songs[ $key ] ) ) {
                    $songs[ $key ] = [
                        'post_id'  => $post_id,
                        'title'    => $title,
                        'url'      => $url,
                        'seconds'  => 0,
                        'xfinity'  => 0.0,
                    ];
                }
                $songs[ $key ]['seconds'] += $dur;
                $songs[ $key ]['xfinity'] += $xf;
            } elseif ( $type === 'reading' ) {
                $key = $post_id > 0 ? 'id:' . $post_id : 't:' . strtolower( $title );
                if ( ! isset( $articles[ $key ] ) ) {
                    $articles[ $key ] = [
                        'post_id' => $post_id,
                        'title'   => $title,
                        'url'     => $url,
                        'seconds' => 0,
                    ];
                }
                $articles[ $key ]['seconds'] += $dur;
            } else {
                // Browsing — keep IP distinct so multi-location visits show separately.
                $key = strtolower( $title ) . '|' . strtolower( $url ) . '|' . $row_ip;
                if ( ! isset( $pages[ $key ] ) ) {
                    $geo = self::get_geo_for_ip( $row_ip );
                    $pages[ $key ] = [
                        'title'         => $title,
                        'url'           => $url,
                        'seconds'       => 0,
                        'ip_address'    => $row_ip,
                        'country_code'  => $geo['country_code'],
                        'country_name'  => $geo['country_name'],
                        'city'          => $geo['city'],
                        'country_flag'  => self::country_code_to_flag( (string) ( $geo['country_code'] ?? '' ) ),
                    ];
                }
                $pages[ $key ]['seconds'] += $dur;
            }
        }

        $songs_out = [];
        foreach ( $songs as $song ) {
            $pid = (int) $song['post_id'];
            $songs_out[] = [
                'post_id'   => $pid,
                'title'     => $song['title'],
                'url'       => $song['url'],
                'minutes'   => max( 1, (int) round( $song['seconds'] / 60 ) ),
                'xfinity'   => round( (float) $song['xfinity'], 4 ),
                'liked'     => $pid > 0 && in_array( $pid, $fav_tracks, true ),
                'commented' => $pid > 0 && self::user_commented_on_post( $user_id, $pid ),
                'shared'    => $pid > 0 && in_array( $pid, $shared_track_ids, true ),
            ];
        }
        usort( $songs_out, static function ( $a, $b ) {
            return $b['minutes'] <=> $a['minutes'];
        } );

        $pages_out = [];
        foreach ( $pages as $page ) {
            $pages_out[] = [
                'title'        => $page['title'],
                'url'          => $page['url'],
                'minutes'      => max( 1, (int) round( $page['seconds'] / 60 ) ),
                'ip_address'   => $page['ip_address'],
                'country_code' => $page['country_code'],
                'country_name' => $page['country_name'],
                'city'         => $page['city'],
                'country_flag' => $page['country_flag'],
            ];
        }
        usort( $pages_out, static function ( $a, $b ) {
            return $b['minutes'] <=> $a['minutes'];
        } );

        $articles_out = [];
        foreach ( $articles as $article ) {
            $pid = (int) $article['post_id'];
            $articles_out[] = [
                'post_id'   => $pid,
                'title'     => $article['title'],
                'url'       => $article['url'],
                'minutes'   => max( 1, (int) round( $article['seconds'] / 60 ) ),
                'liked'     => $pid > 0 && in_array( $pid, $fav_posts, true ),
                'commented' => $pid > 0 && self::user_commented_on_post( $user_id, $pid ),
                'shared'    => $pid > 0 && in_array( $pid, $shared_post_ids, true ),
            ];
        }
        usort( $articles_out, static function ( $a, $b ) {
            return $b['minutes'] <=> $a['minutes'];
        } );

        $listening_m = (int) round( $seconds['listening'] / 60 );
        $browsing_m  = (int) round( $seconds['browsing'] / 60 );
        $reading_m   = (int) round( $seconds['reading'] / 60 );
        $total_m     = $listening_m + $browsing_m + $reading_m;
        $is_active   = $last_seen !== '' && $last_seen >= $cutoff;

        $geo = self::get_geo_for_ip( $latest_ip );

        $pct = static function ( $part, $whole ) {
            if ( $whole <= 0 ) {
                return 0.0;
            }
            return round( ( $part / $whole ) * 100, 1 );
        };

        return [
            'user_id'             => $user_id,
            'display_name'        => $user->display_name,
            'email'               => $user->user_email,
            'status'              => $is_active ? 'live' : 'idle',
            'is_currently_active' => $is_active,
            'last_activity'       => $last_seen
                ? human_time_diff( strtotime( $last_seen ), $now_ts ) . ' ago'
                : '',
            'last_seen'           => $last_seen,
            'ip_address'          => $latest_ip,
            'country_code'        => $geo['country_code'],
            'country_name'        => $geo['country_name'],
            'city'                => $geo['city'],
            'country_flag'        => self::country_code_to_flag( (string) ( $geo['country_code'] ?? '' ) ),
            'listening'           => [
                'minutes'      => $listening_m,
                'songs_count'  => count( $songs_out ),
                'songs'        => $songs_out,
            ],
            'browsing'            => [
                'minutes'      => $browsing_m,
                'pages_count'  => count( $pages_out ),
                'pages'        => $pages_out,
            ],
            'reading'             => [
                'minutes'         => $reading_m,
                'articles_count'  => count( $articles_out ),
                'articles'        => $articles_out,
            ],
            'total'               => [
                'minutes'    => $total_m,
                'listening'  => $listening_m,
                'browsing'   => $browsing_m,
                'reading'    => $reading_m,
                'pct_listening' => $pct( $listening_m, $total_m ),
                'pct_browsing'  => $pct( $browsing_m, $total_m ),
                'pct_reading'   => $pct( $reading_m, $total_m ),
            ],
            'xfinity'             => [
                'today' => round( $xfinity, 4 ),
                'songs' => array_values( array_filter( $songs_out, static function ( $s ) {
                    return (float) $s['xfinity'] > 0;
                } ) ),
            ],
        ];
    }

    /**
     * @param int    $user_id
     * @param string $item_type
     * @return int[]
     */
    private static function get_shared_item_ids( $user_id, $item_type ) {
        global $wpdb;
        $table = self::shares_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return [];
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT item_id FROM {$table} WHERE user_id = %d AND item_type = %s",
            $user_id,
            $item_type
        ) );

        return array_map( 'absint', (array) $ids );
    }

    /**
     * @param int $user_id
     * @param int $post_id
     * @return bool
     */
    private static function user_commented_on_post( $user_id, $post_id ) {
        $count = get_comments( [
            'post_id' => $post_id,
            'user_id' => $user_id,
            'count'   => true,
            'status'  => 'approve',
        ] );
        return (int) $count > 0;
    }

    /**
     * Enqueue tracker JS only for logged-in users on pages where engagement can occur.
     */
    public function enqueue_scripts() {
        if ( ! is_user_logged_in() || is_admin() ) {
            return;
        }

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
        $post_id       = is_singular() ? (int) get_queried_object_id() : 0;
        $item_title    = '';
        $item_url      = '';

        if ( $post_id ) {
            $item_title = get_the_title( $post_id );
            $item_url   = (string) get_permalink( $post_id );
        } else {
            $item_title = wp_get_document_title();
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
            'page_activity_type' => $page_activity,
            'item_title'         => $item_title,
            'item_url'           => $item_url,
        ] );
    }

    /**
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
        $this->handle_ping( 'browsing', 0 );
    }

    public function handle_reading_ping() {
        $this->handle_ping( 'reading', 0 );
    }

    /**
     * Record a share event from the theme (CF_Auth.trackShare).
     */
    public function handle_track_share() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ], 401 );
        }

        $item_id   = absint( $_POST['item_id'] ?? 0 );
        $item_type = sanitize_key( $_POST['item_type'] ?? '' );
        $platform  = sanitize_key( $_POST['platform'] ?? '' );

        if ( ! $item_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid item.', 'cf-auth' ) ] );
        }
        if ( ! in_array( $item_type, self::SHARE_ITEM_TYPES, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid item type.', 'cf-auth' ) ] );
        }
        if ( ! in_array( $platform, self::SHARE_PLATFORMS, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid platform.', 'cf-auth' ) ] );
        }

        global $wpdb;
        $inserted = $wpdb->insert(
            self::shares_table(),
            [
                'user_id'    => get_current_user_id(),
                'item_id'    => $item_id,
                'item_type'  => $item_type,
                'platform'   => $platform,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Could not record share.', 'cf-auth' ) ] );
        }

        wp_send_json_success();
    }

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

        $throttle_key = 'cf_eng_interact_' . $user_id . '_' . $activity_type;
        if ( ! get_transient( $throttle_key ) ) {
            set_transient( $throttle_key, 1, 30 );
            update_user_meta( $user_id, 'cf_eng_window_anchor_' . $activity_type, time() );
        }

        wp_send_json_success( [ 'reset' => true, 'interaction_type' => $interaction_type ] );
    }

    /**
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

        if ( $item_title === '' ) {
            $item_title = ( $activity_type === 'reading' )
                ? __( 'Article', 'cf-auth' )
                : ( $activity_type === 'browsing' ? __( 'Browsing', 'cf-auth' ) : __( '(untitled)', 'cf-auth' ) );
        }

        $rate_key = 'cf_eng_ping_' . $user_id . '_' . $activity_type;
        if ( get_transient( $rate_key ) ) {
            wp_send_json_success( [
                'awarded' => false,
                'reason'  => 'rate_limited',
            ] );
        }
        set_transient( $rate_key, 1, self::PING_MIN_INTERVAL );

        $anchor_key = 'cf_eng_window_anchor_' . $activity_type;
        $anchor     = (int) get_user_meta( $user_id, $anchor_key, true );
        $now        = time();

        if ( ! $anchor ) {
            update_user_meta( $user_id, $anchor_key, $now );
        } elseif ( ( $now - $anchor ) > self::WINDOW_SECONDS ) {
            if ( $activity_type === 'listening' ) {
                wp_send_json_success( [
                    'awarded' => false,
                    'reason'  => 'window_expired',
                ] );
            }
            update_user_meta( $user_id, $anchor_key, $now );
        }

        $ip = self::get_request_ip();
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
                'item_title'       => $item_title,
                'item_url'         => $item_url,
                'post_id'          => $post_id,
                'duration_seconds' => self::PING_DURATION_SECONDS,
                'xfinity_earned'   => $xfinity,
                'is_valid'         => $is_valid,
                'ip_address'       => $ip,
                'created_at'       => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%s', '%s' ]
        );

        $balance = false;
        if ( $xfinity > 0 ) {
            $balance = CF_Xfinity::get_instance()->add_xfinity(
                $user_id,
                $xfinity,
                $activity_type,
                $post_id ?: null
            );

            if ( $balance !== false && class_exists( 'CF_Notifications' ) ) {
                $threshold_key   = 'cf_eng_notif_threshold_' . $user_id;
                $stored          = get_transient( $threshold_key );
                $balance_before  = round( (float) $balance - (float) $xfinity, 2 );
                $last_notified   = ( false === $stored )
                    ? (int) floor( $balance_before )
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
