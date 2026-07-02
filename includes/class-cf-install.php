<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Install {

    public static function activate() {
        self::create_tables();
        self::create_roles();
        self::create_pages();
        self::set_default_options();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    // ── Database Tables ───────────────────────────────────────────────────────
    private static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Email verification tokens
        $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cf_email_tokens (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     BIGINT UNSIGNED NOT NULL,
            token       VARCHAR(64)     NOT NULL UNIQUE,
            expires_at  DATETIME        NOT NULL,
            created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token   (token),
            INDEX idx_user_id (user_id)
        ) $charset;";

        // Social auth connections
        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cf_social_connections (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     BIGINT UNSIGNED NOT NULL,
            provider    VARCHAR(20)     NOT NULL,
            provider_id VARCHAR(255)    NOT NULL,
            avatar_url  TEXT,
            created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY  unique_provider (provider, provider_id),
            INDEX idx_user_id (user_id)
        ) $charset;";

        // Listening history
        $sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cf_listening_history (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     BIGINT UNSIGNED NOT NULL,
            track_id    BIGINT UNSIGNED NOT NULL,
            listened_at DATETIME        DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_track   (track_id)
        ) $charset;";

        // Password reset tokens
        $sql4 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cf_reset_tokens (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     BIGINT UNSIGNED NOT NULL,
            token       VARCHAR(64)     NOT NULL UNIQUE,
            expires_at  DATETIME        NOT NULL,
            used        TINYINT(1)      DEFAULT 0,
            created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
        dbDelta( $sql4 );
    }

    // ── Custom Role ───────────────────────────────────────────────────────────
    private static function create_roles() {
        remove_role( 'cf_listener' );
        add_role( 'cf_listener', __( 'CF Listener', 'cf-auth' ), [
            'read'                  => true,
            'access_music_library'  => true,
            'save_favorites'        => true,
        ] );
    }

    // ── Auto-create pages with shortcodes ─────────────────────────────────────
    private static function create_pages() {
        $pages = [
            'cf-login'          => [ 'title' => 'Login',           'shortcode' => '[cf_login_form]' ],
            'cf-register'       => [ 'title' => 'Register',        'shortcode' => '[cf_register_form]' ],
            'cf-forgot-password'=> [ 'title' => 'Forgot Password', 'shortcode' => '[cf_forgot_password]' ],
            'cf-reset-password' => [ 'title' => 'Reset Password',  'shortcode' => '[cf_reset_password]' ],
            'cf-profile'        => [ 'title' => 'My Profile',      'shortcode' => '[cf_user_profile]' ],
            'cf-verify-email'   => [ 'title' => 'Verify Email',    'shortcode' => '[cf_verify_email]' ],
        ];

        foreach ( $pages as $slug => $data ) {
            if ( ! get_page_by_path( $slug ) ) {
                wp_insert_post( [
                    'post_title'   => $data['title'],
                    'post_name'    => $slug,
                    'post_content' => $data['shortcode'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ] );
            }
        }
    }

    // ── Default Options ───────────────────────────────────────────────────────
    private static function set_default_options() {
        $defaults = [
            'cf_auth_google_client_id'      => '',
            'cf_auth_google_client_secret'  => '',
            'cf_auth_facebook_app_id'       => '',
            'cf_auth_facebook_app_secret'   => '',
            'cf_auth_discord_client_id'     => '',
            'cf_auth_discord_client_secret' => '',
            'cf_auth_twitter_api_key'       => '',
            'cf_auth_twitter_api_secret'    => '',
            'cf_auth_email_verification'    => '1',
            'cf_auth_login_redirect'        => home_url( '/cf-profile' ),
            'cf_auth_logout_redirect'       => home_url(),
            'cf_auth_after_register'        => home_url( '/cf-verify-email' ),
        ];
        foreach ( $defaults as $key => $val ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $val );
            }
        }
    }
}
