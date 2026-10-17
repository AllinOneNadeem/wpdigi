<?php
/**
 * Plugin Name: Gemini AI Site & Store Manager
 * Plugin URI:  https://github.com/
 * Description: Crash-Proof All-in-One Gemini AI Suite: WooCommerce Digital & Physical Product Generator, Live Preview, Full SEO Auditor, RankMath Grid Modules, 1-Click History Restore & Safe GitHub Updater.
 * Version:     2.0.1
 * Author:      AI Store Manager
 * Text Domain: gemini-ai-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GASM_VERSION', '2.0.1' );
define( 'GASM_PLUGIN_FILE', __FILE__ );
define( 'GASM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( ! class_exists( 'Gemini_AI_Site_Manager' ) ) {

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

            register_activation_hook( GASM_PLUGIN_FILE, array( $this, 'activate_plugin' ) );
            add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
            add_action( 'admin_init', array( $this, 'handle_form_submissions' ) );
            add_action( 'wp_head', array( $this, 'output_frontend_seo_meta' ), 1 );

            // Safe GitHub Auto-Updater Hooks
            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_github_update' ) );
            add_filter( 'upgrader_source_selection', array( $this, 'fix_github_folder_name' ), 10, 4 );
        }

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

            if ( ! get_option( 'gasm_settings' ) ) {
                update_option( 'gasm_settings', $this->get_default_settings() );
            }
        }

        private function get_default_settings() {
            return array(
                'gemini_api_key'       => '',
                'gemini_model'         => 'gemini-2.5-flash',
                'api_status'           => 'not_tested',
                'api_plan_info'        => 'Not Connected',
                'github_username'      => '',
                'github_repo'          => 'gemini-ai-site-manager',
                'github_branch'        => 'main',
                'github_status'        => 'not_tested',
                'github_remote_ver'    => '2.0.1',
                'mod_digital_prod'     => 1,
                'mod_physical_prod'    => 1,
                'mod_auto_seo'         => 1,
                'mod_image_studio'     => 1,
                'mod_site_doctor'      => 1,
                'mod_bulk_updater'     => 1,
                'mod_schema_markup'    => 1,
                'mod_speed_booster'    => 1,
                'seo_tone'             => 'Professional & High-Converting',
                'default_sizes'        => 'S, M, L, XL, XXL',
                'default_colors'       => 'Black, Brown, Tan, Navy',
                'digital_expiry_days'  => 'Unlimited',
                'image_aspect_ratio'   => '1:1 Square (Pure White Background)',
                'stat_products_added'  => 0,
                'stat_seo_updated'     => 0,
                'stat_images_gen'      => 0,
                'stat_bugs_fixed'      => 0,
            );
        }

        public function output_frontend_seo_meta() {
            if ( is_singular() ) {
                $post_id  = get_the_ID();
                $seo_desc = get_post_meta( $post_id, '_gasm_seo_description', true );
                $seo_keys = get_post_meta( $post_id, '_gasm_seo_keywords', true );
                if ( ! empty( $seo_desc ) ) {
                    echo '<meta name="description" content="' . esc_attr( $seo_desc ) . '" />' . "\n";
                }
                if ( ! empty( $seo_keys ) ) {
                    echo '<meta name="keywords" content="' . esc_attr( $seo_keys ) . '" />' . "\n";
                }
            }
        }

        private function clean_github_input( $username_or_url, $repo ) {
            $username_or_url = trim( (string) $username_or_url );
            if ( strpos( $username_or_url, 'github.com/' ) !== false ) {
                $parts = explode( 'github.com/', $username_or_url );
                $path  = trim( $parts[1], '/' );
                $segs  = explode( '/', $path );
                if ( ! empty( $segs[0] ) ) {
                    $username_or_url = $segs[0];
                }
                if ( ! empty( $segs[1] ) ) {
                    $repo = str_replace( '.git', '', $segs[1] );
                }
            }
            return array( 'user' => $username_or_url, 'repo' => trim( (string) $repo ) );
        }

        /**
         * CRASH-PROOF GITHUB UPDATER: Verifies EOF marker before allowing update
         */
        public function check_github_update( $transient ) {
            if ( empty( $transient->checked ) ) {
                return $transient;
            }

            $settings = get_option( 'gasm_settings', array() );
            $cleaned  = $this->clean_github_input( $settings['github_username'] ?? '', $settings['github_repo'] ?? '' );
            $username = $cleaned['user'];
            $repo     = $cleaned['repo'];
            $branch   = trim( $settings['github_branch'] ?? 'main' );

            if ( empty( $username ) || empty( $repo ) || strpos( $username, '@' ) !== false ) {
                return $transient;
            }

            $raw_url  = "https://raw.githubusercontent.com/{$username}/{$repo}/{$branch}/gemini-ai-site-manager.php";
            $response = wp_remote_get( $raw_url, array( 'timeout' => 10 ) );

            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                return $transient;
            }

            $remote_body = wp_remote_retrieve_body( $response );

            // Safety Check: Ensure remote file is complete and not half-pasted
            if ( strpos( $remote_body, '/* GASM_SAFE_EOF */' ) === false ) {
                return $transient;
            }

            if ( preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $remote_body, $matches ) ) {
                $remote_version = trim( $matches[1] );
                if ( version_compare( GASM_VERSION, $remote_version, '<' ) ) {
                    $zip_url = "https://github.com/{$username}/{$repo}/archive/refs/heads/{$branch}.zip";

                    $plugin_data              = new stdClass();
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

        public function call_gemini_ai( $prompt, $json_mode = false ) {
            $settings = get_option( 'gasm_settings', array() );
            $api_key  = trim( $settings['gemini_api_key'] ?? '' );
            $model    = trim( $settings['gemini_model'] ?? 'gemini-2.5-flash' );

            if ( empty( $api_key ) ) {
                return new WP_Error( 'no_api_key', 'Gemini API Key nahi dali gayi hai. Settings tab mein jakar API Key connect karein.' );
            }

            $url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
            $body = array(
                'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
                'generationConfig' => array( 'temperature' => 0.7 ),
            );

            if ( $json_mode ) {
                $body['generationConfig']['responseMimeType'] = 'application/json';
            }

            $response = wp_remote_post( $url, array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 45,
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 !== $code || empty( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
                $err_msg = $data['error']['message'] ?? 'Gemini API se response nahi aaya.';
                return new WP_Error( 'gemini_error', $err_msg );
            }

            return $data['candidates'][0]['content']['parts'][0]['text'];
        }

        public function generate_product_image_attachment( $title, $color = '#4f46e5' ) {
            $upload_dir  = wp_upload_dir();
            $clean_name  = sanitize_title( $title ) . '-' . time() . '.svg';
            $file_path   = trailingslashit( $upload_dir['path'] ) . $clean_name;
            $short_title = mb_substr( $title, 0, 30 );

            $svg_content = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800" viewBox="0 0 800 800">'
                . '<rect width="800" height="800" fill="#ffffff"/>'
                . '<rect x="40" y="40" width="720" height="720" rx="30" fill="#f8fafc" stroke="#e2e8f0" stroke-width="4"/>'
                . '<circle cx="400" cy="320" r="110" fill="' . esc_attr( $color ) . '" opacity="0.15"/>'
                . '<text x="400" y="340" font-family="Arial, sans-serif" font-size="60" font-weight="bold" fill="' . esc_attr( $color ) . '" text-anchor="middle">AI STORE</text>'
                . '<text x="400" y="500" font-family="Arial, sans-serif" font-size="30" font-weight="bold" fill="#1e293b" text-anchor="middle">' . esc_html( $short_title ) . '</text>'
                . '<text x="400" y="550" font-family="Arial, sans-serif" font-size="20" fill="#64748b" text-anchor="middle">Generated by Gemini AI Studio</text>'
                . '</svg>';

            file_put_contents( $file_path, $svg_content );

            $attachment = array(
                'guid'           => trailingslashit( $upload_dir['url'] ) . $clean_name,
                'post_mime_type' => 'image/svg+xml',
                'post_title'     => sanitize_text_field( $title ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            );

            $attach_id = wp_insert_attachment( $attachment, $file_path );
            update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );

            $settings = get_option( 'gasm_settings', array() );
            $settings['stat_images_gen'] = intval( $settings['stat_images_gen'] ?? 0 ) + 1;
            update_option( 'gasm_settings', $settings );

            return $attach_id;
        }

        public function handle_form_submissions() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $settings = get_option( 'gasm_settings', $this->get_default_settings() );

            if ( isset( $_POST['gasm_save_settings'] ) && check_admin_referer( 'gasm_settings_nonce' ) ) {
                if ( isset( $_POST['gemini_api_key'] ) ) {
                    $settings['gemini_api_key'] = sanitize_text_field( $_POST['gemini_api_key'] );
                    $settings['gemini_model']   = sanitize_text_field( $_POST['gemini_model'] ?? 'gemini-2.5-flash' );
                }
                if ( isset( $_POST['github_username'] ) ) {
                    $cleaned = $this->clean_github_input( $_POST['github_username'], $_POST['github_repo'] ?? 'gemini-ai-site-manager' );
                    $settings['github_username'] = sanitize_text_field( $cleaned['user'] );
                    $settings['github_repo']     = sanitize_text_field( $cleaned['repo'] );
                    $settings['github_branch']   = sanitize_text_field( $_POST['github_branch'] ?? 'main' );
                }
                update_option( 'gasm_settings', $settings );
                add_settings_error( 'gasm_messages', 'gasm_saved', '✅ Settings successfully save ho gayi hain!', 'updated' );
            }

            if ( isset( $_POST['gasm_save_modules'] ) && check_admin_referer( 'gasm_modules_nonce' ) ) {
                $mods = array( 'mod_digital_prod', 'mod_physical_prod', 'mod_auto_seo', 'mod_image_studio', 'mod_site_doctor', 'mod_bulk_updater', 'mod_schema_markup', 'mod_speed_booster' );
                foreach ( $mods as $m ) {
                    $settings[ $m ] = isset( $_POST[ $m ] ) ? 1 : 0;
                }
                $settings['seo_tone']            = sanitize_text_field( $_POST['seo_tone'] ?? '' );
                $settings['default_sizes']       = sanitize_text_field( $_POST['default_sizes'] ?? '' );
                $settings['default_colors']      = sanitize_text_field( $_POST['default_colors'] ?? '' );
                $settings['digital_expiry_days'] = sanitize_text_field( $_POST['digital_expiry_days'] ?? '' );
                $settings['image_aspect_ratio']  = sanitize_text_field( $_POST['image_aspect_ratio'] ?? '' );

                update_option( 'gasm_settings', $settings );
                add_settings_error( 'gasm_messages', 'gasm_mods_saved', '🎛️ Module Switches aur Controls save ho gaye!', 'updated' );
            }

            if ( isset( $_POST['gasm_test_gemini_api'] ) && check_admin_referer( 'gasm_settings_nonce' ) ) {
                if ( ! empty( $_POST['gemini_api_key'] ) ) {
                    $settings['gemini_api_key'] = sanitize_text_field( $_POST['gemini_api_key'] );
                    $settings['gemini_model']   = sanitize_text_field( $_POST['gemini_model'] ?? 'gemini-2.5-flash' );
                    update_option( 'gasm_settings', $settings );
                }
                $test = $this->call_gemini_ai( 'Reply with exact word: CONNECTED_OK' );
                if ( is_wp_error( $test ) ) {
                    $settings['api_status']    = 'error';
                    $settings['api_plan_info'] = 'Connection Failed: ' . $test->get_error_message();
                    update_option( 'gasm_settings', $settings );
                    add_settings_error( 'gasm_messages', 'gasm_api_err', '❌ Gemini API Test Failed: ' . $test->get_error_message(), 'error' );
                } else {
                    $settings['api_status']    = 'connected';
                    $settings['api_plan_info'] = 'Active & Verified | Model: ' . $settings['gemini_model'] . ' | Google AI Studio Ready';
                    update_option( 'gasm_settings', $settings );
                    add_settings_error( 'gasm_messages', 'gasm_api_ok', '🟢 Gemini API Successfully Connected!', 'updated' );
                }
            }

            if ( isset( $_POST['gasm_test_github'] ) && check_admin_referer( 'gasm_settings_nonce' ) ) {
                $cleaned  = $this->clean_github_input( $_POST['github_username'] ?? '', $_POST['github_repo'] ?? '' );
                $username = sanitize_text_field( $cleaned['user'] );
                $repo     = sanitize_text_field( $cleaned['repo'] );
                $branch   = sanitize_text_field( $_POST['github_branch'] ?? 'main' );

                $settings['github_username'] = $username;
                $settings['github_repo']     = $repo;
                $settings['github_branch']   = $branch;

                if ( strpos( $username, '@' ) !== false ) {
                    $settings['github_status'] = 'error';
                    update_option( 'gasm_settings', $settings );
                    add_settings_error( 'gasm_messages', 'gh_email_err', '⚠️ Kripya Email (@gmail.com) ki jagah apna GitHub Username ya GitHub Repo URL dalein.', 'error' );
                } else {
                    $raw_url  = "https://raw.githubusercontent.com/{$username}/{$repo}/{$branch}/gemini-ai-site-manager.php";
                    $response = wp_remote_get( $raw_url, array( 'timeout' => 10 ) );
                    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                        $settings['github_status'] = 'error';
                        update_option( 'gasm_settings', $settings );
                        add_settings_error( 'gasm_messages', 'gh_conn_err', "❌ GitHub File Nahi Mili! Check karein ki Username ({$username}) aur Repo ({$repo}) sahi hai.", 'error' );
                    } else {
                        $body    = wp_remote_retrieve_body( $response );
                        $rem_ver = 'Unknown';
                        if ( preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $body, $m ) ) {
                            $rem_ver = trim( $m[1] );
                        }
                        $is_safe = ( strpos( $body, '/* GASM_SAFE_EOF */' ) !== false );
                        $settings['github_status']     = 'connected';
                        $settings['github_remote_ver'] = $rem_ver;
                        update_option( 'gasm_settings', $settings );
                        delete_site_transient( 'update_plugins' );
                        wp_update_plugins();
                        $safe_msg = $is_safe ? '✅ Code Integrity Verified (Safe)' : '⚠️ Warning: GitHub file missing EOF marker';
                        add_settings_error( 'gasm_messages', 'gh_conn_ok', "🟢 GitHub Connected! Installed: v" . GASM_VERSION . " | GitHub Live: v{$rem_ver} | {$safe_msg}", 'updated' );
                    }
                }
            }

            if ( isset( $_POST['gasm_ai_fix_single_id'] ) && check_admin_referer( 'gasm_ai_fix_nonce' ) ) {
                $post_id = intval( $_POST['gasm_ai_fix_single_id'] );
                $res     = $this->ai_optimize_single_post( $post_id );
                if ( is_wp_error( $res ) ) {
                    add_settings_error( 'gasm_messages', 'fix_err', '❌ AI Fix Error: ' . $res->get_error_message(), 'error' );
                } else {
                    add_settings_error( 'gasm_messages', 'fix_ok', '✨ AI ne "' . esc_html( get_the_title( $post_id ) ) . '" ka SEO optimize kar diya! (History mein backup safe hai).', 'updated' );
                }
            }

            if ( isset( $_POST['gasm_bulk_ai_fix'] ) && check_admin_referer( 'gasm_ai_fix_nonce' ) ) {
                $issue_ids = array_map( 'intval', explode( ',', sanitize_text_field( $_POST['gasm_bulk_ids'] ?? '' ) ) );
                $issue_ids = array_slice( array_filter( $issue_ids ), 0, 5 );
                $fixed_cnt = 0;
                foreach ( $issue_ids as $pid ) {
                    $r = $this->ai_optimize_single_post( $pid );
                    if ( ! is_wp_error( $r ) ) {
                        $fixed_cnt++;
                    }
                }
                if ( $fixed_cnt > 0 ) {
                    add_settings_error( 'gasm_messages', 'bulk_ok', "🚀 Bulk AI Fix Complete! {$fixed_cnt} items optimize ho gaye.", 'updated' );
                } else {
                    add_settings_error( 'gasm_messages', 'bulk_err', '❌ Bulk Fix ke liye pehle Gemini API Key connect karein.', 'error' );
                }
            }

            if ( isset( $_POST['gasm_generate_product_preview'] ) && check_admin_referer( 'gasm_product_gen_nonce' ) ) {
                $prod_type  = sanitize_text_field( $_POST['prod_type'] ?? 'digital' );
                $basic_info = sanitize_textarea_field( $_POST['prod_basic_info'] ?? '' );
                $ref_url    = esc_url_raw( $_POST['prod_ref_url'] ?? '' );
                $price      = sanitize_text_field( $_POST['prod_price'] ?? '499' );
                $sale_price = sanitize_text_field( $_POST['prod_sale_price'] ?? '' );
                $dl_link    = esc_url_raw( $_POST['prod_download_link'] ?? '' );
                $sizes      = sanitize_text_field( $_POST['prod_sizes'] ?? $settings['default_sizes'] );
                $colors     = sanitize_text_field( $_POST['prod_colors'] ?? $settings['default_colors'] );

                $prompt = "You are an expert WooCommerce Copywriter & SEO Specialist. Create a product listing for:
                Type: {$prod_type}
                Info: {$basic_info}
                Reference: {$ref_url}
                Price: {$price}
                Return ONLY a valid JSON object with keys: title, short_description, full_description, seo_title, seo_description, focus_keyword, tags, category_name.";

                $ai_resp = $this->call_gemini_ai( $prompt, true );
                if ( is_wp_error( $ai_resp ) ) {
                    add_settings_error( 'gasm_messages', 'gen_err', '❌ AI Error: ' . $ai_resp->get_error_message(), 'error' );
                } else {
                    $clean_json = preg_replace( '/^```json|```$/m', '', trim( $ai_resp ) );
                    $parsed     = json_decode( trim( $clean_json ), true );
                    if ( is_array( $parsed ) ) {
                        $preview_data = array(
                            'prod_type'         => $prod_type,
                            'basic_info'        => $basic_info,
                            'ref_url'           => $ref_url,
                            'price'             => $price,
                            'sale_price'        => $sale_price,
                            'dl_link'           => $dl_link,
                            'sizes'             => $sizes,
                            'colors'            => $colors,
                            'title'             => $parsed['title'] ?? 'New AI Product',
                            'short_description' => $parsed['short_description'] ?? '',
                            'full_description'  => $parsed['full_description'] ?? '',
                            'seo_title'         => $parsed['seo_title'] ?? '',
                            'seo_description'   => $parsed['seo_description'] ?? '',
                            'focus_keyword'     => $parsed['focus_keyword'] ?? '',
                            'tags'              => $parsed['tags'] ?? '',
                            'category_name'     => $parsed['category_name'] ?? 'General',
                        );
                        set_transient( 'gasm_product_preview_' . get_current_user_id(), $preview_data, HOUR_IN_SECONDS );
                        add_settings_error( 'gasm_messages', 'gen_ok', '✨ Product Preview taiyar hai! Neeche review karke Publish dabayein.', 'updated' );
                    }
                }
            }

            if ( isset( $_POST['gasm_publish_preview_product'] ) && check_admin_referer( 'gasm_product_publish_nonce' ) ) {
                $pub_status = sanitize_text_field( $_POST['publish_status'] ?? 'publish' );
                $new_id     = $this->create_woocommerce_product_from_preview( $_POST, $pub_status );
                if ( $new_id ) {
                    delete_transient( 'gasm_product_preview_' . get_current_user_id() );
                    $view_link = get_permalink( $new_id );
                    add_settings_error( 'gasm_messages', 'pub_ok', "🚀 Product (#{$new_id}) create ho gaya! <a href='{$view_link}' target='_blank'>View Live</a>", 'updated' );
                }
            }

            if ( isset( $_POST['gasm_restore_id'] ) && check_admin_referer( 'gasm_restore_nonce' ) ) {
                $history_id = intval( $_POST['gasm_restore_id'] );
                if ( $this->restore_snapshot( $history_id ) ) {
                    add_settings_error( 'gasm_messages', 'gasm_restored', '⏪ 1-Click Restore Successful! Purana data wapas set ho gaya.', 'updated' );
                }
            }
        }

        private function ai_optimize_single_post( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'not_found', 'Item nahi mila.' );
            }

            $before_snapshot = array(
                'post_title'   => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'meta'         => array(
                    'rank_math_title'         => get_post_meta( $post_id, 'rank_math_title', true ),
                    'rank_math_description'   => get_post_meta( $post_id, 'rank_math_description', true ),
                    'rank_math_focus_keyword' => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
                    '_yoast_wpseo_title'      => get_post_meta( $post_id, '_yoast_wpseo_title', true ),
                    '_yoast_wpseo_metadesc'   => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
                    '_gasm_seo_description'   => get_post_meta( $post_id, '_gasm_seo_description', true ),
                ),
            );

            $prompt = "Optimize this WordPress {$post->post_type} for SEO:
            Title: {$post->post_title}
            Content: " . wp_strip_all_tags( mb_substr( $post->post_content, 0, 400 ) ) . "
            Return ONLY JSON with keys: optimized_title, meta_description, focus_keyword, short_excerpt.";

            $ai_res = $this->call_gemini_ai( $prompt, true );
            if ( is_wp_error( $ai_res ) ) {
                return $ai_res;
            }

            $clean_json = preg_replace( '/^```json|```$/m', '', trim( $ai_res ) );
            $data       = json_decode( trim( $clean_json ), true );
            if ( ! is_array( $data ) ) {
                return new WP_Error( 'bad_json', 'AI JSON parse error.' );
            }

            $new_title   = sanitize_text_field( $data['optimized_title'] ?? $post->post_title );
            $meta_desc   = sanitize_text_field( $data['meta_description'] ?? '' );
            $focus_kw    = sanitize_text_field( $data['focus_keyword'] ?? '' );
            $new_excerpt = wp_kses_post( $data['short_excerpt'] ?? $post->post_excerpt );

            $update_args = array( 'ID' => $post_id );
            if ( strlen( trim( $post->post_title ) ) < 25 && ! empty( $new_title ) ) {
                $update_args['post_title'] = $new_title;
            }
            if ( empty( $post->post_excerpt ) && ! empty( $new_excerpt ) ) {
                $update_args['post_excerpt'] = $new_excerpt;
            }
            wp_update_post( $update_args );

            if ( ! empty( $meta_desc ) ) {
                update_post_meta( $post_id, 'rank_math_description', $meta_desc );
                update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
                update_post_meta( $post_id, '_gasm_seo_description', $meta_desc );
            }
            if ( ! empty( $focus_kw ) ) {
                update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw );
                update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus_kw );
                update_post_meta( $post_id, '_gasm_seo_keywords', $focus_kw );
            }

            $settings = get_option( 'gasm_settings', array() );
            if ( ! has_post_thumbnail( $post_id ) && ! empty( $settings['mod_image_studio'] ) ) {
                $attach_id = $this->generate_product_image_attachment( $new_title );
                if ( $attach_id ) {
                    set_post_thumbnail( $post_id, $attach_id );
                }
            }

            $this->log_history_snapshot( 'AI SEO & Content Fix', $post_id, $post->post_title, $before_snapshot, $data );
            $settings['stat_seo_updated'] = intval( $settings['stat_seo_updated'] ?? 0 ) + 1;
            update_option( 'gasm_settings', $settings );

            return true;
        }

        private function create_woocommerce_product_from_preview( $post_data, $status = 'publish' ) {
            $title      = sanitize_text_field( $post_data['title'] ?? 'AI Generated Product' );
            $full_desc  = wp_kses_post( $post_data['full_description'] ?? '' );
            $short_desc = wp_kses_post( $post_data['short_description'] ?? '' );
            $prod_type  = sanitize_text_field( $post_data['prod_type'] ?? 'digital' );
            $price      = sanitize_text_field( $post_data['price'] ?? '499' );
            $sale_price = sanitize_text_field( $post_data['sale_price'] ?? '' );
            $dl_link    = esc_url_raw( $post_data['dl_link'] ?? '' );
            $sizes      = sanitize_text_field( $post_data['sizes'] ?? '' );
            $colors     = sanitize_text_field( $post_data['colors'] ?? '' );
            $seo_title  = sanitize_text_field( $post_data['seo_title'] ?? $title );
            $seo_desc   = sanitize_text_field( $post_data['seo_description'] ?? '' );
            $focus_kw   = sanitize_text_field( $post_data['focus_keyword'] ?? '' );
            $tags       = sanitize_text_field( $post_data['tags'] ?? '' );
            $cat_name   = sanitize_text_field( $post_data['category_name'] ?? '' );

            $post_id = wp_insert_post( array(
                'post_title'   => $title,
                'post_content' => $full_desc,
                'post_excerpt' => $short_desc,
                'post_status'  => $status,
                'post_type'    => post_type_exists( 'product' ) ? 'product' : 'post',
            ) );

            if ( ! $post_id || is_wp_error( $post_id ) ) {
                return false;
            }

            update_post_meta( $post_id, '_regular_price', $price );
            update_post_meta( $post_id, '_price', ! empty( $sale_price ) ? $sale_price : $price );
            if ( ! empty( $sale_price ) ) {
                update_post_meta( $post_id, '_sale_price', $sale_price );
            }

            if ( $prod_type === 'digital' ) {
                wp_set_object_terms( $post_id, 'simple', 'product_type' );
                update_post_meta( $post_id, '_virtual', 'yes' );
                update_post_meta( $post_id, '_downloadable', 'yes' );
                if ( ! empty( $dl_link ) ) {
                    $dl_id = md5( $dl_link );
                    update_post_meta( $post_id, '_downloadable_files', array(
                        $dl_id => array( 'id' => $dl_id, 'name' => $title . ' Download', 'file' => $dl_link ),
                    ) );
                }
            } else {
                wp_set_object_terms( $post_id, 'simple', 'product_type' );
                update_post_meta( $post_id, '_virtual', 'no' );
                update_post_meta( $post_id, '_downloadable', 'no' );
                $attributes = array();
                if ( ! empty( $sizes ) ) {
                    $attributes['size'] = array( 'name' => 'Size', 'value' => str_replace( ',', ' | ', $sizes ), 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 );
                }
                if ( ! empty( $colors ) ) {
                    $attributes['color'] = array( 'name' => 'Color', 'value' => str_replace( ',', ' | ', $colors ), 'position' => 1, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 );
                }
                if ( ! empty( $attributes ) ) {
                    update_post_meta( $post_id, '_product_attributes', $attributes );
                }
            }

            if ( ! empty( $cat_name ) && taxonomy_exists( 'product_cat' ) ) {
                wp_set_object_terms( $post_id, $cat_name, 'product_cat', true );
            }
            if ( ! empty( $tags ) && taxonomy_exists( 'product_tag' ) ) {
                wp_set_object_terms( $post_id, array_map( 'trim', explode( ',', $tags ) ), 'product_tag', true );
            }

            update_post_meta( $post_id, 'rank_math_title', $seo_title );
            update_post_meta( $post_id, 'rank_math_description', $seo_desc );
            update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw );
            update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_desc );
            update_post_meta( $post_id, '_gasm_seo_description', $seo_desc );

            $attach_id = $this->generate_product_image_attachment( $title );
            if ( $attach_id ) {
                set_post_thumbnail( $post_id, $attach_id );
            }

            $this->log_history_snapshot( 'New AI Product (' . ucfirst( $prod_type ) . ')', $post_id, $title, array(), $post_data );

            $settings = get_option( 'gasm_settings', array() );
            $settings['stat_products_added'] = intval( $settings['stat_products_added'] ?? 0 ) + 1;
            update_option( 'gasm_settings', $settings );

            return $post_id;
        }

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
            if ( ! empty( $row->object_id ) && ! empty( $before ) && is_array( $before ) ) {
                $update_post = array( 'ID' => intval( $row->object_id ) );
                if ( isset( $before['post_title'] ) ) {
                    $update_post['post_title'] = $before['post_title'];
                }
                if ( isset( $before['post_content'] ) ) {
                    $update_post['post_content'] = $before['post_content'];
                }
                if ( isset( $before['post_excerpt'] ) ) {
                    $update_post['post_excerpt'] = $before['post_excerpt'];
                }
                wp_update_post( $update_post );

                if ( ! empty( $before['meta'] ) && is_array( $before['meta'] ) ) {
                    foreach ( $before['meta'] as $meta_key => $meta_val ) {
                        update_post_meta( $row->object_id, $meta_key, $meta_val );
                    }
                }
            }

            $wpdb->update( $this->table_history, array( 'status' => 'restored' ), array( 'id' => $history_id ) );
            return true;
        }

        public function get_site_analytics() {
            $total_products    = 0;
            $digital_products  = 0;
            $physical_products = 0;
            $digital_list      = array();
            $physical_list     = array();

            if ( post_type_exists( 'product' ) ) {
                $all_prods = get_posts( array(
                    'post_type'      => 'product',
                    'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                    'posts_per_page' => -1,
                ) );

                foreach ( $all_prods as $p ) {
                    $total_products++;
                    $is_dl  = ( get_post_meta( $p->ID, '_downloadable', true ) === 'yes' );
                    $is_vir = ( get_post_meta( $p->ID, '_virtual', true ) === 'yes' );

                    if ( function_exists( 'wc_get_product' ) ) {
                        $wc_prod = wc_get_product( $p->ID );
                        if ( $wc_prod ) {
                            $is_dl  = $wc_prod->is_downloadable();
                            $is_vir = $wc_prod->is_virtual();
                        }
                    }

                    $dl_files = get_post_meta( $p->ID, '_downloadable_files', true );
                    if ( ! empty( $dl_files ) && is_array( $dl_files ) ) {
                        $is_dl = true;
                    }

                    $item_info = array(
                        'ID'     => $p->ID,
                        'title'  => $p->post_title,
                        'status' => $p->post_status,
                        'price'  => get_post_meta( $p->ID, '_price', true ),
                        'type'   => ( $is_dl || $is_vir ) ? 'Digital' : 'Physical',
                    );

                    if ( $is_dl || $is_vir ) {
                        $digital_products++;
                        $digital_list[] = $item_info;
                    } else {
                        $physical_products++;
                        $physical_list[] = $item_info;
                    }
                }
            }

            $pages_list = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 100 ) );
            $posts_list = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 100 ) );

            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins        = get_plugins();
            $active_plugins_raw = get_option( 'active_plugins', array() );
            $plugin_updates     = get_site_transient( 'update_plugins' );
            $update_plugins_map = ! empty( $plugin_updates->response ) ? $plugin_updates->response : array();

            $active_plugins_list   = array();
            $inactive_plugins_list = array();
            foreach ( $all_plugins as $file => $pdata ) {
                $needs_upd = isset( $update_plugins_map[ $file ] ) ? $update_plugins_map[ $file ]->new_version : false;
                $info      = array( 'file' => $file, 'name' => $pdata['Name'], 'version' => $pdata['Version'], 'new_version' => $needs_upd );
                if ( in_array( $file, $active_plugins_raw, true ) ) {
                    $active_plugins_list[] = $info;
                } else {
                    $inactive_plugins_list[] = $info;
                }
            }

            $all_themes     = wp_get_themes();
            $active_theme   = wp_get_theme();
            $theme_updates  = get_site_transient( 'update_themes' );
            $upd_themes_map = ! empty( $theme_updates->response ) ? $theme_updates->response : array();
            $themes_list    = array();
            foreach ( $all_themes as $slug => $tdata ) {
                $themes_list[] = array(
                    'slug'        => $slug,
                    'name'        => $tdata->get( 'Name' ),
                    'version'     => $tdata->get( 'Version' ),
                    'is_active'   => ( $active_theme->get_stylesheet() === $slug ),
                    'new_version' => isset( $upd_themes_map[ $slug ] ) ? $upd_themes_map[ $slug ]['new_version'] : false,
                );
            }

            $items_to_scan = get_posts( array(
                'post_type'      => post_type_exists( 'product' ) ? array( 'product', 'page', 'post' ) : array( 'page', 'post' ),
                'post_status'    => 'publish',
                'posts_per_page' => 100,
            ) );

            $seo_issues_list   = array();
            $missing_meta_desc = 0;
            $short_titles      = 0;
            $missing_featured  = 0;
            $total_scanned     = count( $items_to_scan );

            foreach ( $items_to_scan as $item ) {
                $probs = array();
                if ( strlen( trim( $item->post_title ) ) < 25 ) {
                    $short_titles++;
                    $probs[] = 'Title chota hai (<25 chars)';
                }
                $rm_desc    = get_post_meta( $item->ID, 'rank_math_description', true );
                $yoast_desc = get_post_meta( $item->ID, '_yoast_wpseo_metadesc', true );
                $gasm_desc  = get_post_meta( $item->ID, '_gasm_seo_description', true );
                if ( empty( $rm_desc ) && empty( $yoast_desc ) && empty( $gasm_desc ) && empty( $item->post_excerpt ) ) {
                    $missing_meta_desc++;
                    $probs[] = 'SEO Meta Description gayab hai';
                }
                if ( ! has_post_thumbnail( $item->ID ) ) {
                    $missing_featured++;
                    $probs[] = 'Featured Photo nahi hai';
                }
                if ( ! empty( $probs ) ) {
                    $seo_issues_list[] = array(
                        'ID'       => $item->ID,
                        'title'    => $item->post_title,
                        'type'     => ucfirst( $item->post_type ),
                        'problems' => implode( ' + ', $probs ),
                    );
                }
            }

            $seo_score = 100;
            if ( $total_scanned > 0 ) {
                $total_possible = $total_scanned * 3;
                $total_issues   = $missing_meta_desc + $short_titles + $missing_featured;
                $seo_score      = max( 10, round( ( ( $total_possible - $total_issues ) / $total_possible ) * 100 ) );
            }

            return array(
                'total_products'        => $total_products,
                'digital_products'      => $digital_products,
                'physical_products'     => $physical_products,
                'digital_list'          => $digital_list,
                'physical_list'         => $physical_list,
                'total_pages'           => count( $pages_list ),
                'total_posts'           => count( $posts_list ),
                'pages_list'            => $pages_list,
                'posts_list'            => $posts_list,
                'total_plugins'         => count( $all_plugins ),
                'active_plugins'        => count( $active_plugins_list ),
                'inactive_plugins'      => count( $inactive_plugins_list ),
                'active_plugins_list'   => $active_plugins_list,
                'inactive_plugins_list' => $inactive_plugins_list,
                'plugins_need_update'   => count( $update_plugins_map ),
                'total_themes'          => count( $all_themes ),
                'active_theme_name'     => $active_theme->get( 'Name' ),
                'themes_need_update'    => count( $upd_themes_map ),
                'themes_list'           => $themes_list,
                'seo_score'             => $seo_score,
                'total_scanned'         => $total_scanned,
                'missing_meta_desc'     => $missing_meta_desc,
                'short_titles'          => $short_titles,
                'missing_featured'      => $missing_featured,
                'seo_issues_list'       => $seo_issues_list,
            );
        }

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
            $settings   = get_option( 'gasm_settings', $this->get_default_settings() );
            $analytics  = $this->get_site_analytics();
            $active_tab = sanitize_text_field( $_GET['tab'] ?? 'dashboard' );
            ?>
            <style>
                .gasm-wrap { max-width: 1320px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1e293b; }
                .gasm-header { background: linear-gradient(135deg, #1e1b4b, #312e81, #4f46e5); color: #fff; padding: 25px 30px; border-radius: 14px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; box-shadow: 0 8px 20px rgba(79, 70, 229, 0.18); }
                .gasm-header h1 { color: #fff; margin: 0 0 6px 0; font-size: 24px; font-weight: 700; }
                .gasm-header p { margin: 0; opacity: 0.9; font-size: 14px; }
                .gasm-badge { background: rgba(255,255,255,0.2); padding: 7px 16px; border-radius: 20px; font-size: 13px; font-weight: 700; }
                .gasm-tabs { display: flex; gap: 10px; margin-bottom: 22px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; flex-wrap: wrap; }
                .gasm-tab { text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; color: #334155; background: #fff; border: 1px solid #cbd5e1; transition: 0.2s; font-size: 14px; }
                .gasm-tab.active, .gasm-tab:hover { background: #4f46e5; color: #fff; border-color: #4f46e5; }
                .gasm-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; margin-bottom: 24px; }
                .gasm-card { background: #fff; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
                .gasm-card h3 { margin-top: 0; font-size: 14px; color: #64748b; text-transform: uppercase; display: flex; justify-content: space-between; align-items: center; }
                .gasm-stat-big { font-size: 34px; font-weight: 800; color: #0f172a; margin: 8px 0; display: flex; justify-content: space-between; align-items: center; }
                .gasm-sub-row { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #475569; border-top: 1px solid #f1f5f9; padding-top: 9px; margin-top: 9px; }
                .gasm-view-btn { text-decoration: none; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 6px; background: #eef2ff; color: #4f46e5; border: 1px solid #c7d2fe; }
                .gasm-view-btn:hover { background: #4f46e5; color: #fff; }
                .gasm-rm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 18px; margin-top: 15px; }
                .gasm-rm-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; }
                .gasm-rm-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
                .gasm-rm-icon { font-size: 26px; background: #eef2ff; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 10px; }
                .gasm-rm-title { font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 6px 0; }
                .gasm-rm-desc { font-size: 13px; color: #64748b; margin: 0 0 14px 0; line-height: 1.45; }
                .gasm-rm-foot { border-top: 1px solid #f1f5f9; padding-top: 12px; display: flex; justify-content: space-between; align-items: center; }
                .gasm-switch { position: relative; display: inline-block; width: 48px; height: 25px; }
                .gasm-switch input { opacity: 0; width: 0; height: 0; }
                .gasm-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 25px; }
                .gasm-slider:before { position: absolute; content: ""; height: 19px; width: 19px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
                input:checked + .gasm-slider { background-color: #10b981; }
                input:checked + .gasm-slider:before { transform: translateX(23px); }
                .gasm-table { width: 100%; border-collapse: collapse; background: #fff; }
                .gasm-table th, .gasm-table td { padding: 11px 14px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 13px; }
                .gasm-table th { background: #f8fafc; font-weight: 700; }
                .gasm-issue-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 8px; margin-bottom: 10px; font-size: 14px; }
                .gasm-status-pill { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; }
                .gasm-status-ok { background: #dcfce7; color: #15803d; }
                .gasm-status-err { background: #fee2e2; color: #b91c1c; }
                .gasm-step-box { background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 15px 18px; margin-bottom: 18px; font-size: 13px; line-height: 1.6; }
            </style>

            <div class="gasm-wrap">
                <div class="gasm-header">
                    <div>
                        <h1>🤖 Gemini AI Site & Store Manager</h1>
                        <p>Crash-Proof Store Automation, Live Interactive X-Ray, Phase 2 AI Product Creator, 1-Click Restore & Safe GitHub Updater</p>
                    </div>
                    <div class="gasm-badge">Version <?php echo esc_html( GASM_VERSION ); ?> (Crash-Proof)</div>
                </div>

                <?php settings_errors( 'gasm_messages' ); ?>

                <div class="gasm-tabs">
                    <a href="?page=gemini-ai-manager&tab=dashboard" class="gasm-tab <?php echo $active_tab === 'dashboard' ? 'active' : ''; ?>">📊 Master Dashboard (X-Ray)</a>
                    <a href="?page=gemini-ai-manager&tab=product_creator" class="gasm-tab <?php echo $active_tab === 'product_creator' ? 'active' : ''; ?>">➕ Add Product with AI (Phase 2)</a>
                    <a href="?page=gemini-ai-manager&tab=modules" class="gasm-tab <?php echo $active_tab === 'modules' ? 'active' : ''; ?>">🎛️ On/Off Modules (4x2 Grid)</a>
                    <a href="?page=gemini-ai-manager&tab=history" class="gasm-tab <?php echo $active_tab === 'history' ? 'active' : ''; ?>">⏪ Work History & 1-Click Restore</a>
                    <a href="?page=gemini-ai-manager&tab=settings" class="gasm-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">⚙️ Gemini API & GitHub Updater</a>
                </div>

                <?php
                if ( $active_tab === 'product_creator' ) {
                    $this->render_tab_product_creator( $settings );
                } elseif ( $active_tab === 'modules' ) {
                    $this->render_tab_modules( $settings );
                } elseif ( $active_tab === 'history' ) {
                    $this->render_tab_history();
                } elseif ( $active_tab === 'settings' ) {
                    $this->render_tab_settings( $settings );
                } else {
                    $this->render_tab_dashboard( $settings, $analytics );
                }
                ?>
            </div>
            <?php
        }

        private function render_tab_dashboard( $settings, $analytics ) {
            $view_panel = sanitize_text_field( $_GET['view'] ?? '' );
            ?>
            <div class="gasm-grid">
                <div class="gasm-card">
                    <h3>
                        <span>📦 Total Store Products</span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=all_products#gasm-inspector" class="gasm-view-btn">👁️ View All</a>
                    </h3>
                    <div class="gasm-stat-big">
                        <span><?php echo esc_html( $analytics['total_products'] ); ?></span>
                        <a href="?page=gemini-ai-manager&tab=product_creator" class="button button-small button-primary">+ Add New AI</a>
                    </div>
                    <div class="gasm-sub-row">
                        <span>🧥 Physical: <strong><?php echo esc_html( $analytics['physical_products'] ); ?></strong></span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=physical_products#gasm-inspector" class="gasm-view-btn">👁️ View Physical</a>
                    </div>
                    <div class="gasm-sub-row">
                        <span>💻 Digital: <strong><?php echo esc_html( $analytics['digital_products'] ); ?></strong></span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=digital_products#gasm-inspector" class="gasm-view-btn">👁️ View Digital</a>
                    </div>
                </div>

                <div class="gasm-card">
                    <h3>
                        <span>📄 Website Pages & Posts</span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=pages#gasm-inspector" class="gasm-view-btn">👁️ Inspect</a>
                    </h3>
                    <div class="gasm-stat-big"><?php echo esc_html( $analytics['total_pages'] + $analytics['total_posts'] ); ?></div>
                    <div class="gasm-sub-row">
                        <span>📑 Pages: <strong><?php echo esc_html( $analytics['total_pages'] ); ?></strong></span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=pages#gasm-inspector" class="gasm-view-btn">👁️ View Pages</a>
                    </div>
                    <div class="gasm-sub-row">
                        <span>✍️ Blog Posts: <strong><?php echo esc_html( $analytics['total_posts'] ); ?></strong></span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=posts#gasm-inspector" class="gasm-view-btn">👁️ View Posts</a>
                    </div>
                </div>

                <div class="gasm-card">
                    <h3>
                        <span>🔌 Plugins & Themes Status</span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=plugins_themes#gasm-inspector" class="gasm-view-btn">👁️ View All</a>
                    </h3>
                    <div class="gasm-stat-big">
                        <span><?php echo esc_html( $analytics['active_plugins'] ); ?> / <?php echo esc_html( $analytics['total_plugins'] ); ?> <small style="font-size:13px;font-weight:normal;">Active</small></span>
                    </div>
                    <div class="gasm-sub-row">
                        <span>🟢 Active: <strong><?php echo esc_html( $analytics['active_plugins'] ); ?></strong> | ⚪ Inactive: <strong><?php echo esc_html( $analytics['inactive_plugins'] ); ?></strong></span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=plugins_themes#gasm-inspector" class="gasm-view-btn">👁️ Plugins</a>
                    </div>
                    <div class="gasm-sub-row">
                        <span>🎨 Theme: <strong><?php echo esc_html( $analytics['active_theme_name'] ); ?></strong> (<?php echo esc_html( $analytics['themes_need_update'] ); ?> upd)</span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=plugins_themes#gasm-inspector" class="gasm-view-btn">👁️ Themes</a>
                    </div>
                </div>

                <div class="gasm-card">
                    <h3>
                        <span>📈 Overall Website SEO Score</span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#gasm-inspector" class="gasm-view-btn">⚡ Improve</a>
                    </h3>
                    <div class="gasm-stat-big" style="color: <?php echo $analytics['seo_score'] >= 80 ? '#10b981' : ( $analytics['seo_score'] >= 50 ? '#f59e0b' : '#ef4444' ); ?>;">
                        <span><?php echo esc_html( $analytics['seo_score'] ); ?>%</span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#gasm-inspector" class="button button-small button-primary">⚡ Improve SEO Score</a>
                    </div>
                    <div class="gasm-sub-row">
                        <span>Scanned Items: <strong><?php echo esc_html( $analytics['total_scanned'] ); ?></strong></span>
                        <span>Issues: <strong style="color:#dc2626;"><?php echo count( $analytics['seo_issues_list'] ); ?></strong></span>
                    </div>
                    <div class="gasm-sub-row">
                        <span>AI Auto-Fix: <strong style="color:#10b981;">Ready</strong></span>
                        <a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#gasm-inspector" class="gasm-view-btn">🛠️ Fix All</a>
                    </div>
                </div>
            </div>

            <?php
            if ( ! empty( $view_panel ) ) {
                $this->render_inspector_drawer( $view_panel, $analytics );
            }
            ?>

            <div class="gasm-grid" style="grid-template-columns: 2fr 1fr;">
                <div class="gasm-card">
                    <h3>🔍 Website Mein Kahan Kya Problem Hai (Live Audit & Active AI Fix)</h3>
                    <?php if ( $analytics['missing_meta_desc'] > 0 ) { ?>
                        <div class="gasm-issue-item">
                            <span>⚠️ <strong><?php echo esc_html( $analytics['missing_meta_desc'] ); ?> Products/Pages</strong> mein SEO Meta Description gayab hai.</span>
                            <a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#gasm-inspector" class="button button-small button-primary">✨ AI Se Fix Karein</a>
                        </div>
                    <?php } ?>
                    <?php if ( $analytics['missing_featured'] > 0 ) { ?>
                        <div class="gasm-issue-item">
                            <span>🖼️ <strong><?php echo esc_html( $analytics['missing_featured'] ); ?> Products/Pages</strong> mein Main Photo (Featured Image) nahi lagi hai.</span>
                            <a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#gasm-inspector" class="button button-small button-primary">🎨 AI Image Fix</a>
                        </div>
                    <?php } ?>
                    <?php if ( $analytics['short_titles'] > 0 ) { ?>
                        <div class="gasm-issue-item">
                            <span>📝 <strong><?php echo esc_html( $analytics['short_titles'] ); ?> Items</strong> ka Title SEO ke hisab se chota (< 25 chars) hai.</span>
                            <a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#gasm-inspector" class="button button-small button-primary">⚡ Auto-Optimize Titles</a>
                        </div>
                    <?php } ?>
                </div>

                <div class="gasm-card">
                    <h3>🤖 AI Work Counter (Total Kaam)</h3>
                    <p>📦 Products Created: <strong><?php echo intval( $settings['stat_products_added'] ?? 0 ); ?></strong></p>
                    <p>📈 SEO Tags Updated: <strong><?php echo intval( $settings['stat_seo_updated'] ?? 0 ); ?></strong></p>
                    <p>🎨 AI Photos Generated: <strong><?php echo intval( $settings['stat_images_gen'] ?? 0 ); ?></strong></p>
                    <p>🛠️ Bugs & Cache Fixed: <strong><?php echo intval( $settings['stat_bugs_fixed'] ?? 0 ); ?></strong></p>
                    <hr>
                    <p style="font-size:12px;">Gemini API: <?php echo ( $settings['api_status'] ?? '' ) === 'connected' ? '<strong style="color:#10b981;">🟢 Connected</strong>' : '<a href="?page=gemini-ai-manager&tab=settings" style="color:#dc2626;font-weight:bold;">🔴 Connect API Key</a>'; ?></p>
                </div>
            </div>
            <?php
        }

        private function render_inspector_drawer( $view_panel, $analytics ) {
            ?>
            <div id="gasm-inspector" class="gasm-card" style="margin-bottom:24px; border: 2px solid #4f46e5;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h2 style="margin:0; font-size:18px; color:#1e1b4b;">📋 Selected Inspector View: <?php echo esc_html( strtoupper( str_replace( '_', ' ', $view_panel ) ) ); ?></h2>
                    <a href="?page=gemini-ai-manager&tab=dashboard" class="button">✖ Close View</a>
                </div>
                <?php
                if ( in_array( $view_panel, array( 'physical_products', 'digital_products', 'all_products' ), true ) ) {
                    $list = ( $view_panel === 'physical_products' ) ? $analytics['physical_list'] : ( ( $view_panel === 'digital_products' ) ? $analytics['digital_list'] : array_merge( $analytics['physical_list'], $analytics['digital_list'] ) );
                    echo '<table class="gasm-table"><thead><tr><th>ID</th><th>Product Name</th><th>Type</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
                    foreach ( array_slice( $list, 0, 50 ) as $item ) {
                        echo '<tr>';
                        echo '<td>#' . intval( $item['ID'] ) . '</td>';
                        echo '<td><strong>' . esc_html( $item['title'] ) . '</strong></td>';
                        echo '<td><span class="gasm-status-pill">' . esc_html( $item['type'] ) . '</span></td>';
                        echo '<td>₹' . esc_html( $item['price'] ?: '0' ) . '</td>';
                        echo '<td>' . esc_html( ucfirst( $item['status'] ) ) . '</td>';
                        echo '<td style="display:flex;gap:8px;">';
                        echo '<a href="' . esc_url( get_permalink( $item['ID'] ) ) . '" target="_blank" class="button button-small">👁️ View</a>';
                        echo '<a href="' . esc_url( get_edit_post_link( $item['ID'] ) ) . '" class="button button-small">✏️ Edit</a>';
                        echo '<form method="post" style="display:inline;">' . wp_nonce_field( 'gasm_ai_fix_nonce', '_wpnonce', true, false ) . '<input type="hidden" name="gasm_ai_fix_single_id" value="' . intval( $item['ID'] ) . '"><button type="submit" class="button button-small button-primary">✨ AI SEO Fix</button></form>';
                        echo '</td></tr>';
                    }
                    echo '</tbody></table>';
                } elseif ( in_array( $view_panel, array( 'pages', 'posts' ), true ) ) {
                    $plist = ( $view_panel === 'pages' ) ? $analytics['pages_list'] : $analytics['posts_list'];
                    echo '<table class="gasm-table"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
                    foreach ( $plist as $p ) {
                        echo '<tr><td>#' . intval( $p->ID ) . '</td><td><strong>' . esc_html( $p->post_title ) . '</strong></td><td>' . esc_html( $p->post_status ) . '</td>';
                        echo '<td style="display:flex;gap:8px;"><a href="' . esc_url( get_permalink( $p->ID ) ) . '" target="_blank" class="button button-small">👁️ View</a><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '" class="button button-small">✏️ Edit</a>';
                        echo '<form method="post" style="display:inline;">' . wp_nonce_field( 'gasm_ai_fix_nonce', '_wpnonce', true, false ) . '<input type="hidden" name="gasm_ai_fix_single_id" value="' . intval( $p->ID ) . '"><button type="submit" class="button button-small button-primary">✨ AI Optimize</button></form></td></tr>';
                    }
                    echo '</tbody></table>';
                } elseif ( $view_panel === 'plugins_themes' ) {
                    echo '<div class="gasm-grid" style="grid-template-columns:1fr 1fr;"><div><h4>🔌 Plugins (Active: ' . count( $analytics['active_plugins_list'] ) . ' | Inactive: ' . count( $analytics['inactive_plugins_list'] ) . ')</h4>';
                    echo '<table class="gasm-table"><thead><tr><th>Plugin</th><th>Status</th><th>Version</th></tr></thead><tbody>';
                    foreach ( $analytics['inactive_plugins_list'] as $pl ) {
                        echo '<tr style="background:#fffbeb;"><td><strong>' . esc_html( $pl['name'] ) . '</strong></td><td>⚪ Inactive</td><td>v' . esc_html( $pl['version'] ) . '</td></tr>';
                    }
                    foreach ( array_slice( $analytics['active_plugins_list'], 0, 25 )
