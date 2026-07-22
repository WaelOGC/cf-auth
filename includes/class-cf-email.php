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

    // ── Review Published Email ──────────────────────────────────────────────
    public static function send_review_published( $user_id ) {
        $user    = get_userdata( $user_id );
        $subject = __( 'Your review is now live — Collective Finity', 'cf-auth' );
        $message = self::get_template( 'review-published', [
            'display_name' => $user->display_name,
        ] );
        self::send( $user->user_email, $subject, $message );
    }

    // ── Daily Xfinity Summary ─────────────────────────────────────────────────
    /**
     * Email a 24h earnings digest. Skips sending when the user earned 0.
     *
     * @param int $user_id
     * @return bool True if an email was sent.
     */
    public static function send_daily_xfinity_summary( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_email ) {
            return false;
        }

        $stats = self::get_xfinity_period_stats( (int) $user_id, 1 );
        if ( $stats['xfinity_earned'] <= 0 ) {
            return false;
        }

        $subject = __( 'Your daily Xfinity summary — Collective Finity', 'cf-auth' );
        $message = self::get_template( 'daily-xfinity-summary', [
            'display_name'    => $user->display_name,
            'xfinity_earned'  => number_format( $stats['xfinity_earned'], 2 ),
            'listening_mins'  => (int) $stats['listening_mins'],
            'referrals'       => (int) $stats['referrals'],
            'rewards_url'     => home_url( '/cf-profile#rewards' ),
        ] );
        self::send( $user->user_email, $subject, $message );
        return true;
    }

    // ── Weekly Xfinity Summary ────────────────────────────────────────────────
    /**
     * Email a 7-day earnings digest with day-by-day breakdown + current balance.
     * Skips sending when the user earned 0 in the period.
     *
     * @param int $user_id
     * @return bool True if an email was sent.
     */
    public static function send_weekly_xfinity_summary( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user || ! $user->user_email ) {
            return false;
        }

        $stats = self::get_xfinity_period_stats( (int) $user_id, 7 );
        if ( $stats['xfinity_earned'] <= 0 ) {
            return false;
        }

        $balance = 0.0;
        if ( class_exists( 'CF_Xfinity' ) ) {
            $balance = CF_Xfinity::get_instance()->get_balance( (int) $user_id );
        }

        $subject = __( 'Your weekly Xfinity summary — Collective Finity', 'cf-auth' );
        $message = self::get_template( 'weekly-xfinity-summary', [
            'display_name'    => $user->display_name,
            'xfinity_earned'  => number_format( $stats['xfinity_earned'], 2 ),
            'listening_mins'  => (int) $stats['listening_mins'],
            'referrals'       => (int) $stats['referrals'],
            'balance'         => number_format( $balance, 2 ),
            'daily_breakdown' => $stats['daily_breakdown'],
            'rewards_url'     => home_url( '/cf-profile#rewards' ),
        ] );
        self::send( $user->user_email, $subject, $message );
        return true;
    }

    /**
     * Aggregate engagement + referral stats for a user over the last N days.
     *
     * @param int $user_id
     * @param int $days
     * @return array{xfinity_earned: float, listening_mins: int, referrals: int, daily_breakdown: string}
     */
    private static function get_xfinity_period_stats( $user_id, $days ) {
        global $wpdb;

        $user_id = absint( $user_id );
        $days    = max( 1, absint( $days ) );

        // Site-local time matches "created_at" columns written with current_time('mysql').
        $since_local = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );

        $sessions_table = $wpdb->prefix . 'cf_engagement_sessions';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(xfinity_earned), 0) AS xfinity_earned,
                COALESCE(SUM(CASE WHEN activity_type = 'listening' THEN duration_seconds ELSE 0 END), 0) AS listening_seconds
             FROM {$sessions_table}
             WHERE user_id = %d
               AND is_valid = 1
               AND created_at >= %s",
            $user_id,
            $since_local
        ) );

        $xfinity_earned  = round( (float) ( $row->xfinity_earned ?? 0 ), 2 );
        $listening_mins  = (int) floor( ( (int) ( $row->listening_seconds ?? 0 ) ) / 60 );

        $referrals = 0;
        if ( class_exists( 'CF_Referral' ) ) {
            $referrals = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . CF_Referral::referrals_table() . "
                 WHERE referrer_user_id = %d
                   AND status = 'confirmed'
                   AND confirmed_at >= %s",
                $user_id,
                $since_local
            ) );
        }

        // Also count ledger earnings that aren't session-backed (e.g. referral bonuses)
        // so digests aren't undercounted if referrals aren't in engagement_sessions.
        $ledger_extra = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM " . CF_Xfinity::ledger_table() . "
             WHERE user_id = %d
               AND amount > 0
               AND source IN ('referral_referrer', 'referral_new_user')
               AND created_at >= %s",
            $user_id,
            $since_local
        ) );
        $xfinity_earned = round( $xfinity_earned + $ledger_extra, 2 );

        $daily_breakdown = '';
        if ( $days > 1 ) {
            $day_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DATE(created_at) AS day_date, COALESCE(SUM(xfinity_earned), 0) AS day_total
                 FROM {$sessions_table}
                 WHERE user_id = %d
                   AND is_valid = 1
                   AND created_at >= %s
                 GROUP BY DATE(created_at)
                 ORDER BY day_date ASC",
                $user_id,
                $since_local
            ) );

            $lines = [];
            foreach ( (array) $day_rows as $day_row ) {
                $label   = date_i18n( get_option( 'date_format' ), strtotime( $day_row->day_date ) );
                $lines[] = $label . ': ' . number_format( (float) $day_row->day_total, 2 ) . ' Xfinity';
            }
            $daily_breakdown = implode( "\n", $lines );
        }

        return [
            'xfinity_earned'  => $xfinity_earned,
            'listening_mins'  => $listening_mins,
            'referrals'       => $referrals,
            'daily_breakdown' => $daily_breakdown,
        ];
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

        $btn_style = 'display:inline-block;margin:24px 0;padding:16px 36px;background:#FFB800;color:#000000;font-weight:700;text-decoration:none;border-radius:8px;font-size:15px;letter-spacing:1px;font-family:monospace;';
        $p_style   = 'margin:0 0 16px;color:#cccccc;line-height:1.7;font-size:14px;font-family:monospace;';
        $p_greet   = 'margin:0 0 16px;padding-bottom:16px;border-bottom:1px solid #222222;color:#ffffff;font-weight:700;font-size:18px;line-height:1.7;font-family:monospace;';

        $wrap_open = '
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#000000;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#000000;margin:0;padding:0;">
<tr>
<td align="center" style="padding:40px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;border:1px solid #FFB800;background-color:#0B0B0B;">
<tr>
<td align="center" style="padding:40px;background-color:#0B0B0B;border-bottom:1px solid #222222;">
<img src="' . esc_url( $logo_url ) . '" alt="Collective Finity" width="120" height="120" style="display:block;width:120px;height:120px;border:0;">
</td>
</tr>
<tr>
<td style="padding:40px;background-color:#0B0B0B;color:#cccccc;font-family:monospace;font-size:14px;line-height:1.7;">';

        $wrap_close = '
