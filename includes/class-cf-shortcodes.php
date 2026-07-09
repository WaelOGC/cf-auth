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
        add_shortcode( 'cf_verify_email',    [ $this, 'render_verify'   ] );
        add_shortcode( 'cf_donation_form',   [ $this, 'render_donation' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHARED: Page wrapper
    // ─────────────────────────────────────────────────────────────────────────
    private function page_wrap( $content, $class = '' ) {
        return '<div class="cf-page-wrap ' . esc_attr($class) . '">' . $content . '</div>';
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
        ob_start(); ?>
        <div class="cf-page-wrap">
            <div class="cf-auth-grid">

                <!-- Left: Branding -->
                <div class="cf-auth-brand">
                    <div class="cf-brand-inner">
                        <div class="cf-brand-icon">⚡</div>
                        <h2><?php _e( 'Welcome Back', 'cf-auth' ); ?></h2>
                        <p><?php _e( 'Sign in and continue your cinematic music journey.', 'cf-auth' ); ?></p>
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
                        <div class="cf-social-grid">
                            <button class="cf-social-btn" data-provider="google">
                                <svg viewBox="0 0 24 24" width="18" height="18"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                                Google
                            </button>
                            <button class="cf-social-btn" data-provider="facebook">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                Facebook
                            </button>
                            <button class="cf-social-btn" data-provider="discord">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="#5865F2"><path d="M20.317 4.492c-1.53-.69-3.17-1.2-4.885-1.49a.075.075 0 0 0-.079.036c-.21.369-.444.85-.608 1.23a18.566 18.566 0 0 0-5.487 0 12.36 12.36 0 0 0-.617-1.23A.077.077 0 0 0 8.562 3c-1.714.29-3.354.8-4.885 1.491a.07.07 0 0 0-.032.027C.533 9.093-.32 13.555.099 17.961a.08.08 0 0 0 .031.055 20.03 20.03 0 0 0 5.993 2.98.078.078 0 0 0 .084-.026c.462-.63.874-1.295 1.226-1.963a.074.074 0 0 0-.041-.104 13.175 13.175 0 0 1-1.872-.878.075.075 0 0 1-.008-.125c.126-.093.252-.19.372-.287a.075.075 0 0 1 .078-.01c3.927 1.764 8.18 1.764 12.061 0a.075.075 0 0 1 .079.009c.12.098.245.195.372.288a.075.075 0 0 1-.006.125c-.598.344-1.22.635-1.873.877a.075.075 0 0 0-.041.105c.36.687.772 1.341 1.225 1.962a.077.077 0 0 0 .084.028 19.963 19.963 0 0 0 6.002-2.981.076.076 0 0 0 .032-.054c.5-5.094-.838-9.52-3.549-13.442a.06.06 0 0 0-.031-.028z"/></svg>
                                Discord
                            </button>
                            <button class="cf-social-btn" data-provider="twitter">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                X
                            </button>
                        </div>

                        <div class="cf-divider"><span><?php _e( 'or with email', 'cf-auth' ); ?></span></div>

                        <form id="cf-login-form" class="cf-form" novalidate>
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
        ob_start(); ?>
        <div class="cf-page-wrap">
            <div class="cf-auth-grid">
                <div class="cf-auth-brand">
                    <div class="cf-brand-inner">
                        <div class="cf-brand-icon">✦</div>
                        <h2><?php _e( 'Join the Universe', 'cf-auth' ); ?></h2>
                        <p><?php _e( 'Create your free listener account and become part of the Collective Finity community.', 'cf-auth' ); ?></p>
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

                        <div class="cf-social-grid">
                            <button class="cf-social-btn" data-provider="google">
                                <svg viewBox="0 0 24 24" width="18" height="18"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                                Google
                            </button>
                            <button class="cf-social-btn" data-provider="facebook">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                Facebook
                            </button>
                            <button class="cf-social-btn" data-provider="discord">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="#5865F2"><path d="M20.317 4.492c-1.53-.69-3.17-1.2-4.885-1.49a.075.075 0 0 0-.079.036c-.21.369-.444.85-.608 1.23a18.566 18.566 0 0 0-5.487 0 12.36 12.36 0 0 0-.617-1.23A.077.077 0 0 0 8.562 3c-1.714.29-3.354.8-4.885 1.491a.07.07 0 0 0-.032.027C.533 9.093-.32 13.555.099 17.961a.08.08 0 0 0 .031.055 20.03 20.03 0 0 0 5.993 2.98.078.078 0 0 0 .084-.026c.462-.63.874-1.295 1.226-1.963.074.074 0 0 0-.041-.104a13.175 13.175 0 0 1-1.872-.878.075.075 0 0 1-.008-.125c.126-.093.252-.19.372-.287a.075.075 0 0 1 .078-.01c3.927 1.764 8.18 1.764 12.061 0a.075.075 0 0 1 .079.009c.12.098.245.195.372.288a.075.075 0 0 1-.006.125c-.598.344-1.22.635-1.873.877a.075.075 0 0 0-.041.105c.36.687.772 1.341 1.225 1.962a.077.077 0 0 0 .084.028 19.963 19.963 0 0 0 6.002-2.981.076.076 0 0 0 .032-.054c.5-5.094-.838-9.52-3.549-13.442a.06.06 0 0 0-.031-.028z"/></svg>
                                Discord
                            </button>
                            <button class="cf-social-btn" data-provider="twitter">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.73-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                                X
                            </button>
                        </div>

                        <div class="cf-divider"><span><?php _e( 'or with email', 'cf-auth' ); ?></span></div>

                        <form id="cf-register-form" class="cf-form" novalidate>
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
                <form id="cf-forgot-form" class="cf-form" novalidate>
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
                <form id="cf-reset-form" class="cf-form" novalidate>
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
                            <label for="cf-donation-amount"><?php _e( 'Amount', 'cf-auth' ); ?></label>
                            <input type="number" id="cf-donation-amount" name="amount" class="cf-donation-amount"
                                   min="1" step="0.01" required
                                   placeholder="<?php echo esc_attr( $currency ); ?>">
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
            var CF = window.CF_AUTH || {};

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
                var body = new FormData();
                body.append('action', action);
                body.append('nonce', CF.nonce || '');
                Object.keys(data).forEach(function (key) {
                    body.append(key, data[key]);
                });
                return fetch(CF.ajax_url, { method: 'POST', body: body, credentials: 'same-origin' })
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
        $fav_tracks = get_user_meta( $user_id, 'cf_favorite_tracks', true ) ?: [];
        $fav_albums = get_user_meta( $user_id, 'cf_favorite_albums', true ) ?: [];
        $verified   = get_user_meta( $user_id, 'cf_email_verified', true );

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
            <div class="cf-tab-panel" id="cf-tab-favorites" style="display:none">
                <div class="cf-section-card cf-empty-state">
                    <span class="cf-empty-icon">♥</span>
                    <p><?php _e('No favorites yet. Start listening and save tracks you love.','cf-auth'); ?></p>
                </div>
            </div>

            <!-- ── Tab: History ── -->
            <div class="cf-tab-panel" id="cf-tab-history" style="display:none">
                <div class="cf-section-card">
                    <h4 class="cf-section-title"><?php _e('Listening History','cf-auth'); ?></h4>
                    <div id="cf-history-container">
                        <p class="cf-muted"><?php _e('Loading...','cf-auth'); ?></p>
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
                    <form id="cf-change-password-form" class="cf-form" novalidate>
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

            </div><!-- /settings -->
        </div><!-- /cf-profile-page -->
        <?php return ob_get_clean();
    }
}
