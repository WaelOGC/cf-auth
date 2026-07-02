<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Password {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_nopriv_cf_forgot_password', [ $this, 'handle_forgot' ] );
        add_action( 'wp_ajax_nopriv_cf_reset_password',  [ $this, 'handle_reset' ] );
        add_action( 'wp_ajax_cf_change_password',        [ $this, 'handle_change' ] );
    }

    // ── Forgot Password ───────────────────────────────────────────────────────
    public function handle_forgot() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        $email = sanitize_email( $_POST['email'] ?? '' );

        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'cf-auth' ) ] );
        }

        $user = get_user_by( 'email', $email );

        // Always return success to prevent email enumeration
        if ( $user ) {
            CF_Email::send_password_reset( $user->ID );
        }

        wp_send_json_success( [
            'message' => __( 'If that email exists in our system, you will receive a reset link shortly.', 'cf-auth' ),
        ] );
    }

    // ── Reset Password ────────────────────────────────────────────────────────
    public function handle_reset() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        global $wpdb;

        $token    = sanitize_text_field( $_POST['token'] ?? '' );
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid reset token.', 'cf-auth' ) ] );
        }

        if ( strlen( $password ) < 8 ) {
            wp_send_json_error( [ 'message' => __( 'Password must be at least 8 characters.', 'cf-auth' ) ] );
        }

        if ( $password !== $confirm ) {
            wp_send_json_error( [ 'message' => __( 'Passwords do not match.', 'cf-auth' ) ] );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cf_reset_tokens WHERE token = %s AND expires_at > NOW() AND used = 0",
            $token
        ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => __( 'This reset link has expired or already been used.', 'cf-auth' ) ] );
        }

        // Update password
        wp_set_password( $password, $row->user_id );

        // Mark token as used
        $wpdb->update(
            $wpdb->prefix . 'cf_reset_tokens',
            [ 'used' => 1 ],
            [ 'id'   => $row->id ]
        );

        wp_send_json_success( [
            'message'  => __( 'Password updated successfully! You can now log in.', 'cf-auth' ),
            'redirect' => home_url( '/cf-login' ),
        ] );
    }

    // ── Change Password (logged in) ───────────────────────────────────────────
    public function handle_change() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'cf-auth' ) ] );
        }

        $user_id      = get_current_user_id();
        $current_pass = $_POST['current_password'] ?? '';
        $new_pass     = $_POST['new_password'] ?? '';
        $confirm      = $_POST['confirm_password'] ?? '';

        $user = get_userdata( $user_id );

        if ( ! wp_check_password( $current_pass, $user->user_pass, $user_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Current password is incorrect.', 'cf-auth' ) ] );
        }

        if ( strlen( $new_pass ) < 8 ) {
            wp_send_json_error( [ 'message' => __( 'New password must be at least 8 characters.', 'cf-auth' ) ] );
        }

        if ( $new_pass !== $confirm ) {
            wp_send_json_error( [ 'message' => __( 'Passwords do not match.', 'cf-auth' ) ] );
        }

        wp_set_password( $new_pass, $user_id );
        wp_set_auth_cookie( $user_id ); // Keep logged in after change

        wp_send_json_success( [ 'message' => __( 'Password changed successfully!', 'cf-auth' ) ] );
    }
}
