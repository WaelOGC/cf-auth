<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CF_Admin {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_cf_admin_suspend_user',  [ $this, 'ajax_suspend' ] );
        add_action( 'wp_ajax_cf_admin_delete_user',   [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_cf_admin_resend_verify', [ $this, 'ajax_resend' ] );
        add_action( 'wp_ajax_cf_admin_get_user',      [ $this, 'ajax_get_user' ] );
        add_action( 'wp_ajax_cf_admin_update_user',   [ $this, 'ajax_update_user' ] );
        add_action( 'wp_ajax_cf_admin_get_xfinity_stats', [ $this, 'ajax_get_xfinity_stats' ] );
        add_action( 'wp_ajax_cf_save_settings',       [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_cf_toggle_donation_wall', [ $this, 'ajax_toggle_donation_wall' ] );
    }

    public function register_menus() {
        add_menu_page( 'CF Auth', 'CF Auth', 'manage_options', 'cf-auth', [ $this, 'page_overview' ], 'dashicons-groups', 25 );

        /**
         * Filter the CF Auth admin submenu pages registered under the top-level
         * 'cf-auth' menu.
         *
         * External modules (e.g. future course sales, donations) can append
         * entries to add their own submenu pages without editing this file.
         *
         * Each page is an associative array with these keys:
         * - slug         (string, required)   Menu slug passed to add_submenu_page().
         * - page_title   (string, required)   Browser tab / page heading title.
         * - menu_title   (string, required)   Sidebar menu label.
         * - capability   (string, optional)   Defaults to 'manage_options'.
         * - callback     (callable, required) Page render callback.
         *
         * @param array $pages Default submenu page definitions.
         */
        $pages = apply_filters( 'cf_auth_admin_menu_pages', $this->get_default_admin_pages() );

        foreach ( $pages as $page ) {
            if ( empty( $page['slug'] ) || empty( $page['callback'] ) ) {
                continue;
            }
            add_submenu_page(
                'cf-auth',
                $page['page_title'] ?? $page['menu_title'] ?? '',
                $page['menu_title'] ?? $page['page_title'] ?? '',
                $page['capability'] ?? 'manage_options',
                $page['slug'],
                $page['callback']
            );
        }
    }

    /**
     * Default CF Auth admin submenu pages (Overview, Members, Settings, Activity Log).
     *
     * Returned array is passed through the 'cf_auth_admin_menu_pages' filter so
     * external modules can register additional pages under the 'cf-auth' menu.
     * See register_menus() for the expected array shape per entry.
     *
     * @return array[]
     */
    private function get_default_admin_pages() {
        return [
            [
                'slug'         => 'cf-auth',
                'page_title'   => 'Overview',
                'menu_title'   => 'Overview',
                'capability'   => 'manage_options',
                'callback'     => [ $this, 'page_overview' ],
            ],
            [
                'slug'         => 'cf-auth-members',
                'page_title'   => 'Members',
                'menu_title'   => 'Members',
                'capability'   => 'manage_options',
                'callback'     => [ $this, 'page_members' ],
            ],
            [
                'slug'         => 'cf-auth-donations',
                'page_title'   => 'Donations',
                'menu_title'   => 'Donations',
                'capability'   => 'manage_options',
                'callback'     => [ $this, 'page_donations' ],
            ],
            [
                'slug'         => 'cf-auth-settings',
                'page_title'   => 'Settings',
                'menu_title'   => 'Settings',
                'capability'   => 'manage_options',
                'callback'     => [ $this, 'page_settings' ],
            ],
            [
                'slug'         => 'cf-auth-activity',
                'page_title'   => 'Activity Log',
                'menu_title'   => 'Activity Log',
                'capability'   => 'manage_options',
                'callback'     => [ $this, 'page_activity' ],
            ],
        ];
    }

    public function enqueue_assets( $hook ) {
        $screens = [
            'toplevel_page_cf-auth',
            'cf-auth_page_cf-auth-members',
            'cf-auth_page_cf-auth-donations',
            'cf-auth_page_cf-auth-settings',
            'cf-auth_page_cf-auth-activity',
        ];
        if ( ! in_array( $hook, $screens, true ) ) return;

        // Append filemtime so CSS/JS cache bust even when CF_AUTH_VERSION is unchanged between deploys.
        $admin_css_path      = CF_AUTH_DIR . 'assets/css/cf-admin.css';
        $admin_branding_path = CF_AUTH_DIR . 'assets/css/cf-auth-admin-branding.css';
        $admin_js_path       = CF_AUTH_DIR . 'assets/js/cf-admin.js';
        $admin_css_ver       = CF_AUTH_VERSION . '.' . ( file_exists( $admin_css_path ) ? filemtime( $admin_css_path ) : '0' );
        $admin_branding_ver  = CF_AUTH_VERSION . '.' . ( file_exists( $admin_branding_path ) ? filemtime( $admin_branding_path ) : '0' );
        $admin_js_ver        = CF_AUTH_VERSION . '.' . ( file_exists( $admin_js_path ) ? filemtime( $admin_js_path ) : '0' );

        wp_enqueue_style(  'cf-auth-admin',          CF_AUTH_URL . 'assets/css/cf-admin.css',               [], $admin_css_ver );
        wp_enqueue_style(  'cf-auth-admin-branding', CF_AUTH_URL . 'assets/css/cf-auth-admin-branding.css', ['cf-auth-admin'], $admin_branding_ver );

        $script_deps = [ 'jquery' ];
        if ( $hook === 'cf-auth_page_cf-auth-donations' ) {
            wp_enqueue_script(
                'chart-js',
                'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js',
                [],
                '4.4.0',
                true
            );
            $script_deps[] = 'chart-js';

            $chart_data = $this->get_donations_chart_data();
            wp_add_inline_script(
                'chart-js',
                'window.CF_DONATIONS_CHART = ' . wp_json_encode( $chart_data ) . ';',
                'before'
            );
            wp_add_inline_script(
                'chart-js',
                "document.addEventListener('DOMContentLoaded',function(){var el=document.getElementById('cf-donations-chart');if(!el||typeof Chart==='undefined'||!window.CF_DONATIONS_CHART)return;new Chart(el,{type:'line',data:{labels:CF_DONATIONS_CHART.labels,datasets:[{label:'Daily total',data:CF_DONATIONS_CHART.values,borderColor:'#FFB800',backgroundColor:'rgba(255,184,0,0.12)',borderWidth:2,fill:true,tension:0.3,pointRadius:3,pointBackgroundColor:'#FFB800'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v;}}},x:{grid:{display:false}}}}});});",
                'after'
            );
        }

        wp_enqueue_script( 'cf-auth-admin', CF_AUTH_URL . 'assets/js/cf-admin.js', $script_deps, $admin_js_ver, true );
        wp_localize_script( 'cf-auth-admin', 'CF_ADMIN', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cf_admin_nonce' ),
        ]);

        if ( $hook === 'cf-auth_page_cf-auth-donations' ) {
            wp_add_inline_script( 'cf-auth-admin', "
                jQuery(function($) {
                    $(document).on('change', '.cf-donation-wall-toggle', function() {
                        var \$cb = $(this), id = \$cb.data('id'), checked = \$cb.is(':checked');
                        $.post(CF_ADMIN.ajax_url, {
                            action: 'cf_toggle_donation_wall',
                            nonce: CF_ADMIN.nonce,
                            donation_id: id
                        }).done(function(r) {
                            if (!r.success) {
                                \$cb.prop('checked', !checked);
                                var \$m = $('#cf-admin-msg');
                                \$m.removeClass('notice-success').addClass('notice-error')
                                  .find('p').text((r.data && r.data.message) || 'Failed.').end().show();
                            }
                        }).fail(function() {
                            \$cb.prop('checked', !checked);
                            var \$m = $('#cf-admin-msg');
                            \$m.removeClass('notice-success').addClass('notice-error')
                              .find('p').text('Request failed.').end().show();
                        });
                    });
                });
            " );
        }
    }

    // ── Overview ──────────────────────────────────────────────────────────────
    public function page_overview() {
        global $wpdb;

        $total   = count( get_users(['role'=>'cf_listener','fields'=>'ID']) );
        $pending = count( get_users(['role'=>'cf_listener','fields'=>'ID','meta_key'=>'cf_account_status','meta_value'=>'pending']) );
        $susp    = count( get_users(['role'=>'cf_listener','fields'=>'ID','meta_key'=>'cf_account_status','meta_value'=>'suspended']) );
        $active  = $total - $pending - $susp;
        $today   = count( get_users(['role'=>'cf_listener','fields'=>'ID','date_query'=>[['after'=>date('Y-m-d 00:00:00')]]]) );
        $week    = count( get_users(['role'=>'cf_listener','fields'=>'ID','date_query'=>[['after'=>date('Y-m-d',strtotime('-7 days'))]]]) );

        $providers = $wpdb->get_results("SELECT provider, COUNT(*) as cnt FROM {$wpdb->prefix}cf_social_connections GROUP BY provider ORDER BY cnt DESC");

        $recent = get_users(['role'=>'cf_listener','number'=>8,'orderby'=>'registered','order'=>'DESC']);
        ?>
        <div class="cf-admin-wrap">
            <div class="cf-admin-header">
                <h1>⚡ CF Auth <span>v<?php echo CF_AUTH_VERSION; ?></span></h1>
                <p>Collective Finity — Member Management Dashboard</p>
            </div>

            <!-- Stats -->
            <div class="cf-metric-grid">
                <div class="cf-metric-card">
                    <div class="cf-metric-label">Total Members</div>
                    <div class="cf-metric-value"><?php echo $total; ?></div>
                </div>
                <div class="cf-metric-card">
                    <div class="cf-metric-label">Active</div>
                    <div class="cf-metric-value"><?php echo $active; ?></div>
                </div>
                <div class="cf-metric-card">
                    <div class="cf-metric-label">Pending Verify</div>
                    <div class="cf-metric-value"><?php echo $pending; ?></div>
                </div>
                <div class="cf-metric-card">
                    <div class="cf-metric-label">Joined Today</div>
                    <div class="cf-metric-value"><?php echo $today; ?></div>
                </div>
                <div class="cf-metric-card">
                    <div class="cf-metric-label">This Week</div>
                    <div class="cf-metric-value"><?php echo $week; ?></div>
                </div>
                <div class="cf-metric-card">
                    <div class="cf-metric-label">Suspended</div>
                    <div class="cf-metric-value"><?php echo $susp; ?></div>
                </div>
            </div>

            <div class="cf-admin-cols">
                <!-- Recent Members -->
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons dashicons-groups"></span>
                        <h2>Recent Members</h2>
                        <a href="<?php echo admin_url('admin.php?page=cf-auth-members'); ?>" class="cf-box-link">View All →</a>
                    </div>
                    <div class="cf-list-rows">
                        <?php foreach ($recent as $idx => $u):
                            $status   = get_user_meta($u->ID,'cf_account_status',true) ?: 'active';
                            $provider = get_user_meta($u->ID,'cf_social_provider',true) ?: 'manual';
                            $initials = $this->get_member_initials( $u->display_name ?: $u->user_email );
                            $avatar_i = $idx % 4;
                        ?>
                        <div class="cf-list-row">
                            <div class="cf-avatar-circle cf-avatar-<?php echo (int) $avatar_i; ?>"><?php echo esc_html( $initials ); ?></div>
                            <div class="cf-list-row-body">
                                <div class="cf-tbl-name"><?php echo esc_html($u->display_name); ?></div>
                                <div class="cf-tbl-email"><?php echo esc_html($u->user_email); ?></div>
                            </div>
                            <span class="cf-pill"><?php echo esc_html(ucfirst($provider)); ?></span>
                            <span class="cf-status cf-status-<?php echo esc_attr($status); ?>"><?php echo ucfirst($status); ?></span>
                            <span class="cf-list-row-meta"><?php echo date_i18n(get_option('date_format'), strtotime($u->user_registered)); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Provider Breakdown -->
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons dashicons-admin-network"></span>
                        <h2>Auth Providers</h2>
                    </div>
                    <?php if ($providers): foreach($providers as $p): ?>
                    <div class="cf-provider-row">
                        <span class="cf-provider-name"><?php echo esc_html(ucfirst($p->provider)); ?></span>
                        <span class="cf-provider-bar-wrap">
                            <span class="cf-provider-bar" style="width:<?php echo $total ? round(($p->cnt/$total)*100) : 0; ?>%"></span>
                        </span>
                        <span class="cf-provider-cnt"><?php echo (int)$p->cnt; ?></span>
                    </div>
                    <?php endforeach; else: ?>
                    <p class="cf-empty">No social connections yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Members ───────────────────────────────────────────────────────────────
    public function page_members() {
        $search   = sanitize_text_field( $_GET['s']        ?? '' );
        $status   = sanitize_key(        $_GET['status']   ?? '' );
        $provider = sanitize_key(        $_GET['provider'] ?? '' );
        $paged    = max(1, absint(       $_GET['paged']    ?? 1 ));
        $per      = 20;

        $args = ['role'=>'cf_listener','number'=>$per,'offset'=>($paged-1)*$per,'orderby'=>'registered','order'=>'DESC'];
        if ($search)   { $args['search'] = '*'.$search.'*'; $args['search_columns'] = ['user_login','user_email','display_name']; }
        if ($status)   { $args['meta_key']='cf_account_status'; $args['meta_value']=$status; }
        if ($provider) { $args['meta_key']='cf_social_provider'; $args['meta_value']=$provider; }

        $users = get_users($args);
        $count_args = array_merge($args,['number'=>-1,'offset'=>0]);
        $total = count(get_users($count_args));
        $pages = ceil($total/$per);
        ?>
        <div class="cf-admin-wrap">
            <div class="cf-admin-header">
                <h1>👥 Members <span><?php echo $total; ?> total</span></h1>
            </div>

            <!-- Filters -->
            <form method="get" class="cf-filters">
                <input type="hidden" name="page" value="cf-auth-members">
                <div class="cf-filter-group">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search name or email...">
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach(['active'=>'Active','pending'=>'Pending','suspended'=>'Suspended'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php selected($status,$v); ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="provider">
                        <option value="">All Providers</option>
                        <?php foreach(['manual'=>'Manual','google'=>'Google','facebook'=>'Facebook','discord'=>'Discord','twitter'=>'X / Twitter'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php selected($provider,$v); ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">Filter</button>
                    <?php if($search||$status||$provider): ?>
                    <a href="<?php echo admin_url('admin.php?page=cf-auth-members'); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <div id="cf-admin-msg" class="notice" style="display:none;margin:12px 0"><p></p></div>

            <!-- Table -->
            <div class="cf-card cf-card-flush">
                <table class="cf-table">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Provider</th>
                            <th>Verified</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                    <tr><td colspan="6" class="cf-empty">No members found.</td></tr>
                    <?php else: foreach($users as $u):
                        $u_status   = get_user_meta($u->ID,'cf_account_status',true) ?: 'active';
                        $u_provider = get_user_meta($u->ID,'cf_social_provider',true) ?: 'manual';
                        $u_verified = get_user_meta($u->ID,'cf_email_verified',true);
                        $u_avatar   = CF_Profile::get_avatar_url($u->ID);
                    ?>
                    <tr id="cf-row-<?php echo $u->ID; ?>">
                        <td>
                            <div class="cf-member-cell">
                                <img src="<?php echo esc_url($u_avatar); ?>" class="cf-tbl-avatar" onerror="this.src='<?php echo CF_AUTH_URL; ?>assets/img/default-avatar.svg'">
                                <div>
                                    <div class="cf-tbl-name"><?php echo esc_html($u->display_name); ?></div>
                                    <div class="cf-tbl-email"><?php echo esc_html($u->user_email); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="cf-pill"><?php echo esc_html(ucfirst($u_provider)); ?></span></td>
                        <td>
                            <?php if ($u_verified === '1'): ?>
                                <span class="cf-verified">✓ Yes</span>
                            <?php else: ?>
                                <span class="cf-unverified">✗ No</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="cf-status cf-status-<?php echo esc_attr($u_status); ?>"><?php echo ucfirst($u_status); ?></span></td>
                        <td class="cf-tbl-date"><?php echo date_i18n(get_option('date_format'),strtotime($u->user_registered)); ?></td>
                        <td>
                            <div class="cf-action-btns">
                                <button class="cf-action-btn cf-xfinity-stats" data-id="<?php echo $u->ID; ?>" title="Xfinity stats">💎</button>
                                <button class="cf-action-btn cf-edit-user" data-id="<?php echo $u->ID; ?>" title="Edit">✏️</button>
                                <?php if($u_status==='suspended'): ?>
                                <button class="cf-action-btn cf-suspend-user" data-id="<?php echo $u->ID; ?>" data-action="activate" title="Activate">▶️</button>
                                <?php else: ?>
                                <button class="cf-action-btn cf-suspend-user" data-id="<?php echo $u->ID; ?>" data-action="suspend" title="Suspend">⏸️</button>
                                <?php endif; ?>
                                <?php if($u_verified!=='1'): ?>
                                <button class="cf-action-btn cf-resend-verify" data-id="<?php echo $u->ID; ?>" title="Resend verification">📧</button>
                                <?php endif; ?>
                                <button class="cf-action-btn cf-delete-user cf-danger-btn" data-id="<?php echo $u->ID; ?>" title="Delete">🗑️</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($pages > 1): ?>
                <div class="cf-pagination">
                    <?php for ($i=1;$i<=$pages;$i++): ?>
                    <a href="<?php echo add_query_arg('paged',$i); ?>" class="cf-page-btn <?php echo $i===$paged?'active':''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit Member Modal -->
        <div id="cf-edit-modal" class="cf-modal" style="display:none">
            <div class="cf-modal-backdrop"></div>
            <div class="cf-modal-box">
                <div class="cf-modal-head">
                    <h3>Edit Member</h3>
                    <button class="cf-modal-close">✕</button>
                </div>
                <div class="cf-modal-body">
                    <div id="cf-edit-loading" style="text-align:center;padding:40px;color:#888">Loading...</div>
                    <form id="cf-edit-form" style="display:none">
                        <input type="hidden" name="user_id">
                        <div id="cf-edit-msg" class="notice" style="display:none;margin-bottom:12px"><p></p></div>
                        <div class="cf-modal-avatar-wrap">
                            <img id="cf-edit-avatar" src="" style="width:60px;height:60px;border-radius:50%;object-fit:cover;border:2px solid #FFB800">
                            <div>
                                <div id="cf-edit-provider-badge" class="cf-pill" style="margin-bottom:4px"></div>
                                <div id="cf-edit-joined" style="font-size:12px;color:#888"></div>
                            </div>
                        </div>
                        <table class="form-table cf-edit-table">
                            <tr><th>Display Name</th><td><input type="text" name="display_name" class="regular-text"></td></tr>
                            <tr><th>Email</th><td><input type="email" name="email" class="regular-text"></td></tr>
                            <tr><th>Bio</th><td><textarea name="bio" rows="3" class="regular-text"></textarea></td></tr>
                            <tr><th>Status</th>
                                <td><select name="account_status">
                                    <option value="active">Active</option>
                                    <option value="pending">Pending</option>
                                    <option value="suspended">Suspended</option>
                                </select></td>
                            </tr>
                            <tr><th>Email Verified</th>
                                <td><label><input type="checkbox" name="email_verified" value="1"> Mark as verified</label></td>
                            </tr>
                        </table>
                        <div class="cf-modal-foot">
                            <button type="submit" class="button button-primary">Save Changes</button>
                            <button type="button" class="button cf-modal-close">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Xfinity Stats Modal -->
        <div id="cf-xfinity-stats-modal" class="cf-modal" style="display:none">
            <div class="cf-modal-backdrop"></div>
            <div class="cf-modal-box">
                <div class="cf-modal-head">
                    <h3>Xfinity Stats — <span id="cf-xstats-name"></span></h3>
                    <button class="cf-modal-close">✕</button>
                </div>
                <div class="cf-modal-body">
                    <div id="cf-xstats-loading" style="text-align:center;padding:40px;color:#888">Loading...</div>
                    <div id="cf-xstats-content" style="display:none">
                        <div class="cf-xstats-summary">
                            <div class="cf-xstats-metric">
                                <span class="cf-xstats-num" id="cf-xstats-balance">0</span>
                                <span class="cf-xstats-lbl">Current Balance</span>
                            </div>
                            <div class="cf-xstats-metric">
                                <span class="cf-xstats-num" id="cf-xstats-total">0</span>
                                <span class="cf-xstats-lbl">Total Earned</span>
                            </div>
                            <div class="cf-xstats-metric">
                                <span class="cf-xstats-num" id="cf-xstats-mins">0</span>
                                <span class="cf-xstats-lbl">Minutes Listened</span>
                            </div>
                        </div>
                        <div class="cf-xstats-referrals">
                            <h4>Referrals</h4>
                            <p>
                                <span id="cf-xstats-ref-total">0</span> total ·
                                <span id="cf-xstats-ref-confirmed">0</span> confirmed ·
                                <span id="cf-xstats-ref-pending">0</span> pending
                                (+<span id="cf-xstats-ref-xfinity">0</span> Xfinity earned from referrals)
                            </p>
                        </div>
                        <div class="cf-xstats-recent">
                            <h4>Last 7 Days</h4>
                            <div id="cf-xstats-recent-list"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Donations ─────────────────────────────────────────────────────────────
    public function page_donations() {
        global $wpdb;

        $search = sanitize_text_field( $_GET['s']      ?? '' );
        $status = sanitize_key(        $_GET['status'] ?? '' );
        $paged  = max( 1, absint(      $_GET['paged']  ?? 1 ) );
        $per    = 20;

        $table  = $wpdb->prefix . 'cf_donations';
        $where  = [ '1=1' ];
        $params = [];

        if ( $status ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        if ( $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(donor_name LIKE %s OR message LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total     = (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : $wpdb->get_var( $count_sql )
        );

        $offset    = ( $paged - 1 ) * $per;
        $query_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge( $params, [ $per, $offset ] );
        $rows      = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ) );
        $pages     = ceil( $total / $per );

        $currency_symbols = [ 'EUR' => '€', 'USD' => '$', 'GBP' => '£' ];
        $default_currency = strtoupper( get_option( 'cf_auth_donation_currency', 'EUR' ) );
        $default_symbol   = $currency_symbols[ $default_currency ] ?? ( $default_currency . ' ' );

        $stats_row = $wpdb->get_row(
            "SELECT COUNT(*) AS completed_count, COALESCE(SUM(amount), 0) AS total_raised, COALESCE(AVG(amount), 0) AS avg_amount FROM {$table} WHERE status = 'completed'"
        );
        $completed_count = (int) ( $stats_row->completed_count ?? 0 );
        $total_raised    = (float) ( $stats_row->total_raised ?? 0 );
        $avg_amount      = (float) ( $stats_row->avg_amount ?? 0 );
        $total_raised_fmt = $default_symbol . number_format( $total_raised, 2, '.', '' );
        $avg_amount_fmt   = $default_symbol . number_format( $avg_amount, 2, '.', '' );
        ?>
        <div class="cf-admin-wrap">
            <div class="cf-admin-header">
                <h1>💝 Donations <span><?php echo (int) $total; ?> total</span></h1>
            </div>

            <div class="cf-metric-grid">
                <div class="cf-metric-card">
                    <div class="cf-metric-label">Total raised</div>
                    <div class="cf-metric-value"><?php echo esc_html( $total_raised_fmt ); ?></div>
                </div>
                <div class="cf-metric-card">
                    <div class="cf-metric-label">Completed</div>
                    <div class="cf-metric-value"><?php echo (int) $completed_count; ?></div>
                </div>
                <div class="cf-metric-card">
                    <div class="cf-metric-label">Avg. donation</div>
                    <div class="cf-metric-value"><?php echo esc_html( $avg_amount_fmt ); ?></div>
                </div>
            </div>

            <div class="cf-card">
                <div class="cf-card-header">
                    <span class="dashicons dashicons-chart-line"></span>
                    <h2>Donations over time</h2>
                </div>
                <div class="cf-chart-wrap">
                    <canvas id="cf-donations-chart"></canvas>
                </div>
            </div>

            <form method="get" class="cf-filters">
                <input type="hidden" name="page" value="cf-auth-donations">
                <div class="cf-filter-group">
                    <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search donor or message...">
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ( [ 'completed' => 'Completed', 'pending' => 'Pending' ] as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $status, $v ); ?>><?php echo esc_html( $l ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">Filter</button>
                    <?php if ( $search || $status ) : ?>
                    <a href="<?php echo admin_url( 'admin.php?page=cf-auth-donations' ); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <div id="cf-admin-msg" class="notice" style="display:none;margin:12px 0"><p></p></div>

            <div class="cf-card cf-card-flush">
                <table class="cf-table">
                    <thead>
                        <tr>
                            <th>Donor</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Message</th>
                            <th>Date</th>
                            <th>Show on Wall</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6" class="cf-empty">No donations found.</td></tr>
                    <?php else : foreach ( $rows as $row ) :
                        $donor_label = $row->donor_name ? $row->donor_name : 'Anonymous';
                        $cur_upper   = strtoupper( $row->currency );
                        $symbol      = $currency_symbols[ $cur_upper ] ?? ( $cur_upper . ' ' );
                        $amount_fmt  = $symbol . number_format( (float) $row->amount, 2, '.', '' );
                        $msg_full    = $row->message ?: '';
                        $msg_display = $msg_full;
                        if ( strlen( $msg_display ) > 60 ) {
                            $msg_display = substr( $msg_display, 0, 60 ) . '...';
                        }
                        $badge_class = $row->status === 'completed' ? 'cf-badge cf-badge-success' : 'cf-badge cf-badge-warning';
                    ?>
                    <tr id="cf-donation-row-<?php echo (int) $row->id; ?>">
                        <td><div class="cf-tbl-name"><?php echo esc_html( $donor_label ); ?></div></td>
                        <td><?php echo esc_html( $amount_fmt ); ?></td>
                        <td><span class="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( ucfirst( $row->status ) ); ?></span></td>
                        <td class="cf-tbl-message"<?php echo $msg_full ? ' title="' . esc_attr( $msg_full ) . '"' : ''; ?>><?php echo $msg_full ? esc_html( $msg_display ) : '—'; ?></td>
                        <td class="cf-tbl-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->created_at ) ); ?></td>
                        <td>
                            <label>
                                <input type="checkbox" class="cf-donation-wall-toggle" data-id="<?php echo (int) $row->id; ?>" <?php checked( (int) $row->show_on_wall, 1 ); ?>>
                            </label>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                <div class="cf-pagination">
                    <?php for ( $i = 1; $i <= $pages; $i++ ) :
                        $url = add_query_arg( array_merge(
                            [ 'page' => 'cf-auth-donations', 'paged' => $i ],
                            array_filter( [
                                'status' => $status,
                                's'      => $search,
                            ] )
                        ), admin_url( 'admin.php' ) );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="cf-page-btn <?php echo $i === $paged ? 'active' : ''; ?>"><?php echo (int) $i; ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ── Settings ──────────────────────────────────────────────────────────────
    public function page_settings() {
        $tab = sanitize_key($_GET['tab'] ?? 'general');
        $tabs = ['general'=>'General','social'=>'Social Auth','paypal'=>'PayPal Donations','email'=>'Email','smtp'=>'SMTP Guide'];
        ?>
        <div class="cf-admin-wrap">
            <div class="cf-admin-header">
                <h1>⚙️ Settings</h1>
            </div>
            <div id="cf-settings-msg" class="notice" style="display:none;margin-bottom:16px"><p></p></div>

            <nav class="cf-tab-nav">
                <?php foreach($tabs as $slug=>$label): ?>
                <a href="<?php echo add_query_arg('tab',$slug); ?>" class="cf-tab-link <?php echo $tab===$slug?'active':''; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($tab === 'smtp'): ?>
            <!-- SMTP Guide (no form) -->
            <div class="cf-card">
                <div class="cf-card-header">
                    <span class="dashicons dashicons-email-alt"></span>
                    <h2>SMTP Setup Guide — Fix Email Delivery</h2>
                </div>
                <div class="cf-smtp-guide">
                    <div class="cf-guide-step">
                        <div class="cf-guide-num">1</div>
                        <div>
                            <h4>Install FluentSMTP (Free)</h4>
                            <p>Go to <strong>WordPress → Plugins → Add New</strong>, search for <strong>"FluentSMTP"</strong> and install it.</p>
                            <a href="<?php echo admin_url('plugin-install.php?s=fluentsmtp&tab=search&type=term'); ?>" class="button button-primary">Install FluentSMTP →</a>
                        </div>
                    </div>
                    <div class="cf-guide-step">
                        <div class="cf-guide-num">2</div>
                        <div>
                            <h4>Configure with Hostinger Email</h4>
                            <p>In FluentSMTP settings, choose <strong>"Other SMTP"</strong> and use:</p>
                            <table class="cf-smtp-table">
                                <tr><td>From Email</td><td><code>contact@collectivefinity.com</code></td></tr>
                                <tr><td>SMTP Host</td><td><code>smtp.hostinger.com</code></td></tr>
                                <tr><td>SMTP Port</td><td><code>587</code> (TLS) or <code>465</code> (SSL)</td></tr>
                                <tr><td>Username</td><td><code>contact@collectivefinity.com</code></td></tr>
                                <tr><td>Password</td><td>Your Hostinger email password</td></tr>
                            </table>
                        </div>
                    </div>
                    <div class="cf-guide-step">
                        <div class="cf-guide-num">3</div>
                        <div>
                            <h4>Send a Test Email</h4>
                            <p>Use FluentSMTP's built-in test to confirm emails are working before members register.</p>
                        </div>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <form id="cf-settings-form" class="cf-settings-cards">
                <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">

                <?php if ($tab === 'general'): ?>
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons dashicons-admin-links"></span>
                        <h2>Redirects and verification</h2>
                    </div>
                    <div class="cf-field-group">
                        <label>After Login Redirect</label>
                        <input type="url" name="cf_auth_login_redirect" value="<?php echo esc_attr(get_option('cf_auth_login_redirect')); ?>" class="regular-text">
                        <p class="description">Where to send users after successful login.</p>
                    </div>
                    <div class="cf-field-group">
                        <label>After Logout Redirect</label>
                        <input type="url" name="cf_auth_logout_redirect" value="<?php echo esc_attr(get_option('cf_auth_logout_redirect')); ?>" class="regular-text">
                    </div>
                    <div class="cf-field-group">
                        <label>After Registration</label>
                        <input type="url" name="cf_auth_after_register" value="<?php echo esc_attr(get_option('cf_auth_after_register')); ?>" class="regular-text">
                    </div>
                    <div class="cf-field-group">
                        <label><input type="checkbox" name="cf_auth_email_verification" value="1" <?php checked(get_option('cf_auth_email_verification'),'1'); ?>>
                        Require email verification before users can log in</label>
                    </div>
                </div>

                <?php elseif ($tab === 'social'): ?>
                <div class="cf-callback-info">
                    <strong>📋 Callback URLs — add these in each developer console:</strong>
                    <div class="cf-callback-urls">
                        <?php foreach(['google','facebook','discord','twitter'] as $p): ?>
                        <div class="cf-callback-row">
                            <span><?php echo ucfirst($p==='twitter'?'X/Twitter':$p); ?></span>
                            <code><?php echo esc_url(home_url('/cf-login?cf_oauth='.$p)); ?></code>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
                $providers = [
                    'Google OAuth'      => [ 'icon' => 'dashicons-google', 'enabled' => 'cf_auth_google_enabled', 'fields' => [
                        ['cf_auth_google_client_id','Client ID','text'],
                        ['cf_auth_google_client_secret','Client Secret','password'],
                    ]],
                    'Facebook App'      => [ 'icon' => 'dashicons-facebook-alt', 'enabled' => 'cf_auth_facebook_enabled', 'fields' => [
                        ['cf_auth_facebook_app_id','App ID','text'],
                        ['cf_auth_facebook_app_secret','App Secret','password'],
                    ]],
                    'Discord OAuth'     => [ 'icon' => 'dashicons-format-chat', 'enabled' => 'cf_auth_discord_enabled', 'fields' => [
                        ['cf_auth_discord_client_id','Client ID','text'],
                        ['cf_auth_discord_client_secret','Client Secret','password'],
                    ]],
                    'X / Twitter OAuth' => [ 'icon' => 'dashicons-twitter', 'enabled' => 'cf_auth_twitter_enabled', 'fields' => [
                        ['cf_auth_twitter_api_key','API Key (Client ID)','text'],
                        ['cf_auth_twitter_api_secret','API Secret','password'],
                    ]],
                ];
                foreach ( $providers as $title => $config ) : ?>
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons <?php echo esc_attr( $config['icon'] ); ?>"></span>
                        <h2><?php echo esc_html( $title ); ?></h2>
                        <label class="cf-toggle-switch" title="<?php esc_attr_e( 'Show this login option to users', 'cf-auth' ); ?>">
                            <input type="checkbox" name="<?php echo esc_attr( $config['enabled'] ); ?>" value="1" <?php checked( get_option( $config['enabled'], '1' ), '1' ); ?>>
                            <span class="cf-toggle-slider" aria-hidden="true"></span>
                            <span class="cf-toggle-label-wrap">
                                <span class="cf-toggle-label"><?php esc_html_e( 'Enabled', 'cf-auth' ); ?></span>
                                <span class="cf-toggle-hint"><?php esc_html_e( 'Show this login option to users', 'cf-auth' ); ?></span>
                            </span>
                        </label>
                    </div>
                    <?php foreach ( $config['fields'] as [ $opt, $label, $type ] ) : ?>
                    <div class="cf-field-group">
                        <label><?php echo esc_html( $label ); ?></label>
                        <input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $opt ); ?>" value="<?php echo esc_attr( get_option( $opt ) ); ?>" class="regular-text">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach;

                elseif ($tab === 'paypal'): ?>
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons dashicons-money-alt"></span>
                        <h2>Mode and currency</h2>
                    </div>
                    <div class="cf-field-group">
                        <label>PayPal Mode</label>
                        <div class="cf-mode-toggle">
                            <label class="cf-mode-option">
                                <input type="radio" name="cf_auth_paypal_mode" value="sandbox" <?php checked( get_option( 'cf_auth_paypal_mode', 'sandbox' ), 'sandbox' ); ?>>
                                Sandbox (Testing)
                            </label>
                            <label class="cf-mode-option">
                                <input type="radio" name="cf_auth_paypal_mode" value="live" <?php checked( get_option( 'cf_auth_paypal_mode', 'sandbox' ), 'live' ); ?>>
                                Live (Real Payments)
                            </label>
                        </div>
                    </div>
                    <div class="cf-field-group">
                        <label>Donation Currency</label>
                        <input type="text" name="cf_auth_donation_currency" value="<?php echo esc_attr( get_option( 'cf_auth_donation_currency', 'EUR' ) ); ?>" class="small-text" maxlength="3">
                        <p class="description">3-letter ISO currency code (e.g. EUR, USD).</p>
                    </div>
                </div>
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons dashicons-flag"></span>
                        <h2>Sandbox credentials</h2>
                        <span class="cf-badge cf-badge-warning">Testing</span>
                    </div>
                    <div class="cf-field-group">
                        <label>Sandbox Client ID</label>
                        <input type="text" name="cf_auth_paypal_sandbox_client_id" value="<?php echo esc_attr( get_option( 'cf_auth_paypal_sandbox_client_id' ) ); ?>" class="regular-text">
                    </div>
                    <div class="cf-field-group">
                        <label>Sandbox Client Secret</label>
                        <input type="password" name="cf_auth_paypal_sandbox_client_secret" value="<?php echo esc_attr( get_option( 'cf_auth_paypal_sandbox_client_secret' ) ); ?>" class="regular-text">
                    </div>
                </div>
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <h2>Live credentials</h2>
                        <span class="cf-badge cf-badge-success">Real payments</span>
                    </div>
                    <div class="cf-field-group">
                        <label>Live Client ID</label>
                        <input type="text" name="cf_auth_paypal_live_client_id" value="<?php echo esc_attr( get_option( 'cf_auth_paypal_live_client_id' ) ); ?>" class="regular-text">
                    </div>
                    <div class="cf-field-group">
                        <label>Live Client Secret</label>
                        <input type="password" name="cf_auth_paypal_live_client_secret" value="<?php echo esc_attr( get_option( 'cf_auth_paypal_live_client_secret' ) ); ?>" class="regular-text">
                    </div>
                </div>
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons dashicons-rest-api"></span>
                        <h2>Webhook</h2>
                    </div>
                    <div class="cf-field-group">
                        <label>Webhook ID</label>
                        <input type="text" name="cf_auth_paypal_webhook_id" value="<?php echo esc_attr( get_option( 'cf_auth_paypal_webhook_id' ) ); ?>" class="regular-text">
                        <p class="description">You'll get this ID in Phase 2 when we register the webhook — leave empty for now.</p>
                    </div>
                </div>

                <?php elseif ($tab === 'email'): ?>
                <div class="cf-card">
                    <div class="cf-card-header">
                        <span class="dashicons dashicons-email"></span>
                        <h2>Email Settings</h2>
                    </div>
                    <p class="description" style="margin:0 0 16px">CF Auth sends emails via WordPress <code>wp_mail()</code>. Configure SMTP in the <a href="<?php echo add_query_arg('tab','smtp'); ?>">SMTP Guide tab</a> for reliable delivery.</p>
                    <div class="cf-field-group">
                        <label>From Name</label>
                        <input type="text" name="cf_auth_email_from_name" value="<?php echo esc_attr(get_option('cf_auth_email_from_name','Collective Finity')); ?>" class="regular-text">
                    </div>
                    <div class="cf-field-group">
                        <label>From Email</label>
                        <input type="email" name="cf_auth_email_from" value="<?php echo esc_attr(get_option('cf_auth_email_from','contact@collectivefinity.com')); ?>" class="regular-text">
                    </div>
                </div>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="cf-save-btn">Save Settings</button>
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Activity Log ──────────────────────────────────────────────────────────
    public function page_activity() {
        $view = in_array( $_GET['view'] ?? 'events', [ 'events', 'sessions' ], true ) ? $_GET['view'] : 'events';

        if ( $view === 'sessions' ) {
            $sessions = CF_Engagement_Tracker::get_active_sessions_today();
            $live_now = count( array_filter( $sessions, fn( $s ) => $s['is_currently_active'] ) );
            ?>
            <div class="cf-admin-wrap">
                <div class="cf-subtabs">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=cf-auth-activity&view=events' ) ); ?>"
                       class="cf-subtab">Event Log</a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=cf-auth-activity&view=sessions' ) ); ?>"
                       class="cf-subtab active">Active Sessions Today</a>
                </div>

                <div id="cf-activity-tab-sessions">
                    <div class="cf-admin-header" style="margin-top:0">
                        <h1>🟢 Active Sessions Today <span><?php echo count( $sessions ); ?> members · <?php echo (int) $live_now; ?> live now</span></h1>
                    </div>
                    <div class="cf-card cf-card-flush">
                        <table class="cf-table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Status</th>
                                    <th>Sessions Today</th>
                                    <th>Minutes Today</th>
                                    <th>Xfinity Today</th>
                                    <th>Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ( empty( $sessions ) ) : ?>
                            <tr><td colspan="6" class="cf-empty">No listening activity yet today.</td></tr>
                            <?php else : foreach ( $sessions as $s ) : ?>
                            <tr>
                                <td>
                                    <div class="cf-tbl-name"><?php echo esc_html( $s['display_name'] ); ?></div>
                                    <div class="cf-tbl-email"><?php echo esc_html( $s['email'] ); ?></div>
                                </td>
                                <td>
                                    <?php if ( $s['is_currently_active'] ) : ?>
                                    <span class="cf-badge cf-badge-success">🟢 Live now</span>
                                    <?php else : ?>
                                    <span class="cf-badge cf-badge-neutral">Idle</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int) $s['sessions_count']; ?></td>
                                <td><?php echo (int) $s['total_minutes']; ?></td>
                                <td><?php echo esc_html( number_format( $s['xfinity_today'], 2 ) ); ?></td>
                                <td class="cf-tbl-date"><?php echo esc_html( human_time_diff( strtotime( $s['last_seen'] ), current_time( 'timestamp' ) ) . ' ago' ); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        $event_type = sanitize_key( $_GET['event_type'] ?? '' );
        $date_from  = sanitize_text_field( $_GET['date_from'] ?? '' );
        $date_to    = sanitize_text_field( $_GET['date_to'] ?? '' );
        $search     = sanitize_text_field( $_GET['s'] ?? '' );
        $paged      = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $per        = 20;

        $filters = array_filter( [
            'event_type' => $event_type,
            'date_from'  => $date_from,
            'date_to'    => $date_to,
            'search'     => $search,
        ] );

        $result = CF_Activity_Log::get_entries( $filters, $per, $paged );
        $rows   = $result['rows'];
        $total  = $result['total'];
        $pages  = ceil( $total / $per );

        $event_types = [
            ''               => 'All Events',
            'login_success'  => 'Login Success',
            'login_failed'   => 'Login Failed',
            'registered'     => 'Registered',
            'logout'         => 'Logout',
            'status_changed' => 'Status Changed',
        ];
        ?>
        <div class="cf-admin-wrap">
            <div class="cf-subtabs">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cf-auth-activity&view=events' ) ); ?>"
                   class="cf-subtab active">Event Log</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cf-auth-activity&view=sessions' ) ); ?>"
                   class="cf-subtab">Active Sessions Today</a>
            </div>

            <div id="cf-activity-tab-events">
                <div class="cf-admin-header" style="margin-top:0">
                    <h1>📋 Activity Log <span><?php echo (int) $total; ?> entries</span></h1>
                </div>

                <form method="get" class="cf-filters">
                    <input type="hidden" name="page" value="cf-auth-activity">
                    <input type="hidden" name="view" value="events">
                    <div class="cf-filter-group">
                        <select name="event_type">
                            <?php foreach ( $event_types as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $event_type, $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" placeholder="From">
                        <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" placeholder="To">
                        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search email or name...">
                        <button type="submit" class="button">Filter</button>
                        <?php if ( $event_type || $date_from || $date_to || $search ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=cf-auth-activity&view=events' ) ); ?>" class="button">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="cf-card cf-card-flush">
                    <table class="cf-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Member / Email</th>
                                <th>Provider</th>
                                <th>IP Address</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="5" class="cf-empty">No activity found.</td></tr>
                        <?php else : foreach ( $rows as $row ) :
                            $badge_class = CF_Activity_Log::event_badge_class( $row->event_type, $row->meta );
                            $member_label = $row->display_name
                                ? esc_html( $row->display_name )
                                : ( $row->email ? esc_html( $row->email ) : '—' );
                            $email_sub = ( $row->display_name && $row->email )
                                ? '<div class="cf-tbl-email">' . esc_html( $row->email ) . '</div>'
                                : '';
                        ?>
                        <tr>
                            <td>
                                <span class="<?php echo esc_attr( $badge_class ); ?>">
                                    <?php echo esc_html( CF_Activity_Log::event_label( $row->event_type ) ); ?>
                                </span>
                            </td>
                            <td>
                                <div class="cf-tbl-name"><?php echo $member_label; ?></div>
                                <?php echo $email_sub; ?>
                            </td>
                            <td>
                                <?php if ( $row->provider ) : ?>
                                <span class="cf-pill"><?php echo esc_html( ucfirst( $row->provider ) ); ?></span>
                                <?php else : ?>
                                <span class="cf-tbl-date">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="cf-tbl-date"><?php echo esc_html( $row->ip_address ?: '—' ); ?></td>
                            <td class="cf-tbl-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->created_at ) ); ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>

                    <?php if ( $pages > 1 ) : ?>
                    <div class="cf-pagination">
                        <?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
                            $url = add_query_arg( array_merge(
                                [ 'page' => 'cf-auth-activity', 'view' => 'events', 'paged' => $i ],
                                array_filter( [
                                    'event_type' => $event_type,
                                    'date_from'  => $date_from,
                                    'date_to'    => $date_to,
                                    's'          => $search,
                                ] )
                            ), admin_url( 'admin.php' ) );
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>" class="cf-page-btn <?php echo $i === $paged ? 'active' : ''; ?>"><?php echo (int) $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────
    public function ajax_suspend() {
        check_ajax_referer('cf_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        $uid    = absint($_POST['user_id']);
        $action = sanitize_key($_POST['action_type']);
        $status = $action==='suspend' ? 'suspended' : 'active';
        $old_status = get_user_meta( $uid, 'cf_account_status', true ) ?: 'active';
        update_user_meta($uid,'cf_account_status',$status);
        if ( $old_status !== $status ) {
            $user = get_userdata( $uid );
            CF_Activity_Log::safe_log( 'status_changed', [
                'user_id' => $uid,
                'email'   => $user ? $user->user_email : null,
                'meta'    => [
                    'old_status' => $old_status,
                    'new_status' => $status,
                    'source'     => 'members_suspend_action',
                ],
            ] );
            do_action( 'cf_auth_account_status_changed', $uid, $status, $old_status );
        }
        wp_send_json_success(['message'=>"User {$status}.",'new_status'=>$status]);
    }

    public function ajax_delete() {
        check_ajax_referer('cf_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        $uid = absint($_POST['user_id']);
        require_once ABSPATH.'wp-admin/includes/user.php';
        wp_delete_user($uid);
        wp_send_json_success(['message'=>'User deleted.']);
    }

    public function ajax_resend() {
        check_ajax_referer('cf_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        CF_Email::send_verification(absint($_POST['user_id']));
        wp_send_json_success(['message'=>'Verification email sent.']);
    }

    public function ajax_get_user() {
        check_ajax_referer('cf_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();
        $uid  = absint($_POST['user_id']);
        $user = get_userdata($uid);
        if (!$user) wp_send_json_error(['message'=>'User not found.']);
        wp_send_json_success([
            'id'           => $uid,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'bio'          => get_user_meta($uid,'cf_bio',true),
            'status'       => get_user_meta($uid,'cf_account_status',true) ?: 'active',
            'verified'     => get_user_meta($uid,'cf_email_verified',true) === '1',
            'provider'     => get_user_meta($uid,'cf_social_provider',true) ?: 'manual',
            'avatar'       => CF_Profile::get_avatar_url($uid),
            'joined'       => date_i18n(get_option('date_format'), strtotime($user->user_registered)),
        ]);
    }

    public function ajax_get_xfinity_stats() {
        check_ajax_referer( 'cf_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $uid  = absint( $_POST['user_id'] ?? 0 );
        $user = get_userdata( $uid );
        if ( ! $user ) wp_send_json_error( [ 'message' => 'User not found.' ] );

        global $wpdb;
        $ledger = CF_Xfinity::ledger_table();

        // All-time totals from the ledger.
        $totals = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS total_earned,
                COALESCE(SUM(CASE WHEN source = 'listening' AND amount > 0 THEN amount ELSE 0 END), 0) AS total_listening_xfinity,
                COALESCE(SUM(CASE WHEN source IN ('referral_referrer','referral_new_user') AND amount > 0 THEN amount ELSE 0 END), 0) AS total_referral_xfinity
             FROM {$ledger}
             WHERE user_id = %d",
            $uid
        ) );

        $rate = (float) CF_Xfinity::LISTENING_RATE_PER_MINUTE ?: 0.1;
        $listening_mins_total = $rate > 0 ? (int) round( ( (float) $totals->total_listening_xfinity ) / $rate ) : 0;

        // Referral counts (as referrer) — column is referrer_user_id.
        $ref_table = CF_Referral::referrals_table();
        $ref_counts = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending
             FROM {$ref_table}
             WHERE referrer_user_id = %d",
            $uid
        ) );

        // Last 7 days, day-grouped (same method as front-end Rewards tab).
        $recent = CF_Xfinity::get_instance()->get_daily_transaction_summary( $uid, 1, 7 );

        wp_send_json_success( [
            'balance'                => CF_Xfinity::get_instance()->get_balance( $uid ),
            'total_earned'           => round( (float) $totals->total_earned, 2 ),
            'listening_mins_total'   => $listening_mins_total,
            'referral_xfinity_total' => round( (float) $totals->total_referral_xfinity, 2 ),
            'referral_total'         => (int) ( $ref_counts->total ?? 0 ),
            'referral_confirmed'     => (int) ( $ref_counts->confirmed ?? 0 ),
            'referral_pending'       => (int) ( $ref_counts->pending ?? 0 ),
            'recent_days'            => $recent['days'],
        ] );
    }

    public function ajax_update_user() {
        check_ajax_referer('cf_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $uid    = absint($_POST['user_id']);
        $name   = sanitize_text_field($_POST['display_name'] ?? '');
        $email  = sanitize_email($_POST['email'] ?? '');
        $bio    = sanitize_textarea_field($_POST['bio'] ?? '');
        $status = sanitize_key($_POST['account_status'] ?? 'active');
        $ver    = !empty($_POST['email_verified']) ? '1' : '0';

        if (empty($name)) wp_send_json_error(['message'=>'Display name required.']);

        $old_status = get_user_meta( $uid, 'cf_account_status', true ) ?: 'active';

        wp_update_user(['ID'=>$uid,'display_name'=>$name,'user_email'=>$email]);
        update_user_meta($uid,'cf_bio',$bio);
        update_user_meta($uid,'cf_account_status',$status);
        update_user_meta($uid,'cf_email_verified',$ver);

        if ( $old_status !== $status ) {
            CF_Activity_Log::safe_log( 'status_changed', [
                'user_id' => $uid,
                'email'   => $email,
                'meta'    => [
                    'old_status' => $old_status,
                    'new_status' => $status,
                    'source'     => 'members_edit_modal',
                ],
            ] );
        }

        wp_send_json_success(['message'=>'Member updated successfully.']);
    }

    public function ajax_toggle_donation_wall() {
        check_ajax_referer( 'cf_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $donation_id = absint( $_POST['donation_id'] ?? 0 );
        if ( ! $donation_id ) {
            wp_send_json_error( [ 'message' => 'Invalid donation.' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cf_donations';
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT show_on_wall FROM {$table} WHERE id = %d",
            $donation_id
        ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Donation not found.' ] );
        }

        $new_value = (int) $row->show_on_wall ? 0 : 1;
        $wpdb->update(
            $table,
            [ 'show_on_wall' => $new_value ],
            [ 'id' => $donation_id ],
            [ '%d' ],
            [ '%d' ]
        );

        wp_send_json_success( [
            'show_on_wall' => $new_value,
            'message'      => $new_value ? 'Shown on wall.' : 'Hidden from wall.',
        ] );
    }

    public function ajax_save_settings() {
        check_ajax_referer('cf_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $allowed = [
            'cf_auth_login_redirect','cf_auth_logout_redirect','cf_auth_after_register',
            'cf_auth_email_verification',
            'cf_auth_google_client_id','cf_auth_google_client_secret',
            'cf_auth_google_enabled',
            'cf_auth_facebook_app_id','cf_auth_facebook_app_secret',
            'cf_auth_facebook_enabled',
            'cf_auth_discord_client_id','cf_auth_discord_client_secret',
            'cf_auth_discord_enabled',
            'cf_auth_twitter_api_key','cf_auth_twitter_api_secret',
            'cf_auth_twitter_enabled',
            'cf_auth_email_from_name','cf_auth_email_from',
            'cf_auth_paypal_mode',
            'cf_auth_paypal_sandbox_client_id','cf_auth_paypal_sandbox_client_secret',
            'cf_auth_paypal_live_client_id','cf_auth_paypal_live_client_secret',
            'cf_auth_paypal_webhook_id',
            'cf_auth_donation_currency',
        ];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) update_option($key, sanitize_text_field($_POST[$key]));
        }
        // Only reset "email verification" when the General tab (which owns this field) was submitted.
        if ( isset( $_POST['cf_auth_login_redirect'] ) || isset( $_POST['cf_auth_logout_redirect'] ) || isset( $_POST['cf_auth_after_register'] ) ) {
            if ( ! isset( $_POST['cf_auth_email_verification'] ) ) {
                update_option( 'cf_auth_email_verification', '0' );
            }
        }

        // Only reset each social provider's "enabled" flag when the Social tab was submitted,
        // evidenced by presence of that provider's own credential field in $_POST.
        $social_tab_markers = [
            'cf_auth_google_enabled'   => 'cf_auth_google_client_id',
            'cf_auth_facebook_enabled' => 'cf_auth_facebook_app_id',
            'cf_auth_discord_enabled'  => 'cf_auth_discord_client_id',
            'cf_auth_twitter_enabled'  => 'cf_auth_twitter_api_key',
        ];
        foreach ( $social_tab_markers as $enabled_key => $marker_key ) {
            if ( isset( $_POST[ $marker_key ] ) && ! isset( $_POST[ $enabled_key ] ) ) {
                update_option( $enabled_key, '0' );
            }
        }

        wp_send_json_success(['message'=>'Settings saved successfully.']);
    }

    private function get_member_initials( $name ) {
        $name = trim( (string) $name );
        if ( $name === '' ) {
            return '?';
        }
        $parts = preg_split( '/\s+/', $name );
        if ( count( $parts ) >= 2 ) {
            return strtoupper( substr( $parts[0], 0, 1 ) . substr( $parts[ count( $parts ) - 1 ], 0, 1 ) );
        }
        return strtoupper( substr( $name, 0, 2 ) );
    }

    private function get_donations_chart_data() {
        global $wpdb;

        $table     = $wpdb->prefix . 'cf_donations';
        $start     = gmdate( 'Y-m-d', strtotime( '-13 days' ) );
        $end       = gmdate( 'Y-m-d' );
        $rows      = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS day, COALESCE(SUM(amount), 0) AS total
             FROM {$table}
             WHERE status = 'completed' AND DATE(created_at) >= %s AND DATE(created_at) <= %s
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            $start,
            $end
        ) );

        $by_day = [];
        foreach ( $rows as $row ) {
            $by_day[ $row->day ] = (float) $row->total;
        }

        $labels = [];
        $values = [];
        for ( $i = 13; $i >= 0; $i-- ) {
            $day      = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
            $labels[] = date_i18n( 'M j', strtotime( $day ) );
            $values[] = $by_day[ $day ] ?? 0;
        }

        return [
            'labels' => $labels,
            'values' => $values,
        ];
    }
}
