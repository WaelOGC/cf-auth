<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Migration {

    public static function migrate_legacy_favorites() {
        if ( get_option( 'cf_auth_legacy_favorites_migrated' ) ) {
            return;
        }

        $tracks_users = 0;
        $posts_users  = 0;

        $track_user_ids = get_users( [
            'meta_key' => '_cf_liked_tracks',
            'fields'   => 'ID',
            'number'   => -1,
        ] );

        foreach ( $track_user_ids as $user_id ) {
            $user_id = (int) $user_id;

            $legacy = get_user_meta( $user_id, '_cf_liked_tracks', true );
            $legacy = self::sanitize_id_array( $legacy );
            if ( empty( $legacy ) ) {
                continue;
            }

            $existing = get_user_meta( $user_id, 'cf_favorite_tracks', true );
            if ( ! is_array( $existing ) ) {
                $existing = [];
            }

            $merged = array_values( array_unique( array_merge( $existing, $legacy ) ) );
            update_user_meta( $user_id, 'cf_favorite_tracks', $merged );
            $tracks_users++;
        }

        $post_user_ids = get_users( [
            'meta_key' => '_cf_liked_posts',
            'fields'   => 'ID',
            'number'   => -1,
        ] );

        foreach ( $post_user_ids as $user_id ) {
            $user_id = (int) $user_id;

            $legacy = get_user_meta( $user_id, '_cf_liked_posts', true );
            $legacy = self::sanitize_id_array( $legacy );
            if ( empty( $legacy ) ) {
                continue;
            }

            $existing = get_user_meta( $user_id, 'cf_favorite_posts', true );
            if ( ! is_array( $existing ) ) {
                $existing = [];
            }

            $merged = array_values( array_unique( array_merge( $existing, $legacy ) ) );
            update_user_meta( $user_id, 'cf_favorite_posts', $merged );
            $posts_users++;
        }

        $summary = [
            'tracks_users' => $tracks_users,
            'posts_users'  => $posts_users,
            'date'         => current_time( 'mysql' ),
        ];

        update_option( 'cf_auth_legacy_favorites_migrated', $summary );

        if ( class_exists( 'CF_Activity_Log' ) && method_exists( 'CF_Activity_Log', 'safe_log' ) ) {
            CF_Activity_Log::safe_log( 'legacy_favorites_migrated', [
                'meta' => $summary,
            ] );
        }
    }

    private static function sanitize_id_array( $value ) {
        if ( ! is_array( $value ) ) {
            return [];
        }

        return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
    }
}
