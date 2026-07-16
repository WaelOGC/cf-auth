<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Email {

    // ── Send Verification Email ───────────────────────────────────────────────
    public static function send_verification( $user_id ) {
        global $wpdb;

        $token     = bin2hex( random_bytes( 32 ) );
        $expires   = date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) );
        $user      = get_userdata( $user_id );

        // Delete old tokens for this user
        $wpdb->delete( $wpdb->prefix . 'cf_email_tokens', [ 'user_id' => $user_id ] );

        $wpdb->insert( $wpdb->prefix . 'cf_email_tokens', [
            'user_id'    => $user_id,
            'token'      => $token,
            'expires_at' => $expires,
        ] );

        $verify_url = add_query_arg( [
            'cf_action' => 'verify_email',
            'token'     => $token,
        ], home_url( '/cf-verify-email' ) );

        $subject = __( 'Verify your email — Collective Finity', 'cf-auth' );
        $message = self::get_template( 'verify-email', [
            'display_name' => $user->display_name,
            'verify_url'   => $verify_url,
        ] );

        self::send( $user->user_email, $subject, $message );
    }

    // ── Send Password Reset Email ─────────────────────────────────────────────
    public static function send_password_reset( $user_id ) {
        global $wpdb;

        $token   = bin2hex( random_bytes( 32 ) );
        $expires = date( 'Y-m-d H:i:s', strtotime( '+1 hour' ) );
        $user    = get_userdata( $user_id );

        // Delete old reset tokens
        $wpdb->delete( $wpdb->prefix . 'cf_reset_tokens', [ 'user_id' => $user_id ] );

        $wpdb->insert( $wpdb->prefix . 'cf_reset_tokens', [
            'user_id'    => $user_id,
            'token'      => $token,
            'expires_at' => $expires,
        ] );

        $reset_url = add_query_arg( [
            'cf_action' => 'reset_password',
            'token'     => $token,
        ], home_url( '/cf-reset-password' ) );

        $subject = __( 'Reset your password — Collective Finity', 'cf-auth' );
        $message = self::get_template( 'reset-password', [
            'display_name' => $user->display_name,
            'reset_url'    => $reset_url,
        ] );

        self::send( $user->user_email, $subject, $message );
    }

    // ── Welcome Email ─────────────────────────────────────────────────────────
    public static function send_welcome( $user_id ) {
        $user    = get_userdata( $user_id );
        $subject = __( 'Welcome to Collective Finity 🎵', 'cf-auth' );
        $message = self::get_template( 'welcome', [
            'display_name' => $user->display_name,
            'login_url'    => home_url( '/cf-login' ),
        ] );
        self::send( $user->user_email, $subject, $message );
    }

    // ── Feedback Confirmation Email ───────────────────────────────────────────
    public static function send_feedback_confirmation( $user_id ) {
        $user    = get_userdata( $user_id );
        $subject = __( 'Thanks for your feedback — Collective Finity', 'cf-auth' );
        $message = self::get_template( 'feedback-received', [
            'display_name' => $user->display_name,
        ] );
        self::send( $user->user_email, $subject, $message );
    }

    // ── Core send function ────────────────────────────────────────────────────
    private static function send( $to, $subject, $message ) {
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Collective Finity <noreply@' . parse_url( home_url(), PHP_URL_HOST ) . '>',
        ];
        wp_mail( $to, $subject, $message, $headers );
    }

    // ── HTML Email Templates ──────────────────────────────────────────────────
    private static function get_template( $type, $vars = [] ) {
        $logo_url = plugin_dir_url( __FILE__ ) . '../assets/img/icon-192.png';

        $wrap_open = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><style>
            @property --angle {
                syntax: "<angle>";
                initial-value: 0deg;
                inherits: false;
            }
            body { margin:0; padding:0; background:#000; font-family: "Space Mono", monospace; color:#fff; }
            .card-border {
                max-width:600px; margin:40px auto; padding:1px; border-radius:16px;
                background: conic-gradient(from var(--angle), transparent 60%, rgba(255,183,0,0.35), transparent 80%);
                animation: spin-border 5.5s linear infinite;
            }
            @keyframes spin-border { to { --angle: 360deg; } }
            .wrap {
                background:#0B0B0B; border:1px solid rgba(30,30,30,0.9); border-radius:16px; overflow:hidden;
                position:relative;
            }
            .header { padding:40px; text-align:center; border-bottom:1px solid #222; position:relative; }
            .logo-wrap { position:relative; display:inline-block; }
            .logo-wrap::before {
                content:""; position:absolute; inset:-24px; border-radius:50%;
                background:#FFB800; filter:blur(22px); z-index:0;
                animation: logo-glow 4s ease-in-out infinite;
            }
            @keyframes logo-glow {
                0%, 100% { opacity:0.3; }
                50% { opacity:0.7; }
            }
            .header-logo { position:relative; z-index:1; max-width:120px; height:auto; display:block; }
            .body { padding:40px; position:relative; }
            .body::before {
                content:""; position:absolute; inset:0; pointer-events:none; z-index:0;
                background: radial-gradient(ellipse at center, #FFB800 0%, transparent 70%);
                animation: content-glow 8s ease-in-out infinite;
            }
            @keyframes content-glow {
                0%, 100% { opacity:0.35; }
                50% { opacity:0.7; }
            }
            .body > * { position:relative; z-index:1; }
            .body p { color:#ccc; line-height:1.7; font-size:14px; }
            .body p:first-child {
                color:#fff; font-weight:700; font-size:18px;
                padding-bottom:16px; margin-bottom:16px; border-bottom:1px solid #222;
            }
            .btn {
                display:inline-block; margin:24px 0; padding:16px 36px; background:#FFB800; color:#000 !important;
                font-weight:700; text-decoration:none; border-radius:8px; font-size:15px; letter-spacing:1px;
                box-shadow: 0 4px 24px #FFB800;
            }
            .footer { padding:20px 40px; text-align:center; border-top:1px solid #222; }
            .footer-logo { max-width:60px; height:auto; opacity:0.5; display:block; margin:0 auto 12px; }
            .footer p { color:#555; font-size:12px; margin:0 0 12px; }
            .footer-links { margin:0; font-size:12px; }
            .footer-links a { color:#555; text-decoration:none; }
            .footer-links a:hover { text-decoration:underline; }
        </style></head>
        <body><div class="card-border"><div class="wrap">
        <div class="header"><div class="logo-wrap"><img class="header-logo" src="' . esc_url( $logo_url ) . '" alt="Collective Finity"></div></div>
        <div class="body">';

        $wrap_close = '</div>
        <div class="footer">
            <img class="footer-logo" src="' . esc_url( $logo_url ) . '" alt="Collective Finity">
            <p>© ' . date( 'Y' ) . ' Collective Finity — Music Beyond Imagination</p>
            <p class="footer-links"><a href="' . esc_url( home_url( '/privacy-policy' ) ) . '">Privacy Policy</a> | <a href="' . esc_url( home_url( '/contact-us' ) ) . '">Contact Us</a></p>
        </div>
        </div></div></body></html>';

        switch ( $type ) {
            case 'verify-email':
                $body = '<p>Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p>Welcome to the Collective Finity universe. One last step — verify your email to unlock the full experience.</p>
                <p style="text-align:center"><a href="' . esc_url( $vars['verify_url'] ) . '" class="btn">✓ Verify My Email</a></p>
                <p>This link expires in <strong>24 hours</strong>. If you didn\'t create an account, you can safely ignore this email.</p>';
                break;

            case 'reset-password':
                $body = '<p>Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p>We received a request to reset your password. Click the button below to create a new one.</p>
                <p style="text-align:center"><a href="' . esc_url( $vars['reset_url'] ) . '" class="btn">🔑 Reset My Password</a></p>
                <p>This link expires in <strong>1 hour</strong>. If you didn\'t request this, please ignore this email.</p>';
                break;

            case 'welcome':
                $body = '<p>Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p>Your account is verified and ready. Welcome to the Collective Finity universe — a cinematic world where emotional sound, visual stories and creativity connect.</p>
                <p style="text-align:center"><a href="' . esc_url( $vars['login_url'] ) . '" class="btn">🎵 Start Listening</a></p>';
                break;

            case 'feedback-received':
                $body = '<p>Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p>Thank you for sharing your feedback on the Collective Finity platform. Your message means a lot to us.</p>
                <p>Your feedback will appear on the site after admin approval.</p>
                <p><a href="' . esc_url( home_url( '/privacy-policy' ) ) . '">Privacy Policy</a> | <a href="' . esc_url( home_url( '/contact-us' ) ) . '">Contact Us</a></p>';
                break;

            default:
                $body = '';
        }

        return $wrap_open . $body . $wrap_close;
    }
}
