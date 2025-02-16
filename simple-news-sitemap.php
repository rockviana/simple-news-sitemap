<?php
// Evitar acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Incluir cabeçalho do plugin
require_once plugin_dir_path(__FILE__) . 'header.php';

// Carregar ativador
require_once plugin_dir_path(__FILE__) . 'includes/activator.php';

// Registrar ativação
register_activation_hook(__FILE__, ['Simple_News_Sitemap_Activator', 'activate']);

// Registrar desativação
register_deactivation_hook(__FILE__, function() {
    // Remover schedule de limpeza
    wp_clear_scheduled_hook('simple_news_sitemap_cleanup');
    
    // Limpar regras de rewrite
    flush_rewrite_rules();
});

// Registrar desinstalação
register_uninstall_hook(__FILE__, function() {
    // Remover opções
    delete_option('simple_news_sitemap_options');
    
    // Remover arquivo de log
    $log_file = WP_CONTENT_DIR . '/simple-news-sitemap.log';
    if (file_exists($log_file)) {
        unlink($log_file);
    }
    
    // Remover sitemap
    $upload_dir = wp_upload_dir();
    $sitemap_file = trailingslashit($upload_dir['basedir']) . 'news-sitemap.xml';
    if (file_exists($sitemap_file)) {
        unlink($sitemap_file);
    }
    
    // Limpar cache
    wp_cache_delete('simple_news_sitemap_xml_cache', 'simple_news_sitemap');
    
    // Limpar regras de rewrite
    flush_rewrite_rules();
});

class Simple_News_Sitemap {
    private static $instance = null;
    private $options;
    private $sitemap_path;
    private $site_url;
    private $cache_key = 'simple_news_sitemap_xml_cache';
    private $cache_group = 'simple_news_sitemap';
    private $cache_expiration = 300; // 5 minutos
    private $log_file;
    private $max_log_size = 5242880; // 5MB
    private $default_options = [
        'categories' => [],
        'max_news' => 50,
        'enable_cloudflare' => false,
        'cloudflare_email' => '',
        'cloudflare_api_key' => '',
        'cloudflare_zone_id' => '',
        'enable_siteground' => true,
        'last_update' => '',
        'generation_log' => [],
        'debug_mode' => false,
        'ping_services' => [
            'google' => true,
            'bing' => true
        ],
        'dynamic_priority' => true,
        'base_priority' => 0.7,
        'sitemap_name' => 'news-sitemap.xml'
    ];

