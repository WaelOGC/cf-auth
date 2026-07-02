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
        $wrap_open = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><style>
            body { margin:0; padding:0; background:#000; font-family: "Space Mono", monospace; color:#fff; }
            .wrap { max-width:560px; margin:40px auto; background:#111; border:1px solid #333; border-radius:12px; overflow:hidden; }
            .header { background:#000; padding:32px; text-align:center; border-bottom:1px solid #222; }
            .header h1 { margin:0; font-size:24px; color:#FFB800; letter-spacing:2px; }
            .body { padding:32px; }
            .body p { color:#ccc; line-height:1.7; font-size:14px; }
            .btn { display:inline-block; margin:24px 0; padding:14px 32px; background:#FFB800; color:#000 !important;
                   font-weight:700; text-decoration:none; border-radius:8px; font-size:15px; letter-spacing:1px; }
            .footer { padding:20px 32px; text-align:center; border-top:1px solid #222; }
            .footer p { color:#555; font-size:12px; margin:0; }
        </style></head>
        <body><div class="wrap">
        <div class="header"><h1>⚡ COLLECTIVE FINITY</h1></div>
        <div class="body">';

        $wrap_close = '</div>
        <div class="footer"><p>© ' . date('Y') . ' Collective Finity — Music Beyond Imagination</p></div>
        </div></body></html>';

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

            default:
                $body = '';
        }

        return $wrap_open . $body . $wrap_close;
    }
}
