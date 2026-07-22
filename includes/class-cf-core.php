<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Core {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_textdomain();
        $this->init_components();
        $this->register_hooks();
    }

    private function load_textdomain() {
        load_plugin_textdomain( 'cf-auth', false, CF_AUTH_DIR . 'languages' );
    }

    private function init_components() {
        CF_Registration::get_instance();
        CF_Login::get_instance();
        CF_Password::get_instance();
        CF_Profile::get_instance();
        CF_Playlists::get_instance();
        CF_Social_Auth::get_instance();
        CF_Donations::get_instance();
        CF_Notifications::get_instance();
        CF_Shortcodes::get_instance();
        if ( is_admin() ) {
            CF_Admin::get_instance();
        }
    }

    private function register_hooks() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
        add_action( 'template_redirect',  [ $this, 'redirect_logged_in' ] );
        add_action( 'template_redirect',  [ $this, 'exclude_auth_pages_from_cache' ], 1 );
        add_action( 'delete_user',            [ $this, 'cleanup_user_on_delete' ] );
        add_action( 'transition_post_status', [ $this, 'notify_on_content_publish' ], 10, 3 );

        // Override WordPress default auth URLs
        add_filter( 'login_url',        [ $this, 'custom_login_url'    ], 10, 3 );
        add_filter( 'register_url',     [ $this, 'custom_register_url' ] );
        add_filter( 'lostpassword_url', [ $this, 'custom_lost_pw_url'  ], 10, 2 );

        // Remove "Howdy" & admin bar items for non-admins
        add_action( 'wp_before_admin_bar_render', [ $this, 'clean_admin_bar' ] );
    }

    public function enqueue_frontend() {
        // Main auth styles
        wp_enqueue_style(
            'cf-auth-style',
            CF_AUTH_URL . 'assets/css/cf-auth.css',
            [],
            CF_AUTH_VERSION
        );

        // User menu styles
        wp_enqueue_style(
            'cf-user-menu-style',
            CF_AUTH_URL . 'assets/css/cf-user-menu.css',
            [ 'cf-auth-style' ],
            CF_AUTH_VERSION
        );

        // Main auth script
        wp_enqueue_script(
            'cf-auth-script',
            CF_AUTH_URL . 'assets/js/cf-auth.js',
            [ 'jquery' ],
            CF_AUTH_VERSION,
            true
        );

        // User menu script
        wp_enqueue_script(
            'cf-user-menu-script',
            CF_AUTH_URL . 'assets/js/cf-user-menu.js',
            [ 'jquery', 'cf-auth-script' ],
            CF_AUTH_VERSION,
            true
        );

        // Pass data to JS
        wp_localize_script( 'cf-auth-script', 'CF_AUTH', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'cf_auth_nonce' ),
            'login_url'   => home_url( '/cf-login' ),
            'profile_url' => home_url( '/cf-profile' ),
            'is_logged_in'=> is_user_logged_in() ? '1' : '0',
        ] );
    }

    // Redirect logged-in users away from auth-only pages
    public function redirect_logged_in() {
        if ( ! is_user_logged_in() ) return;
        $auth_pages = [ 'cf-login', 'cf-register', 'cf-forgot-password' ];
        $queried    = get_queried_object();
        if ( $queried instanceof WP_Post && in_array( $queried->post_name, $auth_pages, true ) ) {
            wp_safe_redirect( get_option( 'cf_auth_login_redirect', home_url( '/cf-profile' ) ) );
            exit;
        }
    }

    // Prevent page caches (LiteSpeed, etc.) from serving stale auth/profile pages
    public function exclude_auth_pages_from_cache() {
        if ( ! is_singular() ) {
            return;
        }

        $post = get_post();
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $auth_shortcodes = [
            'cf_login_form', 'cf_register_form', 'cf_forgot_password',
            'cf_reset_password', 'cf_verify_email', 'cf_user_profile',
        ];

        foreach ( $auth_shortcodes as $shortcode ) {
            if ( has_shortcode( $post->post_content, $shortcode ) ) {
                if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                    define( 'DONOTCACHEPAGE', true );
                }
                do_action( 'litespeed_control_set_nocache', 'CF Auth dynamic page' );
                nocache_headers();
                return;
            }
        }
    }

    public function cleanup_user_on_delete( $user_id ) {
        global $wpdb;

        if ( ! $user_id ) {
            return;
        }

        // Delete social connections
        $wpdb->delete( $wpdb->prefix . 'cf_social_connections', [ 'user_id' => $user_id ] );

        // Delete email verification tokens
        $wpdb->delete( $wpdb->prefix . 'cf_email_tokens', [ 'user_id' => $user_id ] );

        // Delete password reset tokens
        $wpdb->delete( $wpdb->prefix . 'cf_reset_tokens', [ 'user_id' => $user_id ] );

        // Delete activity log entries for this user
        $wpdb->delete( $wpdb->prefix . 'cf_activity_log', [ 'user_id' => $user_id ] );

        // Delete notifications for this user
        $wpdb->delete( $wpdb->prefix . 'cf_notifications', [ 'user_id' => $user_id ] );

        // Delete user metadata added by the plugin
        delete_user_meta( $user_id, 'cf_email_verified' );
        delete_user_meta( $user_id, 'cf_account_status' );
        delete_user_meta( $user_id, 'cf_member_since' );
        delete_user_meta( $user_id, 'cf_social_provider' );
        delete_user_meta( $user_id, 'cf_social_avatar' );
        delete_user_meta( $user_id, 'cf_bio' );
        delete_user_meta( $user_id, 'cf_favorite_tracks' );
        delete_user_meta( $user_id, 'cf_favorite_albums' );
        delete_user_meta( $user_id, 'cf_last_active' );
    }

    // Clean admin bar — remove "Howdy" etc (only runs if admin bar somehow shows)
    public function clean_admin_bar() {
        global $wp_admin_bar;
        if ( ! current_user_can( 'manage_options' ) ) {
            $wp_admin_bar->remove_node( 'my-account' );
            $wp_admin_bar->remove_node( 'user-info' );
            $wp_admin_bar->remove_node( 'edit-profile' );
            $wp_admin_bar->remove_node( 'logout' );
            $wp_admin_bar->remove_node( 'wp-logo' );
            $wp_admin_bar->remove_node( 'site-name' );
            $wp_admin_bar->remove_node( 'new-content' );
            $wp_admin_bar->remove_node( 'comments' );
        }
    }

    public function notify_on_content_publish( $new_status, $old_status, $post ) {
        if ( $new_status !== 'publish' || $old_status === 'publish' ) {
            return;
        }

        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $type_map = [
            'tracks' => 'new_track',
            'albums' => 'new_album',
            'post'   => 'new_article',
        ];

        if ( ! isset( $type_map[ $post->post_type ] ) ) {
            return;
        }

        $notification_type = $type_map[ $post->post_type ];

        $title_templates = [
            'new_track'   => __( 'New track: %s', 'cf-auth' ),
            'new_album'   => __( 'New album: %s', 'cf-auth' ),
            'new_article' => __( 'New article: %s', 'cf-auth' ),
        ];

        $message_templates = [
            'new_track'   => __( 'A new track just dropped on Collective Finity.', 'cf-auth' ),
            'new_album'   => __( 'A new album just dropped on Collective Finity.', 'cf-auth' ),
            'new_article' => __( 'A new article was published on Collective Finity.', 'cf-auth' ),
        ];

        CF_Notifications::create_for_all_users(
            $notification_type,
            sprintf( $title_templates[ $notification_type ], $post->post_title ),
            $message_templates[ $notification_type ],
            get_permalink( $post ),
            (int) $post->post_author
        );
    }

    public function custom_login_url( $url, $redirect, $force_reauth ) {
        return home_url( '/cf-login' ) . ( $redirect ? '?redirect_to=' . urlencode( $redirect ) : '' );
    }
    public function custom_register_url() {
        return home_url( '/cf-register' );
    }
    public function custom_lost_pw_url( $url, $redirect ) {
        return home_url( '/cf-forgot-password' );
    }
}
