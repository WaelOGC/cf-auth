<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Install {

    const DB_VERSION = '3';

    public static function activate() {
        self::create_tables();
        CF_Activity_Log::create_table();
        update_option( 'cf_auth_db_version', self::DB_VERSION );
        self::create_roles();
        self::create_pages();
        self::set_default_options();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function maybe_upgrade() {
        $stored = get_option( 'cf_auth_db_version', '' );
        if ( $stored === self::DB_VERSION ) {
            return;
        }
        self::create_tables();
        CF_Activity_Log::create_table();
        update_option( 'cf_auth_db_version', self::DB_VERSION );
        self::create_pages();
    }

    /**
     * Core plugin tables (email tokens, social connections, listening history,
     * password reset tokens).
     *
     * When a future module (e.g. donations, courses) needs a new table, add
     * its dbDelta() SQL here (or call its installer from here), then bump
     * CF_Install::DB_VERSION. Already-active installs will create the new
     * table automatically on their next request via maybe_upgrade() — no
     * reactivation required.
     */
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

        // PayPal donations
        $sql5 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cf_donations (
            id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id           BIGINT UNSIGNED DEFAULT NULL,
            donor_name        VARCHAR(190)    DEFAULT NULL,
            donor_email       VARCHAR(190)    DEFAULT NULL,
            amount            DECIMAL(10,2)   NOT NULL,
            currency          VARCHAR(3)      NOT NULL DEFAULT 'EUR',
            paypal_order_id   VARCHAR(64)     DEFAULT NULL,
            paypal_capture_id VARCHAR(64)     DEFAULT NULL UNIQUE,
            status            VARCHAR(20)     NOT NULL DEFAULT 'pending',
            message           TEXT            DEFAULT NULL,
            show_on_wall      TINYINT(1)      NOT NULL DEFAULT 1,
            created_at        DATETIME        DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        ) $charset;";

        // Playlists
        $sql6 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cf_playlists (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      BIGINT UNSIGNED NOT NULL,
            name         VARCHAR(190)    NOT NULL,
            is_public    TINYINT(1)      NOT NULL DEFAULT 0,
            share_token  VARCHAR(32)     NOT NULL,
            created_at   DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            UNIQUE KEY idx_share_token (share_token)
        ) $charset;";

        $sql7 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cf_playlist_items (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            playlist_id  BIGINT UNSIGNED NOT NULL,
            item_id      BIGINT UNSIGNED NOT NULL,
            item_type    VARCHAR(10)     NOT NULL,
            position     INT UNSIGNED    NOT NULL DEFAULT 0,
            added_at     DATETIME        DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_playlist (playlist_id),
            UNIQUE KEY unique_item (playlist_id, item_id, item_type)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
        dbDelta( $sql4 );
        dbDelta( $sql5 );
        dbDelta( $sql6 );
        dbDelta( $sql7 );
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
            'cf-playlist'       => [ 'title' => 'Playlist',        'shortcode' => '[cf_playlist_view]' ],
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
            'cf_auth_paypal_mode'                 => 'sandbox',
            'cf_auth_paypal_sandbox_client_id'    => '',
            'cf_auth_paypal_sandbox_client_secret'=> '',
            'cf_auth_paypal_live_client_id'       => '',
            'cf_auth_paypal_live_client_secret'   => '',
            'cf_auth_paypal_webhook_id'           => '',
            'cf_auth_donation_currency'           => 'EUR',
        ];
        foreach ( $defaults as $key => $val ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $val );
            }
        }
    }
}
