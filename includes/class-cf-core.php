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
        CF_Social_Auth::get_instance();
        CF_Donations::get_instance();
        CF_Shortcodes::get_instance();
        if ( is_admin() ) {
            CF_Admin::get_instance();
        }
    }

    private function register_hooks() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend' ] );
        add_action( 'template_redirect',  [ $this, 'redirect_logged_in' ] );

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