    private $ping_endpoints = [
        'google' => 'http://www.google.com/webmasters/tools/ping?sitemap=',
        'bing' => 'http://www.bing.com/ping?sitemap='
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        try {
            // Definir caminhos base
            $this->site_url = get_site_url();
            $upload_dir = wp_upload_dir();
            
            // Verificar se wp_upload_dir() retornou erro
            if (!empty($upload_dir['error'])) {
                throw new Exception('Erro no diretório de upload: ' . $upload_dir['error']);
            }
            
            // Criar diretório de uploads se não existir
            if (!file_exists($upload_dir['basedir'])) {
                $created = wp_mkdir_p($upload_dir['basedir']);
                if (!$created) {
                    throw new Exception('Não foi possível criar o diretório de uploads');
                }
            }
            
            // Verificar permissões do diretório de uploads
            if (!is_writable($upload_dir['basedir'])) {
                throw new Exception('O diretório de uploads não tem permissão de escrita');
            }
            
            $this->sitemap_path = trailingslashit($upload_dir['basedir']) . $this->default_options['sitemap_name'];
            $this->log_file = WP_CONTENT_DIR . '/simple-news-sitemap.log';
            
            // Verificar permissões do diretório WP_CONTENT_DIR
            if (!is_writable(WP_CONTENT_DIR)) {
                throw new Exception('O diretório wp-content não tem permissão de escrita');
            }
            
            // Criar diretório de assets se não existir
            $plugin_dir = plugin_dir_path(__FILE__);
            $js_dir = $plugin_dir . 'js';
            $css_dir = $plugin_dir . 'css';
            
            foreach ([$js_dir, $css_dir] as $dir) {
                if (!file_exists($dir)) {
                    $created = wp_mkdir_p($dir);
                    if (!$created) {
                        throw new Exception('Não foi possível criar o diretório: ' . $dir);
                    }
                }
                if (!is_writable($dir)) {
                    throw new Exception('O diretório não tem permissão de escrita: ' . $dir);
                }
            }
            
            // Inicializar opções com merge seguro
            $saved_options = get_option('simple_news_sitemap_options', []);
            $this->options = wp_parse_args($saved_options, $this->default_options);
            
            // Garantir que arrays existam e sejam do tipo correto
            $this->options['categories'] = isset($this->options['categories']) ? (array)$this->options['categories'] : [];
            $this->options['ping_services'] = isset($this->options['ping_services']) ? (array)$this->options['ping_services'] : [];
            $this->options['generation_log'] = isset($this->options['generation_log']) ? (array)$this->options['generation_log'] : [];
            
            // Validar valores numéricos
            $this->options['max_news'] = absint($this->options['max_news']);
            if ($this->options['max_news'] < 1 || $this->options['max_news'] > 1000) {
                $this->options['max_news'] = 50;
            }
            
            $this->options['base_priority'] = (float)$this->options['base_priority'];
            if ($this->options['base_priority'] < 0.1 || $this->options['base_priority'] > 1.0) {
                $this->options['base_priority'] = 0.7;
            }
            
            // Inicializar cache com suporte a object cache
            if (wp_using_ext_object_cache()) {
                wp_cache_add_global_groups($this->cache_group);
            } else {
                wp_cache_add_non_persistent_groups($this->cache_group);
            }
            
            $this->init_hooks();
            
        } catch (Exception $e) {
            // Registrar erro no log do WordPress
            error_log('Simple News Sitemap - Erro na inicialização: ' . $e->getMessage());
            
            // Se estiver no admin, mostrar notificação
            if (is_admin()) {
                add_action('admin_notices', function() use ($e) {
                    echo '<div class="notice notice-error"><p>';
                    echo 'Simple News Sitemap - Erro na inicialização: ' . esc_html($e->getMessage());
                    echo '</p></div>';
                });
            }
        }
    }

    private function init_hooks() {
        // Core hooks
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Post hooks otimizados
        add_action('transition_post_status', [$this, 'handle_post_status_transition'], 10, 3);
        add_action('add_meta_boxes', [$this, 'add_exclude_meta_box']);
        add_action('save_post', [$this, 'save_exclude_meta']);
        
        // Admin actions
        add_action('admin_post_generate_sitemap_now', [$this, 'handle_manual_generation']);
        add_action('wp_ajax_check_sitemap_progress', [$this, 'ajax_check_progress']);
        
        // Cleanup schedule
        if (!wp_next_scheduled('simple_news_sitemap_cleanup')) {
            wp_schedule_event(time(), 'daily', 'simple_news_sitemap_cleanup');
        }
        add_action('simple_news_sitemap_cleanup', [$this, 'cleanup_old_logs']);

        // Cache cleanup
        add_action('clean_site_cache', [$this, 'clear_sitemap_cache']);
    }

    public function init() {
        try {
            // Adicionar regra de rewrite com prioridade alta
            add_rewrite_rule(
                '^' . preg_quote($this->options['sitemap_name']) . '$',
                'index.php?simple_news_sitemap=1',
                'top'
            );
            
            add_filter('query_vars', function($vars) {
                $vars[] = 'simple_news_sitemap';
                return $vars;
            });

            add_action('template_redirect', [$this, 'serve_sitemap']);
            add_action('pre_get_posts', [$this, 'handle_sitemap_request']);
            
        } catch (Exception $e) {
            if ($this->options['debug_mode']) {
                error_log('Simple News Sitemap - Erro na inicialização: ' . $e->getMessage());
            }
        }
    }

