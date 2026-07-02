<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Login {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_nopriv_cf_login',  [ $this, 'handle_login' ] );
        add_action( 'wp_ajax_cf_logout',        [ $this, 'handle_logout' ] );
        add_action( 'wp_ajax_nopriv_cf_logout', [ $this, 'handle_logout' ] );
    }

    // ── AJAX Login ────────────────────────────────────────────────────────────
    public function handle_login() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        $email    = sanitize_email( $_POST['email'] ?? '' );
        $password = $_POST['password'] ?? '';
        $remember = ! empty( $_POST['remember'] );

        if ( empty( $email ) || empty( $password ) ) {
            wp_send_json_error( [ 'message' => __( 'Email and password are required.', 'cf-auth' ) ] );
        }

        // Rate limiting — 5 attempts per 15 min per IP
        $ip      = $_SERVER['REMOTE_ADDR'];
        $key     = 'cf_login_attempts_' . md5( $ip );
        $attempts = (int) get_transient( $key );

        if ( $attempts >= 5 ) {
            wp_send_json_error( [ 'message' => __( 'Too many login attempts. Please try again in 15 minutes.', 'cf-auth' ) ] );
        }

        // Get user by email
        $user = get_user_by( 'email', $email );

        if ( ! $user ) {
            set_transient( $key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
            wp_send_json_error( [ 'message' => __( 'Invalid email or password.', 'cf-auth' ) ] );
        }

        // Check password
        if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
            set_transient( $key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
            wp_send_json_error( [ 'message' => __( 'Invalid email or password.', 'cf-auth' ) ] );
        }

        // Check email verified
        $verified = get_user_meta( $user->ID, 'cf_email_verified', true );
        if ( $verified !== '1' ) {
            wp_send_json_error( [
                'message'       => __( 'Please verify your email before logging in.', 'cf-auth' ),
                'show_resend'   => true,
                'user_id'       => $user->ID,
            ] );
        }

        // Check account status
        $status = get_user_meta( $user->ID, 'cf_account_status', true );
        if ( $status === 'suspended' ) {
            wp_send_json_error( [ 'message' => __( 'Your account has been suspended. Contact support.', 'cf-auth' ) ] );
        }

        // Clear rate limit on success
        delete_transient( $key );

        // Update last active
        update_user_meta( $user->ID, 'cf_last_active', current_time( 'mysql' ) );

        // Set auth cookie
        wp_set_auth_cookie( $user->ID, $remember );

        $redirect = sanitize_url( $_POST['redirect_to'] ?? '' );
        if ( empty( $redirect ) || ! wp_validate_redirect( $redirect ) ) {
            $redirect = get_option( 'cf_auth_login_redirect', home_url( '/cf-profile' ) );
        }

        wp_send_json_success( [
            'message'  => __( 'Login successful! Redirecting...', 'cf-auth' ),
            'redirect' => $redirect,
        ] );
    }

    // ── Logout ────────────────────────────────────────────────────────────────
    public function handle_logout() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        wp_logout();
        wp_send_json_success( [
            'redirect' => get_option( 'cf_auth_logout_redirect', home_url() ),
        ] );
    }

    // ── Resend verification email ─────────────────────────────────────────────
    public static function resend_verification( $user_id ) {
        CF_Email::send_verification( $user_id );
    }
}
