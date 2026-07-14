<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Playlists {

    private static $instance = null;

    private const POST_TYPE_MAP = [
        'track' => 'tracks',
        'album' => 'albums',
    ];

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_cf_create_playlist',            [ $this, 'handle_create_playlist' ] );
        add_action( 'wp_ajax_cf_get_user_playlists',        [ $this, 'handle_get_user_playlists' ] );
        add_action( 'wp_ajax_cf_add_to_playlist',           [ $this, 'handle_add_to_playlist' ] );
        add_action( 'wp_ajax_cf_remove_from_playlist',      [ $this, 'handle_remove_from_playlist' ] );
        add_action( 'wp_ajax_cf_delete_playlist',           [ $this, 'handle_delete_playlist' ] );
        add_action( 'wp_ajax_cf_rename_playlist',           [ $this, 'handle_rename_playlist' ] );
        add_action( 'wp_ajax_cf_toggle_playlist_visibility',[ $this, 'handle_toggle_visibility' ] );
        add_action( 'wp_ajax_cf_get_playlist_items',        [ $this, 'handle_get_playlist_items' ] );
        add_action( 'wp_ajax_cf_get_public_playlist',       [ $this, 'handle_get_public_playlist' ] );
        add_action( 'wp_ajax_nopriv_cf_get_public_playlist',[ $this, 'handle_get_public_playlist' ] );
    }

    // ── AJAX: Create Playlist ─────────────────────────────────────────────────
    public function handle_create_playlist() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $name = $this->validate_playlist_name( $_POST['name'] ?? '' );
        if ( is_wp_error( $name ) ) {
            wp_send_json_error( [ 'message' => $name->get_error_message() ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cf_playlists';
        $token = self::generate_share_token();

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id'     => get_current_user_id(),
                'name'        => $name,
                'is_public'   => 0,
                'share_token' => $token,
            ],
            [ '%d', '%s', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            wp_send_json_error( [ 'message' => __( 'Could not create playlist.', 'cf-auth' ) ] );
        }

        wp_send_json_success( self::format_playlist_row( [
            'id'          => (int) $wpdb->insert_id,
            'name'        => $name,
            'is_public'   => 0,
            'share_token' => $token,
            'item_count'  => 0,
        ] ) );
    }

    // ── AJAX: Get User Playlists ──────────────────────────────────────────────
    public function handle_get_user_playlists() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $item_id   = absint( $_POST['item_id'] ?? 0 );
        $item_type = sanitize_key( $_POST['item_type'] ?? '' );
        $filter    = null;

        if ( $item_id && isset( self::POST_TYPE_MAP[ $item_type ] ) ) {
            $filter = [
                'item_id'   => $item_id,
                'item_type' => $item_type,
            ];
        }

        wp_send_json_success( [
            'playlists' => self::get_user_playlists( get_current_user_id(), $filter ),
        ] );
    }

    // ── AJAX: Add to Playlist ─────────────────────────────────────────────────
    public function handle_add_to_playlist() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $playlist_id = absint( $_POST['playlist_id'] ?? 0 );
        $item_id     = absint( $_POST['item_id'] ?? 0 );
        $item_type   = sanitize_key( $_POST['item_type'] ?? '' );

        $playlist = $this->get_owned_playlist( $playlist_id );
        if ( ! $playlist ) {
            wp_send_json_error( [ 'message' => __( 'Playlist not found.', 'cf-auth' ) ] );
        }

        if ( ! $this->is_valid_item( $item_id, $item_type ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid item.', 'cf-auth' ) ] );
        }

        global $wpdb;
        $items_table = $wpdb->prefix . 'cf_playlist_items';
        $position    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(position), -1) + 1 FROM {$items_table} WHERE playlist_id = %d",
            $playlist_id
        ) );

        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$items_table} (playlist_id, item_id, item_type, position) VALUES (%d, %d, %s, %d)",
            $playlist_id,
            $item_id,
            $item_type,
            $position
        ) );

        wp_send_json_success( [
            'item_count' => self::get_item_count( $playlist_id ),
        ] );
    }

    // ── AJAX: Remove from Playlist ────────────────────────────────────────────
    public function handle_remove_from_playlist() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $playlist_id = absint( $_POST['playlist_id'] ?? 0 );
        $item_id     = absint( $_POST['item_id'] ?? 0 );
        $item_type   = sanitize_key( $_POST['item_type'] ?? '' );

        $playlist = $this->get_owned_playlist( $playlist_id );
        if ( ! $playlist ) {
            wp_send_json_error( [ 'message' => __( 'Playlist not found.', 'cf-auth' ) ] );
        }

        if ( ! isset( self::POST_TYPE_MAP[ $item_type ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid item type.', 'cf-auth' ) ] );
        }

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'cf_playlist_items',
            [
                'playlist_id' => $playlist_id,
                'item_id'     => $item_id,
                'item_type'   => $item_type,
            ],
            [ '%d', '%d', '%s' ]
        );

        wp_send_json_success( [
            'item_count' => self::get_item_count( $playlist_id ),
        ] );
    }

    // ── AJAX: Delete Playlist ─────────────────────────────────────────────────
    public function handle_delete_playlist() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $playlist_id = absint( $_POST['playlist_id'] ?? 0 );
        $playlist    = $this->get_owned_playlist( $playlist_id );
        if ( ! $playlist ) {
            wp_send_json_error( [ 'message' => __( 'Playlist not found.', 'cf-auth' ) ] );
        }

        global $wpdb;
        $items_table     = $wpdb->prefix . 'cf_playlist_items';
        $playlists_table = $wpdb->prefix . 'cf_playlists';

        $wpdb->delete( $items_table, [ 'playlist_id' => $playlist_id ], [ '%d' ] );
        $wpdb->delete( $playlists_table, [ 'id' => $playlist_id ], [ '%d' ] );

        wp_send_json_success( [ 'deleted' => true ] );
    }

    // ── AJAX: Rename Playlist ─────────────────────────────────────────────────
    public function handle_rename_playlist() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $playlist_id = absint( $_POST['playlist_id'] ?? 0 );
        $playlist    = $this->get_owned_playlist( $playlist_id );
        if ( ! $playlist ) {
            wp_send_json_error( [ 'message' => __( 'Playlist not found.', 'cf-auth' ) ] );
        }

        $name = $this->validate_playlist_name( $_POST['name'] ?? '' );
        if ( is_wp_error( $name ) ) {
            wp_send_json_error( [ 'message' => $name->get_error_message() ] );
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cf_playlists',
            [ 'name' => $name ],
            [ 'id' => $playlist_id ],
            [ '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [
            'name' => $name,
        ] );
    }

    // ── AJAX: Toggle Visibility ───────────────────────────────────────────────
    public function handle_toggle_visibility() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $playlist_id = absint( $_POST['playlist_id'] ?? 0 );
        $playlist    = $this->get_owned_playlist( $playlist_id );
        if ( ! $playlist ) {
            wp_send_json_error( [ 'message' => __( 'Playlist not found.', 'cf-auth' ) ] );
        }

        $is_public = absint( $_POST['is_public'] ?? 0 ) ? 1 : 0;

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'cf_playlists',
            [ 'is_public' => $is_public ],
            [ 'id' => $playlist_id ],
            [ '%d' ],
            [ '%d' ]
        );

        wp_send_json_success( [
            'is_public' => $is_public,
            'share_url' => self::get_share_url( $playlist->share_token ),
        ] );
    }

    // ── AJAX: Get Playlist Items (owner only) ─────────────────────────────────
    public function handle_get_playlist_items() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'cf-auth' ) ] );
        }

        $playlist_id = absint( $_POST['playlist_id'] ?? 0 );
        $playlist    = $this->get_owned_playlist( $playlist_id );
        if ( ! $playlist ) {
            wp_send_json_error( [ 'message' => __( 'Playlist not found.', 'cf-auth' ) ] );
        }

        wp_send_json_success( [
            'items' => self::resolve_playlist_items( $playlist_id ),
        ] );
    }

    // ── AJAX: Get Public Playlist ─────────────────────────────────────────────
    public function handle_get_public_playlist() {
        check_ajax_referer( 'cf_auth_nonce', 'nonce' );

        $share_token = sanitize_text_field( $_POST['share_token'] ?? '' );
        if ( ! $share_token ) {
            wp_send_json_error( [ 'message' => __( 'This playlist is private or doesn\'t exist.', 'cf-auth' ) ] );
        }

        $playlist = self::get_playlist_by_token( $share_token );
        if ( ! $playlist || ! self::is_playlist_visible( $playlist ) ) {
            wp_send_json_error( [ 'message' => __( 'This playlist is private or doesn\'t exist.', 'cf-auth' ) ] );
        }

        $owner = get_userdata( (int) $playlist->user_id );

        wp_send_json_success( [
            'name'           => $playlist->name,
            'owner_name'     => $owner ? $owner->display_name : '',
            'is_public'      => (int) $playlist->is_public,
            'is_owner'       => is_user_logged_in() && (int) get_current_user_id() === (int) $playlist->user_id,
            'share_url'      => self::get_share_url( $playlist->share_token ),
            'items'          => self::resolve_playlist_items( (int) $playlist->id ),
        ] );
    }

    // ── Static: User playlists with metadata ──────────────────────────────────
    public static function get_user_playlists( int $user_id, ?array $item_filter = null ): array {
        global $wpdb;
        $table       = $wpdb->prefix . 'cf_playlists';
        $items_table = $wpdb->prefix . 'cf_playlist_items';

        if ( $item_filter && ! empty( $item_filter['item_id'] ) && ! empty( $item_filter['item_type'] ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.*, COUNT(i.id) AS item_count,
                        (SELECT COUNT(*) FROM {$items_table} i2
                         WHERE i2.playlist_id = p.id AND i2.item_id = %d AND i2.item_type = %s) AS contains_item
                 FROM {$table} p
                 LEFT JOIN {$items_table} i ON i.playlist_id = p.id
                 WHERE p.user_id = %d
                 GROUP BY p.id
                 ORDER BY p.created_at DESC",
                (int) $item_filter['item_id'],
                $item_filter['item_type'],
                $user_id
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.*, COUNT(i.id) AS item_count
                 FROM {$table} p
                 LEFT JOIN {$items_table} i ON i.playlist_id = p.id
                 WHERE p.user_id = %d
                 GROUP BY p.id
                 ORDER BY p.created_at DESC",
                $user_id
            ) );
        }

        $playlists = [];
        foreach ( $rows as $row ) {
            $formatted = self::format_playlist_row( $row );
            $formatted['cover'] = self::get_playlist_cover( (int) $row->id );
            $playlists[] = $formatted;
        }

        return $playlists;
    }

    // ── Static: Resolve playlist items to post data ───────────────────────────
    public static function resolve_playlist_items( int $playlist_id ): array {
        global $wpdb;
        $items_table = $wpdb->prefix . 'cf_playlist_items';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_id, item_type FROM {$items_table}
             WHERE playlist_id = %d
             ORDER BY position ASC, added_at ASC",
            $playlist_id
        ) );

        $items = [];
        foreach ( $rows as $row ) {
            $resolved = self::resolve_item( (int) $row->item_id, $row->item_type );
            if ( $resolved ) {
                $items[] = $resolved;
            }
        }

        return $items;
    }

    public static function resolve_item( int $item_id, string $item_type ): ?array {
        if ( ! isset( self::POST_TYPE_MAP[ $item_type ] ) ) {
            return null;
        }

        $post = get_post( $item_id );
        if ( ! $post || $post->post_type !== self::POST_TYPE_MAP[ $item_type ] || $post->post_status !== 'publish' ) {
            return null;
        }

        $resolved = [
            'item_id'   => $item_id,
            'item_type' => $item_type,
            'title'     => $post->post_title,
            'cover'     => CF_Profile::get_release_cover_url( $post, $item_type ),
            'artist'    => self::get_item_artist_name( $post, $item_type ),
            'permalink' => get_permalink( $post ),
        ];

        if ( $item_type === 'track' ) {
            $resolved['file_url'] = get_post_meta( $post->ID, 'track_preview_url', true ) ?: get_post_meta( $post->ID, 'track_audio_url', true ) ?: '';
        }

        return $resolved;
    }

    public static function get_playlist_by_token( string $token ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'cf_playlists';

        $playlist = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE share_token = %s LIMIT 1",
            $token
        ) );

        return $playlist ?: null;
    }

    public static function is_playlist_visible( object $playlist ): bool {
        if ( (int) $playlist->is_public === 1 ) {
            return true;
        }

        return is_user_logged_in() && (int) get_current_user_id() === (int) $playlist->user_id;
    }

    public static function get_share_url( string $token ): string {
        return add_query_arg( 'share', rawurlencode( $token ), home_url( '/cf-playlist/' ) );
    }

    public static function get_item_count( int $playlist_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cf_playlist_items WHERE playlist_id = %d",
            $playlist_id
        ) );
    }

    public static function get_playlist_cover( int $playlist_id ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT item_id, item_type FROM {$wpdb->prefix}cf_playlist_items
             WHERE playlist_id = %d
             ORDER BY position ASC, added_at ASC
             LIMIT 1",
            $playlist_id
        ) );

        if ( ! $row ) {
            return '';
        }

        $resolved = self::resolve_item( (int) $row->item_id, $row->item_type );
        return $resolved ? $resolved['cover'] : '';
    }

    // ── Private helpers ───────────────────────────────────────────────────────
    private function get_owned_playlist( int $playlist_id ): ?object {
        if ( ! $playlist_id ) {
            return null;
        }

        global $wpdb;
        $playlist = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cf_playlists WHERE id = %d LIMIT 1",
            $playlist_id
        ) );

        if ( ! $playlist || (int) $playlist->user_id !== get_current_user_id() ) {
            return null;
        }

        return $playlist;
    }

    private function is_valid_item( int $item_id, string $item_type ): bool {
        if ( ! $item_id || ! isset( self::POST_TYPE_MAP[ $item_type ] ) ) {
            return false;
        }

        $post = get_post( $item_id );
        return $post
            && $post->post_type === self::POST_TYPE_MAP[ $item_type ]
            && $post->post_status === 'publish';
    }

    private function validate_playlist_name( $raw ) {
        $name = sanitize_text_field( $raw );
        $name = trim( $name );

        if ( $name === '' ) {
            return new WP_Error( 'empty_name', __( 'Playlist name is required.', 'cf-auth' ) );
        }

        if ( mb_strlen( $name ) > 190 ) {
            return new WP_Error( 'name_too_long', __( 'Playlist name must be 190 characters or fewer.', 'cf-auth' ) );
        }

        return $name;
    }

    private static function format_playlist_row( $row ): array {
        $data = is_array( $row ) ? $row : (array) $row;

        return [
            'id'             => (int) ( $data['id'] ?? 0 ),
            'name'           => $data['name'] ?? '',
            'is_public'      => (int) ( $data['is_public'] ?? 0 ),
            'share_token'    => $data['share_token'] ?? '',
            'item_count'     => (int) ( $data['item_count'] ?? 0 ),
            'share_url'      => ! empty( $data['share_token'] ) ? self::get_share_url( $data['share_token'] ) : '',
            'contains_item'  => (int) ( $data['contains_item'] ?? 0 ),
        ];
    }

    private static function generate_share_token(): string {
        do {
            $token = substr( md5( uniqid( wp_generate_password( 8, false ), true ) ), 0, 20 );
            $exists = self::get_playlist_by_token( $token );
        } while ( $exists );

        return $token;
    }

    private static function get_item_artist_name( WP_Post $post, string $type ): string {
        if ( $type === 'track' ) {
            $artists = wp_get_post_terms( $post->ID, 'track_artist' );
            if ( ! empty( $artists ) && ! is_wp_error( $artists ) ) {
                return $artists[0]->name;
            }
            return 'Collective Finity';
        }

        if ( function_exists( 'collective_finity_brand_name' ) ) {
            return collective_finity_brand_name();
        }

        return 'Collective Finity';
    }
}