    public function generate_sitemap() {
        try {
            $this->log_message("Iniciando geração do sitemap");
            
            // Usar transient como lock para evitar gerações simultâneas
            if (get_transient('simple_news_sitemap_lock')) {
                $this->log_message("Geração já em andamento");
                return false;
            }
            
            set_transient('simple_news_sitemap_lock', true, 5 * MINUTE_IN_SECONDS);
            
            // Preparar query args
            $args = [
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $this->options['max_news'],
                'orderby' => 'modified',
                'order' => 'DESC',
                'no_found_rows' => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false
            ];
            
            // Adicionar filtro de categoria se configurado
            if (!empty($this->options['categories'])) {
                $args['cat'] = implode(',', $this->options['categories']);
            }
            
            // Excluir posts marcados
            $args['meta_query'] = [
                [
                    'key' => '_exclude_from_news_sitemap',
                    'value' => '1',
                    'compare' => 'NOT EXISTS'
                ]
            ];
            
            $posts = get_posts($args);
            
            if (empty($posts)) {
                $this->log_message("Nenhum post encontrado");
                delete_transient('simple_news_sitemap_lock');
                return false;
            }
            
            // Iniciar XML
            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = true;
            
            $urlset = $xml->createElement('urlset');
            $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $urlset->setAttribute('xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
            $xml->appendChild($urlset);
            
            foreach ($posts as $post) {
                $url = $xml->createElement('url');
                
                // URL base
                $loc = $xml->createElement('loc', esc_url(get_permalink($post)));
                $url->appendChild($loc);
                
                // Informações de notícia
                $news = $xml->createElement('news:news');
                
                $publication = $xml->createElement('news:publication');
                $name = $xml->createElement('news:name', esc_html(get_bloginfo('name')));
                $lang = $xml->createElement('news:language', get_locale());
                
                $publication->appendChild($name);
                $publication->appendChild($lang);
                
                $pubDate = $xml->createElement('news:publication_date', mysql2date('c', $post->post_date_gmt));
                $title = $xml->createElement('news:title', esc_html($post->post_title));
                
                $news->appendChild($publication);
                $news->appendChild($pubDate);
                $news->appendChild($title);
                
                // Adicionar prioridade dinâmica se habilitada
                if ($this->options['dynamic_priority']) {
                    $priority = $this->calculate_priority($post);
                    $priorityNode = $xml->createElement('priority', number_format($priority, 1));
                    $url->appendChild($priorityNode);
                }
                
                $url->appendChild($news);
                $urlset->appendChild($url);
            }
            
            // Salvar XML em arquivo temporário primeiro
            $temp_file = $this->sitemap_path . '.tmp';
            $xml->save($temp_file);
            
            // Mover arquivo temporário para local final
            if (@rename($temp_file, $this->sitemap_path)) {
                $this->options['last_update'] = current_time('mysql');
                update_option('simple_news_sitemap_options', $this->options);
                
                // Limpar cache
                $this->clear_sitemap_cache();
                
                // Ping search engines
                $this->ping_search_engines();
                
                $this->log_message("Sitemap gerado com sucesso");
                delete_transient('simple_news_sitemap_lock');
                return true;
            }
            
            $this->log_message("Erro ao mover arquivo temporário");
            delete_transient('simple_news_sitemap_lock');
            return false;
            
        } catch (Exception $e) {
            $this->log_message("Erro na geração do sitemap: " . $e->getMessage());
            delete_transient('simple_news_sitemap_lock');
            return false;
        }
    }

    private function calculate_priority($post) {
        $base_priority = $this->options['base_priority'];
        
        // Posts mais recentes têm prioridade maior
        $age_in_days = (time() - strtotime($post->post_date_gmt)) / DAY_IN_SECONDS;
        $age_factor = max(0, 1 - ($age_in_days / 30)); // Diminui gradualmente ao longo de 30 dias
        
        // Posts com mais comentários têm prioridade maior
        $comment_count = get_comment_count($post->ID)['approved'];
        $comment_factor = min(1, $comment_count / 100); // Máximo de 100 comentários
        
        // Calcular prioridade final
        $priority = $base_priority;
        $priority += $age_factor * 0.2; // Até +0.2 para posts novos
        $priority += $comment_factor * 0.1; // Até +0.1 para posts populares
        
        return min(1, max(0.1, $priority));
    }

    public function ping_search_engines() {
        if (empty($this->options['ping_services'])) {
            return;
        }

        $sitemap_url = urlencode(home_url($this->options['sitemap_name']));
        
        foreach ($this->options['ping_services'] as $service => $enabled) {
            if (!$enabled || empty($this->ping_endpoints[$service])) {
                continue;
            }
            
            $ping_url = $this->ping_endpoints[$service] . $sitemap_url;
            
            $response = wp_remote_get($ping_url, [
                'timeout' => 5,
                'redirection' => 5,
                'httpversion' => '1.1',
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
                'sslverify' => true
            ]);
            
            if (is_wp_error($response)) {
                $this->log_message("Erro ao pingar {$service}: " . $response->get_error_message());
            } else {
                $this->log_message("Ping enviado com sucesso para {$service}");
            }
        }
    }

    public function clear_sitemap_cache() {
        wp_cache_delete($this->cache_key, $this->cache_group);
        
        if ($this->options['enable_cloudflare'] && 
            !empty($this->options['cloudflare_email']) && 
            !empty($this->options['cloudflare_api_key']) && 
            !empty($this->options['cloudflare_zone_id'])) {
            $this->purge_cloudflare_cache();
        }
        
        if ($this->options['enable_siteground']) {
            $this->purge_siteground_cache();
        }
    }

    private function purge_cloudflare_cache() {
        $url = "https://api.cloudflare.com/client/v4/zones/" . 
               $this->options['cloudflare_zone_id'] . "/purge_cache";
        
        $response = wp_remote_post($url, [
            'headers' => [
                'X-Auth-Email' => $this->options['cloudflare_email'],
                'X-Auth-Key' => $this->options['cloudflare_api_key'],
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'files' => [
                    home_url($this->options['sitemap_name'])
                ]
            ])
        ]);
        
        if (is_wp_error($response)) {
            $this->log_message("Erro ao limpar cache Cloudflare: " . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($body['success'])) {
            $this->log_message("Erro ao limpar cache Cloudflare: " . print_r($body['errors'], true));
            return false;
        }
        
        $this->log_message("Cache Cloudflare limpo com sucesso");
        return true;
    }

    private function purge_siteground_cache() {
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $this->log_message("Cache SiteGround limpo");
            return true;
        }
        return false;
    }

