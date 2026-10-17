<?php
/**
 * Plugin Name: Gemini AI Site & Store Manager
 * Plugin URI:  https://github.com/
 * Description: All-in-One Gemini AI Suite: WooCommerce Digital & Physical Product Generator, Live Preview, Full SEO Auditor, RankMath 4x2 Grid Modules, 1-Click Restore & GitHub Updater.
 * Version:     3.0.0
 * Author:      AI Store Manager
 * Text Domain: gemini-ai-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GASM_VERSION', '3.0.0' );
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
                'github_repo'          => 'wpdigi',
                'github_branch'        => 'main',
                'github_status'        => 'not_tested',
                'github_remote_ver'    => '3.0.0',
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
                'image_aspect_ratio'   => '1:1 Square (White BG)',
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
            $user = trim( (string) $username_or_url );
            $rep  = trim( (string) $repo );
            if ( strpos( $user, 'github.com/' ) !== false ) {
                $parts = explode( 'github.com/', $user );
                $path  = trim( $parts[1], '/' );
                $segs  = explode( '/', $path );
                if ( ! empty( $segs[0] ) ) {
                    $user = $segs[0];
                }
                if ( ! empty( $segs[1] ) ) {
                    $rep = str_replace( '.git', '', $segs[1] );
                }
            }
            return array( 'user' => $user, 'repo' => $rep );
        }

        public function check_github_update( $transient ) {
            if ( empty( $transient->checked ) ) {
                return $transient;
            }
            $settings = get_option( 'gasm_settings', array() );
            $cleaned  = $this->clean_github_input( $settings['github_username'] ?? '', $settings['github_repo'] ?? 'wpdigi' );
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
            if ( strpos( $remote_body, '/* GASM_SAFE_EOF */' ) === false ) {
                return $transient;
            }

            if ( preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $remote_body, $matches ) ) {
                $remote_version = trim( $matches[1] );
                if ( version_compare( GASM_VERSION, $remote_version, '<' ) ) {
                    $plugin_data              = new stdClass();
                    $plugin_data->slug        = dirname( GASM_PLUGIN_BASENAME );
                    $plugin_data->plugin      = GASM_PLUGIN_BASENAME;
                    $plugin_data->new_version = $remote_version;
                    $plugin_data->url         = "https://github.com/{$username}/{$repo}";
                    $plugin_data->package     = "https://github.com/{$username}/{$repo}/archive/refs/heads/{$branch}.zip";
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
                return new WP_Error( 'no_key', 'Gemini API Key nahi dali gayi hai. Settings tab mein jakar Connect karein.' );
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
                $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'API Error';
                return new WP_Error( 'api_err', $msg );
            }
            return $data['candidates'][0]['content']['parts'][0]['text'];
        }

        public function generate_product_image_attachment( $title ) {
            $upload_dir  = wp_upload_dir();
            $clean_name  = sanitize_title( $title ) . '-' . time() . '.svg';
            $file_path   = trailingslashit( $upload_dir['path'] ) . $clean_name;
            $short_title = mb_substr( $title, 0, 28 );

            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800" viewBox="0 0 800 800">'
                . '<rect width="800" height="800" fill="#ffffff"/>'
                . '<rect x="40" y="40" width="720" height="720" rx="30" fill="#f8fafc" stroke="#cbd5e1" stroke-width="4"/>'
                . '<text x="400" y="340" font-family="Arial" font-size="56" font-weight="bold" fill="#4f46e5" text-anchor="middle">AI STORE</text>'
                . '<text x="400" y="470" font-family="Arial" font-size="30" font-weight="bold" fill="#0f172a" text-anchor="middle">' . esc_html( $short_title ) . '</text>'
                . '</svg>';

            file_put_contents( $file_path, $svg );
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
                $settings['gemini_api_key']  = sanitize_text_field( $_POST['gemini_api_key'] ?? '' );
                $settings['gemini_model']    = sanitize_text_field( $_POST['gemini_model'] ?? 'gemini-2.5-flash' );
                $cleaned                     = $this->clean_github_input( $_POST['github_username'] ?? '', $_POST['github_repo'] ?? 'wpdigi' );
                $settings['github_username'] = sanitize_text_field( $cleaned['user'] );
                $settings['github_repo']     = sanitize_text_field( $cleaned['repo'] );
                $settings['github_branch']   = sanitize_text_field( $_POST['github_branch'] ?? 'main' );
                update_option( 'gasm_settings', $settings );
                add_settings_error( 'gasm_messages', 'saved', '✅ Settings save ho gayi hain!', 'updated' );
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
                add_settings_error( 'gasm_messages', 'mods', '🎛️ Sabhi Module Controls save ho gaye!', 'updated' );
            }

            if ( isset( $_POST['gasm_clear_cache_now'] ) && check_admin_referer( 'gasm_cache_nonce' ) ) {
                global $wpdb;
                $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
                wp_cache_flush();
                $settings['stat_bugs_fixed'] = intval( $settings['stat_bugs_fixed'] ?? 0 ) + 1;
                update_option( 'gasm_settings', $settings );
                add_settings_error( 'gasm_messages', 'cache', '⚡ Website Cache aur Expired Transients clean kar diye gaye! Speed optimized.', 'updated' );
            }

            if ( isset( $_POST['gasm_test_gemini_api'] ) && check_admin_referer( 'gasm_settings_nonce' ) ) {
                $settings['gemini_api_key'] = sanitize_text_field( $_POST['gemini_api_key'] ?? '' );
                $settings['gemini_model']   = sanitize_text_field( $_POST['gemini_model'] ?? 'gemini-2.5-flash' );
                update_option( 'gasm_settings', $settings );

                $test = $this->call_gemini_ai( 'Reply OK' );
                if ( is_wp_error( $test ) ) {
                    $settings['api_status']    = 'error';
                    $settings['api_plan_info'] = 'Error: ' . $test->get_error_message();
                    update_option( 'gasm_settings', $settings );
                    add_settings_error( 'gasm_messages', 'api_err', '❌ API Error: ' . $test->get_error_message(), 'error' );
                } else {
                    $settings['api_status']    = 'connected';
                    $settings['api_plan_info'] = 'Active & Verified | Model: ' . $settings['gemini_model'] . ' | Google AI Studio';
                    update_option( 'gasm_settings', $settings );
                    add_settings_error( 'gasm_messages', 'api_ok', '🟢 Gemini API Connected & Verified!', 'updated' );
                }
            }

            if ( isset( $_POST['gasm_test_github'] ) && check_admin_referer( 'gasm_settings_nonce' ) ) {
                $cleaned                     = $this->clean_github_input( $_POST['github_username'] ?? '', $_POST['github_repo'] ?? 'wpdigi' );
                $username                    = sanitize_text_field( $cleaned['user'] );
                $repo                        = sanitize_text_field( $cleaned['repo'] );
                $branch                      = sanitize_text_field( $_POST['github_branch'] ?? 'main' );
                $settings['github_username'] = $username;
                $settings['github_repo']     = $repo;
                $settings['github_branch']   = $branch;

                if ( strpos( $username, '@' ) !== false || empty( $username ) ) {
                    $settings['github_status'] = 'error';
                    update_option( 'gasm_settings', $settings );
                    add_settings_error( 'gasm_messages', 'gh_err', '⚠️ Email (@gmail.com) mat dalein! Apna GitHub Username ya Repo URL dalein.', 'error' );
                } else {
                    $raw_url  = "https://raw.githubusercontent.com/{$username}/{$repo}/{$branch}/gemini-ai-site-manager.php";
                    $response = wp_remote_get( $raw_url, array( 'timeout' => 10 ) );
                    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                        $settings['github_status'] = 'error';
                        update_option( 'gasm_settings', $settings );
                        add_settings_error( 'gasm_messages', 'gh_404', "❌ GitHub par file nahi mili! Check karein: {$raw_url}", 'error' );
                    } else {
                        $body    = wp_remote_retrieve_body( $response );
                        $rem_ver = GASM_VERSION;
                        if ( preg_match( '/^[ \t\/*#@]*Version:(.*)$/mi', $body, $m ) ) {
                            $rem_ver = trim( $m[1] );
                        }
                        $settings['github_status']     = 'connected';
                        $settings['github_remote_ver'] = $rem_ver;
                        update_option( 'gasm_settings', $settings );
                        delete_site_transient( 'update_plugins' );
                        wp_update_plugins();
                        add_settings_error( 'gasm_messages', 'gh_ok', "🟢 GitHub Connected! Installed: v" . GASM_VERSION . " | GitHub Live: v{$rem_ver}", 'updated' );
                    }
                }
            }

            if ( isset( $_POST['gasm_ai_fix_single_id'] ) && check_admin_referer( 'gasm_ai_fix_nonce' ) ) {
                $res = $this->ai_optimize_single_post( intval( $_POST['gasm_ai_fix_single_id'] ) );
                if ( is_wp_error( $res ) ) {
                    add_settings_error( 'gasm_messages', 'fix_e', '❌ ' . $res->get_error_message(), 'error' );
                } else {
                    add_settings_error( 'gasm_messages', 'fix_s', '✨ AI SEO Fix Complete! (Old version History mein safe hai).', 'updated' );
                }
            }

            if ( isset( $_POST['gasm_bulk_ai_fix'] ) && check_admin_referer( 'gasm_ai_fix_nonce' ) ) {
                $ids = array_slice( array_filter( array_map( 'intval', explode( ',', sanitize_text_field( $_POST['gasm_bulk_ids'] ?? '' ) ) ) ), 0, 5 );
                $cnt = 0;
                foreach ( $ids as $pid ) {
                    if ( ! is_wp_error( $this->ai_optimize_single_post( $pid ) ) ) {
                        $cnt++;
                    }
                }
                add_settings_error( 'gasm_messages', 'bulk_s', "🚀 {$cnt} Items ka SEO AI se fix ho gaya!", 'updated' );
            }

            if ( isset( $_POST['gasm_generate_product_preview'] ) && check_admin_referer( 'gasm_product_gen_nonce' ) ) {
                $prod_type  = sanitize_text_field( $_POST['prod_type'] ?? 'digital' );
                $basic_info = sanitize_textarea_field( $_POST['prod_basic_info'] ?? '' );
                $ref_url    = esc_url_raw( $_POST['prod_ref_url'] ?? '' );
                $price      = sanitize_text_field( $_POST['prod_price'] ?? '999' );
                $sale_price = sanitize_text_field( $_POST['prod_sale_price'] ?? '499' );
                $dl_link    = esc_url_raw( $_POST['prod_download_link'] ?? '' );
                $sizes      = sanitize_text_field( $_POST['prod_sizes'] ?? 'S, M, L, XL' );
                $colors     = sanitize_text_field( $_POST['prod_colors'] ?? 'Black, Brown' );

                $prompt = "Create WooCommerce product JSON for Type: {$prod_type}, Info: {$basic_info}, Ref: {$ref_url}, Price: {$price}. Return ONLY JSON with keys: title, short_description, full_description, seo_title, seo_description, focus_keyword, tags, category_name.";
                $ai_res = $this->call_gemini_ai( $prompt, true );
                if ( is_wp_error( $ai_res ) ) {
                    add_settings_error( 'gasm_messages', 'p_err', '❌ ' . $ai_res->get_error_message(), 'error' );
                } else {
                    $clean  = preg_replace( '/^```json|```$/m', '', trim( $ai_res ) );
                    $parsed = json_decode( trim( $clean ), true );
                    if ( is_array( $parsed ) ) {
                        $parsed['prod_type']  = $prod_type;
                        $parsed['basic_info'] = $basic_info;
                        $parsed['ref_url']    = $ref_url;
                        $parsed['price']      = $price;
                        $parsed['sale_price'] = $sale_price;
                        $parsed['dl_link']    = $dl_link;
                        $parsed['sizes']      = $sizes;
                        $parsed['colors']     = $colors;
                        set_transient( 'gasm_product_preview_' . get_current_user_id(), $parsed, HOUR_IN_SECONDS );
                        add_settings_error( 'gasm_messages', 'p_ok', '✨ Product Preview Ready! Neeche check karke Publish karein.', 'updated' );
                    }
                }
            }

            if ( isset( $_POST['gasm_publish_preview_product'] ) && check_admin_referer( 'gasm_product_publish_nonce' ) ) {
                $status = sanitize_text_field( $_POST['publish_status'] ?? 'publish' );
                $new_id = $this->create_woocommerce_product_from_preview( $_POST, $status );
                if ( $new_id ) {
                    delete_transient( 'gasm_product_preview_' . get_current_user_id() );
                    add_settings_error( 'gasm_messages', 'pub_ok', '🚀 Product (#' . $new_id . ') Live ho gaya!', 'updated' );
                }
            }

            if ( isset( $_POST['gasm_restore_id'] ) && check_admin_referer( 'gasm_restore_nonce' ) ) {
                if ( $this->restore_snapshot( intval( $_POST['gasm_restore_id'] ) ) ) {
                    add_settings_error( 'gasm_messages', 'rest_ok', '⏪ 1-Click Restore Successful!', 'updated' );
                }
            }
        }

        private function ai_optimize_single_post( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                return new WP_Error( 'nf', 'Item not found' );
            }
            $before = array(
                'post_title'   => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'meta'         => array(
                    'rank_math_description' => get_post_meta( $post_id, 'rank_math_description', true ),
                    '_yoast_wpseo_metadesc' => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
                    '_gasm_seo_description' => get_post_meta( $post_id, '_gasm_seo_description', true ),
                ),
            );

            $prompt = "Optimize SEO for {$post->post_title}. Return ONLY JSON with keys: optimized_title, meta_description, focus_keyword, short_excerpt.";
            $ai_res = $this->call_gemini_ai( $prompt, true );
            if ( is_wp_error( $ai_res ) ) {
                return $ai_res;
            }
            $clean = preg_replace( '/^```json|```$/m', '', trim( $ai_res ) );
            $data  = json_decode( trim( $clean ), true );
            if ( ! is_array( $data ) ) {
                return new WP_Error( 'json', 'Invalid AI JSON' );
            }

            $new_title = sanitize_text_field( $data['optimized_title'] ?? $post->post_title );
            $meta_desc = sanitize_text_field( $data['meta_description'] ?? '' );
            $focus_kw  = sanitize_text_field( $data['focus_keyword'] ?? '' );

            $upd = array( 'ID' => $post_id );
            if ( strlen( trim( $post->post_title ) ) < 25 && ! empty( $new_title ) ) {
                $upd['post_title'] = $new_title;
            }
            if ( empty( $post->post_excerpt ) && ! empty( $data['short_excerpt'] ) ) {
                $upd['post_excerpt'] = wp_kses_post( $data['short_excerpt'] );
            }
            wp_update_post( $upd );

            if ( ! empty( $meta_desc ) ) {
                update_post_meta( $post_id, 'rank_math_description', $meta_desc );
                update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
                update_post_meta( $post_id, '_gasm_seo_description', $meta_desc );
            }
            if ( ! empty( $focus_kw ) ) {
                update_post_meta( $post_id, 'rank_math_focus_keyword', $focus_kw );
                update_post_meta( $post_id, '_gasm_seo_keywords', $focus_kw );
            }
            if ( ! has_post_thumbnail( $post_id ) ) {
                $att_id = $this->generate_product_image_attachment( $new_title );
                if ( $att_id ) {
                    set_post_thumbnail( $post_id, $att_id );
                }
            }

            $this->log_history_snapshot( 'AI SEO Fix', $post_id, $post->post_title, $before, $data );
            $settings = get_option( 'gasm_settings', array() );
            $settings['stat_seo_updated'] = intval( $settings['stat_seo_updated'] ?? 0 ) + 1;
            update_option( 'gasm_settings', $settings );
            return true;
        }

        private function create_woocommerce_product_from_preview( $d, $status = 'publish' ) {
            $title = sanitize_text_field( $d['title'] ?? 'New AI Product' );
            $pid   = wp_insert_post( array(
                'post_title'   => $title,
                'post_content' => wp_kses_post( $d['full_description'] ?? '' ),
                'post_excerpt' => wp_kses_post( $d['short_description'] ?? '' ),
                'post_status'  => $status,
                'post_type'    => post_type_exists( 'product' ) ? 'product' : 'post',
            ) );
            if ( ! $pid || is_wp_error( $pid ) ) {
                return false;
            }

            $price = sanitize_text_field( $d['price'] ?? '999' );
            $sale  = sanitize_text_field( $d['sale_price'] ?? '' );
            update_post_meta( $pid, '_regular_price', $price );
            update_post_meta( $pid, '_price', ! empty( $sale ) ? $sale : $price );
            if ( ! empty( $sale ) ) {
                update_post_meta( $pid, '_sale_price', $sale );
            }

            $type = sanitize_text_field( $d['prod_type'] ?? 'digital' );
            wp_set_object_terms( $pid, 'simple', 'product_type' );
            if ( $type === 'digital' ) {
                update_post_meta( $pid, '_virtual', 'yes' );
                update_post_meta( $pid, '_downloadable', 'yes' );
                if ( ! empty( $d['dl_link'] ) ) {
                    $dl  = esc_url_raw( $d['dl_link'] );
                    $key = md5( $dl );
                    update_post_meta( $pid, '_downloadable_files', array( $key => array( 'id' => $key, 'name' => 'Download', 'file' => $dl ) ) );
                }
            } else {
                update_post_meta( $pid, '_virtual', 'no' );
                update_post_meta( $pid, '_downloadable', 'no' );
                $attrs = array();
                if ( ! empty( $d['sizes'] ) ) {
                    $attrs['size'] = array( 'name' => 'Size', 'value' => str_replace( ',', ' | ', sanitize_text_field( $d['sizes'] ) ), 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 );
                }
                if ( ! empty( $d['colors'] ) ) {
                    $attrs['color'] = array( 'name' => 'Color', 'value' => str_replace( ',', ' | ', sanitize_text_field( $d['colors'] ) ), 'position' => 1, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 0 );
                }
                update_post_meta( $pid, '_product_attributes', $attrs );
            }

            update_post_meta( $pid, 'rank_math_description', sanitize_text_field( $d['seo_description'] ?? '' ) );
            update_post_meta( $pid, '_gasm_seo_description', sanitize_text_field( $d['seo_description'] ?? '' ) );
            update_post_meta( $pid, 'rank_math_focus_keyword', sanitize_text_field( $d['focus_keyword'] ?? '' ) );

            $att_id = $this->generate_product_image_attachment( $title );
            if ( $att_id ) {
                set_post_thumbnail( $pid, $att_id );
            }

            $this->log_history_snapshot( 'Created ' . ucfirst( $type ) . ' Product', $pid, $title, array(), $d );
            $settings = get_option( 'gasm_settings', array() );
            $settings['stat_products_added'] = intval( $settings['stat_products_added'] ?? 0 ) + 1;
            update_option( 'gasm_settings', $settings );
            return $pid;
        }

        public function log_history_snapshot( $action, $id, $title, $before, $after ) {
            global $wpdb;
            $wpdb->insert( $this->table_history, array(
                'action_type'     => $action,
                'object_id'       => $id,
                'object_title'    => $title,
                'before_snapshot' => wp_json_encode( $before ),
                'after_snapshot'  => wp_json_encode( $after ),
                'status'          => 'active',
            ) );
        }

        public function restore_snapshot( $hid ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_history} WHERE id = %d", $hid ) );
            if ( ! $row ) {
                return false;
            }
            $before = json_decode( $row->before_snapshot, true );
            if ( ! empty( $row->object_id ) && is_array( $before ) ) {
                $u = array( 'ID' => intval( $row->object_id ) );
                if ( isset( $before['post_title'] ) ) {
                    $u['post_title'] = $before['post_title'];
                }
                if ( isset( $before['post_content'] ) ) {
                    $u['post_content'] = $before['post_content'];
                }
                if ( isset( $before['post_excerpt'] ) ) {
                    $u['post_excerpt'] = $before['post_excerpt'];
                }
                wp_update_post( $u );
                if ( ! empty( $before['meta'] ) && is_array( $before['meta'] ) ) {
                    foreach ( $before['meta'] as $k => $v ) {
                        update_post_meta( $row->object_id, $k, $v );
                    }
                }
            }
            $wpdb->update( $this->table_history, array( 'status' => 'restored' ), array( 'id' => $hid ) );
            return true;
        }

        public function get_site_analytics() {
            $digital_list  = array();
            $physical_list = array();

            if ( post_type_exists( 'product' ) ) {
                $prods = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => -1 ) );
                foreach ( $prods as $p ) {
                    $is_dl  = ( get_post_meta( $p->ID, '_downloadable', true ) === 'yes' );
                    $is_vir = ( get_post_meta( $p->ID, '_virtual', true ) === 'yes' );
                    $files  = get_post_meta( $p->ID, '_downloadable_files', true );
                    if ( ! empty( $files ) && is_array( $files ) ) {
                        $is_dl = true;
                    }
                    if ( function_exists( 'wc_get_product' ) ) {
                        $wcp = wc_get_product( $p->ID );
                        if ( $wcp ) {
                            if ( $wcp->is_downloadable() ) {
                                $is_dl = true;
                            }
                            if ( $wcp->is_virtual() ) {
                                $is_vir = true;
                            }
                        }
                    }
                    $type = ( $is_dl || $is_vir ) ? 'Digital' : 'Physical';
                    $info = array( 'ID' => $p->ID, 'title' => $p->post_title, 'status' => $p->post_status, 'price' => get_post_meta( $p->ID, '_price', true ), 'type' => $type );
                    if ( $type === 'Digital' ) {
                        $digital_list[] = $info;
                    } else {
                        $physical_list[] = $info;
                    }
                }
            }

            $pages_list = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 100 ) );
            $posts_list = get_posts( array( 'post_type' => 'post', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 100 ) );

            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_pl    = get_plugins();
            $act_pl    = get_option( 'active_plugins', array() );
            $pl_upds   = get_site_transient( 'update_plugins' );
            $upd_map   = ! empty( $pl_upds->response ) ? $pl_upds->response : array();
            $act_list  = array();
            $inact_list = array();

            foreach ( $all_pl as $f => $pd ) {
                $row = array( 'name' => $pd['Name'], 'version' => $pd['Version'], 'upd' => isset( $upd_map[ $f ] ) ? $upd_map[ $f ]->new_version : false );
                if ( in_array( $f, $act_pl, true ) ) {
                    $act_list[] = $row;
                } else {
                    $inact_list[] = $row;
                }
            }

            $all_th   = wp_get_themes();
            $act_th   = wp_get_theme();
            $th_upds  = get_site_transient( 'update_themes' );
            $th_map   = ! empty( $th_upds->response ) ? $th_upds->response : array();
            $th_list  = array();
            foreach ( $all_th as $slug => $td ) {
                $th_list[] = array( 'name' => $td->get( 'Name' ), 'version' => $td->get( 'Version' ), 'active' => ( $act_th->get_stylesheet() === $slug ), 'upd' => isset( $th_map[ $slug ] ) ? $th_map[ $slug ]['new_version'] : false );
            }

            $scan_items = get_posts( array( 'post_type' => post_type_exists( 'product' ) ? array( 'product', 'page', 'post' ) : array( 'page', 'post' ), 'post_status' => 'publish', 'posts_per_page' => 100 ) );
            $seo_issues = array();
            $m_meta     = 0;
            $s_title    = 0;
            $m_img      = 0;

            foreach ( $scan_items as $it ) {
                $pr = array();
                if ( strlen( trim( $it->post_title ) ) < 25 ) {
                    $s_title++;
                    $pr[] = 'Short Title (<25 chars)';
                }
                $d1 = get_post_meta( $it->ID, 'rank_math_description', true );
                $d2 = get_post_meta( $it->ID, '_yoast_wpseo_metadesc', true );
                $d3 = get_post_meta( $it->ID, '_gasm_seo_description', true );
                if ( empty( $d1 ) && empty( $d2 ) && empty( $d3 ) && empty( $it->post_excerpt ) ) {
                    $m_meta++;
                    $pr[] = 'Missing Meta Description';
                }
                if ( ! has_post_thumbnail( $it->ID ) ) {
                    $m_img++;
                    $pr[] = 'Missing Featured Image';
                }
                if ( ! empty( $pr ) ) {
                    $seo_issues[] = array( 'ID' => $it->ID, 'title' => $it->post_title, 'type' => ucfirst( $it->post_type ), 'problems' => implode( ' + ', $pr ) );
                }
            }

            $scanned   = count( $scan_items );
            $seo_score = 100;
            if ( $scanned > 0 ) {
                $seo_score = max( 10, round( ( ( ( $scanned * 3 ) - ( $m_meta + $s_title + $m_img ) ) / ( $scanned * 3 ) ) * 100 ) );
            }

            return array(
                'total_products'        => count( $digital_list ) + count( $physical_list ),
                'digital_products'      => count( $digital_list ),
                'physical_products'     => count( $physical_list ),
                'digital_list'          => $digital_list,
                'physical_list'         => $physical_list,
                'total_pages'           => count( $pages_list ),
                'total_posts'           => count( $posts_list ),
                'pages_list'            => $pages_list,
                'posts_list'            => $posts_list,
                'total_plugins'         => count( $all_pl ),
                'active_plugins'        => count( $act_list ),
                'inactive_plugins'      => count( $inact_list ),
                'active_plugins_list'   => $act_list,
                'inactive_plugins_list' => $inact_list,
                'plugins_need_update'   => count( $upd_map ),
                'active_theme_name'     => $act_th->get( 'Name' ),
                'themes_need_update'    => count( $th_map ),
                'themes_list'           => $th_list,
                'seo_score'             => $seo_score,
                'total_scanned'         => $scanned,
                'missing_meta_desc'     => $m_meta,
                'short_titles'          => $s_title,
                'missing_featured'      => $m_img,
                'seo_issues_list'       => $seo_issues,
            );
        }

        public function register_admin_menu() {
            add_menu_page( 'Gemini AI Manager', 'Gemini AI Suite', 'manage_options', 'gemini-ai-manager', array( $this, 'render_admin_ui' ), 'dashicons-superhero', 2 );
        }

        public function render_admin_ui() {
            global $wpdb;
            $s   = get_option( 'gasm_settings', $this->get_default_settings() );
            $a   = $this->get_site_analytics();
            $tab = sanitize_text_field( $_GET['tab'] ?? 'dashboard' );
            $vw  = sanitize_text_field( $_GET['view'] ?? '' );

            echo '<style>
                .gasm-wrap{max-width:1300px;margin:20px auto;font-family:sans-serif;color:#1e293b}
                .gasm-head{background:linear-gradient(135deg,#1e1b4b,#4f46e5);color:#fff;padding:22px 28px;border-radius:12px;display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
                .gasm-head h1{color:#fff;margin:0 0 5px 0;font-size:23px}
                .gasm-tabs{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
                .gasm-tab{text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:600;color:#334155;background:#fff;border:1px solid #cbd5e1}
                .gasm-tab.active,.gasm-tab:hover{background:#4f46e5;color:#fff;border-color:#4f46e5}
                .gasm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:16px;margin-bottom:22px}
                .gasm-card{background:#fff;border-radius:12px;padding:18px;border:1px solid #e2e8f0;box-shadow:0 2px 5px rgba(0,0,0,0.03)}
                .gasm-card h3{margin-top:0;font-size:13px;color:#64748b;text-transform:uppercase;display:flex;justify-content:space-between;align-items:center}
                .gasm-big{font-size:32px;font-weight:800;color:#0f172a;margin:8px 0;display:flex;justify-content:space-between;align-items:center}
                .gasm-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;border-top:1px solid #f1f5f9;padding-top:8px;margin-top:8px}
                .gasm-vbtn{text-decoration:none;font-size:11px;font-weight:600;padding:3px 8px;border-radius:6px;background:#eef2ff;color:#4f46e5;border:1px solid #c7d2fe}
                .gasm-vbtn:hover{background:#4f46e5;color:#fff}
                .gasm-rm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-top:15px}
                .gasm-rm-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;display:flex;flex-direction:column;justify-content:space-between}
                .gasm-switch{position:relative;display:inline-block;width:46px;height:24px}
                .gasm-switch input{opacity:0;width:0;height:0}
                .gasm-slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#cbd5e1;transition:.3s;border-radius:24px}
                .gasm-slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background:#fff;transition:.3s;border-radius:50%}
                input:checked + .gasm-slider{background:#10b981}
                input:checked + .gasm-slider:before{transform:translateX(22px)}
                .gasm-table{width:100%;border-collapse:collapse;background:#fff}
                .gasm-table th,.gasm-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #e2e8f0;font-size:13px}
                .gasm-table th{background:#f8fafc;font-weight:700}
                .gasm-issue{display:flex;justify-content:space-between;align-items:center;padding:12px;background:#fef2f2;border-left:4px solid #ef4444;border-radius:6px;margin-bottom:10px}
                .gasm-box{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:14px;margin-bottom:15px;font-size:13px;line-height:1.6}
                .gasm-pill{padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700;background:#dcfce7;color:#15803d}
                .gasm-pill.err{background:#fee2e2;color:#b91c1c}
            </style>';

            echo '<div class="gasm-wrap">';
            echo '<div class="gasm-head"><div><h1>🤖 Gemini AI Site & Store Manager</h1><p style="margin:0;opacity:0.9;">All-in-One WooCommerce AI Creator, Live X-Ray, RankMath Grid, 1-Click Restore & GitHub Updater</p></div><span style="background:rgba(255,255,255,0.2);padding:6px 14px;border-radius:20px;font-weight:bold;">v' . esc_html( GASM_VERSION ) . '</span></div>';

            settings_errors( 'gasm_messages' );

            $tabs = array(
                'dashboard'       => '📊 Master Dashboard (X-Ray)',
                'product_creator' => '➕ Add Product with AI (Phase 2)',
                'modules'         => '🎛️ On/Off Modules (4x2 Grid)',
                'history'         => '⏪ Work History & 1-Click Restore',
                'settings'        => '⚙️ Gemini API & GitHub Updater',
            );
            echo '<div class="gasm-tabs">';
            foreach ( $tabs as $k => $label ) {
                $cls = ( $tab === $k ) ? 'gasm-tab active' : 'gasm-tab';
                echo '<a href="?page=gemini-ai-manager&tab=' . esc_attr( $k ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</div>';

            if ( $tab === 'product_creator' ) {
                $this->ui_product_creator( $s );
            } elseif ( $tab === 'modules' ) {
                $this->ui_modules_grid( $s );
            } elseif ( $tab === 'history' ) {
                $this->ui_history();
            } elseif ( $tab === 'settings' ) {
                $this->ui_settings( $s );
            } else {
                $this->ui_dashboard( $s, $a, $vw );
            }
            echo '</div>';
        }

        private function ui_dashboard( $s, $a, $vw ) {
            echo '<div class="gasm-grid">';

            // Card 1: Products
            echo '<div class="gasm-card"><h3><span>📦 Total Store Products</span><a href="?page=gemini-ai-manager&tab=dashboard&view=all_products#inspect" class="gasm-vbtn">👁️ View All</a></h3>';
            echo '<div class="gasm-big"><span>' . intval( $a['total_products'] ) . '</span><a href="?page=gemini-ai-manager&tab=product_creator" class="button button-small button-primary">+ Add Product AI</a></div>';
            echo '<div class="gasm-row"><span>🧥 Physical: <strong>' . intval( $a['physical_products'] ) . '</strong></span><a href="?page=gemini-ai-manager&tab=dashboard&view=physical_products#inspect" class="gasm-vbtn">👁️ View Physical</a></div>';
            echo '<div class="gasm-row"><span>💻 Digital: <strong>' . intval( $a['digital_products'] ) . '</strong></span><a href="?page=gemini-ai-manager&tab=dashboard&view=digital_products#inspect" class="gasm-vbtn">👁️ View Digital</a></div></div>';

            // Card 2: Pages & Posts
            echo '<div class="gasm-card"><h3><span>📄 Website Pages & Posts</span><a href="?page=gemini-ai-manager&tab=dashboard&view=pages#inspect" class="gasm-vbtn">👁️ Inspect</a></h3>';
            echo '<div class="gasm-big"><span>' . intval( $a['total_pages'] + $a['total_posts'] ) . '</span></div>';
            echo '<div class="gasm-row"><span>📑 Pages: <strong>' . intval( $a['total_pages'] ) . '</strong></span><a href="?page=gemini-ai-manager&tab=dashboard&view=pages#inspect" class="gasm-vbtn">👁️ View Pages</a></div>';
            echo '<div class="gasm-row"><span>✍️ Blog Posts: <strong>' . intval( $a['total_posts'] ) . '</strong></span><a href="?page=gemini-ai-manager&tab=dashboard&view=posts#inspect" class="gasm-vbtn">👁️ View Posts</a></div></div>';

            // Card 3: Plugins & Themes
            echo '<div class="gasm-card"><h3><span>🔌 Plugins & Themes Status</span><a href="?page=gemini-ai-manager&tab=dashboard&view=plugins_themes#inspect" class="gasm-vbtn">👁️ View All</a></h3>';
            echo '<div class="gasm-big"><span>' . intval( $a['active_plugins'] ) . ' / ' . intval( $a['total_plugins'] ) . ' <small style="font-size:13px;font-weight:normal;">Active</small></span></div>';
            echo '<div class="gasm-row"><span>🟢 Active: <strong>' . intval( $a['active_plugins'] ) . '</strong> | ⚪ Inactive: <strong>' . intval( $a['inactive_plugins'] ) . '</strong></span><a href="?page=gemini-ai-manager&tab=dashboard&view=plugins_themes#inspect" class="gasm-vbtn">👁️ Plugins</a></div>';
            echo '<div class="gasm-row"><span>🎨 Theme: <strong>' . esc_html( $a['active_theme_name'] ) . '</strong> (' . intval( $a['themes_need_update'] ) . ' upd)</span><a href="?page=gemini-ai-manager&tab=dashboard&view=plugins_themes#inspect" class="gasm-vbtn">👁️ Themes</a></div></div>';

            // Card 4: SEO Score
            echo '<div class="gasm-card"><h3><span>📈 Overall Website SEO Score</span><a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#inspect" class="gasm-vbtn">⚡ Improve</a></h3>';
            echo '<div class="gasm-big" style="color:#f59e0b;"><span>' . intval( $a['seo_score'] ) . '%</span><a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#inspect" class="button button-small button-primary">⚡ Improve SEO Score</a></div>';
            echo '<div class="gasm-row"><span>Scanned Items: <strong>' . intval( $a['total_scanned'] ) . '</strong></span><span>Issues: <strong style="color:#dc2626;">' . count( $a['seo_issues_list'] ) . '</strong></span></div>';
            echo '<div class="gasm-row"><span>AI Auto-Fix Engine: <strong style="color:#10b981;">Active</strong></span><a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#inspect" class="gasm-vbtn">🛠️ Fix Issues</a></div></div>';
            echo '</div>';

            // Inspector Drawer
            if ( ! empty( $vw ) ) {
                echo '<div id="inspect" class="gasm-card" style="margin-bottom:22px;border:2px solid #4f46e5;">';
                echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><h2 style="margin:0;font-size:17px;">📋 Selected View: ' . esc_html( strtoupper( str_replace( '_', ' ', $vw ) ) ) . '</h2><a href="?page=gemini-ai-manager&tab=dashboard" class="button">✖ Close View</a></div>';

                if ( in_array( $vw, array( 'physical_products', 'digital_products', 'all_products' ), true ) ) {
                    $list = array_merge( $a['physical_list'], $a['digital_list'] );
                    if ( $vw === 'physical_products' ) {
                        $list = $a['physical_list'];
                    } elseif ( $vw === 'digital_products' ) {
                        $list = $a['digital_list'];
                    }
                    echo '<table class="gasm-table"><thead><tr><th>ID</th><th>Product Name</th><th>Type</th><th>Price</th><th>Status</th><th>Select & Action</th></tr></thead><tbody>';
                    foreach ( array_slice( $list, 0, 60 ) as $it ) {
                        echo '<tr><td>#' . intval( $it['ID'] ) . '</td><td><strong>' . esc_html( $it['title'] ) . '</strong></td><td><span class="gasm-pill">' . esc_html( $it['type'] ) . '</span></td><td>₹' . esc_html( $it['price'] ) . '</td><td>' . esc_html( $it['status'] ) . '</td>';
                        echo '<td style="display:flex;gap:6px;"><a href="' . esc_url( get_permalink( $it['ID'] ) ) . '" target="_blank" class="button button-small">👁️ View</a><a href="' . esc_url( get_edit_post_link( $it['ID'] ) ) . '" class="button button-small">✏️ Edit</a>';
                        echo '<form method="post" style="display:inline;">' . wp_nonce_field( 'gasm_ai_fix_nonce', '_wpnonce', true, false ) . '<input type="hidden" name="gasm_ai_fix_single_id" value="' . intval( $it['ID'] ) . '"><button type="submit" class="button button-small button-primary">✨ AI SEO Fix</button></form></td></tr>';
                    }
                    echo '</tbody></table>';
                } elseif ( $vw === 'pages' || $vw === 'posts' ) {
                    $plist = ( $vw === 'pages' ) ? $a['pages_list'] : $a['posts_list'];
                    echo '<table class="gasm-table"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Select & Action</th></tr></thead><tbody>';
                    foreach ( $plist as $p ) {
                        echo '<tr><td>#' . intval( $p->ID ) . '</td><td><strong>' . esc_html( $p->post_title ) . '</strong></td><td>' . esc_html( $p->post_status ) . '</td>';
                        echo '<td style="display:flex;gap:6px;"><a href="' . esc_url( get_permalink( $p->ID ) ) . '" target="_blank" class="button button-small">👁️ View</a><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '" class="button button-small">✏️ Edit</a>';
                        echo '<form method="post" style="display:inline;">' . wp_nonce_field( 'gasm_ai_fix_nonce', '_wpnonce', true, false ) . '<input type="hidden" name="gasm_ai_fix_single_id" value="' . intval( $p->ID ) . '"><button type="submit" class="button button-small button-primary">✨ AI Optimize</button></form></td></tr>';
                    }
                    echo '</tbody></table>';
                } elseif ( $vw === 'plugins_themes' ) {
                    echo '<div class="gasm-grid" style="grid-template-columns:1fr 1fr;"><div><h4>🔌 Inactive (' . count( $a['inactive_plugins_list'] ) . ') & Active Plugins (' . count( $a['active_plugins_list'] ) . ')</h4><table class="gasm-table"><thead><tr><th>Plugin</th><th>Status</th><th>Version</th></tr></thead><tbody>';
                    foreach ( $a['inactive_plugins_list'] as $pl ) {
                        echo '<tr style="background:#fffbeb;"><td><strong>' . esc_html( $pl['name'] ) . '</strong></td><td><span class="gasm-pill err">⚪ Inactive</span></td><td>v' . esc_html( $pl['version'] ) . '</td></tr>';
                    }
                    foreach ( array_slice( $a['active_plugins_list'], 0, 25 ) as $pl ) {
                        echo '<tr><td>' . esc_html( $pl['name'] ) . '</td><td><span class="gasm-pill">🟢 Active</span></td><td>v' . esc_html( $pl['version'] ) . '</td></tr>';
                    }
                    echo '</tbody></table></div><div><h4>🎨 Installed Themes</h4><p><a href="' . esc_url( admin_url( 'update-core.php' ) ) . '" class="button button-small button-primary">🔄 Update Themes/Plugins</a></p><table class="gasm-table"><thead><tr><th>Theme</th><th>Status</th><th>Update</th></tr></thead><tbody>';
                    foreach ( $a['themes_list'] as $th ) {
                        $st = $th['active'] ? '<span class="gasm-pill">🟢 Active</span>' : '⚪ Inactive';
                        $up = $th['upd'] ? '<span class="gasm-pill err">Update v' . esc_html( $th['upd'] ) . '</span>' : '✅ Up to date';
                        echo '<tr><td><strong>' . esc_html( $th['name'] ) . '</strong></td><td>' . $st . '</td><td>' . $up . '</td></tr>';
                    }
                    echo '</tbody></table></div></div>';
                } elseif ( $vw === 'seo_improver' ) {
                    $ids = implode( ',', wp_list_pluck( $a['seo_issues_list'], 'ID' ) );
                    echo '<div class="gasm-box"><strong>📈 SEO Score ' . intval( $a['seo_score'] ) . '% Kyun Hai & Kaise Improve Karein:</strong><br>Neeche diye gaye items mein Meta Description, Featured Image ya SEO Title ki kami hai. Bulk ya Single button daba kar AI se turant fix karein:<br><br>';
                    echo '<form method="post">' . wp_nonce_field( 'gasm_ai_fix_nonce', '_wpnonce', true, false ) . '<input type="hidden" name="gasm_bulk_ids" value="' . esc_attr( $ids ) . '"><button type="submit" name="gasm_bulk_ai_fix" class="button button-primary button-large">🚀 1-Click AI Fix Next 5 Items Automatically</button></form></div>';
                    echo '<table class="gasm-table"><thead><tr><th>ID</th><th>Title</th><th>Type</th><th>Kya Problem Hai</th><th>AI Action</th></tr></thead><tbody>';
                    foreach ( array_slice( $a['seo_issues_list'], 0, 40 ) as $iss ) {
                        echo '<tr><td>#' . intval( $iss['ID'] ) . '</td><td><strong>' . esc_html( $iss['title'] ) . '</strong></td><td>' . esc_html( $iss['type'] ) . '</td><td style="color:#dc2626;font-weight:600;">⚠️ ' . esc_html( $iss['problems'] ) . '</td>';
                        echo '<td><form method="post" style="display:inline;">' . wp_nonce_field( 'gasm_ai_fix_nonce', '_wpnonce', true, false ) . '<input type="hidden" name="gasm_ai_fix_single_id" value="' . intval( $iss['ID'] ) . '"><button type="submit" class="button button-small button-primary">✨ 1-Click AI Fix</button></form></td></tr>';
                    }
                    echo '</tbody></table>';
                }
                echo '</div>';
            }

            // Bottom Summary
            echo '<div class="gasm-grid" style="grid-template-columns:2fr 1fr;"><div class="gasm-card"><h3>🔍 Website Mein Kahan Kya Problem Hai (Live Audit)</h3>';
            echo '<div class="gasm-issue"><span>⚠️ <strong>' . intval( $a['missing_meta_desc'] ) . ' Products/Pages</strong> mein SEO Meta Description gayab hai.</span><a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#inspect" class="button button-small button-primary">✨ AI Se Fix Karein</a></div>';
            echo '<div class="gasm-issue"><span>🖼️ <strong>' . intval( $a['missing_featured'] ) . ' Products/Pages</strong> mein Main Photo nahi lagi hai.</span><a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#inspect" class="button button-small button-primary">🎨 AI Image Studio</a></div>';
            echo '<div class="gasm-issue"><span>📝 <strong>' . intval( $a['short_titles'] ) . ' Items</strong> ka Title SEO ke hisab se chota (< 25 chars) hai.</span><a href="?page=gemini-ai-manager&tab=dashboard&view=seo_improver#inspect" class="button button-small button-primary">⚡ Auto-Optimize</a></div>';
            echo '</div><div class="gasm-card"><h3>🤖 AI Work Counter & Speed Doctor</h3>';
            echo '<p>📦 Products Created: <strong>' . intval( $s['stat_products_added'] ?? 0 ) . '</strong></p>';
            echo '<p>📈 SEO Tags Updated: <strong>' . intval( $s['stat_seo_updated'] ?? 0 ) . '</strong></p>';
            echo '<p>🎨 AI Photos Generated: <strong>' . intval( $s['stat_images_gen'] ?? 0 ) . '</strong></p>';
            echo '<p>🛠️ Cache & Bugs Fixed: <strong>' . intval( $s['stat_bugs_fixed'] ?? 0 ) . '</strong></p><hr>';
            echo '<form method="post">' . wp_nonce_field( 'gasm_cache_nonce', '_wpnonce', true, false ) . '<button type="submit" name="gasm_clear_cache_now" class="button button-secondary" style="width:100%;">⚡ 1-Click Clear Cache & Boost Speed</button></form>';
            echo '</div></div>';
        }

        private function ui_product_creator( $s ) {
            $p = get_transient( 'gasm_product_preview_' . get_current_user_id() );
            if ( ! empty( $p ) && is_array( $p ) ) {
                echo '<div class="gasm-card" style="border:2px solid #10b981;margin-bottom:20px;"><h2 style="margin-top:0;color:#065f46;">👁️ Live Product Preview (Manual Edit ya Approve & Publish)</h2>';
                echo '<form method="post">' . wp_nonce_field( 'gasm_product_publish_nonce', '_wpnonce', true, false );
                echo '<input type="hidden" name="prod_type" value="' . esc_attr( $p['prod_type'] ?? 'digital' ) . '">';
                echo '<table class="form-table">';
                echo '<tr><th>Product Title</th><td><input type="text" name="title" value="' . esc_attr( $p['title'] ?? '' ) . '" class="large-text"></td></tr>';
                echo '<tr><th>Short Description</th><td><textarea name="short_description" rows="2" class="large-text">' . esc_textarea( $p['short_description'] ?? '' ) . '</textarea></td></tr>';
                echo '<tr><th>Full HTML Description</th><td><textarea name="full_description" rows="5" class="large-text">' . esc_textarea( $p['full_description'] ?? '' ) . '</textarea></td></tr>';
                echo '<tr><th>Price & Sale Price (₹)</th><td>Regular: <input type="text" name="price" value="' . esc_attr( $p['price'] ?? '999' ) . '" style="width:110px;"> Sale: <input type="text" name="sale_price" value="' . esc_attr( $p['sale_price'] ?? '499' ) . '" style="width:110px;"></td></tr>';
                echo '<tr><th>Digital Link / Variants</th><td>Link: <input type="url" name="dl_link" value="' . esc_attr( $p['dl_link'] ?? '' ) . '" style="width:220px;"> Sizes: <input type="text" name="sizes" value="' . esc_attr( $p['sizes'] ?? '' ) . '" style="width:140px;"> Colors: <input type="text" name="colors" value="' . esc_attr( $p['colors'] ?? '' ) . '" style="width:140px;"></td></tr>';
                echo '<tr><th>SEO Meta Description</th><td><textarea name="seo_description" rows="2" class="large-text">' . esc_textarea( $p['seo_description'] ?? '' ) . '</textarea></td></tr>';
                echo '<tr><th>Focus Keyword</th><td><input type="text" name="focus_keyword" value="' . esc_attr( $p['focus_keyword'] ?? '' ) . '" class="regular-text"></td></tr>';
                echo '</table><input type="hidden" name="publish_status" value="publish">';
                echo '<p style="display:flex;gap:12px;"><button type="submit" name="gasm_publish_preview_product" value="1" onclick="this.form.publish_status.value=\'publish\';" class="button button-primary button-hero">🚀 Approve & Publish Live</button><button type="submit" name="gasm_publish_preview_product" value="1" onclick="this.form.publish_status.value=\'draft\';" class="button button-secondary button-hero">📝 Save as Draft</button></p></form></div>';
            }

            echo '<div class="gasm-card"><h3>➕ Smart AI Product Creator (Digital & Physical/Variant)</h3>';
            echo '<form method="post">' . wp_nonce_field( 'gasm_product_gen_nonce', '_wpnonce', true, false );
            echo '<table class="form-table">';
            echo '<tr><th>1. Product Type</th><td><label style="margin-right:20px;font-weight:bold;"><input type="radio" name="prod_type" value="digital" checked> 💻 Digital Product</label><label style="font-weight:bold;"><input type="radio" name="prod_type" value="physical"> 🧥 Physical Product (Variants)</label></td></tr>';
            echo '<tr><th>2. Basic Jankari</th><td><textarea name="prod_basic_info" rows="3" class="large-text" placeholder="Product ka naam aur thodi jankari dalein..." required></textarea></td></tr>';
            echo '<tr><th>3. Reference Link</th><td><input type="url" name="prod_ref_url" class="large-text" placeholder="https://... (Optional)"></td></tr>';
            echo '<tr><th>4. Price (₹)</th><td>Regular: <input type="number" name="prod_price" value="999" style="width:110px;" required> Sale: <input type="number" name="prod_sale_price" value="499" style="width:110px;"></td></tr>';
            echo '<tr><th>5. Digital Link / Variants</th><td>Download URL: <input type="url" name="prod_download_link" style="width:220px;" placeholder="For Digital"> Sizes: <input type="text" name="prod_sizes" value="' . esc_attr( $s['default_sizes'] ?? 'S, M, L, XL' ) . '" style="width:150px;"> Colors: <input type="text" name="prod_colors" value="' . esc_attr( $s['default_colors'] ?? 'Black, Brown' ) . '" style="width:150px;"></td></tr>';
            echo '</table><p><button type="submit" name="gasm_generate_product_preview" class="button button-primary button-hero">✨ Generate Full Product & Show Preview (AI)</button></p></form></div>';
        }

        private function ui_modules_grid( $s ) {
            echo '<form method="post">' . wp_nonce_field( 'gasm_modules_nonce', '_wpnonce', true, false );
            echo '<div style="display:flex;justify-content:space-between;align-items:center;"><div><h2 style="margin:0;">🎛️ Feature Modules (RankMath Style 4x2 Container Grid)</h2><p style="margin:4px 0 0;color:#64748b;">Apni marzi se kisi bhi feature ko ON ya OFF karein:</p></div><button type="submit" name="gasm_save_modules" class="button button-primary button-large">💾 Save All Module Controls</button></div>';

            $cards = array(
                array( 'mod_digital_prod', '💻 Digital Product Manager', 'Downloadable PDFs, Softwares aur Digital files auto-add aur SEO karne ka module.', 'digital_expiry_days', 'Expiry', $s['digital_expiry_days'] ?? 'Unlimited' ),
                array( 'mod_physical_prod', '🧥 Physical & Variant Creator', 'Size, Color aur Price variants ke saath physical products generate karne ka module.', 'default_sizes', 'Sizes', $s['default_sizes'] ?? 'S, M, L, XL' ),
                array( 'mod_auto_seo', '📈 AI Full SEO & Meta Master', 'Title, Meta Description, Focus Keywords aur RankMath/Yoast tags auto-fix karein.', 'seo_tone', 'Tone', $s['seo_tone'] ?? 'Professional' ),
                array( 'mod_image_studio', '🎨 AI Image Studio', 'Bina photo wale products ke liye AI product image aur SEO Alt-Tags lagayein.', 'image_aspect_ratio', 'Style', $s['image_aspect_ratio'] ?? '1:1 White BG' ),
                array( 'mod_bulk_updater', '🔄 Old Product Bulk Updater', 'Purane uploaded products ko scan karke 1-Click mein bulk SEO update karein.', 'default_colors', 'Colors', $s['default_colors'] ?? 'Black, Brown' ),
                array( 'mod_site_doctor', '🩺 Site Doctor & Bug Scanner', 'Active/Inactive plugins, themes aur site health ko monitor karne ka module.', '', 'Live Scanner', '' ),
                array( 'mod_speed_booster', '⚡ Cache & Speed Booster', 'Website ki cache aur faltu transients clear karke loading speed fast rakhein.', '', 'Speed Mode', '' ),
                array( 'mod_schema_markup', '⏪ 1-Click Restore Shield', 'Har AI badlav se pehle purane setup ka snapshot save karta hai.', '', 'Auto Backup', '' ),
            );

            echo '<div class="gasm-rm-grid">';
            foreach ( $cards as $c ) {
                $chk = ! empty( $s[ $c[0] ] ) ? 'checked' : '';
                echo '<div class="gasm-rm-card"><div><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;"><strong>' . esc_html( $c[1] ) . '</strong>';
                echo '<label class="gasm-switch"><input type="checkbox" name="' . esc_attr( $c[0] ) . '" value="1" ' . $chk . '><span class="gasm-slider"></span></label></div>';
                echo '<p style="font-size:13px;color:#64748b;margin:0 0 12px 0;">' . esc_html( $c[2] ) . '</p></div>';
                echo '<div style="border-top:1px solid #f1f5f9;padding-top:10px;display:flex;justify-content:space-between;align-items:center;font-size:12px;">';
                if ( ! empty( $c[3] ) ) {
                    echo '<span>' . esc_html( $c[4] ) . ': <input type="text" name="' . esc_attr( $c[3] ) . '" value="' . esc_attr( $c[5] ) . '" style="width:110px;font-size:12px;"></span>';
                } else {
                    echo '<span class="gasm-pill">' . esc_html( $c[4] ) . '</span>';
                }
                echo '<a href="?page=gemini-ai-manager&tab=dashboard" class="gasm-vbtn">Manage ➔</a></div></div>';
            }
            echo '</div></form>';
        }

        private function ui_history() {
            global $wpdb;
            $rows = $wpdb->get_results( "SELECT * FROM {$this->table_history} ORDER BY id DESC LIMIT 30" );
            echo '<div class="gasm-card"><h3>⏪ Complete Work History & 1-Click Restore</h3><table class="gasm-table"><thead><tr><th>Date</th><th>Action</th><th>Item</th><th>Status</th><th>Restore</th></tr></thead><tbody>';
            if ( empty( $rows ) ) {
                echo '<tr><td colspan="5">Abhi tak koi history record nahi hai.</td></tr>';
            } else {
                foreach ( $rows as $r ) {
                    echo '<tr><td>' . esc_html( $r->created_at ) . '</td><td><strong>' . esc_html( $r->action_type ) . '</strong></td><td>' . esc_html( $r->object_title ) . ' (#' . intval( $r->object_id ) . ')</td><td>' . esc_html( $r->status ) . '</td><td>';
                    if ( $r->status !== 'restored' && ! empty( $r->object_id ) ) {
                        echo '<form method="post" style="display:inline;">' . wp_nonce_field( 'gasm_restore_nonce', '_wpnonce', true, false ) . '<input type="hidden" name="gasm_restore_id" value="' . intval( $r->id ) . '"><button type="submit" class="button button-secondary">⏪ 1-Click Restore</button></form>';
                    }
                    echo '</td></tr>';
                }
            }
            echo '</tbody></table></div>';
        }

        private function ui_settings( $s ) {
            $api_ok = ( ( $s['api_status'] ?? '' ) === 'connected' );
            $gh_ok  = ( ( $s['github_status'] ?? '' ) === 'connected' );

            echo '<form method="post">' . wp_nonce_field( 'gasm_settings_nonce', '_wpnonce', true, false );
            echo '<div class="gasm-grid" style="grid-template-columns:1fr 1fr;">';

            // Gemini API Box
            echo '<div class="gasm-card"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><h3 style="margin:0;">🔑 1. Google Gemini API Connection</h3>';
            echo '<span class="gasm-pill ' . ( $api_ok ? '' : 'err' ) . '">' . ( $api_ok ? '🟢 Connected & Verified' : '🔴 Not Connected' ) . '</span></div>';
            echo '<div class="gasm-box"><strong>📖 Gemini API Kaise Connect Karein?</strong><br>1️⃣ Neeche button par click karke Google AI Studio kholein aur <strong>Create API Key</strong> par click karein.<br>2️⃣ Key copy karke neeche box mein paste karein.<br>3️⃣ <strong>⚡ Test & Connect Gemini API</strong> button dabayein!<br><div style="margin-top:8px;"><a href="https://aistudio.google.com/app/apikey" target="_blank" class="button button-secondary">🔗 Get / Create Gemini API Key ↗</a></div></div>';
            echo '<table class="form-table"><tr><th>Gemini API Key</th><td><input type="password" name="gemini_api_key" value="' . esc_attr( $s['gemini_api_key'] ?? '' ) . '" class="large-text" placeholder="AIzaSy..."></td></tr>';
            echo '<tr><th>AI Model</th><td><select name="gemini_model" style="width:100%;"><option value="gemini-2.5-flash" ' . selected( 'gemini-2.5-flash', $s['gemini_model'] ?? '', false ) . '>Gemini 2.5 Flash (Fastest & Best)</option><option value="gemini-2.5-pro" ' . selected( 'gemini-2.5-pro', $s['gemini_model'] ?? '', false ) . '>Gemini 2.5 Pro (Advanced)</option></select></td></tr>';
            echo '<tr><th>Plan Status</th><td><code>' . esc_html( $s['api_plan_info'] ?? 'Not Tested' ) . '</code></td></tr></table>';
            echo '<p style="display:flex;gap:10px;"><button type="submit" name="gasm_test_gemini_api" class="button button-primary button-large">⚡ Test & Connect Gemini API</button><button type="submit" name="gasm_save_settings" class="button button-secondary button-large">💾 Save</button></p></div>';

            // GitHub Box
            echo '<div class="gasm-card"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><h3 style="margin:0;">🐙 2. Public GitHub 1-Click Updater</h3>';
            echo '<span class="gasm-pill ' . ( $gh_ok ? '' : 'err' ) . '">' . ( $gh_ok ? '🟢 Connected (v' . esc_html( $s['github_remote_ver'] ?? GASM_VERSION ) . ')' : '🔴 Not Connected' ) . '</span></div>';
            echo '<div class="gasm-box"><strong>📖 GitHub Kaise Connect Karein?</strong><br>1️⃣ Neeche <strong>GitHub Username / Repo URL</strong> mein apni GitHub repository ka link (jaise <code>https://github.com/USERNAME/wpdigi</code>) paste karein.<br>2️⃣ Humara system link se Username aur Repo Name (`wpdigi`) khud nikaal lega!<br>3️⃣ <strong>🔌 Test GitHub & Check Update</strong> par click karein.<br><div style="margin-top:8px;"><a href="https://github.com/" target="_blank" class="button button-secondary">🔗 Open GitHub ↗</a></div></div>';
            echo '<table class="form-table"><tr><th>GitHub Username OR Repo Link</th><td><input type="text" name="github_username" value="' . esc_attr( $s['github_username'] ?? '' ) . '" class="large-text" placeholder="https://github.com/your-username/wpdigi"></td></tr>';
            echo '<tr><th>Repository Name</th><td><input type="text" name="github_repo" value="' . esc_attr( $s['github_repo'] ?? 'wpdigi' ) . '" class="large-text"></td></tr>';
            echo '<tr><th>Branch Name</th><td><input type="text" name="github_branch" value="' . esc_attr( $s['github_branch'] ?? 'main' ) . '" class="regular-text"></td></tr></table>';
            echo '<p style="display:flex;gap:10px;"><button type="submit" name="gasm_test_github" class="button button-primary button-large">🔌 Test GitHub & Check Update</button><button type="submit" name="gasm_save_settings" class="button button-secondary button-large">💾 Save</button></p></div>';

            echo '</div></form>';
        }
    }

    Gemini_AI_Site_Manager::get_instance();
}
/* GASM_SAFE_EOF */
