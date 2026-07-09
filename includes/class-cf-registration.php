<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Registration {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_nopriv_cf_register',    [ $this, 'handle_register' ] );
        add_action( 'wp_ajax_nopriv_cf_verify_email',[ $this, 'handle_verify_email' ] );
        add_action( 'template_redirect',             [ $this, 'handle_verify_redirect' ] );
    }

    // ── AJAX Registration ─────────────────────────────────────────────────────
    public function handle_register() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        $display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
        $email        = sanitize_email( $_POST['email'] ?? '' );
        $password     = $_POST['password'] ?? '';
        $confirm      = $_POST['confirm_password'] ?? '';

        // Validate
        $errors = [];
        if ( empty( $display_name ) ) $errors[] = __( 'Display name is required.', 'cf-auth' );
        if ( ! is_email( $email ) )   $errors[] = __( 'Invalid email address.', 'cf-auth' );
        if ( strlen( $password ) < 8 )$errors[] = __( 'Password must be at least 8 characters.', 'cf-auth' );
        if ( $password !== $confirm )  $errors[] = __( 'Passwords do not match.', 'cf-auth' );
        if ( email_exists( $email ) )  $errors[] = __( 'This email is already registered.', 'cf-auth' );

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'message' => implode( '<br>', $errors ) ] );
        }

        // Generate username from email
        $username = sanitize_user( strstr( $email, '@', true ), true );
        if ( username_exists( $username ) ) {
            $username .= rand( 100, 999 );
        }

        $user_id = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( [ 'message' => $user_id->get_error_message() ] );
        }

        // Set role and meta
        $user = new WP_User( $user_id );
        $user->set_role( 'cf_listener' );

        update_user_meta( $user_id, 'display_name',         $display_name );
        update_user_meta( $user_id, 'cf_email_verified',    '0' );
        update_user_meta( $user_id, 'cf_member_since',      current_time( 'mysql' ) );
        update_user_meta( $user_id, 'cf_social_provider',   'manual' );
        update_user_meta( $user_id, 'cf_bio',               '' );
        update_user_meta( $user_id, 'cf_favorite_tracks',   [] );
        update_user_meta( $user_id, 'cf_favorite_albums',   [] );

        wp_update_user( [ 'ID' => $user_id, 'display_name' => $display_name ] );

        // Block login until verified
        update_user_meta( $user_id, 'cf_account_status', 'pending' );

        // Send verification email
        CF_Email::send_verification( $user_id );

        CF_Activity_Log::safe_log( 'registered', [
            'user_id'  => $user_id,
            'email'    => $email,
            'provider' => 'manual',
        ] );

        do_action( 'cf_auth_after_register', $user_id, [
            'email_verification_required' => get_option( 'cf_auth_email_verification' ) === '1',
        ] );

        wp_send_json_success( [
            'message'  => __( 'Account created! Please check your email to verify your account.', 'cf-auth' ),
            'redirect' => get_option( 'cf_auth_after_register', home_url( '/cf-verify-email' ) ),
        ] );
    }

    // ── Handle Verify Email redirect ──────────────────────────────────────────
    public function handle_verify_redirect() {
        if ( ! isset( $_GET['cf_action'] ) || $_GET['cf_action'] !== 'verify_email' ) return;
        $this->handle_verify_email();
    }

    // ── Verify email token ────────────────────────────────────────────────────
    public function handle_verify_email() {
        global $wpdb;

        $token = sanitize_text_field( $_GET['token'] ?? '' );
        if ( empty( $token ) ) return;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cf_email_tokens WHERE token = %s AND expires_at > NOW()",
            $token
        ) );

        if ( ! $row ) {
            // Show expired/invalid message via query var
            set_query_var( 'cf_verify_status', 'invalid' );
            return;
        }

        $user_id = $row->user_id;
        update_user_meta( $user_id, 'cf_email_verified', '1' );
        update_user_meta( $user_id, 'cf_account_status', 'active' );
        $wpdb->delete( $wpdb->prefix . 'cf_email_tokens', [ 'user_id' => $user_id ] );

        // Send welcome email
        CF_Email::send_welcome( $user_id );

        do_action( 'cf_auth_after_email_verified', $user_id );

        // Auto-login
        wp_set_auth_cookie( $user_id, false );
        wp_safe_redirect( get_option( 'cf_auth_login_redirect', home_url( '/cf-profile' ) ) );
        exit;
    }
}
