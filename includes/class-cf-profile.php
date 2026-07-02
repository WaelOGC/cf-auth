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
        add_action( 'wp_ajax_cf_log_listening',        [ $this, 'handle_log_listening' ] );
        add_action( 'wp_ajax_cf_get_listening_history',[ $this, 'handle_get_history' ] );
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

    // ── Toggle Favorite (track or album) ─────────────────────────────────────
    public function handle_toggle_favorite() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( [ 'message' => 'Unauthorized' ] );

        $user_id  = get_current_user_id();
        $item_id  = absint( $_POST['item_id'] ?? 0 );
        $type     = sanitize_key( $_POST['item_type'] ?? 'track' ); // track | album

        $meta_key = $type === 'album' ? 'cf_favorite_albums' : 'cf_favorite_tracks';
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

        wp_send_json_success( [
            'action'      => $action,
            'is_favorite' => ! $is_favorited,
            'count'       => count( $favorites ),
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

        $wpdb->insert( $wpdb->prefix . 'cf_listening_history', [
            'user_id'  => $user_id,
            'track_id' => $track_id,
        ] );

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
            $limit
        ) );

        wp_send_json_success( [ 'history' => $rows ] );
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
}
