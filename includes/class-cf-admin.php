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
        add_action( 'wp_ajax_cf_save_settings',       [ $this, 'ajax_save_settings' ] );
    }

    public function register_menus() {
        add_menu_page( 'CF Auth', 'CF Auth', 'manage_options', 'cf-auth', [ $this, 'page_overview' ], 'dashicons-groups', 25 );
        add_submenu_page( 'cf-auth', 'Overview', 'Overview', 'manage_options', 'cf-auth',          [ $this, 'page_overview'  ] );
        add_submenu_page( 'cf-auth', 'Members',  'Members',  'manage_options', 'cf-auth-members',  [ $this, 'page_members'   ] );
        add_submenu_page( 'cf-auth', 'Settings', 'Settings', 'manage_options', 'cf-auth-settings', [ $this, 'page_settings'  ] );
        add_submenu_page( 'cf-auth', 'Activity Log', 'Activity Log', 'manage_options', 'cf-auth-activity', [ $this, 'page_activity' ] );
    }

    public function enqueue_assets( $hook ) {
        $screens = [
            'toplevel_page_cf-auth',
            'cf-auth_page_cf-auth-members',
            'cf-auth_page_cf-auth-settings',
            'cf-auth_page_cf-auth-activity',
        ];
        if ( ! in_array( $hook, $screens, true ) ) return;

        wp_enqueue_style(  'cf-auth-admin',          CF_AUTH_URL . 'assets/css/cf-admin.css',               [], CF_AUTH_VERSION );
        wp_enqueue_style(  'cf-auth-admin-branding', CF_AUTH_URL . 'assets/css/cf-auth-admin-branding.css', ['cf-auth-admin'], CF_AUTH_VERSION );
        wp_enqueue_script( 'cf-auth-admin',          CF_AUTH_URL . 'assets/js/cf-admin.js',                 ['jquery'], CF_AUTH_VERSION, true );
        wp_localize_script( 'cf-auth-admin', 'CF_ADMIN', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cf_admin_nonce' ),
        ]);
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
            <div class="cf-stat-grid">
                <div class="cf-stat-card">
                    <div class="cf-stat-icon" style="background:#FFF7E6;color:#FFB800">👥</div>
                    <div><div class="cf-stat-num"><?php echo $total; ?></div><div class="cf-stat-lbl">Total Members</div></div>
                </div>
                <div class="cf-stat-card">
                    <div class="cf-stat-icon" style="background:#F0FDF4;color:#16a34a">✅</div>
                    <div><div class="cf-stat-num"><?php echo $active; ?></div><div class="cf-stat-lbl">Active</div></div>
                </div>
                <div class="cf-stat-card">
                    <div class="cf-stat-icon" style="background:#FFFBEB;color:#d97706">⏳</div>
                    <div><div class="cf-stat-num"><?php echo $pending; ?></div><div class="cf-stat-lbl">Pending Verify</div></div>
                </div>
                <div class="cf-stat-card">
                    <div class="cf-stat-icon" style="background:#EFF6FF;color:#2563eb">🆕</div>
                    <div><div class="cf-stat-num"><?php echo $today; ?></div><div class="cf-stat-lbl">Joined Today</div></div>
                </div>
                <div class="cf-stat-card">
                    <div class="cf-stat-icon" style="background:#F5F3FF;color:#7c3aed">📅</div>
                    <div><div class="cf-stat-num"><?php echo $week; ?></div><div class="cf-stat-lbl">This Week</div></div>
                </div>
                <div class="cf-stat-card">
                    <div class="cf-stat-icon" style="background:#FFF1F2;color:#e11d48">🚫</div>
                    <div><div class="cf-stat-num"><?php echo $susp; ?></div><div class="cf-stat-lbl">Suspended</div></div>
                </div>
            </div>

            <div class="cf-admin-cols">
                <!-- Recent Members -->
                <div class="cf-admin-box cf-admin-box-lg">
                    <div class="cf-box-head">
                        <h2>Recent Members</h2>
                        <a href="<?php echo admin_url('admin.php?page=cf-auth-members'); ?>" class="cf-box-link">View All →</a>
                    </div>
                    <table class="cf-table">
                        <thead><tr><th>Member</th><th>Provider</th><th>Status</th><th>Joined</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $u):
                            $status   = get_user_meta($u->ID,'cf_account_status',true) ?: 'active';
                            $provider = get_user_meta($u->ID,'cf_social_provider',true) ?: 'manual';
                            $avatar   = CF_Profile::get_avatar_url($u->ID);
                        ?>
                        <tr>
                            <td>
                                <div class="cf-member-cell">
                                    <img src="<?php echo esc_url($avatar); ?>" class="cf-tbl-avatar" onerror="this.src='<?php echo CF_AUTH_URL; ?>assets/img/default-avatar.svg'">
                                    <div>
                                        <div class="cf-tbl-name"><?php echo esc_html($u->display_name); ?></div>
                                        <div class="cf-tbl-email"><?php echo esc_html($u->user_email); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="cf-pill"><?php echo esc_html(ucfirst($provider)); ?></span></td>
                            <td><span class="cf-status cf-status-<?php echo esc_attr($status); ?>"><?php echo ucfirst($status); ?></span></td>
                            <td class="cf-tbl-date"><?php echo date_i18n(get_option('date_format'), strtotime($u->user_registered)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Provider Breakdown -->
                <div class="cf-admin-box">
                    <div class="cf-box-head"><h2>Auth Providers</h2></div>
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
            <div class="cf-admin-box">
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
        <?php
    }

    // ── Settings ──────────────────────────────────────────────────────────────
    public function page_settings() {
        $tab = sanitize_key($_GET['tab'] ?? 'general');
        $tabs = ['general'=>'General','social'=>'Social Auth','email'=>'Email','smtp'=>'SMTP Guide'];
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
            <div class="cf-admin-box">
                <div class="cf-box-head"><h2>📧 SMTP Setup Guide — Fix Email Delivery</h2></div>
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
            <form id="cf-settings-form" class="cf-admin-box">
                <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">

                <?php if ($tab === 'general'): ?>
                <div class="cf-box-head"><h2>General Settings</h2></div>
                <table class="form-table">
                    <tr><th>After Login Redirect</th>
                        <td><input type="url" name="cf_auth_login_redirect" value="<?php echo esc_attr(get_option('cf_auth_login_redirect')); ?>" class="regular-text">
                        <p class="description">Where to send users after successful login.</p></td></tr>
                    <tr><th>After Logout Redirect</th>
                        <td><input type="url" name="cf_auth_logout_redirect" value="<?php echo esc_attr(get_option('cf_auth_logout_redirect')); ?>" class="regular-text"></td></tr>
                    <tr><th>After Registration</th>
                        <td><input type="url" name="cf_auth_after_register" value="<?php echo esc_attr(get_option('cf_auth_after_register')); ?>" class="regular-text"></td></tr>
                    <tr><th>Email Verification</th>
                        <td><label><input type="checkbox" name="cf_auth_email_verification" value="1" <?php checked(get_option('cf_auth_email_verification'),'1'); ?>>
                        Require email verification before users can log in</label></td></tr>
                </table>

                <?php elseif ($tab === 'social'): ?>
                <div class="cf-box-head"><h2>Social Auth Credentials</h2></div>
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
                $fields = [
                    'Google OAuth'       => [['cf_auth_google_client_id','Client ID','text'],['cf_auth_google_client_secret','Client Secret','password']],
                    'Facebook App'       => [['cf_auth_facebook_app_id','App ID','text'],['cf_auth_facebook_app_secret','App Secret','password']],
                    'Discord OAuth'      => [['cf_auth_discord_client_id','Client ID','text'],['cf_auth_discord_client_secret','Client Secret','password']],
                    'X / Twitter OAuth'  => [['cf_auth_twitter_api_key','API Key (Client ID)','text'],['cf_auth_twitter_api_secret','API Secret','password']],
                ];
                foreach ($fields as $title => $rows): ?>
                <h3 class="cf-provider-heading"><?php echo $title; ?></h3>
                <table class="form-table">
                    <?php foreach ($rows as [$opt,$label,$type]): ?>
                    <tr><th><?php echo $label; ?></th>
                        <td><input type="<?php echo $type; ?>" name="<?php echo $opt; ?>" value="<?php echo esc_attr(get_option($opt)); ?>" class="regular-text"></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php endforeach;

                elseif ($tab === 'email'): ?>
                <div class="cf-box-head"><h2>Email Settings</h2></div>
                <p class="description" style="padding:0 0 16px">CF Auth sends emails via WordPress <code>wp_mail()</code>. Configure SMTP in the <a href="<?php echo add_query_arg('tab','smtp'); ?>">SMTP Guide tab</a> for reliable delivery.</p>
                <table class="form-table">
                    <tr><th>From Name</th>
                        <td><input type="text" name="cf_auth_email_from_name" value="<?php echo esc_attr(get_option('cf_auth_email_from_name','Collective Finity')); ?>" class="regular-text"></td></tr>
                    <tr><th>From Email</th>
                        <td><input type="email" name="cf_auth_email_from" value="<?php echo esc_attr(get_option('cf_auth_email_from','contact@collectivefinity.com')); ?>" class="regular-text"></td></tr>
                </table>
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
            <div class="cf-admin-header">
                <h1>📋 Activity Log <span><?php echo (int) $total; ?> entries</span></h1>
            </div>

            <form method="get" class="cf-filters">
                <input type="hidden" name="page" value="cf-auth-activity">
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
                    <a href="<?php echo admin_url( 'admin.php?page=cf-auth-activity' ); ?>" class="button">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="cf-admin-box">
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
                        <td class="cf-tbl-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $row->created_at ) ) ); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <?php if ( $pages > 1 ) : ?>
                <div class="cf-pagination">
                    <?php for ( $i = 1; $i <= $pages; $i++ ) :
                        $url = add_query_arg( array_merge(
                            [ 'page' => 'cf-auth-activity', 'paged' => $i ],
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

    public function ajax_save_settings() {
        check_ajax_referer('cf_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error();

        $allowed = [
            'cf_auth_login_redirect','cf_auth_logout_redirect','cf_auth_after_register',
            'cf_auth_email_verification',
            'cf_auth_google_client_id','cf_auth_google_client_secret',
            'cf_auth_facebook_app_id','cf_auth_facebook_app_secret',
            'cf_auth_discord_client_id','cf_auth_discord_client_secret',
            'cf_auth_twitter_api_key','cf_auth_twitter_api_secret',
            'cf_auth_email_from_name','cf_auth_email_from',
        ];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) update_option($key, sanitize_text_field($_POST[$key]));
        }
        if (!isset($_POST['cf_auth_email_verification'])) update_option('cf_auth_email_verification','0');

        wp_send_json_success(['message'=>'Settings saved successfully.']);
    }
}
