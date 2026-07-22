<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Daily / weekly Xfinity digest emails via WP-Cron.
 */
class CF_Digests {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_weekly_schedule' ] );
        add_action( 'cf_daily_xfinity_digest', [ $this, 'run_daily_digest' ] );
        add_action( 'cf_weekly_xfinity_digest', [ $this, 'run_weekly_digest' ] );

        // Ensure schedules exist for already-active installs (activation hook may not re-run).
        add_action( 'init', [ __CLASS__, 'maybe_schedule_events' ], 20 );
    }

    /**
     * Schedule digest crons if missing (activation + runtime safety net).
     */
    public static function maybe_schedule_events() {
        // Ensure 'weekly' exists before wp_schedule_event() — activation may run
        // before the cron_schedules filter from the constructor has fired.
        add_filter( 'cron_schedules', [ __CLASS__, 'add_weekly_schedule' ] );

        if ( ! wp_next_scheduled( 'cf_daily_xfinity_digest' ) ) {
            wp_schedule_event( time(), 'daily', 'cf_daily_xfinity_digest' );
        }
        if ( ! wp_next_scheduled( 'cf_weekly_xfinity_digest' ) ) {
            wp_schedule_event( time(), 'weekly', 'cf_weekly_xfinity_digest' );
        }
    }

    /**
     * @param array $schedules
     * @return array
     */
    public static function add_weekly_schedule( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'cf-auth' ),
            ];
        }
        return $schedules;
    }

    /**
     * Clear digest crons (plugin deactivation).
     */
    public static function clear_scheduled_events() {
        wp_clear_scheduled_hook( 'cf_daily_xfinity_digest' );
        wp_clear_scheduled_hook( 'cf_weekly_xfinity_digest' );
    }

    public function run_daily_digest() {
        $users = get_users( [ 'role' => 'cf_listener', 'fields' => 'ID' ] );
        foreach ( $users as $user_id ) {
            CF_Email::send_daily_xfinity_summary( (int) $user_id );
        }
    }

    public function run_weekly_digest() {
        $users = get_users( [ 'role' => 'cf_listener', 'fields' => 'ID' ] );
        foreach ( $users as $user_id ) {
            CF_Email::send_weekly_xfinity_summary( (int) $user_id );
        }
    }
}
