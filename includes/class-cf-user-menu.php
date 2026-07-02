<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_User_Menu {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Shortcode: place [cf_user_menu] anywhere, or use the nav hook
        add_shortcode( 'cf_user_menu', [ $this, 'render_menu' ] );

        // Auto-inject into WordPress nav menus via a custom menu item
        add_filter( 'wp_nav_menu_items', [ $this, 'inject_into_nav' ], 99, 2 );

        // Block WordPress admin bar & dashboard access for cf_listeners
        add_action( 'after_setup_theme',  [ $this, 'hide_admin_bar' ] );
        add_action( 'template_redirect',  [ $this, 'block_admin_access' ] );
        add_filter( 'show_admin_bar',     [ $this, 'filter_admin_bar' ] );

        // AJAX logout (already in login class, but also handle here for the menu)
        add_action( 'wp_ajax_cf_logout',        [ $this, 'handle_logout' ] );
        add_action( 'wp_ajax_nopriv_cf_logout', [ $this, 'handle_logout' ] );
    }

    // ── Render the User Menu Button + Dropdown ────────────────────────────────
    public function render_menu( $atts = [] ) {
        ob_start();

        if ( is_user_logged_in() ) {
            $this->render_logged_in();
        } else {
            $this->render_logged_out();
        }

        return ob_get_clean();
    }

    // ── Logged IN state ───────────────────────────────────────────────────────
    private function render_logged_in() {
        $user_id      = get_current_user_id();
        $user         = get_userdata( $user_id );
        $avatar_url   = CF_Profile::get_avatar_url( $user_id );
        $display_name = $user->display_name;
        $profile_url  = home_url( '/cf-profile' );
        ?>
        <div class="cf-user-menu" id="cf-user-menu">

            <!-- Trigger Button -->
            <button class="cf-user-btn" id="cf-user-btn" aria-expanded="false" aria-label="User menu">
                <img
                    src="<?php echo esc_url( $avatar_url ); ?>"
                    alt="<?php echo esc_attr( $display_name ); ?>"
                    class="cf-user-avatar"
                    onerror="this.src='<?php echo esc_url( CF_AUTH_URL . 'assets/img/default-avatar.svg' ); ?>'"
                >
                <span class="cf-user-chevron">▾</span>
            </button>

            <!-- Dropdown -->
            <div class="cf-user-dropdown" id="cf-user-dropdown" role="menu">

                <!-- User Info Header -->
                <div class="cf-dropdown-header">
                    <img src="<?php echo esc_url( $avatar_url ); ?>" alt="" class="cf-dropdown-avatar">
                    <div class="cf-dropdown-info">
                        <span class="cf-dropdown-name"><?php echo esc_html( $display_name ); ?></span>
                        <span class="cf-dropdown-role">🎵 Listener</span>
                    </div>
                </div>

                <div class="cf-dropdown-divider"></div>

                <!-- Menu Items -->
                <nav class="cf-dropdown-nav" role="navigation">
                    <a href="<?php echo esc_url( $profile_url . '#overview' ); ?>" class="cf-dropdown-item" role="menuitem">
                        <span class="cf-dropdown-icon">👤</span>
                        <?php _e( 'My Account', 'cf-auth' ); ?>
                    </a>
                    <a href="<?php echo esc_url( $profile_url . '#favorites' ); ?>" class="cf-dropdown-item" role="menuitem">
                        <span class="cf-dropdown-icon">♥</span>
                        <?php _e( 'Favorites', 'cf-auth' ); ?>
                    </a>
                    <a href="<?php echo esc_url( $profile_url . '#history' ); ?>" class="cf-dropdown-item" role="menuitem">
                        <span class="cf-dropdown-icon">🕐</span>
                        <?php _e( 'Listening History', 'cf-auth' ); ?>
                    </a>
                    <a href="<?php echo esc_url( $profile_url . '#settings' ); ?>" class="cf-dropdown-item" role="menuitem">
                        <span class="cf-dropdown-icon">⚙</span>
                        <?php _e( 'Settings', 'cf-auth' ); ?>
                    </a>
                </nav>

                <div class="cf-dropdown-divider"></div>

                <!-- Logout -->
                <button class="cf-dropdown-item cf-dropdown-logout" id="cf-menu-logout" role="menuitem">
                    <span class="cf-dropdown-icon">↩</span>
                    <?php _e( 'Sign Out', 'cf-auth' ); ?>
                </button>

            </div>
        </div>
        <?php
    }

    // ── Logged OUT state ──────────────────────────────────────────────────────
    private function render_logged_out() {
        $login_url    = home_url( '/cf-login' );
        $register_url = home_url( '/cf-register' );
        ?>
        <div class="cf-user-menu cf-user-menu--guest" id="cf-user-menu">

            <!-- Trigger Button (guest icon) -->
            <button class="cf-user-btn cf-user-btn--guest" id="cf-user-btn" aria-expanded="false" aria-label="Sign in">
                <span class="cf-guest-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    </svg>
                </span>
            </button>

            <!-- Dropdown -->
            <div class="cf-user-dropdown" id="cf-user-dropdown" role="menu">

                <div class="cf-dropdown-header cf-dropdown-header--guest">
                    <p><?php _e( 'Join the Collective Finity universe', 'cf-auth' ); ?></p>
                </div>

                <div class="cf-dropdown-divider"></div>

                <nav class="cf-dropdown-nav" role="navigation">
                    <a href="<?php echo esc_url( $login_url ); ?>" class="cf-dropdown-item cf-dropdown-login" role="menuitem">
                        <span class="cf-dropdown-icon">→</span>
                        <?php _e( 'Sign In', 'cf-auth' ); ?>
                    </a>
                    <a href="<?php echo esc_url( $register_url ); ?>" class="cf-dropdown-item cf-dropdown-register" role="menuitem">
                        <span class="cf-dropdown-icon">✦</span>
                        <?php _e( 'Join Community', 'cf-auth' ); ?>
                    </a>
                </nav>

            </div>
        </div>
        <?php
    }

    // ── Auto-inject into nav menu ─────────────────────────────────────────────
    // Adds the user menu button to the END of ANY WordPress nav menu automatically
    public function inject_into_nav( $items, $args ) {
        // You can restrict to specific menus by name if needed:
        // if ( $args->theme_location !== 'primary' ) return $items;

        $menu_html = $this->render_menu();
        $items    .= '<li class="menu-item cf-user-menu-item">' . $menu_html . '</li>';

        return $items;
    }

    // ── Block WordPress admin for regular listeners ────────────────────────────
    public function block_admin_access() {
        if ( ! is_user_logged_in() ) return;
        if ( ! current_user_can( 'manage_options' ) ) {
            // Block /wp-admin except AJAX
            if ( is_admin() && ! wp_doing_ajax() ) {
                wp_safe_redirect( home_url( '/cf-profile' ) );
                exit;
            }
        }
    }

    public function hide_admin_bar() {
        if ( ! current_user_can( 'manage_options' ) ) {
            add_filter( 'show_admin_bar', '__return_false' );
        }
    }

    public function filter_admin_bar( $show ) {
        if ( ! current_user_can( 'manage_options' ) ) return false;
        return $show;
    }

    // ── Logout handler ────────────────────────────────────────────────────────
    public function handle_logout() {
        // Only process once (may be registered in CF_Login too)
        if ( did_action( 'wp_ajax_cf_logout' ) > 1 ) return;
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        wp_logout();
        wp_send_json_success( [
            'redirect' => get_option( 'cf_auth_logout_redirect', home_url() ),
        ] );
    }
}
