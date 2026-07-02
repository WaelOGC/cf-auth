<?php
/**
 * Plugin Name: CF Auth — Collective Finity
 * Plugin URI:  https://collectivefinity.com
 * Description: Custom authentication system for Collective Finity — Register, Login, Social OAuth, User Profiles & Admin Dashboard.
 * Version:     2.0.0
 * Author:      Collective Finity
 * Text Domain: cf-auth
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'CF_AUTH_VERSION',   '2.0.0' );
define( 'CF_AUTH_DIR',       plugin_dir_path( __FILE__ ) );
define( 'CF_AUTH_URL',       plugin_dir_url( __FILE__ ) );
define( 'CF_AUTH_TEMPLATES', CF_AUTH_DIR . 'templates/' );

// ── Autoload ─────────────────────────────────────────────────────────────────
require_once CF_AUTH_DIR . 'includes/class-cf-install.php';
require_once CF_AUTH_DIR . 'includes/class-cf-core.php';
require_once CF_AUTH_DIR . 'includes/class-cf-email.php';
require_once CF_AUTH_DIR . 'includes/class-cf-registration.php';
require_once CF_AUTH_DIR . 'includes/class-cf-login.php';
require_once CF_AUTH_DIR . 'includes/class-cf-password.php';
require_once CF_AUTH_DIR . 'includes/class-cf-profile.php';
require_once CF_AUTH_DIR . 'includes/class-cf-social-auth.php';
require_once CF_AUTH_DIR . 'includes/class-cf-shortcodes.php';
require_once CF_AUTH_DIR . 'includes/class-cf-user-menu.php';
require_once CF_AUTH_DIR . 'includes/class-cf-admin.php';

// ── Hide Admin Bar for non-admins immediately ─────────────────────────────────
add_action( 'init', function() {
    if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
        add_filter( 'show_admin_bar', '__return_false' );
        remove_action( 'wp_head', '_admin_bar_bump_cb' );
    }
}, 1 );

// ── Block wp-admin for regular listeners ──────────────────────────────────────
add_action( 'admin_init', function() {
    if ( wp_doing_ajax() ) return;
    if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) ) {
        wp_safe_redirect( home_url( '/cf-profile' ) );
        exit;
    }
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook(   __FILE__, [ 'CF_Install', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'CF_Install', 'deactivate' ] );

// ── Boot ──────────────────────────────────────────────────────────────────────
function cf_auth_init() {
    CF_Core::get_instance();
    CF_User_Menu::get_instance();
}
add_action( 'plugins_loaded', 'cf_auth_init' );
