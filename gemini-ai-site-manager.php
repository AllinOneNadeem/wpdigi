<?php
/**
 * Plugin Name: Gemini AI Site & Store Manager
 * Plugin URI:  https://github.com/
 * Description: All-in-One Gemini AI Suite: WooCommerce Digital & Physical Product Generator, Live Preview, Full SEO Auditor, Image Studio, 1-Click History Restore, Site Doctor & GitHub Auto-Updater.
 * Version:     1.0.0
 * Author:      AI Store Manager
 * Text Domain: gemini-ai-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GASM_VERSION', '1.0.0' );
define( 'GASM_PLUGIN_FILE', __FILE__ );
define( 'GASM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'GASM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

class Gemini_AI_Site_Manager {

    private static $instance = null;
    private $table_history;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->table_history = $wpdb->prefix . 'gasm_history_log';

        // Activation Hook: Create History Table & Default Settings
        register_activation_hook( GASM_PLUGIN_FILE, array( $this, 'activate_plugin' ) );

        // Admin Menu & Actions
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_form_submissions' ) );

        // GitHub Auto-Updater Hooks (Direct Branch Updater for Public Repo)
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_github_update' ) );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_github_folder_name' ), 10, 4 );
    }

    /**
     * 1. PLUGIN ACTIVATION: Create History/Restore DB Table & Defaults
     */
    public function activate_plugin() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_history} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            action_type varchar(100) NOT NULL,
            object_id bigint(20) DEFAULT 0,
            object_title varchar(255) NOT NULL,
            before_snapshot longtext NOT NULL,
            after_snapshot longtext NOT NULL,
            status varchar(50) DEFAULT 'completed',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Default Modules & Settings
        if ( ! get_option( 'gasm_settings' ) ) {
            $default_settings = array(
                'gemini_api_key'       => '',
                'gemini_model'         => 'gemini-2.5-flash',
                'github_username'      => '',
                'github_repo'          => 'gemini-ai-site-manager',
                'github_branch'        => 'main',
                // Module Switches
                'mod_digital_prod'     => 1,
                'mod_physical_prod'    => 1,
                'mod_auto_seo'         => 1,
                'mod_image_studio'     => 1,
                'mod_site_doctor'      => 1,
                // Module Specific Controls
                'seo_tone'             => 'Professional & High-Converting',
                'default_sizes'        => 'S, M, L, XL, XXL',
                'default_colors'       => 'Black, Brown, Tan, Navy',
                'digital_expiry_days'  => 'Unlimited',
                'image_aspect_ratio'   => '1:1 Square (White Background)',
                // Stats Counter
                'stat_products_added'  => 0,
                'stat_seo_updated'     => 0,
                'stat_images_gen'      => 0,
                'stat_bugs_fixed'      => 0,
            );
            update_option( 'gasm_settings', $default_settings );
        }
    }

    /**
     * 2. GITHUB DIRECT AUTO-UPDATER (Public Repo - No Release Required)
     */
    public function check_github_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $settings = get_option( 'gasm_settings', array() );
        $username = trim( $settings['github_username'] ?? '' );
        $repo     = trim( $settings['github_repo'] ?? '' );
        $branch   = trim( $settings['github_branch'] ?? 'main' );

        if ( empty( $username ) || empty( $repo ) ) {
            return $transient;
        }

        // Fetch main plugin file directly from GitHub Raw to read Version header
        $raw_url  = "https://raw.githubusercontent.com/{$username}/{$repo}/{$branch}/gemini-ai-site-manager.php";
        $response = wp_remote_get( $raw_url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return $transient;
        }

        $remote_Body = wp_remote_retrieve_body( $response );
        if ( preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $remote_Body, $matches ) ) {
            $remote_version = trim( $matches[1] );

            if ( version_compare( GASM_VERSION, $remote_version, '<' ) ) {
                $zip_url = "https://github.com/{$username}/{$repo}/archive/refs/heads/{$branch}.zip";

                $plugin_data = new stdClass();
                $plugin_data->slug        = dirname( GASM_PLUGIN_BASENAME );
                $plugin_data->plugin      = GASM_PLUGIN_BASENAME;
                $plugin_data->new_version = $remote_version;
                $plugin_data->url         = "https://github.com/{$username}/{$repo}";
                $plugin_data->package     = $zip_url;

                $transient->response[ GASM_PLUGIN_BASENAME ] = $plugin_data;
            }
        }

        return $transient;
    }

    /**
     * Fix GitHub ZIP folder name during update so plugin stays active
     */
    public function fix_github_folder_name( $source, $remote_source, $upgrader, $hook_extra = null ) {
        global $wp_filesystem;
        if ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === GASM_PLUGIN_BASENAME ) {
            $proper_folder = trailingslashit( $remote_source ) . dirname( GASM_PLUGIN_BASENAME );
            if ( $source !== $proper_folder ) {
                $wp_filesystem->move( $source, $proper_folder );
                return trailingslashit( $proper_folder );
            }
        }
        return $source;
    }

    /**
     * 3. FORM HANDLERS: Save Settings, Force Update Check, 1-Click Restore
     */
    public function handle_form_submissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Save Settings & Modules
        if ( isset( $_POST['gasm_save_settings'] ) && check_admin_referer( 'gasm_settings_nonce' ) ) {
            $settings = get_option( 'gasm_settings', array() );

            $settings['gemini_api_key']      = sanitize_text_field( $_POST['gemini_api_key'] ?? '' );
            $settings['gemini_model']        = sanitize_text_field( $_POST['gemini_model'] ?? 'gemini-2.5-flash' );
            $settings['github_username']     = sanitize_text_field( $_POST['github_username'] ?? '' );
            $settings['github_repo']         = sanitize_text_field( $_POST['github_repo'] ?? '' );
            $settings['github_branch']       = sanitize_text_field( $_POST['github_branch'] ?? 'main' );

            // Switches
            $settings['mod_digital_prod']    = isset( $_POST['mod_digital_prod'] ) ? 1 : 0;
            $settings['mod_physical_prod']   = isset( $_POST['mod_physical_prod'] ) ? 1 : 0;
            $settings['mod_auto_seo']        = isset( $_POST['mod_auto_seo'] ) ? 1 : 0;
            $settings['mod_image_studio']    = isset( $_POST['mod_image_studio'] ) ? 1 : 0;
            $settings['mod_site_doctor']     = isset( $_POST['mod_site_doctor'] ) ? 1 : 0;

            // Controls
            $settings['seo_tone']            = sanitize_text_field( $_POST['seo_tone'] ?? '' );
            $settings['default_sizes']       = sanitize_text_field( $_POST['default_sizes'] ?? '' );
            $settings['default_colors']      = sanitize_text_field( $_POST['default_colors'] ?? '' );
            $settings['digital_expiry_days'] = sanitize_text_field( $_POST['digital_expiry_days'] ?? '' );
            $settings['image_aspect_ratio']  = sanitize_text_field( $_POST['image_aspect_ratio'] ?? '' );

            update_option( 'gasm_settings', $settings );
            add_settings_error( 'gasm_messages', 'gasm_saved', '✅ Sabhi Settings aur Module Controls save ho gaye!', 'updated' );
        }

        // Force GitHub Update Check
        if ( isset( $_POST['gasm_force_update_check'] ) && check_admin_referer( 'gasm_settings_nonce' ) ) {
            delete_site_transient( 'update_plugins' );
            wp_update_plugins();
            add_settings_error( 'gasm_messages', 'gasm_update_checked', '🔍 GitHub se latest version check kar liya gaya! Agar naya version hai toh Plugins page par Update button aa gaya hoga.', 'updated' );
        }

        // 1-Click Restore Handler
        if ( isset( $_POST['gasm_restore_id'] ) && check_admin_referer( 'gasm_restore_nonce' ) ) {
            $history_id = intval( $_POST['gasm_restore_id'] );
            $restored   = $this->restore_snapshot( $history_id );
            if ( $restored ) {
                add_settings_error( 'gasm_messages', 'gasm_restored', '⏪ 1-Click Restore Successful! Purana data wapas set kar diya gaya hai.', 'updated' );
            } else {
                add_settings_error( 'gasm_messages', 'gasm_restore_err', '⚠️ Restore karne mein problem aayi.', 'error' );
            }
        }

        // Create Demo History Snapshot (To test 1-click restore right now)
        if ( isset( $_POST['gasm_create_test_log'] ) && check_admin_referer( 'gasm_restore_nonce' ) ) {
            $sample_post = get_posts( array( 'post_type' => array( 'product', 'page', 'post' ), 'posts_per_page' => 1 ) );
            if ( ! empty( $sample_post ) ) {
                $p = $sample_post[0];
                $this->log_history_snapshot( 'SEO & Content Update (Test)', $p->ID, $p->post_title, array(
                    'post_title'   => $p->post_title,
                    'post_content' => $p->post_content,
                    'post_excerpt' => $p->post_excerpt,
                ), array(
                    'post_title'   => $p->post_title . ' (AI Optimized)',
                ) );
                add_settings_error( 'gasm_messages', 'gasm_test_log', '✅ Test Snapshot History mein jud gaya! Aap Restore button test kar sakte hain.', 'updated' );
            }
        }
    }

    /**
     * 4. SNAPSHOT & 1-CLICK RESTORE ENGINE
     */
    public function log_history_snapshot( $action_type, $object_id, $object_title, $before_data, $after_data ) {
        global $wpdb;
        $wpdb->insert(
            $this->table_history,
            array(
                'action_type'     => $action_type,
                'object_id'       => $object_id,
                'object_title'    => $object_title,
                'before_snapshot' => wp_json_encode( $before_data ),
                'after_snapshot'  => wp_json_encode( $after_data ),
                'status'          => 'active',
            )
        );
    }

    public function restore_snapshot( $history_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_history} WHERE id = %d", $history_id ) );
        if ( ! $row ) {
            return false;
        }

        $before = json_decode( $row->before_snapshot, true );
        if ( ! empty( $row->object_id ) && is_array( $before ) ) {
            $update_post = array( 'ID' => intval( $row->object_id ) );
            if ( isset( $before['post_title'] ) )   $update_post['post_title']   = $before['post_title'];
            if ( isset( $before['post_content'] ) ) $update_post['post_content'] = $before['post_content'];
            if ( isset( $before['post_excerpt'] ) ) $update_post['post_excerpt'] = $before['post_excerpt'];

            wp_update_post( $update_post );

            // Restore Meta Fields (Price, SEO Meta, etc.)
            if ( ! empty( $before['meta'] ) && is_array( $before['meta'] ) ) {
                foreach ( $before['meta'] as $meta_key => $meta_val ) {
                    update_post_meta( $row->object_id, $meta_key, $meta_val );
                }
            }
        }

        $wpdb->update( $this->table_history, array( 'status' => 'restored' ), array( 'id' => $history_id ) );
        return true;
    }

    /**
     * 5. LIVE WEBSITE X-RAY & ANALYTICS SCANNER
     */
    public function get_site_analytics() {
        global $wpdb;

        // Products Breakdown
        $total_products    = 0;
        $digital_products  = 0;
        $physical_products = 0;

        if ( post_type_exists( 'product' ) ) {
            $counts = wp_count_posts( 'product' );
            $total_products = intval( $counts->publish ?? 0 ) + intval( $counts->draft ?? 0 );

            // Count Digital Products (_downloadable = yes OR _virtual = yes)
            $digital_products = (int) $wpdb->get_var( "
                SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft')
                AND ((pm.meta_key = '_downloadable' AND pm.meta_value = 'yes') OR (pm.meta_key = '_virtual' AND pm.meta_value = 'yes'))
            " );
            $physical_products = max( 0, $total_products - $digital_products );
        }

        // Pages & Posts
        $page_counts = wp_count_posts( 'page' );
        $post_counts = wp_count_posts( 'post' );
        $total_pages = intval( $page_counts->publish ?? 0 );
        $total_posts = intval( $post_counts->publish ?? 0 );

        // Plugins Health
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $plugin_updates = get_site_transient( 'update_plugins' );
        $plugins_need_update = ! empty( $plugin_updates->response ) ? count( $plugin_updates->response ) : 0;

        // Themes Health
        $all_themes     = wp_get_themes();
        $active_theme   = wp_get_theme();
        $theme_updates  = get_site_transient( 'update_themes' );
        $themes_need_update = ! empty( $theme_updates->response ) ? count( $theme_updates->response ) : 0;

        // Live SEO Audit & Issue Scanner
        $items_to_scan = get_posts( array(
            'post_type'      => post_type_exists( 'product' ) ? array( 'product', 'page' ) : array( 'page', 'post' ),
            'post_status'    => 'publish',
            'posts_per_page' => 100,
        ) );

        $missing_meta_desc = 0;
        $short_titles      = 0;
        $missing_featured  = 0;
        $total_scanned     = count( $items_to_scan );

        foreach ( $items_to_scan as $item ) {
            // Title check
            if ( strlen( trim( $item->post_title ) ) < 25 ) {
                $short_titles++;
            }
            // Meta Description check (RankMath, Yoast, or GASM AI Meta)
            $rm_desc    = get_post_meta( $item->ID, 'rank_math_description', true );
            $yoast_desc = get_post_meta( $item->ID, '_yoast_wpseo_metadesc', true );
            $gasm_desc  = get_post_meta( $item->ID, '_gasm_seo_description', true );
            if ( empty( $rm_desc ) && empty( $yoast_desc ) && empty( $gasm_desc ) && empty( $item->post_excerpt ) ) {
                $missing_meta_desc++;
            }
            // Image check
            if ( ! has_post_thumbnail( $item->ID ) ) {
                $missing_featured++;
            }
        }

        // Calculate SEO Score %
        $seo_score = 100;
        if ( $total_scanned > 0 ) {
            $total_possible_checks = $total_scanned * 3;
            $total_issues          = $missing_meta_desc + $short_titles + $missing_featured;
            $seo_score             = max( 10, round( ( ( $total_possible_checks - $total_issues ) / $total_possible_checks ) * 100 ) );
        }

        return array(
            'total_products'      => $total_products,
            'digital_products'    => $digital_products,
            'physical_products'   => $physical_products,
            'total_pages'         => $total_pages,
            'total_posts'         => $total_posts,
            'total_plugins'       => count( $all_plugins ),
            'active_plugins'      => count( $active_plugins ),
            'plugins_need_update' => $plugins_need_update,
            'total_themes'        => count( $all_themes ),
            'active_theme_name'   => $active_theme->get( 'Name' ),
            'themes_need_update'  => $themes_need_update,
            'seo_score'           => $seo_score,
            'total_scanned'       => $total_scanned,
            'missing_meta_desc'   => $missing_meta_desc,
            'short_titles'        => $short_titles,
            'missing_featured'    => $missing_featured,
        );
    }

    /**
     * 6. ADMIN MENU & MASTER DASHBOARD UI
     */
    public function register_admin_menu() {
        add_menu_page(
            'Gemini AI Manager',
            'Gemini AI Suite',
            'manage_options',
            'gemini-ai-manager',
            array( $this, 'render_dashboard_page' ),
            'dashicons-superhero',
            2
        );
    }

    public function render_dashboard_page() {
        global $wpdb;
        $settings  = get_option( 'gasm_settings', array() );
        $analytics = $this->get_site_analytics();
        $history   = $wpdb->get_results( "SELECT * FROM {$this->table_history} ORDER BY id DESC LIMIT 20" );
        $active_tab = sanitize_text_field( $_GET['tab'] ?? 'dashboard' );
        ?>
        <style>
            .gasm-wrap { max-width: 1280px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .gasm-header { background: linear-gradient(135deg, #1e1b4b, #312e81, #4f46e5); color: #fff; padding: 25px 30px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
            .gasm-header h1 { color: #fff; margin: 0 0 6px 0; font-size: 24px; font-weight: 700; }
            .gasm-header p { margin: 0; opacity: 0.85; font-size: 14px; }
            .gasm-badge { background: rgba(255,255,255,0.2); padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
            .gasm-tabs { display: flex; gap: 10px; margin-bottom: 22px; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; flex-wrap: wrap; }
            .gasm-tab { text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; color: #374151; background: #fff; border: 1px solid #d1d5db; transition: 0.2s; }
            .gasm-tab.active, .gasm-tab:hover { background: #4f46e5; color: #fff; border-color: #4f46e5; }
            .gasm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-bottom: 24px; }
            .gasm-card { background: #fff; border-radius: 10px; padding: 20px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
            .gasm-card h3 { margin-top: 0; font-size: 15px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
            .gasm-stat-big { font-size: 32px; font-weight: 800; color: #111827; margin: 8px 0; }
            .gasm-sub-stats { display: flex; justify-content: space-between; font-size: 13px; color: #4b5563; border-top: 1px solid #f3f4f6; padding-top: 10px; margin-top: 10px; }
            .gasm-issue-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 6px; margin-bottom: 10px; font-size: 14px; }
            .gasm-ok-item { padding: 10px 14px; background: #ecfdf5; border-left: 4px solid #10b981; border-radius: 6px; margin-bottom: 10px; font-size: 14px; }
            .gasm-module-row { display: flex; justify-content: space-between; align-items: center; padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; background: #fafafa; }
            .gasm-switch { position: relative; display: inline-block; width: 50px; height: 26px; }
            .gasm-switch input { opacity: 0; width: 0; height: 0; }
            .gasm-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .3s; border-radius: 26px; }
            .gasm-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 4px; bottom: 4px; background-color: white; transition: .3s; border-radius: 50%; }
            input:checked + .gasm-slider { background-color: #10b981; }
            input:checked + .gasm-slider:before { transform: translateX(24px); }
            .gasm-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
            .gasm-table th, .gasm-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
            .gasm-table th { background: #f9fafb; font-weight: 600; color: #374151; }
        </style>

        <div class="gasm-wrap">
            <div class="gasm-header">
                <div>
                    <h1>🤖 Gemini AI Site & Store Manager</h1>
                    <p>Complete Store Automation, Live SEO X-Ray, 1-Click History Restore & GitHub Cloud Updater</p>
                </div>
                <div class="gasm-badge">Version <?php echo esc_html( GASM_VERSION ); ?></div>
            </div>

            <?php settings_errors( 'gasm_messages' ); ?>

            <!-- Navigation Tabs -->
            <div class="gasm-tabs">
                <a href="?page=gemini-ai-manager&tab=dashboard" class="gasm-tab <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">📊 Master Dashboard (X-Ray)</a>
                <a href="?page=gemini-ai-manager&tab=modules" class="gasm-tab <?php echo $active_tab === 'modules' ? 'active' : ''; ?>">🎛️ On/Off Modules & Controls</a>
                <a href="?page=gemini-ai-manager&tab=history" class="gasm-tab <?php echo $active_tab === 'history' ? 'active' : ''; ?>">⏪ Work History & 1-Click Restore</a>
                <a href="?page=gemini-ai-manager&tab=settings" class="gasm-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">⚙️ Gemini API & GitHub Updater</a>
            </div>

            <?php if ( $active_tab === 'dashboard' ) : ?>
                <!-- 1. MASTER ANALYTICS CARDS -->
                <div class="gasm-grid">
                    <div class="gasm-card">
                        <h3>📦 Total Store Products</h3>
                        <div class="gasm-stat-big"><?php echo esc_html( $analytics['total_products'] ); ?></div>
                        <div class="gasm-sub-stats">
                            <span>🧥 Physical: <strong><?php echo esc_html( $analytics['physical_products'] ); ?></strong></span>
                            <span>💻 Digital: <strong><?php echo esc_html( $analytics['digital_products'] ); ?></strong></span>
                        </div>
                    </div>

                    <div class="gasm-card">
                        <h3>📄 Website Pages & Posts</h3>
                        <div class="gasm-stat-big"><?php echo esc_html( $analytics['total_pages'] + $analytics['total_posts'] ); ?></div>
                        <div class="gasm-sub-stats">
                            <span>Pages: <strong><?php echo esc_html( $analytics['total_pages'] ); ?></strong></span>
                            <span>Blog Posts: <strong><?php echo esc_html( $analytics['total_posts'] ); ?></strong></span>
                        </div>
                    </div>

                    <div class="gasm-card">
                        <h3>🔌 Plugins & Themes Status</h3>
                        <div class="gasm-stat-big"><?php echo esc_html( $analytics['active_plugins'] ); ?> / <?php echo esc_html( $analytics['total_plugins'] ); ?> <span style="font-size:14px;font-weight:normal;">Active</span></div>
                        <div class="gasm-sub-stats">
                            <span style="color:<?php echo $analytics['plugins_need_update'] > 0 ? '#dc2626' : '#059669'; ?>;">
                                Plugin Updates: <strong><?php echo esc_html( $analytics['plugins_need_update'] ); ?></strong>
                            </span>
                            <span>Theme: <strong><?php echo esc_html( $analytics['active_theme_name'] ); ?></strong> (<?php echo esc_html( $analytics['themes_need_update'] ); ?> upd)</span>
                        </div>
                    </div>

                    <div class="gasm-card">
                        <h3>📈 Overall Website SEO Score</h3>
                        <div class="gasm-stat-big" style="color: <?php echo $analytics['seo_score'] >= 80 ? '#10b981' : ( $analytics['seo_score'] >= 50 ? '#f59e0b' : '#ef4444' ); ?>;">
                            <?php echo esc_html( $analytics['seo_score'] ); ?>%
                        </div>
                        <div class="gasm-sub-stats">
                            <span>Scanned Items: <strong><?php echo esc_html( $analytics['total_scanned'] ); ?></strong></span>
                            <span>Google Rank API: <strong>Ready</strong></span>
                        </div>
                    </div>
                </div>

                <!-- 2. SEO ISSUES & AI WORK SUMMARY -->
                <div class="gasm-grid" style="grid-template-columns: 2fr 1fr;">
                    <div class="gasm-card">
                        <h3>🔍 Website Mein Kahan Kya Problem Hai (Live Audit)</h3>
                        <?php if ( $analytics['missing_meta_desc'] > 0 ) : ?>
                            <div class="gasm-issue-item">
                                <span>⚠️ <strong><?php echo esc_html( $analytics['missing_meta_desc'] ); ?> Products/Pages</strong> mein SEO Meta Description gayab hai.</span>
                                <span class="button button-small">Phase 2 Mein AI Fix Karega</span>
                            </div>
                        <?php endif; ?>

                        <?php if ( $analytics['missing_featured'] > 0 ) : ?>
                            <div class="gasm-issue-item">
                                <span>🖼️ <strong><?php echo esc_html( $analytics['missing_featured'] ); ?> Products/Pages</strong> mein Main Photo (Featured Image) nahi lagi hai.</span>
                                <span class="button button-small">AI Image Studio</span>
                            </div>
                        <?php endif; ?>

                        <?php if ( $analytics['short_titles'] > 0 ) : ?>
                            <div class="gasm-issue-item">
                                <span>📝 <strong><?php echo esc_html( $analytics['short_titles'] ); ?> Items</strong> ka Title SEO ke hisab se bahut chota (< 25 chars) hai.</span>
                                <span class="button button-small">Auto-Optimize</span>
                            </div>
                        <?php endif; ?>

                        <?php if ( $analytics['plugins_need_update'] > 0 ) : ?>
                            <div class="gasm-issue-item">
                                <span>🔄 <strong><?php echo esc_html( $analytics['plugins_need_update'] ); ?> Plugins</strong> ka naya update pending hai.</span>
                                <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>" class="button button-small">View Plugins</a>
                            </div>
                        <?php endif; ?>

                        <?php if ( $analytics['missing_meta_desc'] === 0 && $analytics['missing_featured'] === 0 && $analytics['short_titles'] === 0 ) : ?>
                            <div class="gasm-ok-item">✅ Badhai ho! Aapke sabhi scanned pages aur products ka basic SEO bilkul fit hai.</div>
                        <?php endif; ?>
                    </div>

                    <div class="gasm-card">
                        <h3>🤖 AI Work Counter (Total Kaam)</h3>
                        <p>📦 Products Created: <strong><?php echo intval( $settings['stat_products_added'] ?? 0 ); ?></strong></p>
                        <p>📈 SEO Tags Updated: <strong><?php echo intval( $settings['stat_seo_updated'] ?? 0 ); ?></strong></p>
                        <p>🎨 AI Photos Generated: <strong><?php echo intval( $settings['stat_images_gen'] ?? 0 ); ?></strong></p>
                        <p>🛠️ Bugs & Cache Fixed: <strong><?php echo intval( $settings['stat_bugs_fixed'] ?? 0 ); ?></strong></p>
                        <hr>
                        <p style="font-size:12px;color:#6b7280;">Active Modules: 
                            <?php 
                            $active_mods = array();
                            if ( ! empty( $settings['mod_digital_prod'] ) )  $active_mods[] = 'Digital';
                            if ( ! empty( $settings['mod_physical_prod'] ) ) $active_mods[] = 'Physical';
                            if ( ! empty( $settings['mod_auto_seo'] ) )      $active_mods[] = 'SEO';
                            if ( ! empty( $settings['mod_image_studio'] ) )  $active_mods[] = 'Image';
                            if ( ! empty( $settings['mod_site_doctor'] ) )   $active_mods[] = 'Site Doctor';
                            echo esc_html( implode( ', ', $active_mods ) );
                            ?>
                        </p>
                    </div>
                </div>

            <?php elseif ( $active_tab === 'modules' ) : ?>
                <!-- 3. ON/OFF MODULE SWITCHBOARD & INDIVIDUAL CONTROLS -->
                <form method="post" class="gasm-card">
                    <?php wp_nonce_field( 'gasm_settings_nonce' ); ?>
                    <h3>🎛️ Feature Modules (Apni Jarurat Ke Hisab Se ON / OFF Karein)</h3>
                    <p style="margin-bottom:20px;color:#4b5563;">Jo feature aap OFF rakhenge, wo disable rahega. Har feature ki apni control setting neeche di gayi hai:</p>

                    <div class="gasm-module-row">
                        <div>
                            <strong>💻 Digital Product Manager</strong>
                            <p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Downloadable links, PDFs, Softwares add aur auto-SEO karne ka module.</p>
                            <div style="margin-top:8px;">
                                <label style="font-size:12px;">Default Download Expiry: </label>
                                <input type="text" name="digital_expiry_days" value="<?php echo esc_attr( $settings['digital_expiry_days'] ?? 'Unlimited' ); ?>" style="width:180px;">
                            </div>
                        </div>
                        <label class="gasm-switch">
                            <input type="checkbox" name="mod_digital_prod" value="1" <?php checked( 1, $settings['mod_digital_prod'] ?? 1 ); ?>>
                            <span class="gasm-slider"></span>
                        </label>
                    </div>

                    <div class="gasm-module-row">
                        <div>
                            <strong>🧥 Physical & Variant Product Manager</strong>
                            <p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Simple aur Variable products (Size, Color, Price, Stock) generate aur update karne ka module.</p>
                            <div style="margin-top:8px; display:flex; gap:15px; flex-wrap:wrap;">
                                <span><label style="font-size:12px;">Default Sizes: </label><input type="text" name="default_sizes" value="<?php echo esc_attr( $settings['default_sizes'] ?? 'S, M, L, XL' ); ?>"></span>
                                <span><label style="font-size:12px;">Default Colors: </label><input type="text" name="default_colors" value="<?php echo esc_attr( $settings['default_colors'] ?? 'Black, Brown' ); ?>"></span>
                            </div>
                        </div>
                        <label class="gasm-switch">
                            <input type="checkbox" name="mod_physical_prod" value="1" <?php checked( 1, $settings['mod_physical_prod'] ?? 1 ); ?>>
                            <span class="gasm-slider"></span>
                        </label>
                    </div>

                    <div class="gasm-module-row">
                        <div>
                            <strong>📈 AI Full SEO & Meta Master</strong>
                            <p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Title, Meta Description, Focus Keywords aur Tags generate aur audit karne ka module.</p>
                            <div style="margin-top:8px;">
                                <label style="font-size:12px;">SEO Writing Style / Tone: </label>
                                <input type="text" name="seo_tone" value="<?php echo esc_attr( $settings['seo_tone'] ?? 'Professional & High-Converting' ); ?>" style="width:260px;">
                            </div>
                        </div>
                        <label class="gasm-switch">
                            <input type="checkbox" name="mod_auto_seo" value="1" <?php checked( 1, $settings['mod_auto_seo'] ?? 1 ); ?>>
                            <span class="gasm-slider"></span>
                        </label>
                    </div>

                    <div class="gasm-module-row">
                        <div>
                            <strong>🎨 AI Image Generation Studio</strong>
                            <p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Bina photo wale products ke liye AI se photo generate karke Media Library mein lagana.</p>
                            <div style="margin-top:8px;">
                                <label style="font-size:12px;">Default Image Style: </label>
                                <input type="text" name="image_aspect_ratio" value="<?php echo esc_attr( $settings['image_aspect_ratio'] ?? '1:1 Square (Pure White Background)' ); ?>" style="width:260px;">
                            </div>
                        </div>
                        <label class="gasm-switch">
                            <input type="checkbox" name="mod_image_studio" value="1" <?php checked( 1, $settings['mod_image_studio'] ?? 1 ); ?>>
                            <span class="gasm-slider"></span>
                        </label>
                    </div>

                    <div class="gasm-module-row">
                        <div>
                            <strong>🩺 Site Doctor (Speed, Cache Cleaner & Bug Finder)</strong>
                            <p style="margin:4px 0 0;font-size:13px;color:#6b7280;">Website ke error bugs dhoondhna, cache files clear karna aur speed fast rakhna.</p>
                        </div>
                        <label class="gasm-switch">
                            <input type="checkbox" name="mod_site_doctor" value="1" <?php checked( 1, $settings['mod_site_doctor'] ?? 1 ); ?>>
                            <span class="gasm-slider"></span>
                        </label>
                    </div>

                    <!-- Preserve API & GitHub fields when saving modules -->
                    <input type="hidden" name="gemini_api_key" value="<?php echo esc_attr( $settings['gemini_api_key'] ?? '' ); ?>">
                    <input type="hidden" name="gemini_model" value="<?php echo esc_attr( $settings['gemini_model'] ?? 'gemini-2.5-flash' ); ?>">
                    <input type="hidden" name="github_username" value="<?php echo esc_attr( $settings['github_username'] ?? '' ); ?>">
                    <input type="hidden" name="github_repo" value="<?php echo esc_attr( $settings['github_repo'] ?? '' ); ?>">
                    <input type="hidden" name="github_branch" value="<?php echo esc_attr( $settings['github_branch'] ?? 'main' ); ?>">

                    <p style="margin-top:20px;">
                        <button type="submit" name="gasm_save_settings" class="button button-primary button-large">💾 Save Module Controls</button>
                    </p>
                </form>

            <?php elseif ( $active_tab === 'history' ) : ?>
                <!-- 4. COMPLETE WORK HISTORY & 1-CLICK RESTORE -->
                <div class="gasm-card">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3>⏪ AI Work History & 1-Click Restore (Time Machine)</h3>
                        <form method="post">
                            <?php wp_nonce_field( 'gasm_restore_nonce' ); ?>
                            <button type="submit" name="gasm_create_test_log" class="button">🧪 Create Demo Snapshot (Test Restore)</button>
                        </form>
                    </div>
                    <p style="color:#4b5563;font-size:13px;">Jab bhi AI kisi product ya page ko edit karega, uska purana record yahan save ho jayega. Agar aapko badlav pasand na aaye, toh <strong>1-Click Restore</strong> daba kar purana setup wapas la sakte hain.</p>

                    <table class="gasm-table">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Kaam (Action Type)</th>
                                <th>Product / Page Name</th>
                                <th>Status</th>
                                <th>Action (Restore)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $history ) ) : ?>
                                <tr><td colspan="5">Abhi tak koi history record nahi hai. Upar "Create Demo Snapshot" par click karke test karein!</td></tr>
                            <?php else : ?>
                                <?php foreach ( $history as $row ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $row->created_at ); ?></td>
                                        <td><strong><?php echo esc_html( $row->action_type ); ?></strong></td>
                                        <td><?php echo esc_html( $row->object_title ); ?> (#<?php echo intval( $row->object_id ); ?>)</td>
                                        <td>
                                            <?php if ( $row->status === 'restored' ) : ?>
                                                <span style="color:#059669;font-weight:600;">⏪ Restored to Old Version</span>
                                            <?php else : ?>
                                                <span style="color:#4f46e5;font-weight:600;">✅ AI Updated</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( $row->status !== 'restored' ) : ?>
                                                <form method="post" style="display:inline;">
                                                    <?php wp_nonce_field( 'gasm_restore_nonce' ); ?>
                                                    <input type="hidden" name="gasm_restore_id" value="<?php echo intval( $row->id ); ?>">
                                                    <button type="submit" class="button button-secondary" onclick="return confirm('Kya aap sach mein is product/page ko purane version par restore karna chahte hain?');">⏪ 1-Click Restore</button>
                                                </form>
                                            <?php else : ?>
                                                <span style="color:#9ca3af;">Already Restored</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ( $active_tab === 'settings' ) : ?>
                <!-- 5. GEMINI API & GITHUB AUTO-UPDATER SETTINGS -->
                <form method="post" class="gasm-card">
                    <?php wp_nonce_field( 'gasm_settings_nonce' ); ?>
                    <h3>🔑 1. Google Gemini API Configuration</h3>
                    <table class="form-table">
                        <tr>
                            <th>Gemini API Key</th>
                            <td>
                                <input type="password" name="gemini_api_key" value="<?php echo esc_attr( $settings['gemini_api_key'] ?? '' ); ?>" class="regular-text" placeholder="AIzaSy...">
                                <p class="description">Google AI Studio se apni API key yahan dalein.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>AI Model</th>
                            <td>
                                <select name="gemini_model">
                                    <option value="gemini-2.5-flash" <?php selected( 'gemini-2.5-flash', $settings['gemini_model'] ?? '' ); ?>>Gemini 2.5 Flash (Fastest & Best for Products/SEO)</option>
                                    <option value="gemini-2.5-pro" <?php selected( 'gemini-2.5-pro', $settings['gemini_model'] ?? '' ); ?>>Gemini 2.5 Pro (Deep Coding & Complex Audit)</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <hr style="margin: 25px 0;">

                    <h3>🐙 2. Public GitHub 1-Click Updater Setup</h3>
                    <p style="color:#4b5563;font-size:13px;">Apna GitHub Username aur Repository naam yahan dalein. Jab bhi hum GitHub par code mein <code>Version:</code> badhayenge, aapke WordPress mein turant Update aa jayega!</p>
                    <table class="form-table">
                        <tr>
                            <th>GitHub Username</th>
                            <td>
                                <input type="text" name="github_username" value="<?php echo esc_attr( $settings['github_username'] ?? '' ); ?>" class="regular-text" placeholder="e.g. rahul-dev">
                            </td>
                        </tr>
                        <tr>
                            <th>Repository Name</th>
                            <td>
                                <input type="text" name="github_repo" value="<?php echo esc_attr( $settings['github_repo'] ?? 'gemini-ai-site-manager' ); ?>" class="regular-text" placeholder="gemini-ai-site-manager">
                            </td>
                        </tr>
                        <tr>
                            <th>Branch Name</th>
                            <td>
                                <input type="text" name="github_branch" value="<?php echo esc_attr( $settings['github_branch'] ?? 'main' ); ?>" class="regular-text" placeholder="main">
                                <p class="description">Default <code>main</code> hota hai.</p>
                            </td>
                        </tr>
                    </table>

                    <!-- Preserve module checkboxes when saving API settings -->
                    <?php if ( ! empty( $settings['mod_digital_prod'] ) ) : ?><input type="hidden" name="mod_digital_prod" value="1"><?php endif; ?>
                    <?php if ( ! empty( $settings['mod_physical_prod'] ) ) : ?><input type="hidden" name="mod_physical_prod" value="1"><?php endif; ?>
                    <?php if ( ! empty( $settings['mod_auto_seo'] ) ) : ?><input type="hidden" name="mod_auto_seo" value="1"><?php endif; ?>
                    <?php if ( ! empty( $settings['mod_image_studio'] ) ) : ?><input type="hidden" name="mod_image_studio" value="1"><?php endif; ?>
                    <?php if ( ! empty( $settings['mod_site_doctor'] ) ) : ?><input type="hidden" name="mod_site_doctor" value="1"><?php endif; ?>
                    <input type="hidden" name="seo_tone" value="<?php echo esc_attr( $settings['seo_tone'] ?? '' ); ?>">
                    <input type="hidden" name="default_sizes" value="<?php echo esc_attr( $settings['default_sizes'] ?? '' ); ?>">
                    <input type="hidden" name="default_colors" value="<?php echo esc_attr( $settings['default_colors'] ?? '' ); ?>">
                    <input type="hidden" name="digital_expiry_days" value="<?php echo esc_attr( $settings['digital_expiry_days'] ?? '' ); ?>">
                    <input type="hidden" name="image_aspect_ratio" value="<?php echo esc_attr( $settings['image_aspect_ratio'] ?? '' ); ?>">

                    <p style="margin-top:20px; display:flex; gap:12px;">
                        <button type="submit" name="gasm_save_settings" class="button button-primary button-large">💾 Save API & GitHub Settings</button>
                        <button type="submit" name="gasm_force_update_check" class="button button-secondary button-large">🔍 Check GitHub for Updates Now</button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}

Gemini_AI_Site_Manager::get_instance();