</td>
</tr>
<tr>
<td align="center" style="padding:20px 40px;background-color:#0B0B0B;border-top:1px solid #222222;">
<img src="' . esc_url( $logo_url ) . '" alt="Collective Finity" width="60" height="60" style="display:block;width:60px;height:60px;margin:0 auto 12px;opacity:0.5;border:0;">
<p style="margin:0 0 12px;color:#555555;font-size:12px;font-family:monospace;line-height:1.5;">© ' . date( 'Y' ) . ' Collective Finity — Music Beyond Imagination</p>
<p style="margin:0;font-size:12px;font-family:monospace;line-height:1.5;"><a href="' . esc_url( home_url( '/privacy-policy' ) ) . '" style="color:#555555;text-decoration:none;">Privacy Policy</a> | <a href="' . esc_url( home_url( '/contact-us' ) ) . '" style="color:#555555;text-decoration:none;">Contact Us</a></p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>';

        switch ( $type ) {
            case 'verify-email':
                $body = '<p style="' . $p_greet . '">Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p style="' . $p_style . '">Welcome to the Collective Finity universe. One last step — verify your email to unlock the full experience.</p>
                <p style="margin:0 0 16px;text-align:center;font-family:monospace;"><a href="' . esc_url( $vars['verify_url'] ) . '" style="' . $btn_style . '">✓ Verify My Email</a></p>
                <p style="' . $p_style . '">This link expires in <strong>24 hours</strong>. If you didn\'t create an account, you can safely ignore this email.</p>';
                break;

            case 'reset-password':
                $body = '<p style="' . $p_greet . '">Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p style="' . $p_style . '">We received a request to reset your password. Click the button below to create a new one.</p>
                <p style="margin:0 0 16px;text-align:center;font-family:monospace;"><a href="' . esc_url( $vars['reset_url'] ) . '" style="' . $btn_style . '">🔑 Reset My Password</a></p>
                <p style="' . $p_style . '">This link expires in <strong>1 hour</strong>. If you didn\'t request this, please ignore this email.</p>';
                break;

            case 'welcome':
                $body = '<p style="' . $p_greet . '">Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p style="' . $p_style . '">Your account is verified and ready. Welcome to the Collective Finity universe — a cinematic world where emotional sound, visual stories and creativity connect.</p>
                <p style="margin:0 0 16px;text-align:center;font-family:monospace;"><a href="' . esc_url( $vars['login_url'] ) . '" style="' . $btn_style . '">🎵 Start Listening</a></p>';
                break;

            case 'feedback-received':
                $body = '<p style="' . $p_greet . '">Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p style="' . $p_style . '">Thank you for sharing your feedback on the Collective Finity platform. Your message means a lot to us.</p>
                <p style="' . $p_style . '">Your feedback will appear on the site after admin approval.</p>
                <p style="' . $p_style . '"><a href="' . esc_url( home_url( '/privacy-policy' ) ) . '" style="color:#555555;text-decoration:none;">Privacy Policy</a> | <a href="' . esc_url( home_url( '/contact-us' ) ) . '" style="color:#555555;text-decoration:none;">Contact Us</a></p>';
                break;

            case 'review-published':
                $body = '<p style="' . $p_greet . '">Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p style="' . $p_style . '">Good news — your review on the Collective Finity platform has been approved and is now live for everyone to see.</p>
                <p style="' . $p_style . '">Thank you for sharing your experience with the community.</p>
                <p style="' . $p_style . '"><a href="' . esc_url( home_url( '/privacy-policy' ) ) . '" style="color:#555555;text-decoration:none;">Privacy Policy</a> | <a href="' . esc_url( home_url( '/contact-us' ) ) . '" style="color:#555555;text-decoration:none;">Contact Us</a></p>';
                break;

            case 'daily-xfinity-summary':
                $body = '<p style="' . $p_greet . '">Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p style="' . $p_style . '">Here\'s your Xfinity activity from the last 24 hours:</p>
                <p style="' . $p_style . '"><strong style="color:#FFB800;">+' . esc_html( $vars['xfinity_earned'] ) . ' Xfinity</strong> earned<br>
                ' . esc_html( (string) $vars['listening_mins'] ) . ' minutes listening<br>
                ' . esc_html( (string) $vars['referrals'] ) . ' referral(s) confirmed</p>
                <p style="margin:0 0 16px;text-align:center;font-family:monospace;"><a href="' . esc_url( $vars['rewards_url'] ) . '" style="' . $btn_style . '">View Rewards</a></p>';
                break;

            case 'weekly-xfinity-summary':
                $breakdown_html = '';
                if ( ! empty( $vars['daily_breakdown'] ) ) {
                    $lines = preg_split( '/\r\n|\r|\n/', (string) $vars['daily_breakdown'] );
                    $breakdown_html = '<p style="' . $p_style . '"><strong style="color:#ffffff;">Day by day</strong><br>';
                    foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( $line === '' ) {
                            continue;
                        }
                        $breakdown_html .= esc_html( $line ) . '<br>';
                    }
                    $breakdown_html .= '</p>';
                }
                $body = '<p style="' . $p_greet . '">Hey <strong>' . esc_html( $vars['display_name'] ) . '</strong>,</p>
                <p style="' . $p_style . '">Here\'s your Xfinity activity from the last 7 days:</p>
                <p style="' . $p_style . '"><strong style="color:#FFB800;">+' . esc_html( $vars['xfinity_earned'] ) . ' Xfinity</strong> earned<br>
                ' . esc_html( (string) $vars['listening_mins'] ) . ' minutes listening<br>
                ' . esc_html( (string) $vars['referrals'] ) . ' referral(s) confirmed<br>
                Current balance: <strong style="color:#FFB800;">' . esc_html( $vars['balance'] ) . ' Xfinity</strong></p>
                ' . $breakdown_html . '
                <p style="margin:0 0 16px;text-align:center;font-family:monospace;"><a href="' . esc_url( $vars['rewards_url'] ) . '" style="' . $btn_style . '">View Rewards</a></p>';
                break;

            default:
                $body = '';
        }

        return $wrap_open . $body . $wrap_close;
    }
}
