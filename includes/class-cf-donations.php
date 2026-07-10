<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Donations {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_cf_paypal_create_order',         [ $this, 'handle_create_order' ] );
        add_action( 'wp_ajax_nopriv_cf_paypal_create_order',  [ $this, 'handle_create_order' ] );
        add_action( 'wp_ajax_cf_paypal_capture_order',        [ $this, 'handle_capture_order' ] );
        add_action( 'wp_ajax_nopriv_cf_paypal_capture_order', [ $this, 'handle_capture_order' ] );
        add_action( 'rest_api_init',                          [ $this, 'register_webhook_route' ] );
        add_action( 'cf_auth_donation_completed',             [ $this, 'log_donation_activity' ], 10, 2 );
    }

    // ── PayPal config helpers ─────────────────────────────────────────────────
    private static function get_mode() {
        return get_option( 'cf_auth_paypal_mode', 'sandbox' );
    }

    private static function get_client_id() {
        if ( self::get_mode() === 'live' ) {
            return get_option( 'cf_auth_paypal_live_client_id', '' );
        }
        return get_option( 'cf_auth_paypal_sandbox_client_id', '' );
    }

    public static function get_public_client_id() {
        return self::get_client_id();
    }

    private static function get_client_secret() {
        if ( self::get_mode() === 'live' ) {
            return get_option( 'cf_auth_paypal_live_client_secret', '' );
        }
        return get_option( 'cf_auth_paypal_sandbox_client_secret', '' );
    }

    private static function get_api_base() {
        if ( self::get_mode() === 'live' ) {
            return 'https://api-m.paypal.com';
        }
        return 'https://api-m.sandbox.paypal.com';
    }

    private function get_access_token() {
        $cache_key = 'cf_paypal_access_token_' . self::get_mode();
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        $client_id     = self::get_client_id();
        $client_secret = self::get_client_secret();
        if ( ! $client_id || ! $client_secret ) {
            return false;
        }

        $response = wp_remote_post( self::get_api_base() . '/v1/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body'    => 'grant_type=client_credentials',
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            return false;
        }

        $expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
        $ttl        = max( 60, $expires_in - 60 );
        set_transient( $cache_key, $data['access_token'], $ttl );

        return $data['access_token'];
    }

    // ── Create PayPal order (AJAX) ──────────────────────────────────────────────
    public function handle_create_order() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        if ( ! isset( $_POST['amount'] ) || ! is_numeric( $_POST['amount'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid donation amount.', 'cf-auth' ) ] );
        }

        $amount = (float) $_POST['amount'];
        if ( $amount < 1.00 || $amount > 100000 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid donation amount.', 'cf-auth' ) ] );
        }

        $is_anonymous = ! empty( $_POST['is_anonymous'] ) && (string) $_POST['is_anonymous'] !== '0';
        $donor_name   = null;
        if ( ! $is_anonymous ) {
            $donor_name = sanitize_text_field( $_POST['donor_name'] ?? '' );
            if ( strlen( $donor_name ) > 190 ) {
                $donor_name = substr( $donor_name, 0, 190 );
            }
            if ( $donor_name === '' ) {
                $donor_name = null;
            }
        }

        $message  = sanitize_textarea_field( $_POST['message'] ?? '' );
        $currency = get_option( 'cf_auth_donation_currency', 'EUR' );
        $user_id  = get_current_user_id();
        $db_user  = $user_id > 0 ? $user_id : null;

        global $wpdb;
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'cf_donations',
            [
                'user_id'      => $db_user,
                'donor_name'   => $donor_name,
                'donor_email'  => null,
                'amount'       => $amount,
                'currency'     => $currency,
                'status'       => 'pending',
                'message'      => $message !== '' ? $message : null,
                'show_on_wall' => 1,
            ],
            [ '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%d' ]
        );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again.', 'cf-auth' ) ] );
        }

        $donation_id = (int) $wpdb->insert_id;

        $token = $this->get_access_token();
        if ( ! $token ) {
            CF_Activity_Log::safe_log( 'paypal_token_error', [
                'meta' => [ 'donation_id' => $donation_id ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        $order_response = wp_remote_post( self::get_api_base() . '/v2/checkout/orders', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => wp_json_encode( [
                'intent'         => 'CAPTURE',
                'purchase_units' => [
                    [
                        'custom_id' => (string) $donation_id,
                        'amount'    => [
                            'currency_code' => $currency,
                            'value'         => number_format( $amount, 2, '.', '' ),
                        ],
                    ],
                ],
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $order_response ) ) {
            CF_Activity_Log::safe_log( 'paypal_order_error', [
                'meta' => [
                    'donation_id' => $donation_id,
                    'error'       => $order_response->get_error_message(),
                ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        $order_code = wp_remote_retrieve_response_code( $order_response );
        if ( $order_code !== 200 && $order_code !== 201 ) {
            CF_Activity_Log::safe_log( 'paypal_order_error', [
                'meta' => [
                    'donation_id' => $donation_id,
                    'http_code'   => $order_code,
                    'body'        => wp_remote_retrieve_body( $order_response ),
                ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        $order_data = json_decode( wp_remote_retrieve_body( $order_response ), true );
        if ( empty( $order_data['id'] ) ) {
            CF_Activity_Log::safe_log( 'paypal_order_error', [
                'meta' => [
                    'donation_id' => $donation_id,
                    'reason'      => 'missing_order_id',
                ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        $wpdb->update(
            $wpdb->prefix . 'cf_donations',
            [ 'paypal_order_id' => $order_data['id'] ],
            [ 'id' => $donation_id ],
            [ '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [ 'order_id' => $order_data['id'] ] );
    }

    // ── Capture PayPal order (AJAX) ───────────────────────────────────────────
    public function handle_capture_order() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        $paypal_order_id = sanitize_text_field( $_POST['paypal_order_id'] ?? '' );
        if ( ! $paypal_order_id ) {
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again.', 'cf-auth' ) ] );
        }

        $token = $this->get_access_token();
        if ( ! $token ) {
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        $capture_response = wp_remote_post(
            self::get_api_base() . '/v2/checkout/orders/' . rawurlencode( $paypal_order_id ) . '/capture',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body'    => '{}',
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $capture_response ) ) {
            CF_Activity_Log::safe_log( 'paypal_capture_error', [
                'meta' => [
                    'paypal_order_id' => $paypal_order_id,
                    'error'           => $capture_response->get_error_message(),
                ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        $capture_code = wp_remote_retrieve_response_code( $capture_response );
        if ( $capture_code !== 200 && $capture_code !== 201 ) {
            CF_Activity_Log::safe_log( 'paypal_capture_error', [
                'meta' => [
                    'paypal_order_id' => $paypal_order_id,
                    'http_code'       => $capture_code,
                    'body'            => wp_remote_retrieve_body( $capture_response ),
                ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        $capture_data = json_decode( wp_remote_retrieve_body( $capture_response ), true );
        $unit         = $capture_data['purchase_units'][0] ?? null;
        $capture      = $unit['payments']['captures'][0] ?? null;

        if ( ! is_array( $unit ) || ! is_array( $capture ) ) {
            CF_Activity_Log::safe_log( 'paypal_capture_error', [
                'meta' => [
                    'paypal_order_id' => $paypal_order_id,
                    'reason'          => 'missing_capture_structure',
                    'raw_response'    => wp_remote_retrieve_body( $capture_response ),
                ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        $capture_id    = $capture['id'] ?? '';
        $capture_status = $capture['status'] ?? '';
        $amount_value  = $capture['amount']['value'] ?? '';
        $currency_code = $capture['amount']['currency_code'] ?? '';
        $custom_id     = $capture['custom_id'] ?? '';

        if ( ! $capture_id || ! $capture_status || $amount_value === '' || ! $currency_code || ! $custom_id ) {
            CF_Activity_Log::safe_log( 'paypal_capture_error', [
                'meta' => [
                    'paypal_order_id' => $paypal_order_id,
                    'reason'          => 'missing_capture_fields',
                    'raw_response'    => wp_remote_retrieve_body( $capture_response ),
                ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Unable to process donation. Please try again later.', 'cf-auth' ) ] );
        }

        if ( $capture_status === 'COMPLETED' ) {
            $this->mark_donation_completed(
                (int) $custom_id,
                $capture_id,
                $amount_value,
                $currency_code
            );
            wp_send_json_success( [ 'status' => 'completed' ] );
        }

        wp_send_json_success( [ 'status' => $capture_status ] );
    }

    // ── Shared donation completion ──────────────────────────────────────────
    private function mark_donation_completed( $donation_id, $capture_id, $amount, $currency ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cf_donations';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE id = %d",
            $donation_id
        ) );

        if ( ! $row ) {
            return false;
        }

        if ( $row->status === 'completed' ) {
            return false;
        }

        $updated = $wpdb->update(
            $table,
            [
                'status'            => 'completed',
                'paypal_capture_id' => $capture_id,
            ],
            [
                'id'     => $donation_id,
                'status' => 'pending',
            ],
            [ '%s', '%s' ],
            [ '%d', '%s' ]
        );

        if ( $updated ) {
            do_action( 'cf_auth_donation_completed', $donation_id, [
                'amount'     => $amount,
                'currency'   => $currency,
                'capture_id' => $capture_id,
            ] );
            return true;
        }

        return false;
    }

    public function log_donation_activity( $donation_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'cf_donations';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT donor_name, user_id FROM {$table} WHERE id = %d",
            $donation_id
        ) );
        CF_Activity_Log::safe_log( 'donation_completed', [
            'user_id' => $row ? $row->user_id : null,
            'email'   => null,
            'meta'    => [
                'donation_id' => $donation_id,
                'donor_name'  => $row ? $row->donor_name : null,
                'amount'      => $data['amount'] ?? null,
                'currency'    => $data['currency'] ?? null,
                'capture_id'  => $data['capture_id'] ?? null,
            ],
        ] );
    }

    // ── PayPal webhook (REST) ─────────────────────────────────────────────────
    public function register_webhook_route() {
        register_rest_route( 'cf-auth/v1', '/paypal-webhook', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_webhook( WP_REST_Request $request ) {
        try {
            $body  = $request->get_body();
            $event = json_decode( $body, true );
            if ( ! is_array( $event ) || empty( $event ) ) {
                return new WP_REST_Response( [ 'error' => 'Invalid payload' ], 400 );
            }

            $transmission_id   = $request->get_header( 'paypal-transmission-id' );
            $transmission_time = $request->get_header( 'paypal-transmission-time' );
            $cert_url          = $request->get_header( 'paypal-cert-url' );
            $auth_algo         = $request->get_header( 'paypal-auth-algo' );
            $transmission_sig  = $request->get_header( 'paypal-transmission-sig' );

            if ( ! $transmission_id || ! $transmission_time || ! $cert_url || ! $auth_algo || ! $transmission_sig ) {
                return new WP_REST_Response( [ 'error' => 'Missing verification headers' ], 400 );
            }

            $token = $this->get_access_token();
            if ( ! $token ) {
                CF_Activity_Log::safe_log( 'paypal_webhook_verification_failed', [
                    'meta' => [
                        'transmission_id' => $transmission_id,
                        'reason'          => 'token_unavailable',
                    ],
                ] );
                return new WP_REST_Response( [ 'error' => 'Verification failed' ], 400 );
            }

            $verify_response = wp_remote_post( self::get_api_base() . '/v1/notifications/verify-webhook-signature', [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body'    => wp_json_encode( [
                    'transmission_id'   => $transmission_id,
                    'transmission_time' => $transmission_time,
                    'cert_url'          => $cert_url,
                    'auth_algo'         => $auth_algo,
                    'transmission_sig'  => $transmission_sig,
                    'webhook_id'        => get_option( 'cf_auth_paypal_webhook_id' ),
                    'webhook_event'     => $event,
                ] ),
                'timeout' => 15,
            ] );

            if ( is_wp_error( $verify_response ) ) {
                CF_Activity_Log::safe_log( 'paypal_webhook_verification_failed', [
                    'meta' => [
                        'transmission_id' => $transmission_id,
                        'error'           => $verify_response->get_error_message(),
                    ],
                ] );
                return new WP_REST_Response( [ 'error' => 'Verification failed' ], 400 );
            }

            $verify_data = json_decode( wp_remote_retrieve_body( $verify_response ), true );
            if ( empty( $verify_data['verification_status'] ) || $verify_data['verification_status'] !== 'SUCCESS' ) {
                CF_Activity_Log::safe_log( 'paypal_webhook_verification_failed', [
                    'meta' => [
                        'transmission_id'      => $transmission_id,
                        'verification_status'  => $verify_data['verification_status'] ?? 'missing',
                    ],
                ] );
                return new WP_REST_Response( [ 'error' => 'Verification failed' ], 400 );
            }

            if ( ( $event['event_type'] ?? '' ) !== 'PAYMENT.CAPTURE.COMPLETED' ) {
                return new WP_REST_Response( null, 200 );
            }

            $resource   = $event['resource'] ?? [];
            $capture_id = $resource['id'] ?? '';
            $custom_id  = $resource['custom_id'] ?? '';
            $amount     = $resource['amount']['value'] ?? '';
            $currency   = $resource['amount']['currency_code'] ?? '';

            $donation_id = absint( $custom_id );
            if ( ! $donation_id || ! $capture_id ) {
                CF_Activity_Log::safe_log( 'paypal_webhook_unmatched_donation', [
                    'meta' => [
                        'capture_id' => $capture_id,
                        'custom_id'  => $custom_id,
                        'reason'     => 'missing_ids',
                    ],
                ] );
                return new WP_REST_Response( null, 200 );
            }

            global $wpdb;
            $table = $wpdb->prefix . 'cf_donations';
            $row   = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, status FROM {$table} WHERE id = %d",
                $donation_id
            ) );

            if ( ! $row ) {
                CF_Activity_Log::safe_log( 'paypal_webhook_unmatched_donation', [
                    'meta' => [
                        'capture_id'  => $capture_id,
                        'custom_id'   => $custom_id,
                        'donation_id' => $donation_id,
                    ],
                ] );
                return new WP_REST_Response( null, 200 );
            }

            $this->mark_donation_completed( $donation_id, $capture_id, $amount, $currency );

            return new WP_REST_Response( null, 200 );

        } catch ( Exception $e ) {
            CF_Activity_Log::safe_log( 'paypal_webhook_error', [
                'meta' => [ 'error' => $e->getMessage() ],
            ] );
            return new WP_REST_Response( null, 200 );
        }
    }
}
