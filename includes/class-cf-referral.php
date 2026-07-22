<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Referral {

    private static $instance = null;

    const COOKIE_NAME    = 'cf_ref';
    const COOKIE_DAYS    = 30;
    const CODE_LENGTH    = 8;
    const META_SIGNUP_IP = 'cf_signup_ip';
    const META_LAST_IP   = 'cf_last_ip';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Persist ?ref= across the funnel until registration completes.
        add_action( 'init', [ $this, 'maybe_store_ref_cookie' ], 5 );

        // Generate code + attach pending referral for every new WP user (manual + social).
        add_action( 'user_register', [ $this, 'on_user_register' ], 20 );

        // Confirm when email ownership is proven (manual registration flow).
        add_action( 'cf_auth_after_email_verified', [ $this, 'confirm_referral' ], 10, 1 );
    }

    public static function codes_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_referral_codes';
    }

    public static function referrals_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_referrals';
    }

    /**
     * If the request carries ?ref=, store it in a 30-day cookie so it survives
     * until the visitor finishes registration.
     */
    public function maybe_store_ref_cookie() {
        if ( empty( $_GET['ref'] ) ) {
            return;
        }

        $code = strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) );
        if ( $code === '' || strlen( $code ) > 20 ) {
            return;
        }

        if ( ! preg_match( '/^[A-Z0-9]+$/', $code ) ) {
            return;
        }

        $expire = time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS );
        setcookie(
            self::COOKIE_NAME,
            $code,
            [
                'expires'  => $expire,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );

        // Available for the rest of this request (registration may complete same page).
        $_COOKIE[ self::COOKIE_NAME ] = $code;
    }

    /**
     * After a new account is created: store signup IP, ensure referral code exists,
     * and link a pending referral if a ref cookie/param is present.
     *
     * @param int $user_id
     */
    public function on_user_register( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return;
        }

        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
        if ( $ip !== '' ) {
            update_user_meta( $user_id, self::META_SIGNUP_IP, $ip );
            update_user_meta( $user_id, self::META_LAST_IP, $ip );
        }

        $this->generate_referral_code( $user_id );

        $code = $this->resolve_incoming_referral_code();
        if ( $code ) {
            $this->handle_referral_signup( $user_id, $code );
        }
    }

    /**
     * Create a unique 8-char alphanumeric referral code for the user if missing.
     *
     * @param int $user_id
     * @return string|false The code, or false on failure.
     */
    public function generate_referral_code( $user_id ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT code FROM " . self::codes_table() . " WHERE user_id = %d LIMIT 1",
            $user_id
        ) );

        if ( $existing ) {
            return $existing;
        }

        $code  = '';
        $tries = 0;
        do {
            $code = $this->random_code( self::CODE_LENGTH );
            $taken = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM " . self::codes_table() . " WHERE code = %s LIMIT 1",
                $code
            ) );
            $tries++;
        } while ( $taken && $tries < 20 );

        if ( $taken ) {
            return false;
        }

        $inserted = $wpdb->insert(
            self::codes_table(),
            [
                'user_id'    => $user_id,
                'code'       => $code,
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s' ]
        );

        return $inserted ? $code : false;
    }

    /**
     * Site URL with ?ref={code} for sharing.
     *
     * @param int $user_id
     * @return string
     */
    public function get_referral_link( $user_id ) {
        $code = $this->generate_referral_code( $user_id );
        if ( ! $code ) {
            return home_url( '/' );
        }
        return add_query_arg( 'ref', $code, home_url( '/' ) );
    }

    /**
     * Link a new user to their referrer as a pending referral.
     *
     * @param int    $new_user_id
     * @param string $referral_code
     * @return bool
     */
    public function handle_referral_signup( $new_user_id, $referral_code ) {
        global $wpdb;

        $new_user_id   = absint( $new_user_id );
        $referral_code = strtoupper( sanitize_text_field( $referral_code ) );

        if ( ! $new_user_id || $referral_code === '' ) {
            return false;
        }

        // Already referred once — unique constraint on referred_user_id.
        $already = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . self::referrals_table() . " WHERE referred_user_id = %d LIMIT 1",
            $new_user_id
        ) );
        if ( $already ) {
            return false;
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id, code FROM " . self::codes_table() . " WHERE code = %s LIMIT 1",
            $referral_code
        ) );

        if ( ! $row ) {
            return false;
        }

        $referrer_id = (int) $row->user_id;

        // Cannot refer yourself.
        if ( $referrer_id === $new_user_id ) {
            return false;
        }

        $inserted = $wpdb->insert(
            self::referrals_table(),
            [
                'referrer_user_id'         => $referrer_id,
                'referred_user_id'         => $new_user_id,
                'referral_code'            => $row->code,
                'status'                   => 'pending',
                'xfinity_awarded_referrer' => 0,
                'xfinity_awarded_referred' => 0,
                'confirmed_at'             => null,
                'created_at'               => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s' ]
        );

        return (bool) $inserted;
    }

    /**
     * Confirm a pending referral after email verification or first real engagement.
     * Anti-cheat: matching IPs between referrer and referred → flagged_fake, no awards.
     *
     * @param int $referred_user_id
     * @return bool True if confirmed and awarded (or already confirmed).
     */
    public function confirm_referral( $referred_user_id ) {
        global $wpdb;

        $referred_user_id = absint( $referred_user_id );
        if ( ! $referred_user_id ) {
            return false;
        }

        $table = self::referrals_table();
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE referred_user_id = %d LIMIT 1",
            $referred_user_id
        ) );

        if ( ! $row ) {
            return false;
        }

        if ( $row->status === 'confirmed' ) {
            return true;
        }

        // Already flagged or otherwise non-pending — leave for admin review.
        if ( $row->status !== 'pending' ) {
            return false;
        }

        $referrer_id = (int) $row->referrer_user_id;

        // Anti-cheat: if the referred user's signup IP matches the referrer's last known
        // (or signup) IP, treat as a likely self-referral / farm and do not award Xfinity.
        // Admins can still review rows with status flagged_fake manually.
        $referred_ip = (string) get_user_meta( $referred_user_id, self::META_SIGNUP_IP, true );
        if ( $referred_ip === '' ) {
            $referred_ip = (string) get_user_meta( $referred_user_id, self::META_LAST_IP, true );
        }

        $referrer_ip = (string) get_user_meta( $referrer_id, self::META_LAST_IP, true );
        if ( $referrer_ip === '' ) {
            $referrer_ip = (string) get_user_meta( $referrer_id, self::META_SIGNUP_IP, true );
        }

        if ( $referred_ip !== '' && $referrer_ip !== '' && hash_equals( $referrer_ip, $referred_ip ) ) {
            $wpdb->update(
                $table,
                [ 'status' => 'flagged_fake' ],
                [ 'id' => (int) $row->id ],
                [ '%s' ],
                [ '%d' ]
            );
            return false;
        }

        $reward_referrer = (float) CF_Xfinity::REFERRAL_REWARD_REFERRER;
        $reward_referred = (float) CF_Xfinity::REFERRAL_REWARD_NEW_USER;
        $xfinity         = CF_Xfinity::get_instance();

        $xfinity->add_xfinity( $referrer_id, $reward_referrer, 'referral_referrer', $referred_user_id );
        $xfinity->add_xfinity( $referred_user_id, $reward_referred, 'referral_new_user', $referrer_id );

        $wpdb->update(
            $table,
            [
                'status'                   => 'confirmed',
                'xfinity_awarded_referrer' => $reward_referrer,
                'xfinity_awarded_referred' => $reward_referred,
                'confirmed_at'             => current_time( 'mysql' ),
            ],
            [ 'id' => (int) $row->id ],
            [ '%s', '%f', '%f', '%s' ],
            [ '%d' ]
        );

        if ( class_exists( 'CF_Notifications' ) ) {
            CF_Notifications::create_for_user(
                $referrer_id,
                'referral_confirmed',
                __( 'Referral confirmed', 'cf-auth' ),
                sprintf(
                    /* translators: %s: Xfinity amount awarded */
                    __( 'Your referral is confirmed — +%s Xfinity', 'cf-auth' ),
                    $reward_referrer
                ),
                home_url( '/cf-profile#rewards' )
            );
            CF_Notifications::create_for_user(
                $referred_user_id,
                'referral_welcome',
                __( 'Welcome bonus', 'cf-auth' ),
                sprintf(
                    /* translators: %s: Xfinity amount awarded */
                    __( 'Welcome bonus — +%s Xfinity', 'cf-auth' ),
                    $reward_referred
                ),
                home_url( '/cf-profile#rewards' )
            );
        }

        return true;
    }

    /**
     * Prefer live ?ref=, then the 30-day cookie.
     *
     * @return string Empty if none.
     */
    private function resolve_incoming_referral_code() {
        if ( ! empty( $_GET['ref'] ) ) {
            return strtoupper( sanitize_text_field( wp_unslash( $_GET['ref'] ) ) );
        }
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return strtoupper( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) );
        }
        return '';
    }

    /**
     * @param int $length
     * @return string
     */
    private function random_code( $length ) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // No I/O/0/1 — fewer ambiguous codes.
        $max   = strlen( $chars ) - 1;
        $out   = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $out .= $chars[ random_int( 0, $max ) ];
        }
        return $out;
    }
}
