<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Xfinity {

    private static $instance = null;

    /** Xfinity earned per valid listening minute. */
    const LISTENING_RATE_PER_MINUTE = 0.1;

    /** Xfinity earned per valid reading minute. */
    const READING_RATE_PER_MINUTE = 0.05;

    /** Xfinity awarded to the referrer when a referral is confirmed. */
    const REFERRAL_REWARD_REFERRER = 5;

    /** Xfinity awarded to the new user when a referral is confirmed. */
    const REFERRAL_REWARD_NEW_USER = 5;

    const META_BALANCE = 'cf_xfinity_balance';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_cf_get_xfinity_summary', [ $this, 'handle_get_xfinity_summary' ] );
    }

    /**
     * Profile Rewards tab: referral stats + recent ledger for the current user.
     */
    public function handle_get_xfinity_summary() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        global $wpdb;
        $user_id = get_current_user_id();

        $stats = [
            'total'        => 0,
            'confirmed'    => 0,
            'pending'      => 0,
            'under_review' => 0,
        ];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS cnt
             FROM " . CF_Referral::referrals_table() . "
             WHERE referrer_user_id = %d
             GROUP BY status",
            $user_id
        ) );

        foreach ( (array) $rows as $row ) {
            $cnt = (int) $row->cnt;
            $stats['total'] += $cnt;
            if ( $row->status === 'confirmed' ) {
                $stats['confirmed'] += $cnt;
            } elseif ( $row->status === 'pending' ) {
                $stats['pending'] += $cnt;
            } elseif ( $row->status === 'flagged_fake' ) {
                $stats['under_review'] += $cnt;
            }
        }

        $transactions = [];
        foreach ( $this->get_transaction_history( $user_id, 20 ) as $row ) {
            $transactions[] = [
                'id'           => (int) $row->id,
                'amount'       => round( (float) $row->amount, 2 ),
                'source'       => $row->source,
                'source_label' => $this->get_source_label( $row->source ),
                'date'         => date_i18n( get_option( 'date_format' ), strtotime( $row->created_at ) ),
            ];
        }

        wp_send_json_success( [
            'referral_stats' => $stats,
            'transactions'   => $transactions,
        ] );
    }

    /**
     * Human-readable ledger source labels for the Rewards tab.
     *
     * @param string $source
     * @return string
     */
    private function get_source_label( $source ) {
        $labels = [
            'listening'         => __( 'Music Listening', 'cf-auth' ),
            'reading'           => __( 'Article Reading', 'cf-auth' ),
            'referral_referrer' => __( 'Referral Bonus', 'cf-auth' ),
            'referral_new_user' => __( 'Welcome Bonus', 'cf-auth' ),
            'milestone_redeem'  => __( 'Milestone Reward', 'cf-auth' ),
            'admin_adjustment'  => __( 'Adjustment', 'cf-auth' ),
        ];

        return $labels[ $source ] ?? ucwords( str_replace( '_', ' ', (string) $source ) );
    }

    public static function ledger_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_xfinity_ledger';
    }

    public static function milestones_table() {
        global $wpdb;
        return $wpdb->prefix . 'cf_xfinity_milestones';
    }

    /**
     * Append a ledger row, update cached balance, and check milestones.
     *
     * @param int         $user_id
     * @param float       $amount       Positive = earn, negative = redeem.
     * @param string      $source       listening|reading|referral_referrer|referral_new_user|milestone_redeem|admin_adjustment
     * @param int|null    $reference_id Optional related ID (post, referred user, etc.).
     * @return float|false New balance, or false on failure.
     */
    public function add_xfinity( $user_id, $amount, $source, $reference_id = null ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        $amount  = round( (float) $amount, 2 );
        $source  = sanitize_key( $source );
        $allowed = [
            'listening',
            'reading',
            'referral_referrer',
            'referral_new_user',
            'milestone_redeem',
            'admin_adjustment',
        ];

        if ( ! in_array( $source, $allowed, true ) ) {
            return false;
        }

        $balance_before = $this->get_balance( $user_id );
        $balance_after  = round( $balance_before + $amount, 2 );

        $inserted = $wpdb->insert(
            self::ledger_table(),
            [
                'user_id'       => $user_id,
                'amount'        => $amount,
                'source'        => $source,
                'reference_id'  => $reference_id ? absint( $reference_id ) : null,
                'balance_after' => $balance_after,
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%d', '%f', '%s', '%d', '%f', '%s' ]
        );

        if ( ! $inserted ) {
            return false;
        }

        update_user_meta( $user_id, self::META_BALANCE, $balance_after );

        $this->maybe_create_milestones( $user_id, $balance_before, $balance_after );

        return $balance_after;
    }

    /**
     * Cached balance from user_meta, with ledger sum fallback.
     *
     * @param int $user_id
     * @return float
     */
    public function get_balance( $user_id ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return 0.0;
        }

        $cached = get_user_meta( $user_id, self::META_BALANCE, true );
        if ( $cached !== '' && $cached !== false && is_numeric( $cached ) ) {
            return round( (float) $cached, 2 );
        }

        $sum = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM " . self::ledger_table() . " WHERE user_id = %d",
            $user_id
        ) );

        $balance = round( (float) $sum, 2 );
        update_user_meta( $user_id, self::META_BALANCE, $balance );

        return $balance;
    }

    /**
     * Recent ledger rows for a user (newest first).
     *
     * @param int $user_id
     * @param int $limit
     * @return array
     */
    public function get_transaction_history( $user_id, $limit = 20 ) {
        global $wpdb;

        $user_id = absint( $user_id );
        $limit   = max( 1, absint( $limit ) );

        if ( ! $user_id ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, amount, source, reference_id, balance_after, created_at
             FROM " . self::ledger_table() . "
             WHERE user_id = %d
             ORDER BY created_at DESC, id DESC
             LIMIT %d",
            $user_id,
            $limit
        ) );

        return $rows ?: [];
    }

    /**
     * Default milestone thresholds (filterable).
     *
     * @return int[]
     */
    public static function get_milestone_thresholds() {
        $thresholds = [ 1000, 5000, 10000 ];
        /**
         * Filter Xfinity milestone thresholds.
         *
         * @param int[] $thresholds Balance amounts that trigger a pending_review milestone.
         */
        return array_map( 'absint', (array) apply_filters( 'cf_xfinity_milestone_thresholds', $thresholds ) );
    }

    /**
     * If the balance crossed one or more thresholds, queue pending_review milestone rows.
     * Does NOT auto-send rewards — admin reviews and fulfills manually.
     *
     * @param int   $user_id
     * @param float $balance_before
     * @param float $balance_after
     */
    private function maybe_create_milestones( $user_id, $balance_before, $balance_after ) {
        global $wpdb;

        // Redeeming / losing balance should not create milestones.
        if ( $balance_after <= $balance_before ) {
            return;
        }

        $table = self::milestones_table();

        foreach ( self::get_milestone_thresholds() as $threshold ) {
            if ( $threshold <= 0 ) {
                continue;
            }

            // Only fire when this earn crosses the threshold for the first time.
            if ( $balance_before >= $threshold || $balance_after < $threshold ) {
                continue;
            }

            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND milestone_threshold = %d LIMIT 1",
                $user_id,
                $threshold
            ) );

            if ( $exists ) {
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'user_id'             => $user_id,
                    'milestone_threshold' => $threshold,
                    'reward_type'         => 'coupon',
                    'reward_description'  => null,
                    'status'              => 'pending_review',
                    'sent_at'             => null,
                    'created_at'          => current_time( 'mysql' ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
    }
}
