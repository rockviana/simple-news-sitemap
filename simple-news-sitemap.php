<?php
/**
 * Plugin Name: Simple News Sitemap
 * Plugin URI: https://crivo.tech
 * Description: Um plugin WordPress que gera automaticamente um Sitemap de Notícias, compatível com Google News, sem sobrecarga de recursos.
 * Version: 1.0.0
 * Author: Roque Viana
 * Author URI: https://crivo.tech
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-news-sitemap
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Simple_News_Sitemap {
    private static $instance = null;
    private $options;
    private $sitemap_path;
    private $site_url;
    private $cache_key = 'simple_news_sitemap_xml_cache';
    private $cache_group = 'simple_news_sitemap';
    private $cache_expiration = 300; // 5 minutos

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->site_url = get_site_url();
        $this->sitemap_path = ABSPATH . 'news-sitemap.xml';
        $this->options = get_option('simple_news_sitemap_options', [
            'categories' => [],
            'max_news' => 50,
            'enable_cloudflare' => false,
            'cloudflare_email' => '',
            'cloudflare_api_key' => '',
            'cloudflare_zone_id' => '',
            'enable_siteground' => true,
            'last_update' => '',
            'generation_log' => []
        ]);

        // Inicializar cache
        wp_cache_add_non_persistent_groups($this->cache_group);

        // Hooks principais
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Otimizar hooks de atualização
        add_action('transition_post_status', [$this, 'handle_post_status_transition'], 10, 3);
        add_action('add_meta_boxes', [$this, 'add_exclude_meta_box']);
        add_action('save_post', [$this, 'save_exclude_meta']);
        add_action('admin_post_generate_sitemap_now', [$this, 'handle_manual_generation']);

        // Adicionar schedule para limpeza de logs antigos
        if (!wp_next_scheduled('simple_news_sitemap_cleanup')) {
            wp_schedule_event(time(), 'daily', 'simple_news_sitemap_cleanup');
        }
        add_action('simple_news_sitemap_cleanup', [$this, 'cleanup_old_logs']);
    }

    public function handle_manual_generation() {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        check_admin_referer('generate_sitemap_now');
        
        $this->generate_sitemap();
        
        wp_redirect(add_query_arg(
            ['page' => 'simple-news-sitemap', 'generated' => '1'],
            admin_url('options-general.php')
        ));
        exit;
    }

    public function init() {
        add_action('template_redirect', [$this, 'serve_sitemap']);
        add_action('pre_get_posts', [$this, 'handle_sitemap_request']);
    }

    public function handle_sitemap_request($query) {
        if (!$query->is_main_query()) {
            return;
        }

        $current_url = $_SERVER['REQUEST_URI'];
        if (strpos($current_url, 'news-sitemap.xml') !== false) {
            $this->serve_sitemap();
            exit;
        }
    }

    public function serve_sitemap() {
        $current_url = $_SERVER['REQUEST_URI'];
        if (strpos($current_url, 'news-sitemap.xml') !== false) {
            header('Content-Type: application/xml; charset=UTF-8');
            
            // Tentar obter do cache
            $cached_xml = wp_cache_get($this->cache_key, $this->cache_group);
            if ($cached_xml !== false) {
                echo $cached_xml;
                exit;
            }

            // Gerar novo XML
            $xml = $this->generate_sitemap();
            
            // Salvar no cache
            wp_cache_set($this->cache_key, $xml, $this->cache_group, $this->cache_expiration);
            
            echo $xml;
            exit;
        }
    }

    public function add_admin_menu() {
        add_options_page(
            'Simple News Sitemap',
            'Simple News Sitemap',
            'manage_options',
            'simple-news-sitemap',
            [$this, 'admin_page']
        );
    }

    public function register_settings() {
        register_setting(
            'simple_news_sitemap',
            'simple_news_sitemap_options',
            [
                'sanitize_callback' => [$this, 'sanitize_options']
            ]
        );
        
        add_settings_section(
            'simple_news_sitemap_section',
            'Configurações do Sitemap',
            null,
            'simple-news-sitemap'
        );

        add_settings_field(
            'categories',
            'Categorias de Notícias',
            [$this, 'categories_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );

        add_settings_field(
            'max_news',
            'Número Máximo de Notícias',
            [$this, 'max_news_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );

        add_settings_section(
            'cache_section',
            'Configurações de Cache',
            null,
            'simple-news-sitemap'
        );

        add_settings_field(
            'enable_siteground',
            'Limpeza de Cache SiteGround',
            [$this, 'enable_siteground_callback'],
            'simple-news-sitemap',
            'cache_section'
        );

        add_settings_field(
            'enable_cloudflare',
            'Limpeza de Cache Cloudflare',
            [$this, 'enable_cloudflare_callback'],
            'simple-news-sitemap',
            'cache_section'
        );

        add_settings_field(
            'cloudflare_email',
            'E-mail do Cloudflare',
            [$this, 'cloudflare_email_callback'],
            'simple-news-sitemap',
            'cache_section'
        );

        add_settings_field(
            'cloudflare_api_key',
            'API Key do Cloudflare',
            [$this, 'cloudflare_api_key_callback'],
            'simple-news-sitemap',
            'cache_section'
        );

        add_settings_field(
            'cloudflare_zone_id',
            'Zone ID do Cloudflare',
            [$this, 'cloudflare_zone_id_callback'],
            'simple-news-sitemap',
            'cache_section'
        );
    }

    public function enable_siteground_callback() {
        printf(
            '<input type="checkbox" name="simple_news_sitemap_options[enable_siteground]" %s>',
            isset($this->options['enable_siteground']) && $this->options['enable_siteground'] ? 'checked' : ''
        );
    }

    public function enable_cloudflare_callback() {
        printf(
            '<input type="checkbox" name="simple_news_sitemap_options[enable_cloudflare]" %s>',
            isset($this->options['enable_cloudflare']) && $this->options['enable_cloudflare'] ? 'checked' : ''
        );
    }

    public function cloudflare_email_callback() {
        printf(
            '<input type="email" class="regular-text" name="simple_news_sitemap_options[cloudflare_email]" value="%s">',
            isset($this->options['cloudflare_email']) ? esc_attr($this->options['cloudflare_email']) : ''
        );
    }

    public function cloudflare_api_key_callback() {
        printf(
            '<input type="password" class="regular-text" name="simple_news_sitemap_options[cloudflare_api_key]" value="%s">',
            isset($this->options['cloudflare_api_key']) ? esc_attr($this->options['cloudflare_api_key']) : ''
        );
    }

    public function cloudflare_zone_id_callback() {
        printf(
            '<input type="text" class="regular-text" name="simple_news_sitemap_options[cloudflare_zone_id]" value="%s">',
            isset($this->options['cloudflare_zone_id']) ? esc_attr($this->options['cloudflare_zone_id']) : ''
        );
    }

    public function categories_callback() {
        $categories = get_categories(['hide_empty' => false]);
        $selected = isset($this->options['categories']) ? $this->options['categories'] : [];
        
        foreach ($categories as $category) {
            printf(
                '<label><input type="checkbox" name="simple_news_sitemap_options[categories][]" value="%s" %s> %s</label><br>',
                esc_attr($category->term_id),
                in_array($category->term_id, $selected) ? 'checked' : '',
                esc_html($category->name)
            );
        }
    }

    public function max_news_callback() {
        printf(
            '<input type="number" name="simple_news_sitemap_options[max_news]" value="%d" min="1" max="50">',
            isset($this->options['max_news']) ? esc_attr($this->options['max_news']) : 50
        );
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php
            if (isset($_GET['generated'])) {
                echo '<div class="notice notice-success"><p>Sitemap gerado com sucesso!</p></div>';
            }

            $sitemap_url = home_url('/news-sitemap.xml');
            $last_update = !empty($this->options['last_update']) ? 
                          wp_date('d/m/Y H:i:s', strtotime($this->options['last_update'])) : 
                          'Ainda não gerado';
            ?>
            
            <div class="notice notice-info">
                <p>
                    <strong>URL do Sitemap:</strong> 
                    <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank">
                        <?php echo esc_html($sitemap_url); ?>
                        <span class="dashicons dashicons-external" style="text-decoration: none;"></span>
                    </a>
                </p>
                <p>
                    <strong>Última Atualização:</strong> 
                    <?php echo esc_html($last_update); ?>
                </p>
                <p>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display: inline;">
                        <?php wp_nonce_field('generate_sitemap_now'); ?>
                        <input type="hidden" name="action" value="generate_sitemap_now">
                        <button type="submit" class="button button-secondary">
                            <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                            Gerar Sitemap Agora
                        </button>
                    </form>
                </p>
            </div>

            <?php if (!empty($this->options['generation_log'])): ?>
                <div class="card" style="max-width: 100%;">
                    <h2>Log de Geração do Sitemap</h2>
                    <div style="max-height: 300px; overflow-y: auto; padding: 10px; background: #f8f9fa; border: 1px solid #ddd;">
                        <?php foreach (array_reverse($this->options['generation_log']) as $log): ?>
                            <p style="margin: 5px 0; padding: 5px; border-bottom: 1px solid #eee;">
                                <strong>[<?php echo esc_html(wp_date('d/m/Y H:i:s', strtotime($log['time']))); ?>]</strong>
                                <?php echo esc_html($log['message']); ?>
                                <?php if (isset($log['count'])): ?>
                                    <br>
                                    <small>Posts incluídos: <?php echo intval($log['count']); ?></small>
                                <?php endif; ?>
                            </p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php
                settings_fields('simple_news_sitemap');
                do_settings_sections('simple-news-sitemap');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function add_exclude_meta_box() {
        add_meta_box(
            'simple_news_sitemap_exclude',
            'Simple News Sitemap',
            [$this, 'exclude_meta_box_html'],
            'post',
            'side'
        );
    }

    public function exclude_meta_box_html($post) {
        $excluded = get_post_meta($post->ID, '_simple_news_sitemap_exclude', true);
        wp_nonce_field('simple_news_sitemap_exclude', 'simple_news_sitemap_nonce');
        ?>
        <label>
            <input type="checkbox" name="simple_news_sitemap_exclude" value="1" <?php checked($excluded, '1'); ?>>
            Excluir do Sitemap de Notícias
        </label>
        <?php
    }

    public function save_exclude_meta($post_id) {
        if (!isset($_POST['simple_news_sitemap_nonce']) || 
            !wp_verify_nonce($_POST['simple_news_sitemap_nonce'], 'simple_news_sitemap_exclude')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $excluded = isset($_POST['simple_news_sitemap_exclude']) ? '1' : '0';
        update_post_meta($post_id, '_simple_news_sitemap_exclude', $excluded);
    }

    public function sanitize_options($input) {
        $input['categories'] = isset($input['categories']) ? array_map('intval', $input['categories']) : [];
        $input['max_news'] = isset($input['max_news']) ? intval($input['max_news']) : 50;
        $input['enable_siteground'] = isset($input['enable_siteground']);
        $input['enable_cloudflare'] = isset($input['enable_cloudflare']);
        $input['cloudflare_email'] = sanitize_email($input['cloudflare_email']);
        $input['cloudflare_api_key'] = sanitize_text_field($input['cloudflare_api_key']);
        $input['cloudflare_zone_id'] = sanitize_text_field($input['cloudflare_zone_id']);
        
        $this->generate_sitemap();
        $this->clear_sitemap_cache();
        
        return $input;
    }

    public function handle_post_status_transition($new_status, $old_status, $post) {
        // Só atualiza se for um post e se houver mudança relevante de status
        if ($post->post_type !== 'post') {
            return;
        }

        $relevant_statuses = ['publish', 'trash', 'draft'];
        if (!in_array($new_status, $relevant_statuses) && !in_array($old_status, $relevant_statuses)) {
            return;
        }

        // Limpar cache do XML
        wp_cache_delete($this->cache_key, $this->cache_group);
        
        // Gerar novo sitemap
        $this->generate_sitemap();
    }

    public function cleanup_old_logs() {
        // Manter apenas os últimos 7 dias de logs
        if (!empty($this->options['generation_log'])) {
            $cutoff_date = strtotime('-7 days');
            $this->options['generation_log'] = array_filter(
                $this->options['generation_log'],
                function($log) use ($cutoff_date) {
                    return strtotime($log['time']) > $cutoff_date;
                }
            );
            update_option('simple_news_sitemap_options', $this->options);
        }
    }

    public function generate_sitemap() {
        global $wpdb;
        $start_time = microtime(true);
        $posts_count = 0;

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
        $urlset->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
        $xml->appendChild($urlset);

        // Query otimizada usando SQL direto
        $categories = array_map('intval', $this->options['categories']);
        $categories_in = empty($categories) ? '' : "AND tt.term_id IN (" . implode(',', $categories) . ")";
        
        $query = $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, p.post_date_gmt, p.guid 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->term_relationships} tr ON (p.ID = tr.object_id)
            LEFT JOIN {$wpdb->term_taxonomy} tt ON (tr.term_taxonomy_id = tt.term_taxonomy_id)
            WHERE p.post_type = 'post' 
            AND p.post_status = 'publish'
            AND p.post_date_gmt > %s
            {$categories_in}
            ORDER BY p.post_date_gmt DESC
            LIMIT %d",
            gmdate('Y-m-d H:i:s', strtotime('-24 hours')),
            intval($this->options['max_news'])
        );

        $posts = $wpdb->get_results($query);

        if ($posts) {
            // Pré-carregar meta dados em massa
            $post_ids = wp_list_pluck($posts, 'ID');
            update_meta_cache('post', $post_ids);

            foreach ($posts as $post) {
                if (get_post_meta($post->ID, '_simple_news_sitemap_exclude', true)) {
                    continue;
                }

                $url = $xml->createElement('url');
                
                $loc = $xml->createElement('loc');
                $loc->appendChild($xml->createTextNode(get_permalink($post->ID)));
                $url->appendChild($loc);

                $news = $xml->createElement('news:news');
                
                $publication = $xml->createElement('news:publication');
                
                $name = $xml->createElement('news:name');
                $name->appendChild($xml->createTextNode('Central da Toca'));
                $publication->appendChild($name);
                
                $language = $xml->createElement('news:language');
                $language->appendChild($xml->createTextNode('pt-BR'));
                $publication->appendChild($language);
                
                $news->appendChild($publication);

                $pub_date = $xml->createElement('news:publication_date');
                $pub_date->appendChild($xml->createTextNode(mysql2date('c', $post->post_date_gmt)));
                $news->appendChild($pub_date);

                $title = $xml->createElement('news:title');
                $title->appendChild($xml->createTextNode(html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8')));
                $news->appendChild($title);

                $url->appendChild($news);
                $urlset->appendChild($url);
                $posts_count++;
            }
        }

        $execution_time = round(microtime(true) - $start_time, 2);
        
        $log_message = sprintf(
            'Sitemap gerado em %s segundos. %s',
            $execution_time,
            $posts_count > 0 ? '' : 'Nenhum post encontrado nas últimas 24 horas.'
        );

        $this->add_to_log([
            'time' => current_time('mysql'),
            'message' => $log_message,
            'count' => $posts_count,
            'execution_time' => $execution_time
        ]);

        $this->options['last_update'] = current_time('mysql');
        update_option('simple_news_sitemap_options', $this->options);

        return $xml->saveXML();
    }

    public function clear_sitemap_cache() {
        $sitemap_url = home_url('/news-sitemap.xml');

        // Limpar cache do SiteGround
        if (isset($this->options['enable_siteground']) && $this->options['enable_siteground']) {
            // Primeiro tenta via WP-CLI
            if (!defined('WP_CLI') && function_exists('shell_exec') && $this->is_shell_exec_available()) {
                $command = sprintf('wp sg purge %s 2>&1', escapeshellarg($sitemap_url));
                $output = shell_exec($command);
                
                // Registrar no log
                $log_message = $output ? "WP-CLI SG Cache: " . trim($output) : "WP-CLI SG Cache: Executado sem saída";
                $this->add_to_log($log_message);
            } 
            // Fallback para o método padrão do SG
            elseif (function_exists('do_action')) {
                do_action('sg_cachepress_purge_url', $sitemap_url);
                $this->add_to_log("Cache SG limpo via action sg_cachepress_purge_url");
            }
        }

        // Limpar cache do Cloudflare
        if (isset($this->options['enable_cloudflare']) && $this->options['enable_cloudflare']) {
            $this->clear_cloudflare_cache($sitemap_url);
        }
    }

    private function is_shell_exec_available() {
        if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
            // Testar se podemos executar wp-cli
            $test = shell_exec('wp --info 2>&1');
            return $test !== null && !empty($test) && strpos($test, 'WP-CLI') !== false;
        }
        return false;
    }

    private function add_to_log($message) {
        // Manter apenas os últimos 50 logs
        $this->options['generation_log'][] = [
            'time' => current_time('mysql'),
            'message' => $message['message'],
            'count' => $message['count'],
            'execution_time' => $message['execution_time']
        ];

        if (count($this->options['generation_log']) > 50) {
            $this->options['generation_log'] = array_slice($this->options['generation_log'], -50);
        }

        update_option('simple_news_sitemap_options', $this->options);
    }

    private function clear_cloudflare_cache($sitemap_url) {
        if (empty($this->options['cloudflare_email']) || 
            empty($this->options['cloudflare_api_key']) || 
            empty($this->options['cloudflare_zone_id'])) {
            $this->add_to_log("Cloudflare: Configurações incompletas");
            return;
        }

        $url = "https://api.cloudflare.com/client/v4/zones/" . 
               $this->options['cloudflare_zone_id'] . "/purge_cache";

        $response = wp_remote_post($url, [
            'headers' => [
                'X-Auth-Email' => $this->options['cloudflare_email'],
                'X-Auth-Key' => $this->options['cloudflare_api_key'],
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'files' => [$sitemap_url]
            ]),
        ]);

        if (is_wp_error($response)) {
            $this->add_to_log("Erro ao limpar cache do Cloudflare: " . $response->get_error_message());
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $message = isset($body['success']) && $body['success'] 
                      ? "Cache do Cloudflare limpo com sucesso" 
                      : "Erro ao limpar cache do Cloudflare: " . json_encode($body['errors'] ?? []);
            $this->add_to_log($message);
        }
    }
}

// Initialize the plugin
Simple_News_Sitemap::get_instance();
