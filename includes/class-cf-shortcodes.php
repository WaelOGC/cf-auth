<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Shortcodes {

    private static $instance = null;
    private static $paypal_sdk_loaded = false;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'cf_login_form',      [ $this, 'render_login'    ] );
        add_shortcode( 'cf_register_form',   [ $this, 'render_register' ] );
        add_shortcode( 'cf_forgot_password', [ $this, 'render_forgot'   ] );
        add_shortcode( 'cf_reset_password',  [ $this, 'render_reset'    ] );
        add_shortcode( 'cf_user_profile',    [ $this, 'render_profile'  ] );
        add_shortcode( 'cf_playlist_view',   [ $this, 'render_playlist_view' ] );
        add_shortcode( 'cf_verify_email',    [ $this, 'render_verify'   ] );
        add_shortcode( 'cf_donation_form',   [ $this, 'render_donation' ] );
        add_shortcode( 'cf_donor_wall',      [ $this, 'render_donor_wall' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHARED: Page wrapper
    // ─────────────────────────────────────────────────────────────────────────
    private function page_wrap( $content, $class = '' ) {
        return '<div class="cf-page-wrap ' . esc_attr($class) . '">' . $content . '</div>';
    }

    private function is_social_provider_enabled( $provider ) {
        $keys = [
            'google'   => 'cf_auth_google_enabled',
            'facebook' => 'cf_auth_facebook_enabled',
            'discord'  => 'cf_auth_discord_enabled',
            'twitter'  => 'cf_auth_twitter_enabled',
        ];

        if ( ! isset( $keys[ $provider ] ) ) {
            return false;
        }

        return get_option( $keys[ $provider ], '1' ) === '1';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGIN
    // ─────────────────────────────────────────────────────────────────────────
    public function render_login() {
        if ( is_user_logged_in() ) {
            return $this->page_wrap( '<div class="cf-card cf-text-center">
                <p class="cf-notice-text">✓ ' . __( 'You are already signed in.', 'cf-auth' ) . '</p>
                <a href="' . esc_url( home_url('/cf-profile') ) . '" class="cf-btn cf-btn-primary">' . __( 'Go to My Account', 'cf-auth' ) . '</a>
            </div>' );
        }

        $error = sanitize_text_field( $_GET['error'] ?? '' );
        $logo_url = plugin_dir_url( __FILE__ ) . '../assets/img/icon-192.png';
        $social_enabled_count = 0;
        foreach ( [ 'google', 'facebook', 'discord', 'twitter' ] as $provider ) {
            if ( $this->is_social_provider_enabled( $provider ) ) {
                $social_enabled_count++;
            }
        }
        ob_start(); ?>
        <div class="cf-page-wrap">
            <div class="cf-auth-grid">

                <!-- Left: Branding -->
                <div class="cf-auth-brand">
                    <div class="cf-brand-inner">
                        <div class="cf-brand-icon">
                            <img src="<?php echo esc_url( $logo_url ); ?>" alt="" width="64" height="64">
                        </div>
                        <h2><?php _e( 'Welcome Back', 'cf-auth' ); ?></h2>
                        <p><?php _e( 'We\'re glad to see you again. Sign in to pick up where you left off with your music, favorites, and listening history.', 'cf-auth' ); ?></p>
                        <div class="cf-brand-features">
                            <div class="cf-brand-feat"><span>🎵</span><?php _e( 'Unlimited streaming', 'cf-auth' ); ?></div>
                            <div class="cf-brand-feat"><span>♥</span><?php _e( 'Save your favorites', 'cf-auth' ); ?></div>
                            <div class="cf-brand-feat"><span>🕐</span><?php _e( 'Full listening history', 'cf-auth' ); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Right: Form -->
                <div class="cf-auth-form-side">
                    <div class="cf-card">
                        <div class="cf-card-header">
                            <h3><?php _e( 'Sign In', 'cf-auth' ); ?></h3>
                            <p><?php _e( 'New here?', 'cf-auth' ); ?> <a href="<?php echo esc_url(home_url('/cf-register')); ?>"><?php _e( 'Create an account', 'cf-auth' ); ?></a></p>
                        </div>

                        <?php if ( $error ) : ?>
                            <div class="cf-alert cf-alert-error"><?php echo esc_html( urldecode($error) ); ?></div>
                        <?php endif; ?>

                        <!-- Social Buttons -->
                        <div class="cf-social-grid cf-social-grid--<?php echo (int) $social_enabled_count; ?>">
                            <?php if ( $this->is_social_provider_enabled( 'google' ) ) : ?>
                            <button class="cf-social-btn" data-provider="google">
                                <svg viewBox="0 0 24 24" width="18" height="18"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                                Google
                            </button>
                            <?php endif; ?>
                            <?php if ( $this->is_social_provider_enabled( 'facebook' ) ) : ?>
                            <button class="cf-social-btn" data-provider="facebook">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                Facebook
                            </button>
                            <?php endif; ?>
                            <?php if ( $this->is_social_provider_enabled( 'discord' ) ) : ?>
                            <button class="cf-social-btn" data-provider="discord">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="#5865F2"><path d="M20.317 4.492c-1.53-.69-3.17-1.2-4.885-1.49a.075.075 0 0 0-.079.036c-.21.369-.444.85-.608 1.23a18.566 18.566 0 0 0-5.487 0 12.36 12.36 0 0 0-.617-1.23A.077.077 0 0 0 8.562 3c-1.714.29-3.354.8-4.885 1.491a.07.07 0 0 0-.032.027C.533 9.093-.32 13.555.099 17.961a.08.08 0 0 0 .031.055 20.03 20.03 0 0 0 5.993 2.98.078.078 0 0 0 .084-.026c.462-.63.874-1.295 1.226-1.963a.074.074 0 0 0-.041-.104 13.175 13.175 0 0 1-1.872-.878.075.075 0 0 1-.008-.125c.126-.093.252-.19.372-.287a.075.075 0 0 1 .078-.01c3.927 1.764 8.18 1.764 12.061 0a.075.075 0 0 1 .079.009c.12.098.245.195.372.288a.075.075 0 0 1-.006.125c-.598.344-1.22.635-1.873.877a.075.075 0 0 0-.041.105c.36.687.772 1.341 1.225 1.962a.077.077 0 0 0 .084.028 19.963 19.963 0 0 0 6.002-2.981.076.076 0 0 0 .032-.054c.5-5.094-.838-9.52-3.549-13.442a.06.06 0 0 0-.031-.028z"/></svg>
                                Discord
                            </button>
                            <?php endif; ?>
                            <?php if ( $this->is_social_provider_enabled( 'twitter' ) ) : ?>
                            <button class="cf-social-btn" data-provider="twitter">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                X
                            </button>
                            <?php endif; ?>
                        </div>

                        <div class="cf-divider"><span><?php _e( 'or with email', 'cf-auth' ); ?></span></div>

                        <form id="cf-login-form" class="cf-form" method="post" novalidate>
                            <div id="cf-login-message" class="cf-message" style="display:none"></div>

                            <div class="cf-field">
                                <label for="cf-login-email"><?php _e( 'Email Address', 'cf-auth' ); ?></label>
                                <input type="email" id="cf-login-email" name="email" placeholder="your@email.com" required autocomplete="email">
                            </div>

                            <div class="cf-field">
                                <label for="cf-login-password">
                                    <?php _e( 'Password', 'cf-auth' ); ?>
                                    <a href="<?php echo esc_url(home_url('/cf-forgot-password')); ?>" class="cf-label-link"><?php _e( 'Forgot password?', 'cf-auth' ); ?></a>
                                </label>
                                <div class="cf-input-wrap">
                                    <input type="password" id="cf-login-password" name="password" placeholder="••••••••" required autocomplete="current-password">
                                    <button type="button" class="cf-eye-btn cf-toggle-password" aria-label="Show password">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </div>
                            </div>

                            <div class="cf-field-row">
                                <label class="cf-checkbox-label">
                                    <input type="checkbox" name="remember"> <?php _e( 'Keep me signed in', 'cf-auth' ); ?>
                                </label>
                            </div>

                            <button type="submit" class="cf-btn cf-btn-primary cf-btn-full">
                                <span class="cf-btn-text"><?php _e( 'Sign In', 'cf-auth' ); ?></span>
                                <span class="cf-btn-loader" style="display:none">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="cf-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
                                </span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REGISTER
    // ─────────────────────────────────────────────────────────────────────────
    public function render_register() {
        if ( is_user_logged_in() ) {
            return $this->page_wrap('<div class="cf-card cf-text-center">
                <p class="cf-notice-text">✓ ' . __('You already have an account.','cf-auth') . '</p>
                <a href="' . esc_url(home_url('/cf-profile')) . '" class="cf-btn cf-btn-primary">' . __('My Account','cf-auth') . '</a>
            </div>');
        }
        $logo_url = plugin_dir_url( __FILE__ ) . '../assets/img/icon-192.png';
        $social_enabled_count = 0;
        foreach ( [ 'google', 'facebook', 'discord', 'twitter' ] as $provider ) {
            if ( $this->is_social_provider_enabled( $provider ) ) {
                $social_enabled_count++;
            }
        }
        ob_start(); ?>
        <div class="cf-page-wrap">
            <div class="cf-auth-grid">
                <div class="cf-auth-brand">
                    <div class="cf-brand-inner">
                        <div class="cf-brand-icon">
                            <img src="<?php echo esc_url( $logo_url ); ?>" alt="" width="64" height="64">
                        </div>
                        <h2><?php _e( 'Join the Universe', 'cf-auth' ); ?></h2>
                        <p><?php _e( 'Become part of a growing community of listeners who love cinematic music. Create your free account in under a minute and start exploring together.', 'cf-auth' ); ?></p>
                        <div class="cf-brand-features">
                            <div class="cf-brand-feat"><span>🆓</span><?php _e( 'Always free', 'cf-auth' ); ?></div>
                            <div class="cf-brand-feat"><span>🎧</span><?php _e( 'Full music access', 'cf-auth' ); ?></div>
                            <div class="cf-brand-feat"><span>🌍</span><?php _e( 'Global community', 'cf-auth' ); ?></div>
                        </div>
                    </div>
                </div>

                <div class="cf-auth-form-side">
                    <div class="cf-card">
                        <div class="cf-card-header">
                            <h3><?php _e( 'Create Account', 'cf-auth' ); ?></h3>
                            <p><?php _e( 'Already a member?', 'cf-auth' ); ?> <a href="<?php echo esc_url(home_url('/cf-login')); ?>"><?php _e( 'Sign in', 'cf-auth' ); ?></a></p>
                        </div>

                        <div class="cf-social-grid cf-social-grid--<?php echo (int) $social_enabled_count; ?>">
                            <?php if ( $this->is_social_provider_enabled( 'google' ) ) : ?>
                            <button class="cf-social-btn" data-provider="google">
                                <svg viewBox="0 0 24 24" width="18" height="18"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                                Google
                            </button>
                            <?php endif; ?>
                            <?php if ( $this->is_social_provider_enabled( 'facebook' ) ) : ?>
                            <button class="cf-social-btn" data-provider="facebook">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                Facebook
                            </button>
                            <?php endif; ?>
                            <?php if ( $this->is_social_provider_enabled( 'discord' ) ) : ?>
                            <button class="cf-social-btn" data-provider="discord">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="#5865F2"><path d="M20.317 4.492c-1.53-.69-3.17-1.2-4.885-1.49a.075.075 0 0 0-.079.036c-.21.369-.444.85-.608 1.23a18.566 18.566 0 0 0-5.487 0 12.36 12.36 0 0 0-.617-1.23A.077.077 0 0 0 8.562 3c-1.714.29-3.354.8-4.885 1.491a.07.07 0 0 0-.032.027C.533 9.093-.32 13.555.099 17.961a.08.08 0 0 0 .031.055 20.03 20.03 0 0 0 5.993 2.98.078.078 0 0 0 .084-.026c.462-.63.874-1.295 1.226-1.963.074.074 0 0 0-.041-.104a13.175 13.175 0 0 1-1.872-.878.075.075 0 0 1-.008-.125c.126-.093.252-.19.372-.287a.075.075 0 0 1 .078-.01c3.927 1.764 8.18 1.764 12.061 0a.075.075 0 0 1 .079.009c.12.098.245.195.372.288a.075.075 0 0 1-.006.125c-.598.344-1.22.635-1.873.877a.075.075 0 0 0-.041.105c.36.687.772 1.341 1.225 1.962a.077.077 0 0 0 .084.028 19.963 19.963 0 0 0 6.002-2.981.076.076 0 0 0 .032-.054c.5-5.094-.838-9.52-3.549-13.442a.06.06 0 0 0-.031-.028z"/></svg>
                                Discord
                            </button>
                            <?php endif; ?>
                            <?php if ( $this->is_social_provider_enabled( 'twitter' ) ) : ?>
                            <button class="cf-social-btn" data-provider="twitter">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                X
                            </button>
                            <?php endif; ?>
                        </div>

                        <div class="cf-divider"><span><?php _e( 'or with email', 'cf-auth' ); ?></span></div>

                        <form id="cf-register-form" class="cf-form" method="post" novalidate>
                            <div id="cf-register-message" class="cf-message" style="display:none"></div>

                            <div class="cf-field">
                                <label><?php _e( 'Display Name', 'cf-auth' ); ?></label>
                                <input type="text" name="display_name" placeholder="<?php _e('Your listener name','cf-auth'); ?>" required autocomplete="name">
                            </div>
                            <div class="cf-field">
                                <label><?php _e( 'Email Address', 'cf-auth' ); ?></label>
                                <input type="email" name="email" placeholder="your@email.com" required autocomplete="email">
                            </div>
                            <div class="cf-field">
                                <label><?php _e( 'Password', 'cf-auth' ); ?></label>
                                <div class="cf-input-wrap">
                                    <input type="password" id="cf-reg-password" name="password" placeholder="<?php _e('Min. 8 characters','cf-auth'); ?>" required autocomplete="new-password">
                                    <button type="button" class="cf-eye-btn cf-toggle-password">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                </div>
                                <div class="cf-strength-wrap"><div class="cf-strength-bar"></div><span class="cf-strength-label"></span></div>
                            </div>
                            <div class="cf-field">
                                <label><?php _e( 'Confirm Password', 'cf-auth' ); ?></label>
                                <input type="password" id="cf-reg-confirm" name="confirm_password" placeholder="••••••••" required autocomplete="new-password">
                            </div>
                            <div class="cf-field">
                                <label class="cf-checkbox-label">
                                    <input type="checkbox" name="terms" required>
                                    <?php printf( __('I agree to the <a href="%s" target="_blank">Terms</a> and <a href="%s" target="_blank">Privacy Policy</a>','cf-auth'), '#', '#' ); ?>
                                </label>
                            </div>

                            <button type="submit" class="cf-btn cf-btn-primary cf-btn-full">
                                <span class="cf-btn-text"><?php _e( 'Create My Account', 'cf-auth' ); ?></span>
                                <span class="cf-btn-loader" style="display:none"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="cf-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORGOT PASSWORD
    // ─────────────────────────────────────────────────────────────────────────
    public function render_forgot() {
        ob_start(); ?>
        <div class="cf-page-wrap cf-page-centered">
            <div class="cf-card cf-card-sm">
                <div class="cf-card-icon-header">
                    <div class="cf-icon-circle">🔑</div>
                    <h3><?php _e( 'Reset Password', 'cf-auth' ); ?></h3>
                    <p><?php _e( "Enter your email and we'll send you a reset link.", 'cf-auth' ); ?></p>
                </div>
                <form id="cf-forgot-form" class="cf-form" method="post" novalidate>
                    <div id="cf-forgot-message" class="cf-message" style="display:none"></div>
                    <div class="cf-field">
                        <label><?php _e( 'Email Address', 'cf-auth' ); ?></label>
                        <input type="email" name="email" placeholder="your@email.com" required>
                    </div>
                    <button type="submit" class="cf-btn cf-btn-primary cf-btn-full">
                        <span class="cf-btn-text"><?php _e( 'Send Reset Link', 'cf-auth' ); ?></span>
                        <span class="cf-btn-loader" style="display:none"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="cf-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></span>
                    </button>
                </form>
                <p class="cf-text-center cf-mt-16"><a href="<?php echo esc_url(home_url('/cf-login')); ?>" class="cf-back-link">← <?php _e( 'Back to Sign In', 'cf-auth' ); ?></a></p>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESET PASSWORD
    // ─────────────────────────────────────────────────────────────────────────
    public function render_reset() {
        global $wpdb;
        $token = sanitize_text_field( $_GET['token'] ?? '' );

        if ( empty( $token ) ) {
            return $this->page_wrap('<div class="cf-card cf-card-sm cf-text-center">
                <div class="cf-icon-circle cf-icon-error">⚠</div>
                <h3>' . __('Invalid Link','cf-auth') . '</h3>
                <p class="cf-muted">' . __('This reset link is missing or invalid.','cf-auth') . '</p>
                <a href="' . esc_url(home_url('/cf-forgot-password')) . '" class="cf-btn cf-btn-primary">' . __('Request New Link','cf-auth') . '</a>
            </div>', 'cf-page-centered');
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cf_reset_tokens WHERE token=%s AND expires_at>NOW() AND used=0", $token
        ) );

        if ( ! $row ) {
            return $this->page_wrap('<div class="cf-card cf-card-sm cf-text-center">
                <div class="cf-icon-circle cf-icon-error">⏱</div>
                <h3>' . __('Link Expired','cf-auth') . '</h3>
                <p class="cf-muted">' . __('This reset link has expired or been used.','cf-auth') . '</p>
                <a href="' . esc_url(home_url('/cf-forgot-password')) . '" class="cf-btn cf-btn-primary">' . __('Request New Link','cf-auth') . '</a>
            </div>', 'cf-page-centered');
        }

        ob_start(); ?>
        <div class="cf-page-wrap cf-page-centered">
            <div class="cf-card cf-card-sm">
                <div class="cf-card-icon-header">
                    <div class="cf-icon-circle">🔒</div>
                    <h3><?php _e( 'New Password', 'cf-auth' ); ?></h3>
                    <p><?php _e( 'Choose a strong password for your account.', 'cf-auth' ); ?></p>
                </div>
                <form id="cf-reset-form" class="cf-form" method="post" novalidate>
                    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                    <div id="cf-reset-message" class="cf-message" style="display:none"></div>
                    <div class="cf-field">
                        <label><?php _e( 'New Password', 'cf-auth' ); ?></label>
                        <div class="cf-input-wrap">
                            <input type="password" name="password" placeholder="<?php _e('Min. 8 characters','cf-auth'); ?>" required>
                            <button type="button" class="cf-eye-btn cf-toggle-password"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                        </div>
                    </div>
                    <div class="cf-field">
                        <label><?php _e( 'Confirm Password', 'cf-auth' ); ?></label>
                        <input type="password" name="confirm_password" placeholder="••••••••" required>
                    </div>
                    <button type="submit" class="cf-btn cf-btn-primary cf-btn-full">
                        <span class="cf-btn-text"><?php _e( 'Update Password', 'cf-auth' ); ?></span>
                        <span class="cf-btn-loader" style="display:none"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" class="cf-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></span>
                    </button>
                </form>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERIFY EMAIL
    // ─────────────────────────────────────────────────────────────────────────
    public function render_verify() {
        ob_start(); ?>
        <div class="cf-page-wrap cf-page-centered">
            <div class="cf-card cf-card-sm cf-text-center">
                <div class="cf-icon-circle cf-icon-email">📧</div>
                <h3><?php _e( 'Check Your Inbox', 'cf-auth' ); ?></h3>
                <p><?php _e( "We've sent a verification link to your email. Click it to activate your account.", 'cf-auth' ); ?></p>
                <p class="cf-muted"><?php _e( "Didn't receive it?", 'cf-auth' ); ?> <a href="#" id="cf-resend-verify"><?php _e( 'Resend email', 'cf-auth' ); ?></a></p>
                <div id="cf-resend-message" class="cf-message" style="display:none"></div>
                <a href="<?php echo esc_url(home_url('/cf-login')); ?>" class="cf-back-link">← <?php _e( 'Back to Sign In', 'cf-auth' ); ?></a>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DONATION FORM
    // ─────────────────────────────────────────────────────────────────────────
    public function render_donation() {
        $currency  = get_option( 'cf_auth_donation_currency', 'EUR' );
        $client_id = CF_Donations::get_public_client_id();

        ob_start();

        if ( ! self::$paypal_sdk_loaded && $client_id ) {
            self::$paypal_sdk_loaded = true;
            $sdk_url = 'https://www.paypal.com/sdk/js?client-id=' . rawurlencode( $client_id )
                     . '&currency=' . rawurlencode( $currency )
                     . '&intent=capture';
            echo '<script src="' . esc_url( $sdk_url ) . '"></script>';
        }
        ?>
        <div class="cf-page-wrap cf-donation-page">
            <div class="cf-card">
                <div class="cf-card-header">
                    <h3><?php _e( 'Support Us', 'cf-auth' ); ?></h3>
                    <p><?php _e( 'Your donation helps keep the music flowing.', 'cf-auth' ); ?></p>
                </div>

                <div id="cf-donation-container">
                    <form id="cf-donation-form" class="cf-form">
                        <div id="cf-donation-message" class="cf-message" style="display:none"></div>

                        <div class="cf-field">
                            <label for="cf-donation-amount-select"><?php _e( 'Amount', 'cf-auth' ); ?></label>
                            <select id="cf-donation-amount-select" class="cf-donation-amount-select">
                                <option value="5">5 <?php echo esc_html( $currency ); ?></option>
                                <option value="10" selected>10 <?php echo esc_html( $currency ); ?></option>
                                <option value="15">15 <?php echo esc_html( $currency ); ?></option>
                                <option value="other"><?php _e( 'Other amount…', 'cf-auth' ); ?></option>
                            </select>
                            <input type="number" id="cf-donation-amount" name="amount" class="cf-donation-amount cf-donation-amount--custom"
                                   min="1" step="0.01" required value="10"
                                   placeholder="<?php echo esc_attr( $currency ); ?>"
                                   style="display:none;">
                        </div>

                        <div class="cf-field">
                            <label for="cf-donation-name"><?php _e( 'Your Name', 'cf-auth' ); ?> <span class="cf-muted"><?php _e( '(optional)', 'cf-auth' ); ?></span></label>
                            <input type="text" id="cf-donation-name" name="donor_name" class="cf-donation-name"
                                   placeholder="<?php esc_attr_e( 'Display on the donor wall', 'cf-auth' ); ?>">
                        </div>

                        <div class="cf-field">
                            <label for="cf-donation-message-field"><?php _e( 'Message', 'cf-auth' ); ?> <span class="cf-muted"><?php _e( '(optional)', 'cf-auth' ); ?></span></label>
                            <textarea id="cf-donation-message-field" name="message" class="cf-donation-message-field" rows="3"
                                      placeholder="<?php esc_attr_e( 'Leave a note with your donation', 'cf-auth' ); ?>"></textarea>
                        </div>

                        <div class="cf-field">
                            <label class="cf-checkbox-label">
                                <input type="checkbox" id="cf-donation-anonymous" name="is_anonymous" class="cf-donation-anonymous">
                                <?php _e( 'Donate anonymously', 'cf-auth' ); ?>
                            </label>
                        </div>

                        <div id="cf-paypal-buttons" class="cf-paypal-buttons"></div>
                    </form>

                    <div id="cf-donation-thankyou" class="cf-donation-thankyou" style="display:none">
                        <p class="cf-notice-text">✓ <?php _e( 'Thank you for your donation!', 'cf-auth' ); ?></p>
                    </div>

                    <div id="cf-donation-processing" class="cf-donation-processing" style="display:none">
                        <p class="cf-notice-text"><?php _e( 'Your payment is being processed. We will confirm once it completes.', 'cf-auth' ); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function () {
            'use strict';

            function getCF() {
                return window.CF_AUTH || {};
            }

            function showMessage(text, type) {
                var el = document.getElementById('cf-donation-message');
                if (!el) return;
                el.textContent = text;
                el.className = 'cf-message' + (type === 'error' ? ' is-error' : ' is-success');
                el.style.display = '';
            }

            function hideMessage() {
                var el = document.getElementById('cf-donation-message');
                if (el) el.style.display = 'none';
            }

            function showThankYou() {
                var form = document.getElementById('cf-donation-form');
                var thankyou = document.getElementById('cf-donation-thankyou');
                var processing = document.getElementById('cf-donation-processing');
                if (form) form.style.display = 'none';
                if (processing) processing.style.display = 'none';
                if (thankyou) thankyou.style.display = '';
            }

            function showProcessing() {
                var form = document.getElementById('cf-donation-form');
                var thankyou = document.getElementById('cf-donation-thankyou');
                var processing = document.getElementById('cf-donation-processing');
                if (form) form.style.display = 'none';
                if (thankyou) thankyou.style.display = 'none';
                if (processing) processing.style.display = '';
            }

            function postAction(action, data) {
                var cf = getCF();
                var body = new FormData();
                body.append('action', action);
                body.append('nonce', cf.nonce || '');
                Object.keys(data).forEach(function (key) {
                    body.append(key, data[key]);
                });
                return fetch(cf.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); });
            }

            function initPayPalButtons() {
                if (typeof paypal === 'undefined') {
                    setTimeout(initPayPalButtons, 100);
                    return;
                }

                var container = document.getElementById('cf-paypal-buttons');
                if (!container || container.dataset.initialized === '1') return;
                container.dataset.initialized = '1';

                paypal.Buttons({
                    createOrder: function () {
                        var cf = getCF();
                        if (!cf.ajax_url) {
                            return Promise.reject(new Error('CF Auth not loaded'));
                        }

                        var amountEl = document.getElementById('cf-donation-amount');
                        var amount = parseFloat(amountEl ? amountEl.value : '');
                        if (isNaN(amount) || amount <= 0) {
                            showMessage('<?php echo esc_js( __( 'Please enter a valid donation amount.', 'cf-auth' ) ); ?>', 'error');
                            return Promise.reject(new Error('invalid_amount'));
                        }

                        hideMessage();

                        var nameEl = document.getElementById('cf-donation-name');
                        var msgEl = document.getElementById('cf-donation-message-field');
                        var anonEl = document.getElementById('cf-donation-anonymous');

                        return postAction('cf_paypal_create_order', {
                            amount: amount,
                            donor_name: nameEl ? nameEl.value : '',
                            message: msgEl ? msgEl.value : '',
                            is_anonymous: anonEl && anonEl.checked ? '1' : '0'
                        }).then(function (res) {
                            if (!res.success || !res.data || !res.data.order_id) {
                                var msg = (res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Unable to process donation. Please try again.', 'cf-auth' ) ); ?>';
                                showMessage(msg, 'error');
                                return Promise.reject(new Error('create_order_failed'));
                            }
                            return res.data.order_id;
                        });
                    },
                    onApprove: function (data) {
                        return postAction('cf_paypal_capture_order', {
                            paypal_order_id: data.orderID
                        }).then(function (res) {
                            if (!res.success) {
                                var msg = (res.data && res.data.message) ? res.data.message : '<?php echo esc_js( __( 'Unable to process donation. Please try again.', 'cf-auth' ) ); ?>';
                                showMessage(msg, 'error');
                                return;
                            }
                            if (res.data && res.data.status === 'completed') {
                                showThankYou();
                            } else {
                                showProcessing();
                            }
                        });
                    },
                    onError: function () {
                        showMessage('<?php echo esc_js( __( 'Something went wrong with PayPal. Please try again.', 'cf-auth' ) ); ?>', 'error');
                    }
                }).render('#cf-paypal-buttons');
            }

            var amountSelect = document.getElementById('cf-donation-amount-select');
            var amountInput = document.getElementById('cf-donation-amount');
            if (amountSelect && amountInput) {
                amountSelect.addEventListener('change', function () {
                    var val = amountSelect.value;
                    if (val === 'other') {
                        amountInput.value = '';
                        amountInput.style.display = '';
                        amountInput.classList.add('is-visible');
                        amountInput.focus();
                    } else {
                        amountInput.value = val;
                        amountInput.style.display = 'none';
                        amountInput.classList.remove('is-visible');
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initPayPalButtons);
            } else {
                initPayPalButtons();
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DONOR WALL
    // ─────────────────────────────────────────────────────────────────────────
    public function render_donor_wall() {
        global $wpdb;

        $table = $wpdb->prefix . 'cf_donations';
        $rows  = $wpdb->get_results(
            "SELECT donor_name, message, created_at
             FROM {$table}
             WHERE status = 'completed' AND show_on_wall = 1
             ORDER BY created_at DESC
             LIMIT 60"
        );

        if ( ! is_array( $rows ) ) {
            $rows = [];
        }

        $is_empty = empty( $rows );

        ob_start();
        ?>
        <div class="cf-donor-wall<?php echo $is_empty ? ' cf-donor-wall--empty' : ''; ?>">
            <div class="cf-donor-wall__track">
                <?php $this->render_donor_wall_cards( $rows ); ?>
                <?php if ( ! $is_empty ) : ?>
                    <?php $this->render_donor_wall_cards( $rows ); ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Echo one pass of donor-wall cards (duplicated by the caller for seamless marquee).
     *
     * @param array $rows Donation rows (or empty for the placeholder state).
     */
    private function render_donor_wall_cards( array $rows ): void {
        if ( empty( $rows ) ) {
            ?>
            <div class="cf-donor-wall__card">
                <span class="cf-donor-wall__name"><?php esc_html_e( 'Be the first to support Collective Finity.', 'cf-auth' ); ?></span>
            </div>
            <?php
            return;
        }

        $now = current_time( 'timestamp' );

        foreach ( $rows as $row ) {
            $name = isset( $row->donor_name ) ? trim( (string) $row->donor_name ) : '';
            if ( $name === '' ) {
                $name = __( 'A generous supporter', 'cf-auth' );
            }

            $message = isset( $row->message ) ? trim( (string) $row->message ) : '';
            $created = isset( $row->created_at ) ? strtotime( $row->created_at ) : false;
            $date    = $created
                ? sprintf(
                    /* translators: %s: human-readable time difference, e.g. "3 days" */
                    __( '%s ago', 'cf-auth' ),
                    human_time_diff( $created, $now )
                )
                : '';
            ?>
            <div class="cf-donor-wall__card">
                <span class="cf-donor-wall__name"><?php echo esc_html( $name ); ?></span>
                <?php if ( $message !== '' ) : ?>
                    <p class="cf-donor-wall__message">"<?php echo esc_html( $message ); ?>"</p>
                <?php endif; ?>
                <?php if ( $date !== '' ) : ?>
                    <span class="cf-donor-wall__date"><?php echo esc_html( $date ); ?></span>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Favorites helpers (match collective-finity theme data schema)
    // ─────────────────────────────────────────────────────────────────────────
    private function get_published_favorites( array $ids, string $post_type ): array {
        $posts = [];
        foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
            if ( ! $id ) {
                continue;
            }
            $post = get_post( $id );
            if ( $post && $post->post_type === $post_type && $post->post_status === 'publish' ) {
                $posts[] = $post;
            }
        }
        return $posts;
    }

    private function get_favorite_cover_url( WP_Post $post, string $type ): string {
        return CF_Profile::get_release_cover_url( $post, $type );
    }

    private function get_favorite_artist_name( WP_Post $post, string $type ): string {
        if ( $type === 'track' ) {
            $artists = wp_get_post_terms( $post->ID, 'track_artist' );
            if ( ! empty( $artists ) && ! is_wp_error( $artists ) ) {
                return $artists[0]->name;
            }
            return 'Collective Finity';
        }

        if ( $type === 'post' ) {
            return get_the_author_meta( 'display_name', $post->post_author );
        }

        if ( function_exists( 'collective_finity_brand_name' ) ) {
            return collective_finity_brand_name();
        }

        return 'Collective Finity';
    }

    private function render_favorite_card( WP_Post $post, string $type ): void {
        $cover  = $this->get_favorite_cover_url( $post, $type );
        $artist = $this->get_favorite_artist_name( $post, $type );
        ?>
        <div class="cf-row-item cf-fav-card" data-id="<?php echo esc_attr( $post->ID ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
            <a href="<?php echo esc_url( get_permalink( $post ) ); ?>" class="cf-row-item-main">
                <div class="cf-row-thumb<?php echo $cover ? '' : ' cf-row-thumb--empty'; ?>">
                    <?php if ( $cover ) : ?>
                        <img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( $post->post_title ); ?>" loading="lazy">
                    <?php else : ?>
                        <span aria-hidden="true">♪</span>
                    <?php endif; ?>
                </div>
                <div class="cf-row-info">
                    <span class="cf-row-title"><?php echo esc_html( $post->post_title ); ?></span>
                    <span class="cf-row-subtitle"><?php echo esc_html( $artist ); ?></span>
                </div>
            </a>
            <div class="cf-row-trailing">
                <button type="button" class="cf-fav-remove" data-id="<?php echo esc_attr( $post->ID ); ?>" data-type="<?php echo esc_attr( $type ); ?>" title="<?php esc_attr_e( 'Remove from favorites', 'cf-auth' ); ?>" aria-label="<?php esc_attr_e( 'Remove from favorites', 'cf-auth' ); ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" stroke="none"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </button>
            </div>
        </div>
        <?php
    }

    private function render_playlist_item_row( array $item, int $index, bool $show_remove = false, int $playlist_id = 0 ): void {
        $cover    = $item['cover'] ?? '';
        $artist   = $item['artist'] ?? '';
        $file_url = ( $item['item_type'] ?? '' ) === 'track' ? ( $item['file_url'] ?? '' ) : '';
        ?>
        <div class="cf-tracklist-row cf-playlist-item-row cf-playlist-item-card" role="row"
             data-id="<?php echo esc_attr( $item['item_id'] ); ?>"
             data-type="<?php echo esc_attr( $item['item_type'] ); ?>"
             data-title="<?php echo esc_attr( $item['title'] ); ?>"
             data-artist="<?php echo esc_attr( $artist ); ?>"
             data-cover="<?php echo esc_url( $cover ); ?>"
             <?php if ( $file_url ) : ?>data-file-url="<?php echo esc_url( $file_url ); ?>"<?php endif; ?>>
            <div class="cf-col-index" role="cell"><span class="cf-track-num"><?php echo (int) $index; ?></span></div>
            <div class="cf-col-title" role="cell">
                <a href="<?php echo esc_url( $item['permalink'] ); ?>" class="cf-playlist-row-link">
                    <span class="cf-playlist-row-cover<?php echo $cover ? '' : ' cf-playlist-row-cover--empty'; ?>">
                        <?php if ( $cover ) : ?>
                            <img src="<?php echo esc_url( $cover ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>" loading="lazy">
                        <?php else : ?>
                            <span class="cf-playlist-row-cover-placeholder" aria-hidden="true">♪</span>
                        <?php endif; ?>
                    </span>
                    <span class="cf-playlist-row-info">
                        <span class="cf-track-name"><?php echo esc_html( $item['title'] ); ?></span>
                        <span class="cf-track-artist-name"><?php echo esc_html( $artist ); ?></span>
                    </span>
                </a>
            </div>
            <div class="cf-col-view" role="cell">
                <a href="<?php echo esc_url( $item['permalink'] ); ?>" class="cf-btn cf-btn-outline-sm"><?php esc_html_e( 'View', 'cf-auth' ); ?></a>
            </div>
            <?php if ( $show_remove && $playlist_id ) : ?>
            <div class="cf-col-remove" role="cell">
                <button type="button" class="cf-fav-remove cf-playlist-item-remove" data-playlist-id="<?php echo esc_attr( $playlist_id ); ?>" data-id="<?php echo esc_attr( $item['item_id'] ); ?>" data-type="<?php echo esc_attr( $item['item_type'] ); ?>" title="<?php esc_attr_e( 'Remove from playlist', 'cf-auth' ); ?>" aria-label="<?php esc_attr_e( 'Remove from playlist', 'cf-auth' ); ?>">×</button>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_playlist_profile_card( array $playlist ): void {
        $cover = $playlist['cover'] ?? '';
        $badge = (int) $playlist['is_public'] ? __( 'Public', 'cf-auth' ) : __( 'Private', 'cf-auth' );
        ?>
        <a href="<?php echo esc_url( $playlist['share_url'] ); ?>" class="cf-row-item" data-id="<?php echo esc_attr( $playlist['id'] ); ?>">
            <div class="cf-row-thumb<?php echo $cover ? '' : ' cf-row-thumb--empty'; ?>">
                <?php if ( $cover ) : ?>
                    <img src="<?php echo esc_url( $cover ); ?>" alt="" loading="lazy">
                <?php else : ?>
                    <span aria-hidden="true">🎵</span>
                <?php endif; ?>
            </div>
            <div class="cf-row-info">
                <span class="cf-row-title"><?php echo esc_html( $playlist['name'] ); ?></span>
                <span class="cf-row-subtitle">
                    <?php
                    printf(
                        /* translators: %d: number of items */
                        esc_html( _n( '%d item', '%d items', (int) $playlist['item_count'], 'cf-auth' ) ),
                        (int) $playlist['item_count']
                    );
                    ?>
                </span>
            </div>
            <div class="cf-row-trailing">
                <span class="cf-row-pill"><?php echo esc_html( $badge ); ?></span>
                <svg class="cf-row-chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
            </div>
        </a>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Profile tab pagination (Favorites / History / Playlists)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Read and clamp page / per-page query args for a profile tab.
     *
     * @param string $page_param
     * @param string $per_page_param
     * @return array{page: int, per_page: int, offset: int}
     */
    private function get_tab_pagination_args( $page_param, $per_page_param ) {
        $page = isset( $_GET[ $page_param ] ) ? max( 1, absint( $_GET[ $page_param ] ) ) : 1;
        $per_page = isset( $_GET[ $per_page_param ] ) ? absint( $_GET[ $per_page_param ] ) : 10;
        if ( ! in_array( $per_page, [ 10, 25, 50, 100 ], true ) ) {
            $per_page = 10;
        }

        return [
            'page'     => $page,
            'per_page' => $per_page,
            'offset'   => ( $page - 1 ) * $per_page,
        ];
    }

    /**
     * Combined published favorites (tracks → albums → posts) for the current user.
     *
     * @param int[] $track_ids
     * @param int[] $album_ids
     * @param int[] $post_ids
     * @return array<int, array{post: WP_Post, type: string}>
     */
    private function build_combined_favorites_list( array $track_ids, array $album_ids, array $post_ids ) {
        $combined = [];
        foreach ( $this->get_published_favorites( $track_ids, 'tracks' ) as $post ) {
            $combined[] = [ 'post' => $post, 'type' => 'track' ];
        }
        foreach ( $this->get_published_favorites( $album_ids, 'albums' ) as $post ) {
            $combined[] = [ 'post' => $post, 'type' => 'album' ];
        }
        foreach ( $this->get_published_favorites( $post_ids, 'post' ) as $post ) {
            $combined[] = [ 'post' => $post, 'type' => 'post' ];
        }
        return $combined;
    }

    /**
     * Paginated listening history (published tracks only).
     *
     * @param int $user_id
     * @param int $per_page
     * @param int $offset
     * @return array{items: array, total: int}
     */
    private function get_paginated_listening_history( $user_id, $per_page, $offset ) {
        global $wpdb;

        $user_id  = absint( $user_id );
        $per_page = max( 1, absint( $per_page ) );
        $offset   = max( 0, absint( $offset ) );

        $history_table = $wpdb->prefix . 'cf_listening_history';
        $posts_table   = $wpdb->posts;

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$history_table} h
             INNER JOIN {$posts_table} p ON p.ID = h.track_id
             WHERE h.user_id = %d
               AND p.post_type = 'tracks'
               AND p.post_status = 'publish'",
            $user_id
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT h.track_id, h.listened_at
             FROM {$history_table} h
             INNER JOIN {$posts_table} p ON p.ID = h.track_id
             WHERE h.user_id = %d
               AND p.post_type = 'tracks'
               AND p.post_status = 'publish'
             ORDER BY h.listened_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ) );

        $items = [];
        foreach ( (array) $rows as $row ) {
            $post = get_post( (int) $row->track_id );
            if ( ! $post ) {
                continue;
            }
            $items[] = [
                'track_id'    => (int) $row->track_id,
                'listened_at' => $row->listened_at,
                'title'       => $post->post_title,
                'url'         => get_permalink( $post ),
                'cover'       => CF_Profile::get_release_cover_url( $post, 'track' ),
            ];
        }

        return [ 'items' => $items, 'total' => $total ];
    }

    /**
     * Paginated playlists for the profile tab (LIMIT/OFFSET in shortcodes).
     *
     * @param int $user_id
     * @param int $per_page
     * @param int $offset
     * @return array{items: array, total: int}
     */
    private function get_paginated_user_playlists( $user_id, $per_page, $offset ) {
        global $wpdb;

        $user_id  = absint( $user_id );
        $per_page = max( 1, absint( $per_page ) );
        $offset   = max( 0, absint( $offset ) );

        $table       = $wpdb->prefix . 'cf_playlists';
        $items_table = $wpdb->prefix . 'cf_playlist_items';

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
            $user_id
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, COUNT(i.id) AS item_count
             FROM {$table} p
             LEFT JOIN {$items_table} i ON i.playlist_id = p.id
             WHERE p.user_id = %d
             GROUP BY p.id
             ORDER BY p.created_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $per_page,
            $offset
        ) );

        $items = [];
        foreach ( (array) $rows as $row ) {
            $items[] = [
                'id'         => (int) $row->id,
                'name'       => $row->name,
                'is_public'  => (int) $row->is_public,
                'item_count' => (int) $row->item_count,
                'share_url'  => CF_Playlists::get_share_url( (string) $row->share_token ),
                'cover'      => CF_Playlists::get_playlist_cover( (int) $row->id ),
            ];
        }

        return [ 'items' => $items, 'total' => $total ];
    }

    /**
     * Shared pagination controls for profile tabs (plain links + forms, works without JS).
     *
     * @param int    $current_page
     * @param int    $total_pages
     * @param int    $per_page
     * @param string $page_param
     * @param string $per_page_param
     * @param array  $per_page_options
     */
    private function render_pagination( $current_page, $total_pages, $per_page, $page_param, $per_page_param, $per_page_options = [ 10, 25, 50, 100 ] ) {
        $total_pages   = (int) $total_pages;
        $current_page  = max( 1, (int) $current_page );
        $per_page      = (int) $per_page;

        if ( $total_pages <= 1 ) {
            return;
        }

        $hash_map = [
            'cf_fav_page'  => 'favorites',
            'cf_hist_page' => 'history',
            'cf_pl_page'   => 'playlists',
        ];
        $tab_hash = $hash_map[ $page_param ] ?? '';

        $base_url = get_permalink();
        if ( ! $base_url ) {
            $base_url = home_url( '/cf-profile' );
        }

        $build_url = function ( $page, $size ) use ( $base_url, $page_param, $per_page_param, $tab_hash ) {
            $url = add_query_arg(
                [
                    $page_param     => max( 1, (int) $page ),
                    $per_page_param => (int) $size,
                ],
                $base_url
            );
            return $tab_hash ? ( $url . '#' . $tab_hash ) : $url;
        };

        // Truncated page list: first 8 … last, or window around current when deep in.
        $pages = [];
        if ( $total_pages <= 9 ) {
            for ( $i = 1; $i <= $total_pages; $i++ ) {
                $pages[] = $i;
            }
        } elseif ( $current_page <= 5 ) {
            for ( $i = 1; $i <= 8; $i++ ) {
                $pages[] = $i;
            }
            $pages[] = '…';
            $pages[] = $total_pages;
        } elseif ( $current_page >= $total_pages - 4 ) {
            $pages[] = 1;
            $pages[] = '…';
            for ( $i = $total_pages - 7; $i <= $total_pages; $i++ ) {
                $pages[] = $i;
            }
        } else {
            $pages[] = 1;
            $pages[] = '…';
            for ( $i = $current_page - 2; $i <= $current_page + 2; $i++ ) {
                $pages[] = $i;
            }
            $pages[] = '…';
            $pages[] = $total_pages;
        }
        ?>
        <div class="cf-pagination-wrap">
            <nav class="cf-pagination" aria-label="<?php esc_attr_e( 'Pagination', 'cf-auth' ); ?>">
                <div class="cf-pagination-size">
                    <label>
                        <?php esc_html_e( 'Show:', 'cf-auth' ); ?>
                        <select class="cf-pagination-per-page" onchange="if (this.value) { window.location.href = this.value; }">
                            <?php foreach ( $per_page_options as $opt ) : ?>
                                <option value="<?php echo esc_url( $build_url( 1, $opt ) ); ?>" <?php selected( $per_page, $opt ); ?>>
                                    <?php echo (int) $opt; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="cf-pagination-pages">
                    <?php foreach ( $pages as $p ) : ?>
                        <?php if ( $p === '…' ) : ?>
                            <span class="cf-pagination-ellipsis" aria-hidden="true">…</span>
                        <?php elseif ( (int) $p === $current_page ) : ?>
                            <span class="cf-pagination-page is-active" aria-current="page"><?php echo (int) $p; ?></span>
                        <?php else : ?>
                            <a class="cf-pagination-page" href="<?php echo esc_url( $build_url( $p, $per_page ) ); ?>"><?php echo (int) $p; ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ( $current_page < $total_pages ) : ?>
                        <a class="cf-pagination-page cf-pagination-next" href="<?php echo esc_url( $build_url( $current_page + 1, $per_page ) ); ?>">
                            <?php esc_html_e( 'Next', 'cf-auth' ); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <form class="cf-pagination-goto" method="get" action="<?php echo esc_url( $base_url ); ?>" data-cf-tab="<?php echo esc_attr( $tab_hash ); ?>">
                    <input type="hidden" name="<?php echo esc_attr( $per_page_param ); ?>" value="<?php echo esc_attr( $per_page ); ?>">
                    <label>
                        <?php esc_html_e( 'Go to page:', 'cf-auth' ); ?>
                        <input type="number" class="cf-pagination-goto-input" name="<?php echo esc_attr( $page_param ); ?>" min="1" max="<?php echo esc_attr( $total_pages ); ?>" value="<?php echo esc_attr( $current_page ); ?>">
                    </label>
                    <button type="submit" class="cf-btn cf-btn-outline-sm"><?php esc_html_e( 'Go', 'cf-auth' ); ?></button>
                </form>
            </nav>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PLAYLIST VIEW — public share page
    // ─────────────────────────────────────────────────────────────────────────
    public function render_playlist_view() {
        $share_token = sanitize_text_field( $_GET['share'] ?? '' );

        if ( ! $share_token ) {
            return $this->page_wrap(
                '<div class="cf-section-card cf-empty-state"><p>' . esc_html__( 'This playlist is private or doesn\'t exist.', 'cf-auth' ) . '</p></div>',
                'cf-playlist-page'
            );
        }

        $playlist = CF_Playlists::get_playlist_by_token( $share_token );

        if ( ! $playlist || ! CF_Playlists::is_playlist_visible( $playlist ) ) {
            return $this->page_wrap(
                '<div class="cf-section-card cf-empty-state"><p>' . esc_html__( 'This playlist is private or doesn\'t exist.', 'cf-auth' ) . '</p></div>',
                'cf-playlist-page'
            );
        }

        $is_owner   = is_user_logged_in() && (int) get_current_user_id() === (int) $playlist->user_id;
        $owner      = get_userdata( (int) $playlist->user_id );
        $items      = CF_Playlists::resolve_playlist_items( (int) $playlist->id );
        $share_url  = CF_Playlists::get_share_url( $playlist->share_token );

        $play_queue = [];
        foreach ( $items as $item ) {
            if ( ( $item['item_type'] ?? '' ) !== 'track' ) {
                continue;
            }
            $file_url = $item['file_url'] ?? '';
            if ( ! $file_url ) {
                continue;
            }
            $play_queue[] = [
                'id'      => (int) $item['item_id'],
                'title'   => $item['title'],
                'artist'  => $item['artist'],
                'art'     => $item['cover'],
                'url'     => $file_url,
                'fileUrl' => $file_url,
            ];
        }

        ob_start(); ?>
        <div class="cf-page-wrap cf-playlist-page" id="cf-playlist-view"
             data-playlist-id="<?php echo esc_attr( $playlist->id ); ?>"
             data-is-owner="<?php echo $is_owner ? '1' : '0'; ?>"
             data-is-public="<?php echo (int) $playlist->is_public; ?>"
             data-share-url="<?php echo esc_url( $share_url ); ?>">
            <div class="cf-playlist-header">
                <section class="cf-playlist-hero">
                    <div class="cf-playlist-hero__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M9 18V5l12-2v13"/>
                            <circle cx="6" cy="18" r="3"/>
                            <circle cx="18" cy="16" r="3"/>
                        </svg>
                    </div>
                    <div class="cf-playlist-hero__content">
                        <div class="cf-playlist-hero__title-row">
                            <h1 class="cf-playlist-title" id="cf-playlist-title"><?php echo esc_html( $playlist->name ); ?></h1>
                            <button type="button" class="cf-btn cf-btn-primary cf-playlist-play-all"
                                    data-playlist-id="<?php echo esc_attr( $playlist->id ); ?>"
                                    <?php echo empty( $play_queue ) ? 'disabled' : ''; ?>>
                                <?php esc_html_e( 'Play All', 'cf-auth' ); ?>
                            </button>
                        </div>
                        <p class="cf-playlist-owner">
                            <?php
                            printf(
                                /* translators: %s: owner display name */
                                esc_html__( 'by %s', 'cf-auth' ),
                                esc_html( $owner ? $owner->display_name : '' )
                            );
                            ?>
                        </p>
                        <div class="cf-playlist-hero__actions">
                            <a href="<?php echo esc_url( home_url( '/cf-profile#playlists' ) ); ?>"
                               class="cf-btn cf-btn-outline-sm cf-playlist-back-btn">
                                <?php esc_html_e( '← Back to Profile', 'cf-auth' ); ?>
                            </a>
                        </div>
                    </div>
                </section>
                <script type="application/json" id="cf-playlist-queue"><?php echo wp_json_encode( $play_queue ); ?></script>
                <?php if ( $is_owner ) : ?>
                <div class="cf-playlist-manage" id="cf-playlist-manage">
                    <div class="cf-playlist-manage-row">
                        <input type="text" id="cf-playlist-rename-input" class="cf-playlist-rename-input" value="<?php echo esc_attr( $playlist->name ); ?>" maxlength="190">
                        <button type="button" id="cf-playlist-rename-btn" class="cf-btn cf-btn-outline-sm"><?php esc_html_e( 'Rename', 'cf-auth' ); ?></button>
                    </div>
                    <div class="cf-playlist-manage-row">
                        <label class="cf-playlist-visibility-toggle">
                            <input type="checkbox" id="cf-playlist-public-toggle" <?php checked( (int) $playlist->is_public, 1 ); ?>>
                            <span><?php esc_html_e( 'Public playlist', 'cf-auth' ); ?></span>
                        </label>
                        <button type="button" id="cf-playlist-copy-link" class="cf-btn cf-btn-outline-sm" <?php echo (int) $playlist->is_public ? '' : 'disabled'; ?>><?php esc_html_e( 'Copy share link', 'cf-auth' ); ?></button>
                        <button type="button" id="cf-playlist-delete-btn" class="cf-btn cf-btn-danger-outline"><?php esc_html_e( 'Delete playlist', 'cf-auth' ); ?></button>
                    </div>
                    <div id="cf-playlist-manage-msg" class="cf-message" style="display:none"></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ( empty( $items ) ) : ?>
            <div class="cf-section-card cf-empty-state" id="cf-playlist-items-empty">
                <span class="cf-empty-icon">🎵</span>
                <p><?php esc_html_e( 'This playlist is empty.', 'cf-auth' ); ?></p>
            </div>
            <?php else : ?>
            <div class="cf-section-card">
                <div class="cf-tracklist-scroll">
                    <div class="cf-playlist-tracklist-grid" id="cf-playlist-items-grid" role="table">
                        <div class="cf-tracklist-row cf-tracklist-head" role="row">
                            <div class="cf-col-index" role="columnheader">#</div>
                            <div class="cf-col-title" role="columnheader"><?php esc_html_e( 'Track', 'cf-auth' ); ?></div>
                            <div class="cf-col-view" role="columnheader"><?php esc_html_e( 'View', 'cf-auth' ); ?></div>
                            <?php if ( $is_owner ) : ?>
                            <div class="cf-col-remove" role="columnheader"></div>
                            <?php endif; ?>
                        </div>
                        <?php foreach ( $items as $index => $item ) : ?>
                            <?php $this->render_playlist_item_row( $item, $index + 1, $is_owner, (int) $playlist->id ); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // USER PROFILE — full page with all tabs
    // ─────────────────────────────────────────────────────────────────────────
    public function render_profile() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( home_url('/cf-login?redirect_to=' . urlencode(get_permalink())) );
            exit;
        }

        $user_id    = get_current_user_id();
        $user       = get_userdata( $user_id );
        $avatar     = CF_Profile::get_avatar_url( $user_id );
        $bio        = get_user_meta( $user_id, 'cf_bio', true );
        $since      = CF_Profile::get_member_since( $user_id );
        $provider   = get_user_meta( $user_id, 'cf_social_provider', true ) ?: 'manual';
        $fav_tracks       = get_user_meta( $user_id, 'cf_favorite_tracks', true ) ?: [];
        $fav_albums       = get_user_meta( $user_id, 'cf_favorite_albums', true ) ?: [];
        $fav_posts        = get_user_meta( $user_id, 'cf_favorite_posts', true ) ?: [];
        $verified         = get_user_meta( $user_id, 'cf_email_verified', true );
        $xfinity_balance  = CF_Xfinity::get_instance()->get_balance( $user_id );
        $referral_link    = CF_Referral::get_instance()->get_referral_link( $user_id );

        // ── Favorites pagination (user-meta list; slice in PHP) ──
        $fav_args     = $this->get_tab_pagination_args( 'cf_fav_page', 'cf_fav_per_page' );
        $fav_all      = $this->build_combined_favorites_list( $fav_tracks, $fav_albums, $fav_posts );
        $fav_total    = count( $fav_all );
        $fav_pages    = $fav_total > 0 ? (int) ceil( $fav_total / $fav_args['per_page'] ) : 0;
        if ( $fav_args['page'] > max( 1, $fav_pages ) ) {
            $fav_args['page']   = max( 1, $fav_pages );
            $fav_args['offset'] = ( $fav_args['page'] - 1 ) * $fav_args['per_page'];
        }
        $fav_page_items = array_slice( $fav_all, $fav_args['offset'], $fav_args['per_page'] );

        // ── History pagination (wpdb LIMIT/OFFSET) ──
        $hist_args  = $this->get_tab_pagination_args( 'cf_hist_page', 'cf_hist_per_page' );
        $hist_data  = $this->get_paginated_listening_history( $user_id, $hist_args['per_page'], $hist_args['offset'] );
        $hist_total = $hist_data['total'];
        $hist_pages = $hist_total > 0 ? (int) ceil( $hist_total / $hist_args['per_page'] ) : 0;
        $hist_items = $hist_data['items'];

        // ── Playlists pagination (wpdb LIMIT/OFFSET) ──
        $pl_args  = $this->get_tab_pagination_args( 'cf_pl_page', 'cf_pl_per_page' );
        $pl_data  = $this->get_paginated_user_playlists( $user_id, $pl_args['per_page'], $pl_args['offset'] );
        $pl_total = $pl_data['total'];
        $pl_pages = $pl_total > 0 ? (int) ceil( $pl_total / $pl_args['per_page'] ) : 0;
        $pl_items = $pl_data['items'];

        ob_start(); ?>
        <div class="cf-page-wrap cf-profile-page">

            <!-- ── Profile Hero ── -->
            <div class="cf-profile-hero">
                <div class="cf-hero-bg"></div>
                <div class="cf-hero-content">
                    <div class="cf-avatar-wrap">
                        <img src="<?php echo esc_url($avatar); ?>" alt="Avatar" class="cf-avatar-lg" id="cf-avatar-img"
                             onerror="this.src='<?php echo esc_url(CF_AUTH_URL.'assets/img/default-avatar.svg'); ?>'">
                        <label class="cf-avatar-edit" for="cf-avatar-input" title="<?php _e('Change photo','cf-auth'); ?>">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <input type="file" id="cf-avatar-input" accept="image/*" style="display:none">
                        </label>
                    </div>
                    <div class="cf-hero-info">
                        <h1><?php echo esc_html($user->display_name); ?></h1>
                        <p class="cf-hero-email"><?php echo esc_html($user->user_email); ?></p>
                        <div class="cf-hero-badges">
                            <span class="cf-badge cf-badge-gold">🎵 <?php _e('Listener','cf-auth'); ?></span>
                            <span class="cf-badge cf-badge-dim"><?php echo esc_html(ucfirst($provider)); ?></span>
                            <span class="cf-badge cf-badge-dim">⏱ <?php echo esc_html($since); ?></span>
                            <?php if ($verified !== '1') : ?>
                                <span class="cf-badge cf-badge-warn">⚠ <?php _e('Email not verified','cf-auth'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cf-hero-actions">
                        <button id="cf-logout-btn" class="cf-btn cf-btn-outline-sm">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                            <?php _e('Sign Out','cf-auth'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Stats Bar ── -->
            <div class="cf-stats-bar">
                <div class="cf-stat">
                    <span class="cf-stat-num"><?php echo count($fav_tracks); ?></span>
                    <span class="cf-stat-lbl"><?php _e('Fav Tracks','cf-auth'); ?></span>
                </div>
                <div class="cf-stat-sep"></div>
                <div class="cf-stat">
                    <span class="cf-stat-num"><?php echo count($fav_albums); ?></span>
                    <span class="cf-stat-lbl"><?php _e('Fav Albums','cf-auth'); ?></span>
                </div>
                <div class="cf-stat-sep"></div>
                <div class="cf-stat">
                    <span class="cf-stat-num"><?php echo count($fav_posts); ?></span>
                    <span class="cf-stat-lbl"><?php _e('Fav Articles','cf-auth'); ?></span>
                </div>
                <div class="cf-stat-sep"></div>
                <div class="cf-stat">
                    <span class="cf-stat-num">∞</span>
                    <span class="cf-stat-lbl"><?php _e('Free Access','cf-auth'); ?></span>
                </div>
            </div>

            <!-- ── Tabs ── -->
            <div class="cf-tabs-wrap">
                <div class="cf-tabs">
                    <button class="cf-tab active" data-tab="overview">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                        <?php _e('Overview','cf-auth'); ?>
                    </button>
                    <button class="cf-tab" data-tab="favorites">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        <?php _e('Favorites','cf-auth'); ?>
                    </button>
                    <button class="cf-tab" data-tab="history">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php _e('History','cf-auth'); ?>
                    </button>
                    <button class="cf-tab" data-tab="playlists">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                        <?php _e('Playlists','cf-auth'); ?>
                    </button>
                    <button class="cf-tab" data-tab="rewards">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                        <?php _e('Rewards','cf-auth'); ?>
                    </button>
                    <button class="cf-tab" data-tab="settings">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <?php _e('Settings','cf-auth'); ?>
                    </button>
                </div>
            </div>

            <!-- ── Tab: Overview ── -->
            <div class="cf-tab-panel active" id="cf-tab-overview">
                <?php if ($bio) : ?>
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e('About Me','cf-auth'); ?></h4>
                    <p class="cf-bio-text"><?php echo esc_html($bio); ?></p>
                </div>
                <?php else : ?>
                <div class="cf-section-card cf-empty-state">
                    <span class="cf-empty-icon">🎵</span>
                    <p><?php _e('Your profile is ready. Add a bio in Settings to personalize it.','cf-auth'); ?></p>
                    <button class="cf-btn cf-btn-primary-sm cf-go-settings"><?php _e('Edit Profile','cf-auth'); ?></button>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Tab: Favorites ── -->
            <div class="cf-tab-panel" id="cf-tab-favorites" style="display:none"
                 data-empty-msg="<?php echo esc_attr( __( 'No favorites yet. Start listening and save tracks you love.', 'cf-auth' ) ); ?>">
                <?php if ( $fav_total === 0 ) : ?>
                <div class="cf-section-card cf-empty-state" id="cf-favorites-empty">
                    <span class="cf-empty-icon">♥</span>
                    <p><?php _e( 'No favorites yet. Start listening and save tracks you love.', 'cf-auth' ); ?></p>
                </div>
                <?php else : ?>
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e( 'Favorites', 'cf-auth' ); ?></h4>
                    <div class="cf-row-list" id="cf-favorites-list">
                        <?php foreach ( $fav_page_items as $entry ) : ?>
                            <?php $this->render_favorite_card( $entry['post'], $entry['type'] ); ?>
                        <?php endforeach; ?>
                    </div>
                    <?php
                    $this->render_pagination(
                        $fav_args['page'],
                        $fav_pages,
                        $fav_args['per_page'],
                        'cf_fav_page',
                        'cf_fav_per_page'
                    );
                    ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Tab: History ── -->
            <div class="cf-tab-panel" id="cf-tab-history" style="display:none">
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e( 'Listening History', 'cf-auth' ); ?></h4>
                    <?php if ( $hist_total === 0 ) : ?>
                        <p class="cf-muted"><?php _e( 'No listening history yet.', 'cf-auth' ); ?></p>
                    <?php else : ?>
                        <div class="cf-history-list" id="cf-history-list">
                            <?php foreach ( $hist_items as $item ) : ?>
                                <div class="cf-history-item">
                                    <span class="cf-history-track" style="display:flex;align-items:center;flex:1;min-width:0">
                                        <?php if ( ! empty( $item['cover'] ) ) : ?>
                                            <img src="<?php echo esc_url( $item['cover'] ); ?>" alt="" class="cf-history-cover" width="36" height="36" loading="lazy" style="border-radius:4px;object-fit:cover;margin-right:10px;flex-shrink:0">
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( $item['url'] ); ?>" class="cf-history-track-link" style="color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html( $item['title'] ); ?></a>
                                    </span>
                                    <span class="cf-history-time"><?php echo esc_html( $item['listened_at'] ); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php
                        $this->render_pagination(
                            $hist_args['page'],
                            $hist_pages,
                            $hist_args['per_page'],
                            'cf_hist_page',
                            'cf_hist_per_page'
                        );
                        ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Tab: Playlists ── -->
            <div class="cf-tab-panel" id="cf-tab-playlists" style="display:none"
                 data-empty-msg="<?php echo esc_attr( __( 'You haven\'t created any playlists yet.', 'cf-auth' ) ); ?>">
                <div class="cf-section-card cf-playlists-create">
                    <h4 class="cf-section-title"><?php _e( 'Create Playlist', 'cf-auth' ); ?></h4>
                    <form id="cf-create-playlist-form" class="cf-playlist-create-form">
                        <input type="text" id="cf-create-playlist-name" name="name" maxlength="190" placeholder="<?php esc_attr_e( 'Playlist name', 'cf-auth' ); ?>" required>
                        <button type="submit" class="cf-btn cf-btn-primary-sm">
                            <span class="cf-btn-text"><?php _e( 'Create Playlist', 'cf-auth' ); ?></span>
                            <span class="cf-btn-loader" style="display:none"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" class="cf-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></span>
                        </button>
                    </form>
                    <div id="cf-create-playlist-msg" class="cf-message" style="display:none"></div>
                </div>

                <?php if ( $pl_total === 0 ) : ?>
                <div class="cf-section-card cf-empty-state" id="cf-playlists-empty">
                    <span class="cf-empty-icon">🎵</span>
                    <p><?php _e( 'You haven\'t created any playlists yet.', 'cf-auth' ); ?></p>
                </div>
                <?php else : ?>
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e( 'Your Playlists', 'cf-auth' ); ?></h4>
                    <div class="cf-row-list" id="cf-playlists-grid">
                        <?php foreach ( $pl_items as $playlist ) : ?>
                            <?php $this->render_playlist_profile_card( $playlist ); ?>
                        <?php endforeach; ?>
                    </div>
                    <?php
                    $this->render_pagination(
                        $pl_args['page'],
                        $pl_pages,
                        $pl_args['per_page'],
                        'cf_pl_page',
                        'cf_pl_per_page'
                    );
                    ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Tab: Rewards ── -->
            <div class="cf-tab-panel" id="cf-tab-rewards" style="display:none">
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e( 'Your Xfinity Balance', 'cf-auth' ); ?></h4>
                    <span class="cf-stat-num"><?php echo esc_html( number_format_i18n( $xfinity_balance, 2 ) ); ?></span>
                    <span class="cf-stat-lbl"><?php _e( 'Xfinity', 'cf-auth' ); ?></span>
                </div>

                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e( 'Your Referral Link', 'cf-auth' ); ?></h4>
                    <div class="cf-playlist-create-form">
                        <input type="text" id="cf-referral-link-input" value="<?php echo esc_attr( $referral_link ); ?>" readonly>
                        <button type="button" id="cf-copy-referral-link" class="cf-btn cf-btn-primary-sm"><?php _e( 'Copy', 'cf-auth' ); ?></button>
                    </div>
                </div>

                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e( 'Referral Stats', 'cf-auth' ); ?></h4>
                    <div id="cf-referral-stats-container">
                        <p class="cf-muted"><?php _e( 'Loading...', 'cf-auth' ); ?></p>
                    </div>
                </div>

                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e( 'Daily Xfinity Activity', 'cf-auth' ); ?></h4>
                    <div id="cf-xfinity-history-container">
                        <p class="cf-muted"><?php _e( 'Loading...', 'cf-auth' ); ?></p>
                    </div>
                </div>
            </div>

            <!-- ── Tab: Settings ── -->
            <div class="cf-tab-panel" id="cf-tab-settings" style="display:none">
                <div id="cf-settings-global-msg" class="cf-message" style="display:none"></div>

                <!-- Profile Info -->
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e('Profile Information','cf-auth'); ?></h4>
                    <form id="cf-profile-form" class="cf-form" novalidate>
                        <div id="cf-profile-message" class="cf-message" style="display:none"></div>
                        <div class="cf-form-row">
                            <div class="cf-field">
                                <label><?php _e('Display Name','cf-auth'); ?></label>
                                <input type="text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>" required>
                            </div>
                            <div class="cf-field">
                                <label><?php _e('Email Address','cf-auth'); ?></label>
                                <input type="email" name="email" value="<?php echo esc_attr($user->user_email); ?>">
                                <?php if ($verified !== '1') : ?>
                                    <span class="cf-field-note cf-warn">⚠ <?php _e('Not verified — <a href="#" id="cf-resend-settings">resend email</a>','cf-auth'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="cf-field">
                            <label><?php _e('Bio','cf-auth'); ?></label>
                            <textarea name="bio" rows="3" placeholder="<?php _e('Tell the community about yourself...','cf-auth'); ?>"><?php echo esc_textarea($bio); ?></textarea>
                        </div>
                        <button type="submit" class="cf-btn cf-btn-primary">
                            <span class="cf-btn-text"><?php _e('Save Profile','cf-auth'); ?></span>
                            <span class="cf-btn-loader" style="display:none"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" class="cf-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></span>
                        </button>
                    </form>
                </div>

                <!-- Change Password (manual accounts only) -->
                <?php if ( $provider === 'manual' ) : ?>
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e('Change Password','cf-auth'); ?></h4>
                    <form id="cf-change-password-form" class="cf-form" method="post" novalidate>
                        <div id="cf-password-message" class="cf-message" style="display:none"></div>
                        <div class="cf-field">
                            <label><?php _e('Current Password','cf-auth'); ?></label>
                            <div class="cf-input-wrap">
                                <input type="password" name="current_password" placeholder="••••••••" required>
                                <button type="button" class="cf-eye-btn cf-toggle-password"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                            </div>
                        </div>
                        <div class="cf-form-row">
                            <div class="cf-field">
                                <label><?php _e('New Password','cf-auth'); ?></label>
                                <div class="cf-input-wrap">
                                    <input type="password" name="new_password" placeholder="<?php _e('Min. 8 characters','cf-auth'); ?>" required>
                                    <button type="button" class="cf-eye-btn cf-toggle-password"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                </div>
                            </div>
                            <div class="cf-field">
                                <label><?php _e('Confirm New Password','cf-auth'); ?></label>
                                <input type="password" name="confirm_password" placeholder="••••••••" required>
                            </div>
                        </div>
                        <button type="submit" class="cf-btn cf-btn-primary">
                            <span class="cf-btn-text"><?php _e('Update Password','cf-auth'); ?></span>
                            <span class="cf-btn-loader" style="display:none"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" class="cf-spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></span>
                        </button>
                    </form>
                </div>
                <?php else : ?>
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e('Password','cf-auth'); ?></h4>
                    <p class="cf-muted"><?php printf( __('Your account is linked via %s. Password is managed by your provider.','cf-auth'), '<strong>'.ucfirst($provider).'</strong>' ); ?></p>
                </div>
                <?php endif; ?>

                <!-- Forgot Password link -->
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e('Account Security','cf-auth'); ?></h4>
                    <p class="cf-muted"><?php _e('If you need to reset your password via email:','cf-auth'); ?></p>
                    <a href="<?php echo esc_url(home_url('/cf-forgot-password')); ?>" class="cf-btn cf-btn-outline">
                        <?php _e('Send Password Reset Email','cf-auth'); ?>
                    </a>
                </div>

                <!-- Danger Zone -->
                <div class="cf-section-card cf-section-danger">
                    <h4 class="cf-section-title cf-danger-title"><?php _e('Sign Out','cf-auth'); ?></h4>
                    <p class="cf-muted"><?php _e('Sign out from your account on this device.','cf-auth'); ?></p>
                    <button id="cf-logout-btn-settings" class="cf-btn cf-btn-danger-outline">
                        <?php _e('Sign Out','cf-auth'); ?>
                    </button>
                </div>

                <div class="cf-section-card cf-danger-zone">
                    <h3><?php esc_html_e( 'Delete Account', 'cf-auth' ); ?></h3>
                    <p><?php esc_html_e( 'Permanently delete your account and all associated data (favorites, playlists, listening history). This cannot be undone.', 'cf-auth' ); ?></p>

                    <label class="cf-danger-ack">
                        <input type="checkbox" id="cf-delete-account-ack">
                        <span>
                            <?php
                            printf(
                                /* translators: 1: privacy policy link open tag, 2: link close tag, 3: terms link open tag, 4: link close tag */
                                esc_html__( 'I have read the %1$sPrivacy Policy%2$s and %3$sTerms of Service%4$s and understand what data will be deleted and my rights regarding it.', 'cf-auth' ),
                                '<a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '" target="_blank" rel="noopener">',
                                '</a>',
                                '<a href="' . esc_url( home_url( '/terms-of-service/' ) ) . '" target="_blank" rel="noopener">',
                                '</a>'
                            );
                            ?>
                        </span>
                    </label>

                    <input type="email" id="cf-delete-account-confirm-email" class="cf-delete-account-email" placeholder="<?php esc_attr_e( 'Type your account email to confirm', 'cf-auth' ); ?>" disabled>
                    <button type="button" id="cf-delete-account-btn" class="cf-btn cf-btn-danger-outline" disabled>
                        <?php esc_html_e( 'Permanently Delete My Account', 'cf-auth' ); ?>
                    </button>
                    <div id="cf-delete-account-msg" class="cf-message" style="display:none"></div>
                </div>

            </div><!-- /settings -->
        </div><!-- /cf-profile-page -->
        <?php return ob_get_clean();
    }
}
