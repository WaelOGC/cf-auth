<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Notifications {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_cf_get_notifications',        [ $this, 'handle_get_notifications' ] );
        add_action( 'wp_ajax_cf_mark_notifications_read',  [ $this, 'handle_mark_notifications_read' ] );
    }

    /**
     * Insert one notification row per cf_listener user.
     *
     * @param string $type    Notification type slug.
     * @param string $title   Short title.
     * @param string $message Body text.
     * @param string $link    Optional URL.
     * @param int    $exclude_user_id Optional user ID to skip (e.g. post author).
     * @return int Number of rows inserted.
     */
    public static function create_for_all_users( $type, $title, $message, $link = '', $exclude_user_id = 0 ) {
        global $wpdb;

        $users = get_users( [ 'role' => 'cf_listener', 'fields' => 'ID' ] );
        if ( empty( $users ) ) {
            return 0;
        }

        $table           = $wpdb->prefix . 'cf_notifications';
        $type            = sanitize_key( $type );
        $title           = sanitize_text_field( $title );
        $message         = sanitize_textarea_field( $message );
        $link            = $link ? esc_url_raw( $link ) : '';
        $exclude_user_id = absint( $exclude_user_id );
        $now             = current_time( 'mysql' );
        $inserted        = 0;

        foreach ( $users as $user_id ) {
            $user_id = (int) $user_id;
            if ( $exclude_user_id && $user_id === $exclude_user_id ) {
                continue;
            }

            $result = $wpdb->insert(
                $table,
                [
                    'user_id'    => $user_id,
                    'type'       => $type,
                    'title'      => $title,
                    'message'    => $message,
                    'link'       => $link ?: null,
                    'is_read'    => 0,
                    'created_at' => $now,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
            );

            if ( $result ) {
                $inserted++;
            }
        }

        return $inserted;
    }

    public function handle_get_notifications() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        global $wpdb;

        $user_id = get_current_user_id();
        $table   = $wpdb->prefix . 'cf_notifications';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, type, title, message, link, is_read, created_at
                 FROM {$table}
                 WHERE user_id = %d
                 ORDER BY created_at DESC
                 LIMIT 20",
                $user_id
            ),
            ARRAY_A
        );

        $notifications = [];
        foreach ( $rows ?: [] as $row ) {
            $notifications[] = [
                'id'         => (int) $row['id'],
                'type'       => $row['type'],
                'title'      => $row['title'],
                'message'    => $row['message'],
                'link'       => $row['link'],
                'is_read'    => (int) $row['is_read'],
                'created_at' => $row['created_at'],
            ];
        }

        $unread_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
                $user_id
            )
        );

        wp_send_json_success( [
            'notifications' => $notifications,
            'unread_count'  => $unread_count,
        ] );
    }

    public function handle_mark_notifications_read() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        global $wpdb;

        $user_id         = get_current_user_id();
        $table           = $wpdb->prefix . 'cf_notifications';
        $notification_id = absint( $_POST['notification_id'] ?? 0 );

        if ( $notification_id ) {
            $wpdb->update(
                $table,
                [ 'is_read' => 1 ],
                [
                    'id'      => $notification_id,
                    'user_id' => $user_id,
                ],
                [ '%d' ],
                [ '%d', '%d' ]
            );
        } else {
            $wpdb->update(
                $table,
                [ 'is_read' => 1 ],
                [
                    'user_id' => $user_id,
                    'is_read' => 0,
                ],
                [ '%d' ],
                [ '%d', '%d' ]
            );
        }

        wp_send_json_success();
    }
}
