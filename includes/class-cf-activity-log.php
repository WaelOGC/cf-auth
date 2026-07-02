<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Activity_Log {

    const DB_VERSION = '1';

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'cf_auth_activity_log';
    }

    public static function init() {
        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_upgrade' ], 5 );
    }

    public static function maybe_upgrade() {
        $stored = get_option( 'cf_auth_db_version', '' );
        if ( $stored === self::DB_VERSION ) {
            return;
        }
        self::create_table();
        update_option( 'cf_auth_db_version', self::DB_VERSION );
    }

    public static function create_table() {
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            email varchar(190) DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            provider varchar(30) DEFAULT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            user_agent text DEFAULT NULL,
            meta longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_event_type (event_type),
            KEY idx_created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function safe_log( $event_type, $args = [] ) {
        try {
            self::log( $event_type, $args );
        } catch ( Exception $e ) {
            // Fail silently — never block user-facing flows.
        }
    }

    public static function log( $event_type, $args = [] ) {
        global $wpdb;

        if ( ! self::table_exists() ) {
            return false;
        }

        $event_type = sanitize_key( $event_type );
        if ( empty( $event_type ) ) {
            return false;
        }

        $user_id  = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : null;
        $email    = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : null;
        $provider = isset( $args['provider'] ) ? sanitize_key( $args['provider'] ) : null;

        if ( $user_id && empty( $email ) ) {
            $user = get_userdata( $user_id );
            if ( $user ) {
                $email = $user->user_email;
            }
        }

        $ip = isset( $args['ip_address'] )
            ? sanitize_text_field( $args['ip_address'] )
            : sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );

        $ua = isset( $args['user_agent'] )
            ? sanitize_textarea_field( $args['user_agent'] )
            : sanitize_textarea_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );

        $meta = null;
        if ( ! empty( $args['meta'] ) && is_array( $args['meta'] ) ) {
            $meta = wp_json_encode( $args['meta'] );
        }

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'user_id'    => $user_id ?: null,
                'email'      => $email ?: null,
                'event_type' => $event_type,
                'provider'   => $provider ?: null,
                'ip_address' => $ip,
                'user_agent' => $ua ?: null,
                'meta'       => $meta,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (bool) $inserted;
    }

    public static function get_entries( $filters = [], $per_page = 20, $paged = 1 ) {
        global $wpdb;

        if ( ! self::table_exists() ) {
            return [ 'rows' => [], 'total' => 0 ];
        }

        $table = self::table_name();
        $users = $wpdb->users;

        $per_page = max( 1, absint( $per_page ) );
        $paged    = max( 1, absint( $paged ) );
        $offset   = ( $paged - 1 ) * $per_page;

        $where  = [ '1=1' ];
        $params = [];

        if ( ! empty( $filters['event_type'] ) ) {
            $where[]  = 'l.event_type = %s';
            $params[] = sanitize_key( $filters['event_type'] );
        }

        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'l.created_at >= %s';
            $params[] = sanitize_text_field( $filters['date_from'] ) . ' 00:00:00';
        }

        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'l.created_at <= %s';
            $params[] = sanitize_text_field( $filters['date_to'] ) . ' 23:59:59';
        }

        if ( ! empty( $filters['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( sanitize_text_field( $filters['search'] ) ) . '%';
            $where[]  = '(l.email LIKE %s OR u.display_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM {$table} l
            LEFT JOIN {$users} u ON u.ID = l.user_id
            WHERE {$where_sql}";

        $total = (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : $wpdb->get_var( $count_sql )
        );

        $query_sql = "SELECT l.*, u.display_name
            FROM {$table} l
            LEFT JOIN {$users} u ON u.ID = l.user_id
            WHERE {$where_sql}
            ORDER BY l.created_at DESC
            LIMIT %d OFFSET %d";

        $query_params = array_merge( $params, [ $per_page, $offset ] );
        $rows         = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ) );

        return [
            'rows'  => $rows ?: [],
            'total' => $total,
        ];
    }

    private static function table_exists() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    public static function event_label( $event_type ) {
        $labels = [
            'login_success'  => 'Login Success',
            'login_failed'   => 'Login Failed',
            'registered'     => 'Registered',
            'logout'         => 'Logout',
            'status_changed' => 'Status Changed',
        ];
        return $labels[ $event_type ] ?? ucwords( str_replace( '_', ' ', $event_type ) );
    }

    public static function event_badge_class( $event_type, $meta_json = '' ) {
        $meta = $meta_json ? json_decode( $meta_json, true ) : [];

        if ( in_array( $event_type, [ 'login_success', 'registered' ], true ) ) {
            return 'cf-event-badge cf-event-positive';
        }

        if ( $event_type === 'login_failed' ) {
            return 'cf-event-badge cf-event-danger';
        }

        if ( $event_type === 'status_changed' ) {
            $new_status = $meta['new_status'] ?? '';
            if ( $new_status === 'suspended' ) {
                return 'cf-event-badge cf-event-danger';
            }
            return 'cf-event-badge cf-event-neutral';
        }

        return 'cf-event-badge cf-event-neutral';
    }
}