    public function handle_post_status_transition($new_status, $old_status, $post) {
        // Apenas processar posts
        if ($post->post_type !== 'post') {
            return;
        }

        // Verificar se o status mudou de/para publicado
        if ($new_status === 'publish' || $old_status === 'publish') {
            // Agendar geração do sitemap para evitar sobrecarga
            if (!wp_next_scheduled('generate_news_sitemap')) {
                wp_schedule_single_event(time() + 60, 'generate_news_sitemap');
            }
        }
    }

    public function ajax_check_progress() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Acesso negado');
        }

        // Verificar se existe um processo de geração em andamento
        $generation_status = get_transient('simple_news_sitemap_generating');
        if (!$generation_status) {
            wp_send_json(['status' => 'complete']);
        }

        // Ler as últimas linhas do log
        $last_log = $this->get_last_log_line();
        if ($last_log) {
            wp_send_json(['status' => 'running', 'message' => $last_log]);
        }

        wp_send_json(['status' => 'running', 'message' => 'Processando...']);
    }

    private function get_last_log_line() {
        if (!file_exists($this->log_file)) {
            return false;
        }

        $lines = file($this->log_file);
        if ($lines) {
            return trim(end($lines));
        }

        return false;
    }

    public function handle_manual_generation() {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        check_admin_referer('generate_sitemap_now');
        
        try {
            // Definir flag de geração em andamento
            set_transient('simple_news_sitemap_generating', true, 5 * MINUTE_IN_SECONDS);
            
            // Limpar cache antes de gerar
            wp_cache_delete($this->cache_key, $this->cache_group);
            
            // Gerar sitemap
            $xml = $this->generate_sitemap();
            
            if ($xml) {
                // Limpar caches
                $this->clear_sitemap_cache();
                delete_transient('simple_news_sitemap_generating');
                
                wp_redirect(add_query_arg(
                    ['page' => 'simple-news-sitemap', 'generated' => '1'],
                    admin_url('options-general.php')
                ));
                exit;
            } else {
                throw new Exception('Erro ao gerar o XML do sitemap');
            }
        } catch (Exception $e) {
            delete_transient('simple_news_sitemap_generating');
            
            $this->add_to_log([
                'time' => current_time('mysql'),
                'message' => 'Erro: ' . $e->getMessage(),
                'count' => 0,
                'execution_time' => 0
            ]);
            
            wp_redirect(add_query_arg(
                ['page' => 'simple-news-sitemap', 'error' => urlencode($e->getMessage())],
                admin_url('options-general.php')
            ));
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

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_simple-news-sitemap') {
            return;
        }

        $plugin_url = plugin_dir_url(__FILE__);
        $version = '1.0.0';

        // Verificar se os arquivos existem antes de registrar
        $js_file = plugin_dir_path(__FILE__) . 'js/admin.js';
        $css_file = plugin_dir_path(__FILE__) . 'css/admin.css';

        if (file_exists($js_file)) {
            wp_enqueue_script(
                'simple-news-sitemap-admin',
                $plugin_url . 'js/admin.js',
                ['jquery'],
                $version,
                true
            );

            wp_localize_script('simple-news-sitemap-admin', 'simpleNewsSitemap', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('simple_news_sitemap_progress')
            ]);
        }

        if (file_exists($css_file)) {
            wp_enqueue_style(
                'simple-news-sitemap-admin',
                $plugin_url . 'css/admin.css',
                [],
                $version
            );
        }
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
        $excluded = get_post_meta($post->ID, '_exclude_from_news_sitemap', true);
        wp_nonce_field('simple_news_sitemap_exclude', 'simple_news_sitemap_nonce');
        ?>
        <label>
            <input type="checkbox" name="simple_news_sitemap_options[exclude_from_news_sitemap]" value="1" <?php checked($excluded, '1'); ?>>
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

        $excluded = isset($_POST['simple_news_sitemap_options']['exclude_from_news_sitemap']) ? '1' : '0';
        update_post_meta($post_id, '_exclude_from_news_sitemap', $excluded);
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

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1>Simple News Sitemap</h1>
            
            <?php
            if (isset($_GET['generated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>Sitemap gerado com sucesso!</p></div>';
            }
            
            if (isset($_GET['error'])) {
                $error = sanitize_text_field(urldecode($_GET['error']));
                echo '<div class="notice notice-error is-dismissible"><p>Erro ao gerar sitemap: ' . esc_html($error) . '</p></div>';
            }
            ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('simple_news_sitemap');
                do_settings_sections('simple-news-sitemap');
                submit_button('Salvar Configurações');
                ?>
            </form>
            
            <?php
            $sitemap_url = home_url($this->options['sitemap_name']);
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
            </div>

            <h2>Gerar Sitemap</h2>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="generate-form">
                <?php wp_nonce_field('generate_sitemap_now'); ?>
                <input type="hidden" name="action" value="generate_sitemap_now">
                <p>
                    <button type="submit" class="button button-primary" id="generate-sitemap">
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                        Gerar Sitemap Agora
                    </button>
                </p>
            </form>
            
            <div id="generation-log" style="display: none;">
                <h3>Log de Geração em Tempo Real</h3>
                <pre id="log-content" style="background: #f1f1f1; padding: 10px; max-height: 200px; overflow-y: auto;"></pre>
            </div>

            <h3>Logs Recentes</h3>
            <div style="background: #f1f1f1; padding: 10px; max-height: 400px; overflow-y: auto;">
                <pre><?php echo esc_html($this->get_log_contents()); ?></pre>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                var isGenerating = false;
                
                $('#generate-form').on('submit', function(e) {
                    e.preventDefault();
                    
                    if (isGenerating) {
                        return;
                    }
                    
                    var $button = $('#generate-sitemap');
                    var $log = $('#generation-log');
                    var $logContent = $('#log-content');
                    
                    isGenerating = true;
                    $button.prop('disabled', true)
                           .find('.dashicons')
                           .addClass('dashicons-update-spin');
                    $log.show();
                    $logContent.html('Iniciando geração do sitemap...\n');
                    
                    function checkProgress() {
                        if (!isGenerating) {
                            return;
                        }
                        
                        $.ajax({
                            url: ajaxurl,
                            data: {
                                action: 'check_sitemap_progress'
                            },
                            success: function(response) {
                                if (response.status === 'running') {
                                    $logContent.append(response.message + '\n');
                                    $logContent.scrollTop($logContent[0].scrollHeight);
                                    setTimeout(checkProgress, 1000);
                                } else if (response.status === 'complete') {
                                    $logContent.append('Geração concluída!\n');
                                    isGenerating = false;
                                    $button.prop('disabled', false)
                                           .find('.dashicons')
                                           .removeClass('dashicons-update-spin');
                                    window.location.reload();
                                }
                            },
                            error: function() {
                                isGenerating = false;
                                $button.prop('disabled', false)
                                       .find('.dashicons')
                                       .removeClass('dashicons-update-spin');
                                $logContent.append('Erro ao verificar progresso\n');
                            }
                        });
                    }
                    
                    $.post($(this).attr('action'), $(this).serialize())
                        .done(function() {
                            checkProgress();
                        })
                        .fail(function() {
                            isGenerating = false;
                            $button.prop('disabled', false)
                                   .find('.dashicons')
                                   .removeClass('dashicons-update-spin');
                            $logContent.append('Erro ao iniciar a geração do sitemap\n');
                        });
                });
            });
            </script>
        </div>
        <?php
    }

    private function get_recent_logs() {
        if (empty($this->options['generation_log'])) {
            return [];
        }
        
        return array_slice(
            array_reverse($this->options['generation_log']),
            0,
            10
        );
    }

    private function get_log_contents() {
        if (!file_exists($this->log_file)) {
            return 'Nenhum log disponível.';
        }
        
        $logs = file($this->log_file);
        if (empty($logs)) {
            return 'Nenhum log disponível.';
        }
        
        // Retornar as últimas 100 linhas do log
        $logs = array_slice($logs, -100);
        return implode('', $logs);
    }

    private function log_message($message, $level = 'info') {
        if (!$this->options['debug_mode'] && $level !== 'error') {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        // Rotacionar log se necessário
        if (file_exists($this->log_file) && filesize($this->log_file) > $this->max_log_size) {
            $this->rotate_log_file();
        }
        
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
        
        // Enviar para o admin se estiver gerando manualmente
        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json(['status' => 'running', 'message' => $message]);
        }
    }

    private function rotate_log_file() {
        $backup_file = $this->log_file . '.1';
        if (file_exists($this->log_file)) {
            rename($this->log_file, $backup_file);
        }
    }

    public function cleanup_old_logs() {
        if (file_exists($this->log_file)) {
            $logs = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (count($logs) > 1000) { // Manter apenas as últimas 1000 linhas
                $logs = array_slice($logs, -1000);
                file_put_contents($this->log_file, implode(PHP_EOL, $logs));
            }
        }

        // Limpar logs antigos do options
        $logs = $this->options['generation_log'];
        if (count($logs) > 50) {
            $this->options['generation_log'] = array_slice($logs, -50);
            update_option('simple_news_sitemap_options', $this->options);
        }
    }

    public function handle_sitemap_request($query) {
        if (!$query->is_main_query()) {
            return;
        }

        if (get_query_var('simple_news_sitemap')) {
            $this->serve_sitemap();
            exit;
        }
    }

    public function serve_sitemap() {
        if (!get_query_var('simple_news_sitemap')) {
            return;
        }

        // Verificar se o arquivo existe
        if (!file_exists($this->sitemap_path)) {
            $this->log_message("Arquivo do sitemap não encontrado em {$this->sitemap_path}", 'error');
            status_header(404);
            exit;
        }

        // Verificar se o arquivo pode ser lido
        if (!is_readable($this->sitemap_path)) {
            $this->log_message("Arquivo do sitemap não pode ser lido em {$this->sitemap_path}", 'error');
            status_header(403);
            exit;
        }

        // Tentar obter do cache primeiro
        $cached_xml = wp_cache_get($this->cache_key, $this->cache_group);
        if ($cached_xml !== false) {
            header('Content-Type: application/xml; charset=UTF-8');
            header('Content-Length: ' . strlen($cached_xml));
            echo $cached_xml;
            exit;
        }

        // Se não estiver em cache, ler do arquivo
        $xml = file_get_contents($this->sitemap_path);
        if ($xml === false) {
            $this->log_message("Erro ao ler arquivo do sitemap", 'error');
            status_header(500);
            exit;
        }

        // Armazenar em cache
        wp_cache_set($this->cache_key, $xml, $this->cache_group, $this->cache_expiration);

        // Enviar headers
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Length: ' . strlen($xml));
        header('X-Robots-Tag: noindex, follow');
        
        // Cache headers
        header('Cache-Control: public, max-age=' . $this->cache_expiration);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $this->cache_expiration) . ' GMT');
        
        echo $xml;
        exit;
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
        register_setting('simple_news_sitemap', 'simple_news_sitemap_options', [
            'sanitize_callback' => [$this, 'sanitize_options']
        ]);

        add_settings_section(
            'simple_news_sitemap_section',
            'Configurações do Sitemap',
            null,
            'simple-news-sitemap'
        );

        // Configurações básicas
        add_settings_field(
            'categories',
            'Categorias',
            [$this, 'categories_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );

        add_settings_field(
            'max_news',
            'Número máximo de notícias',
            [$this, 'max_news_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );

        // Configurações de cache
        add_settings_field(
            'enable_cloudflare',
            'Cloudflare',
            [$this, 'cloudflare_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );

        add_settings_field(
            'enable_siteground',
            'SiteGround',
            [$this, 'siteground_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );

        // Configurações avançadas
        add_settings_field(
            'debug_mode',
            'Modo Debug',
            [$this, 'debug_mode_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );

        add_settings_field(
            'ping_services',
            'Serviços de Ping',
            [$this, 'ping_services_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );

        add_settings_field(
            'dynamic_priority',
            'Prioridade Dinâmica',
            [$this, 'dynamic_priority_callback'],
            'simple-news-sitemap',
            'simple_news_sitemap_section'
        );
    }

    public function categories_callback() {
        $categories = get_categories(['hide_empty' => false]);
        $selected = $this->options['categories'] ?? [];
        ?>
        <select name="simple_news_sitemap_options[categories][]" multiple style="min-width: 200px;">
            <option value="">Todas as categorias</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?php echo esc_attr($category->term_id); ?>" 
                    <?php echo in_array($category->term_id, $selected) ? 'selected' : ''; ?>>
                    <?php echo esc_html($category->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">Selecione as categorias que deseja incluir no sitemap. Deixe em branco para incluir todas.</p>
        <?php
    }

    public function max_news_callback() {
        ?>
        <input type="number" 
               name="simple_news_sitemap_options[max_news]" 
               value="<?php echo esc_attr($this->options['max_news']); ?>" 
               min="1" 
               max="1000" 
               step="1">
        <p class="description">Número máximo de notícias no sitemap (1-1000). Recomendado: 50</p>
        <?php
    }

    public function cloudflare_callback() {
        ?>
        <label>
            <input type="checkbox" 
                   name="simple_news_sitemap_options[enable_cloudflare]" 
                   value="1" 
                   <?php checked($this->options['enable_cloudflare'], 1); ?>>
            Habilitar limpeza de cache do Cloudflare
        </label>
        <div id="cloudflare_settings" style="margin-top: 10px;">
            <input type="email" 
                   name="simple_news_sitemap_options[cloudflare_email]" 
                   value="<?php echo esc_attr($this->options['cloudflare_email']); ?>" 
                   placeholder="Email Cloudflare"
                   style="margin-bottom: 5px; display: block;">
            <input type="password" 
                   name="simple_news_sitemap_options[cloudflare_api_key]" 
                   value="<?php echo esc_attr($this->options['cloudflare_api_key']); ?>" 
                   placeholder="API Key"
                   style="margin-bottom: 5px; display: block;">
            <input type="text" 
                   name="simple_news_sitemap_options[cloudflare_zone_id]" 
                   value="<?php echo esc_attr($this->options['cloudflare_zone_id']); ?>" 
                   placeholder="Zone ID"
                   style="display: block;">
        </div>
        <?php
    }

    public function siteground_callback() {
        ?>
        <label>
            <input type="checkbox" 
                   name="simple_news_sitemap_options[enable_siteground]" 
                   value="1" 
                   <?php checked($this->options['enable_siteground'], 1); ?>>
            Habilitar limpeza de cache do SiteGround
        </label>
        <p class="description">Requer o plugin SG Optimizer instalado e ativo</p>
        <?php
    }

    public function debug_mode_callback() {
        ?>
        <label>
            <input type="checkbox" 
                   name="simple_news_sitemap_options[debug_mode]" 
                   value="1" 
                   <?php checked($this->options['debug_mode'], 1); ?>>
            Habilitar modo debug (logs detalhados)
        </label>
        <?php
    }

    public function ping_services_callback() {
        $services = [
            'google' => 'Google',
            'bing' => 'Bing'
        ];
        
        foreach ($services as $key => $label) {
            ?>
            <label style="display: block; margin-bottom: 5px;">
                <input type="checkbox" 
                       name="simple_news_sitemap_options[ping_services][<?php echo $key; ?>]" 
                       value="1" 
                       <?php checked($this->options['ping_services'][$key] ?? false, 1); ?>>
                <?php echo esc_html($label); ?>
            </label>
            <?php
        }
        
        echo '<p class="description">Selecione os serviços que serão notificados quando o sitemap for atualizado</p>';
    }

    public function dynamic_priority_callback() {
        ?>
        <label>
            <input type="checkbox" 
                   name="simple_news_sitemap_options[dynamic_priority]" 
                   value="1" 
                   <?php checked($this->options['dynamic_priority'], 1); ?>>
            Calcular prioridade dinamicamente
        </label>
        <p class="description">A prioridade será calculada com base na data e popularidade do post</p>
        
        <div style="margin-top: 10px;">
            <label>
                Prioridade base:
                <input type="number" 
                       name="simple_news_sitemap_options[base_priority]" 
                       value="<?php echo esc_attr($this->options['base_priority']); ?>" 
                       min="0.1" 
                       max="1.0" 
                       step="0.1">
            </label>
            <p class="description">Valor base para cálculo da prioridade (0.1-1.0)</p>
        </div>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_simple-news-sitemap') {
            return;
        }

        wp_enqueue_script(
            'simple-news-sitemap-admin',
            plugins_url('js/admin.js', __FILE__),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('simple-news-sitemap-admin', 'simpleNewsSitemap', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('simple_news_sitemap_progress')
        ]);

        wp_enqueue_style(
            'simple-news-sitemap-admin',
            plugins_url('css/admin.css', __FILE__),
            [],
            '1.0.0'
        );
    }
}

// Initialize the plugin
Simple_News_Sitemap::get_instance();
