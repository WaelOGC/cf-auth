<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Social_Auth {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'template_redirect', [ $this, 'handle_oauth_callback' ] );
        add_action( 'wp_ajax_nopriv_cf_social_init', [ $this, 'handle_social_init' ] );
    }

    // ── Start OAuth Flow ──────────────────────────────────────────────────────
    public function handle_social_init() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        $provider = sanitize_key( $_POST['provider'] ?? '' );
        $url      = $this->get_auth_url( $provider );

        if ( ! $url ) {
            CF_Activity_Log::safe_log( 'login_failed', [
                'provider' => $provider,
                'meta'     => [ 'reason' => 'provider_not_configured' ],
            ] );
            wp_send_json_error( [ 'message' => __( 'Provider not configured.', 'cf-auth' ) ] );
        }

        wp_send_json_success( [ 'redirect' => $url ] );
    }

    // ── Generate OAuth URLs ───────────────────────────────────────────────────
    private function get_auth_url( $provider ) {
        $state         = wp_create_nonce( 'cf_oauth_' . $provider );
        $callback_url  = add_query_arg( [ 'cf_oauth' => $provider ], home_url( '/cf-login' ) );

        set_transient( 'cf_oauth_state_' . $state, $provider, 15 * MINUTE_IN_SECONDS );

        switch ( $provider ) {

            case 'google':
                $client_id = get_option( 'cf_auth_google_client_id' );
                if ( ! $client_id ) return false;
                return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( [
                    'client_id'     => $client_id,
                    'redirect_uri'  => $callback_url,
                    'response_type' => 'code',
                    'scope'         => 'openid email profile',
                    'state'         => $state,
                    'access_type'   => 'online',
                ] );

            case 'facebook':
                $app_id = get_option( 'cf_auth_facebook_app_id' );
                if ( ! $app_id ) return false;
                return 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query( [
                    'client_id'     => $app_id,
                    'redirect_uri'  => $callback_url,
                    'response_type' => 'code',
                    'scope'         => 'email,public_profile',
                    'state'         => $state,
                ] );

            case 'discord':
                $client_id = get_option( 'cf_auth_discord_client_id' );
                if ( ! $client_id ) return false;
                return 'https://discord.com/api/oauth2/authorize?' . http_build_query( [
                    'client_id'     => $client_id,
                    'redirect_uri'  => $callback_url,
                    'response_type' => 'code',
                    'scope'         => 'identify email',
                    'state'         => $state,
                ] );

            case 'twitter':
                // Twitter OAuth 2.0 PKCE
                $api_key  = get_option( 'cf_auth_twitter_api_key' );
                if ( ! $api_key ) return false;
                $verifier = bin2hex( random_bytes( 32 ) );
                $challenge= rtrim( base64_encode( hash( 'sha256', $verifier, true ) ), '=' );
                $challenge= strtr( $challenge, '+/', '-_' );
                set_transient( 'cf_pkce_verifier_' . $state, $verifier, 15 * MINUTE_IN_SECONDS );
                return 'https://twitter.com/i/oauth2/authorize?' . http_build_query( [
                    'client_id'             => $api_key,
                    'redirect_uri'          => $callback_url,
                    'response_type'         => 'code',
                    'scope'                 => 'tweet.read users.read offline.access',
                    'state'                 => $state,
                    'code_challenge'        => $challenge,
                    'code_challenge_method' => 'S256',
                ] );

            default:
                return false;
        }
    }

    // ── Handle OAuth Callback ─────────────────────────────────────────────────
    public function handle_oauth_callback() {
        if ( ! isset( $_GET['cf_oauth'] ) ) return;

        $provider = sanitize_key( $_GET['cf_oauth'] );

        if ( ! isset( $_GET['code'] ) ) {
            CF_Activity_Log::safe_log( 'login_failed', [
                'provider' => $provider,
                'meta'     => [ 'reason' => 'oauth_denied' ],
            ] );
            return;
        }

        $code     = sanitize_text_field( $_GET['code'] );
        $state    = sanitize_text_field( $_GET['state'] ?? '' );

        // Validate state
        if ( ! wp_verify_nonce( $state, 'cf_oauth_' . $provider ) ) {
            CF_Activity_Log::safe_log( 'login_failed', [
                'provider' => $provider,
                'meta'     => [ 'reason' => 'oauth_state_invalid' ],
            ] );
            wp_die( __( 'Security check failed. Please try again.', 'cf-auth' ) );
        }

        delete_transient( 'cf_oauth_state_' . $state );

        $callback_url = add_query_arg( [ 'cf_oauth' => $provider ], home_url( '/cf-login' ) );

        switch ( $provider ) {
            case 'google':   $user_data = $this->google_get_user( $code, $callback_url ); break;
            case 'facebook': $user_data = $this->facebook_get_user( $code, $callback_url ); break;
            case 'discord':  $user_data = $this->discord_get_user( $code, $callback_url ); break;
            case 'twitter':  $user_data = $this->twitter_get_user( $code, $state, $callback_url ); break;
            default:
                CF_Activity_Log::safe_log( 'login_failed', [
                    'provider' => $provider,
                    'meta'     => [ 'reason' => 'invalid_provider' ],
                ] );
                wp_safe_redirect( home_url( '/cf-login?error=invalid_provider' ) ); exit;
        }

        if ( is_wp_error( $user_data ) ) {
            CF_Activity_Log::safe_log( 'login_failed', [
                'provider' => $provider,
                'meta'     => [
                    'reason' => 'oauth_token_error',
                    'error'  => $user_data->get_error_message(),
                ],
            ] );
            wp_safe_redirect( home_url( '/cf-login?error=' . urlencode( $user_data->get_error_message() ) ) );
            exit;
        }

        $user_id = $this->find_or_create_user( $user_data, $provider );

        if ( is_wp_error( $user_id ) ) {
            CF_Activity_Log::safe_log( 'login_failed', [
                'email'    => $user_data['email'] ?? null,
                'provider' => $provider,
                'meta'     => [
                    'reason' => 'oauth_user_error',
                    'error'  => $user_id->get_error_message(),
                ],
            ] );
            wp_safe_redirect( home_url( '/cf-login?error=' . urlencode( $user_id->get_error_message() ) ) );
            exit;
        }

        update_user_meta( $user_id, 'cf_last_active', current_time( 'mysql' ) );
        wp_set_auth_cookie( $user_id, false );

        do_action( 'cf_auth_after_login', $user_id, [
            'method'   => 'social',
            'provider' => $provider,
        ] );

        wp_safe_redirect( get_option( 'cf_auth_login_redirect', home_url( '/cf-profile' ) ) );
        exit;
    }

    // ── Google ────────────────────────────────────────────────────────────────
    private function google_get_user( $code, $redirect_uri ) {
        $token_res = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $code,
                'client_id'     => get_option( 'cf_auth_google_client_id' ),
                'client_secret' => get_option( 'cf_auth_google_client_secret' ),
                'redirect_uri'  => $redirect_uri,
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $token_res ) ) return $token_res;
        $token = json_decode( wp_remote_retrieve_body( $token_res ), true );
        if ( empty( $token['access_token'] ) ) return new WP_Error( 'oauth_error', 'Google: failed to get token' );

        $user_res = wp_remote_get( 'https://www.googleapis.com/oauth2/v3/userinfo', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
        ] );

        if ( is_wp_error( $user_res ) ) return $user_res;
        $data = json_decode( wp_remote_retrieve_body( $user_res ), true );

        return [
            'provider_id'  => $data['sub'],
            'email'        => $data['email'],
            'display_name' => $data['name'] ?? $data['given_name'],
            'avatar_url'   => $data['picture'] ?? '',
        ];
    }

    // ── Facebook ──────────────────────────────────────────────────────────────
    private function facebook_get_user( $code, $redirect_uri ) {
        $token_res = wp_remote_get( 'https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query( [
            'client_id'     => get_option( 'cf_auth_facebook_app_id' ),
            'client_secret' => get_option( 'cf_auth_facebook_app_secret' ),
            'redirect_uri'  => $redirect_uri,
            'code'          => $code,
        ] ) );

        if ( is_wp_error( $token_res ) ) return $token_res;
        $token = json_decode( wp_remote_retrieve_body( $token_res ), true );
        if ( empty( $token['access_token'] ) ) return new WP_Error( 'oauth_error', 'Facebook: failed to get token' );

        $user_res = wp_remote_get( 'https://graph.facebook.com/me?fields=id,name,email,picture.width(200)&access_token=' . $token['access_token'] );
        if ( is_wp_error( $user_res ) ) return $user_res;
        $data = json_decode( wp_remote_retrieve_body( $user_res ), true );

        return [
            'provider_id'  => $data['id'],
            'email'        => $data['email'] ?? '',
            'display_name' => $data['name'],
            'avatar_url'   => $data['picture']['data']['url'] ?? '',
        ];
    }

    // ── Discord ───────────────────────────────────────────────────────────────
    private function discord_get_user( $code, $redirect_uri ) {
        $token_res = wp_remote_post( 'https://discord.com/api/oauth2/token', [
            'body' => [
                'client_id'     => get_option( 'cf_auth_discord_client_id' ),
                'client_secret' => get_option( 'cf_auth_discord_client_secret' ),
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
            ],
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
        ] );

        if ( is_wp_error( $token_res ) ) return $token_res;
        $token = json_decode( wp_remote_retrieve_body( $token_res ), true );
        if ( empty( $token['access_token'] ) ) return new WP_Error( 'oauth_error', 'Discord: failed to get token' );

        $user_res = wp_remote_get( 'https://discord.com/api/users/@me', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
        ] );

        if ( is_wp_error( $user_res ) ) return $user_res;
        $data = json_decode( wp_remote_retrieve_body( $user_res ), true );

        $avatar_url = '';
        if ( ! empty( $data['avatar'] ) ) {
            $avatar_url = "https://cdn.discordapp.com/avatars/{$data['id']}/{$data['avatar']}.png?size=256";
        }

        return [
            'provider_id'  => $data['id'],
            'email'        => $data['email'] ?? '',
            'display_name' => $data['global_name'] ?? $data['username'],
            'avatar_url'   => $avatar_url,
        ];
    }

    // ── X / Twitter ───────────────────────────────────────────────────────────
    private function twitter_get_user( $code, $state, $redirect_uri ) {
        $verifier = get_transient( 'cf_pkce_verifier_' . $state );
        delete_transient( 'cf_pkce_verifier_' . $state );

        $api_key    = get_option( 'cf_auth_twitter_api_key' );
        $api_secret = get_option( 'cf_auth_twitter_api_secret' );
        $basic_auth = base64_encode( $api_key . ':' . $api_secret );

        $token_res = wp_remote_post( 'https://api.twitter.com/2/oauth2/token', [
            'body' => [
                'client_id'     => $api_key,
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
                'code_verifier' => $verifier,
            ],
            'headers' => [
                'Authorization' => 'Basic ' . $basic_auth,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
        ] );

        if ( is_wp_error( $token_res ) ) return $token_res;
        $token = json_decode( wp_remote_retrieve_body( $token_res ), true );
        if ( empty( $token['access_token'] ) ) return new WP_Error( 'oauth_error', 'X: failed to get token' );

        $user_res = wp_remote_get( 'https://api.twitter.com/2/users/me?user.fields=profile_image_url,name', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
        ] );

        if ( is_wp_error( $user_res ) ) return $user_res;
        $data = json_decode( wp_remote_retrieve_body( $user_res ), true );
        $u    = $data['data'] ?? [];

        return [
            'provider_id'  => $u['id'] ?? '',
            'email'        => '', // X doesn't always provide email
            'display_name' => $u['name'] ?? $u['username'],
            'avatar_url'   => str_replace( '_normal', '', $u['profile_image_url'] ?? '' ),
        ];
    }

    // ── Find or Create WordPress User ─────────────────────────────────────────
    private function find_or_create_user( $data, $provider ) {
        global $wpdb;

        // 1. Check existing social connection
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}cf_social_connections WHERE provider = %s AND provider_id = %s",
            $provider,
            $data['provider_id']
        ) );

        if ( $row ) {
            // Update avatar if changed
            if ( ! empty( $data['avatar_url'] ) ) {
                $wpdb->update( $wpdb->prefix . 'cf_social_connections', [ 'avatar_url' => $data['avatar_url'] ], [ 'user_id' => $row->user_id, 'provider' => $provider ] );
                update_user_meta( $row->user_id, 'cf_social_avatar', $data['avatar_url'] );
            }
            CF_Activity_Log::safe_log( 'login_success', [
                'user_id'  => (int) $row->user_id,
                'email'    => $data['email'] ?? null,
                'provider' => $provider,
            ] );
            return $row->user_id;
        }

        // 2. Check existing WordPress user by email
        if ( ! empty( $data['email'] ) ) {
            $existing = get_user_by( 'email', $data['email'] );
            if ( $existing ) {
                // Link social account to existing user
                $this->save_social_connection( $existing->ID, $provider, $data );
                CF_Activity_Log::safe_log( 'login_success', [
                    'user_id'  => $existing->ID,
                    'email'    => $data['email'],
                    'provider' => $provider,
                ] );
                return $existing->ID;
            }
        }

        // 3. Create new user
        $email    = ! empty( $data['email'] ) ? $data['email'] : $provider . '_' . $data['provider_id'] . '@noemail.cf';
        $username = sanitize_user( strtolower( str_replace( ' ', '', $data['display_name'] ) ), true );
        if ( username_exists( $username ) ) $username .= rand( 100, 999 );

        // Random password for social users
        $password = wp_generate_password( 24, true );
        $user_id  = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) return $user_id;

        $user = new WP_User( $user_id );
        $user->set_role( 'cf_listener' );

        wp_update_user( [ 'ID' => $user_id, 'display_name' => $data['display_name'] ] );

        update_user_meta( $user_id, 'cf_email_verified', '1' );
        update_user_meta( $user_id, 'cf_account_status', 'active' );
        update_user_meta( $user_id, 'cf_member_since',   current_time( 'mysql' ) );
        update_user_meta( $user_id, 'cf_favorite_tracks', [] );
        update_user_meta( $user_id, 'cf_favorite_albums', [] );
        update_user_meta( $user_id, 'cf_bio', '' );

        $this->save_social_connection( $user_id, $provider, $data );
        CF_Email::send_welcome( $user_id );

        CF_Activity_Log::safe_log( 'registered', [
            'user_id'  => $user_id,
            'email'    => $email,
            'provider' => $provider,
        ] );

        return $user_id;
    }

    private function save_social_connection( $user_id, $provider, $data ) {
        global $wpdb;

        $wpdb->replace( $wpdb->prefix . 'cf_social_connections', [
            'user_id'     => $user_id,
            'provider'    => $provider,
            'provider_id' => $data['provider_id'],
            'avatar_url'  => $data['avatar_url'] ?? '',
        ] );

        update_user_meta( $user_id, 'cf_social_provider', $provider );
        update_user_meta( $user_id, 'cf_social_avatar',   $data['avatar_url'] ?? '' );
    }
}
