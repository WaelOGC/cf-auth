<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Profile {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_cf_update_profile',       [ $this, 'handle_update_profile' ] );
        add_action( 'wp_ajax_cf_upload_avatar',        [ $this, 'handle_upload_avatar' ] );
        add_action( 'wp_ajax_cf_toggle_favorite',      [ $this, 'handle_toggle_favorite' ] );
        add_action( 'wp_ajax_cf_get_favorites',        [ $this, 'handle_get_favorites' ] );
        add_action( 'wp_ajax_nopriv_cf_get_favorites', [ $this, 'handle_get_favorites' ] );
        add_action( 'wp_ajax_cf_log_listening',        [ $this, 'handle_log_listening' ] );
        add_action( 'wp_ajax_cf_get_listening_history',[ $this, 'handle_get_history' ] );
        add_action( 'wp_ajax_cf_refresh_nonces',       [ $this, 'handle_refresh_nonces' ] );
        add_action( 'wp_ajax_cf_delete_account',       [ $this, 'handle_delete_account' ] );
    }

    // ── Update Profile ────────────────────────────────────────────────────────
    public function handle_update_profile() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ] );

        $user_id      = get_current_user_id();
        $display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
        $bio          = sanitize_textarea_field( $_POST['bio'] ?? '' );
        $email        = sanitize_email( $_POST['email'] ?? '' );

        $errors = [];
        if ( empty( $display_name ) ) $errors[] = __( 'Display name is required.', 'cf-auth' );
        if ( ! empty( $email ) && ! is_email( $email ) ) $errors[] = __( 'Invalid email.', 'cf-auth' );
        if ( ! empty( $errors ) ) wp_send_json_error( [ 'message' => implode( '<br>', $errors ) ] );

        $update_data = [ 'ID' => $user_id, 'display_name' => $display_name ];

        // Handle email change
        if ( ! empty( $email ) ) {
            $current_user = get_userdata( $user_id );
            if ( $email !== $current_user->user_email ) {
                if ( email_exists( $email ) ) {
                    wp_send_json_error( [ 'message' => __( 'That email is already in use.', 'cf-auth' ) ] );
                }
                $update_data['user_email'] = $email;
                update_user_meta( $user_id, 'cf_email_verified', '0' );
                update_user_meta( $user_id, 'cf_account_status', 'pending' );
                CF_Email::send_verification( $user_id );
            }
        }

        wp_update_user( $update_data );
        update_user_meta( $user_id, 'cf_bio', $bio );

        wp_send_json_success( [ 'message' => __( 'Profile updated successfully!', 'cf-auth' ) ] );
    }

    // ── Upload Avatar ─────────────────────────────────────────────────────────
    public function handle_upload_avatar() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ] );

        if ( empty( $_FILES['avatar'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file uploaded.', 'cf-auth' ) ] );
        }

        $file = $_FILES['avatar'];

        // Validate file type
        $allowed_types = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        $file_type     = wp_check_filetype( $file['name'] );

        if ( ! in_array( $file['type'], $allowed_types, true ) ) {
            wp_send_json_error( [ 'message' => __( 'Only JPG, PNG, GIF, and WEBP are allowed.', 'cf-auth' ) ] );
        }

        // Max 2MB
        if ( $file['size'] > 2 * MB_IN_BYTES ) {
            wp_send_json_error( [ 'message' => __( 'File must be under 2MB.', 'cf-auth' ) ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $user_id       = get_current_user_id();
        $attachment_id = media_handle_upload( 'avatar', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        // Delete old avatar
        $old_avatar = get_user_meta( $user_id, 'cf_avatar_id', true );
        if ( $old_avatar ) wp_delete_attachment( $old_avatar, true );

        update_user_meta( $user_id, 'cf_avatar_id',  $attachment_id );
        update_user_meta( $user_id, 'cf_avatar_url', wp_get_attachment_url( $attachment_id ) );

        wp_send_json_success( [
            'message'    => __( 'Avatar updated!', 'cf-auth' ),
            'avatar_url' => wp_get_attachment_url( $attachment_id ),
        ] );
    }

    // ── Toggle Favorite (track, album, or post) ──────────────────────────────
    public function handle_toggle_favorite() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ] );

        $user_id  = get_current_user_id();
        $item_id  = absint( $_POST['item_id'] ?? 0 );
        $type     = sanitize_key( $_POST['item_type'] ?? 'track' ); // track | album | post

        $post_type_map = [
            'track' => 'tracks',
            'album' => 'albums',
            'post'  => 'post',
        ];

        if ( ! isset( $post_type_map[ $type ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid item type.', 'cf-auth' ) ] );
        }

        if ( ! $item_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid item ID.', 'cf-auth' ) ] );
        }

        $post_type = $post_type_map[ $type ];
        $post      = get_post( $item_id );

        if ( ! $post || $post->post_type !== $post_type || $post->post_status !== 'publish' ) {
            wp_send_json_error( [ 'message' => __( 'Item not found.', 'cf-auth' ) ] );
        }

        $meta_key_map = [
            'track' => 'cf_favorite_tracks',
            'album' => 'cf_favorite_albums',
            'post'  => 'cf_favorite_posts',
        ];
        $meta_key  = $meta_key_map[ $type ];
        $favorites = get_user_meta( $user_id, $meta_key, true );
        if ( ! is_array( $favorites ) ) $favorites = [];

        $is_favorited = in_array( $item_id, $favorites, true );

        if ( $is_favorited ) {
            $favorites = array_diff( $favorites, [ $item_id ] );
            $action    = 'removed';
        } else {
            $favorites[] = $item_id;
            $action      = 'added';
        }

        update_user_meta( $user_id, $meta_key, array_values( $favorites ) );

        $likes_count = (int) get_post_meta( $item_id, '_cf_total_likes_count', true );
        if ( $action === 'added' ) {
            $likes_count++;
        } else {
            $likes_count = max( 0, $likes_count - 1 );
        }
        update_post_meta( $item_id, '_cf_total_likes_count', $likes_count );

        wp_send_json_success( [
            'action'      => $action,
            'is_favorite' => ! $is_favorited,
            'count'       => count( $favorites ),
            'likes_count' => $likes_count,
        ] );
    }

    // ── Get Favorites ─────────────────────────────────────────────────────────
    public function handle_get_favorites() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_success( [ 'tracks' => [], 'albums' => [], 'posts' => [] ] );
        }

        $user_id = get_current_user_id();

        $tracks = get_user_meta( $user_id, 'cf_favorite_tracks', true );
        $albums = get_user_meta( $user_id, 'cf_favorite_albums', true );
        $posts  = get_user_meta( $user_id, 'cf_favorite_posts', true );

        if ( ! is_array( $tracks ) ) $tracks = [];
        if ( ! is_array( $albums ) ) $albums = [];
        if ( ! is_array( $posts ) )  $posts  = [];

        wp_send_json_success( [
            'tracks' => array_map( 'intval', $tracks ),
            'albums' => array_map( 'intval', $albums ),
            'posts'  => array_map( 'intval', $posts ),
        ] );
    }

    // ── Log Listening History ─────────────────────────────────────────────────
    public function handle_log_listening() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();

        global $wpdb;
        $user_id  = get_current_user_id();
        $track_id = absint( $_POST['track_id'] ?? 0 );
        if ( ! $track_id ) wp_send_json_error();

        $table = $wpdb->prefix . 'cf_listening_history';
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS );
        $existing_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE user_id = %d AND track_id = %d AND listened_at >= %s
             ORDER BY listened_at DESC LIMIT 1",
            $user_id,
            $track_id,
            $cutoff
        ) );

        $now = current_time( 'mysql', true );

        if ( $existing_id ) {
            $wpdb->update(
                $table,
                [ 'listened_at' => $now ],
                [ 'id' => (int) $existing_id ],
                [ '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert( $table, [
                'user_id'     => $user_id,
                'track_id'    => $track_id,
                'listened_at' => $now,
            ] );
        }

        wp_send_json_success();
    }

    // ── Get Listening History ─────────────────────────────────────────────────
    public function handle_get_history() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error();

        global $wpdb;
        $user_id = get_current_user_id();
        $limit   = absint( $_POST['limit'] ?? 20 );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT track_id, listened_at FROM {$wpdb->prefix}cf_listening_history
             WHERE user_id = %d ORDER BY listened_at DESC LIMIT %d",
            $user_id,
            $limit * 3
        ) );

        $history = [];
        foreach ( $rows as $row ) {
            if ( count( $history ) >= $limit ) {
                break;
            }

            $post = get_post( (int) $row->track_id );
            if ( ! $post || $post->post_type !== 'tracks' || $post->post_status !== 'publish' ) {
                continue;
            }

            $history[] = [
                'track_id'    => (int) $row->track_id,
                'listened_at' => $row->listened_at,
                'title'       => $post->post_title,
                'url'         => get_permalink( $post ),
                'cover'       => self::get_release_cover_url( $post, 'track' ),
            ];
        }

        wp_send_json_success( [ 'history' => $history ] );
    }

    // ── Refresh AJAX Nonces ───────────────────────────────────────────────────
    public function handle_refresh_nonces() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        wp_send_json_success( [
            'auth_nonce'        => wp_create_nonce( 'cf_auth_nonce' ),
            'interaction_nonce' => wp_create_nonce( 'cf_interaction_nonce' ),
        ] );
    }

    // ── Delete Account (self-service) ───────────────────────────────────────────
    public function handle_delete_account() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $confirm_email = sanitize_email( $_POST['confirm_email'] ?? '' );
        $user          = wp_get_current_user();
        if ( strcasecmp( $confirm_email, $user->user_email ) !== 0 ) {
            wp_send_json_error( [ 'message' => __( 'Email does not match your account.', 'cf-auth' ) ] );
        }

        $user_id = $user->ID;

        global $wpdb;
        $playlists_table = $wpdb->prefix . 'cf_playlists';
        $items_table     = $wpdb->prefix . 'cf_playlist_items';
        $playlist_ids    = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$playlists_table} WHERE user_id = %d",
            $user_id
        ) );
        if ( $playlist_ids ) {
            $in = implode( ',', array_map( 'intval', $playlist_ids ) );
            $wpdb->query( "DELETE FROM {$items_table} WHERE playlist_id IN ({$in})" );
            $wpdb->query( "DELETE FROM {$playlists_table} WHERE user_id = " . (int) $user_id );
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        $deleted = wp_delete_user( $user_id );

        if ( ! $deleted ) {
            wp_send_json_error( [ 'message' => __( 'Could not delete account. Please try again or contact support.', 'cf-auth' ) ] );
        }

        wp_send_json_success( [ 'redirect' => home_url( '/' ) ] );
    }

    // ── Static Helpers ────────────────────────────────────────────────────────
    public static function get_avatar_url( $user_id ) {
        $url = get_user_meta( $user_id, 'cf_avatar_url', true );
        if ( ! $url ) {
            // Fallback to social provider avatar or Gravatar
            $url = get_user_meta( $user_id, 'cf_social_avatar', true );
        }
        if ( ! $url ) {
            $user = get_userdata( $user_id );
            $url  = get_avatar_url( $user->user_email, [ 'size' => 200 ] );
        }
        return $url;
    }

    public static function get_member_since( $user_id ) {
        $date = get_user_meta( $user_id, 'cf_member_since', true );
        if ( ! $date ) {
            $user = get_userdata( $user_id );
            $date = $user->user_registered;
        }
        return date_i18n( get_option( 'date_format' ), strtotime( $date ) );
    }

    public static function get_likes_count( $post_id ) {
        return (int) get_post_meta( $post_id, '_cf_total_likes_count', true );
    }

    public static function get_release_cover_url( WP_Post $post, string $type ): string {
        if ( $type === 'track' ) {
            $cover_url = get_post_meta( $post->ID, 'track_cover_url', true );
            if ( ! empty( $cover_url ) ) {
                return $cover_url;
            }

            $associated_album = get_post_meta( $post->ID, 'associated_album', true );
            if ( $associated_album ) {
                $cover_url = get_the_post_thumbnail_url( (int) $associated_album, 'medium' );
                if ( ! empty( $cover_url ) ) {
                    return $cover_url;
                }
            }

            $cover_url = get_the_post_thumbnail_url( $post->ID, 'medium' );
            if ( ! empty( $cover_url ) ) {
                return $cover_url;
            }
        } elseif ( $type === 'post' ) {
            $cover_url = get_the_post_thumbnail_url( $post->ID, 'medium' );
            if ( ! empty( $cover_url ) ) {
                return $cover_url;
            }
        } elseif ( $type === 'album' ) {
            $cover_url = get_the_post_thumbnail_url( $post->ID, 'medium' );
            if ( ! empty( $cover_url ) ) {
                return $cover_url;
            }

            $album_tracks = get_posts(
                [
                    'post_type'      => 'tracks',
                    'posts_per_page' => -1,
                    'post_status'    => 'publish',
                    'orderby'        => [
                        'menu_order' => 'ASC',
                        'title'      => 'ASC',
                    ],
                    'meta_query'     => [
                        [
                            'key'     => 'associated_album',
                            'value'   => $post->ID,
                            'compare' => '=',
                        ],
                    ],
                ]
            );

            foreach ( $album_tracks as $track ) {
                $cover_url = get_post_meta( $track->ID, 'track_cover_url', true );
                if ( ! empty( $cover_url ) ) {
                    return $cover_url;
                }
            }
        }

        if ( function_exists( 'collective_finity_default_art_url' ) ) {
            return collective_finity_default_art_url();
        }

        return '';
    }
}
