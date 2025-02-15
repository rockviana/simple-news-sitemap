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
            'last_update' => ''
        ]);

        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('save_post', [$this, 'handle_post_update']);
        add_action('delete_post', [$this, 'handle_post_update']);
        add_action('add_meta_boxes', [$this, 'add_exclude_meta_box']);
        add_action('save_post', [$this, 'save_exclude_meta']);
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
            echo $this->generate_sitemap();
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
            </div>

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

    public function handle_post_update($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if ($post->post_type !== 'post') {
            return;
        }

        $this->generate_sitemap();
        $this->clear_sitemap_cache();
    }

    private function clear_sitemap_cache() {
        $sitemap_url = home_url('/news-sitemap.xml');

        if (isset($this->options['enable_siteground']) && $this->options['enable_siteground']) {
            if (function_exists('do_action')) {
                do_action('sg_cachepress_purge_url', $sitemap_url);
            }
        }

        if (isset($this->options['enable_cloudflare']) && $this->options['enable_cloudflare']) {
            $this->clear_cloudflare_cache($sitemap_url);
        }
    }

    private function clear_cloudflare_cache($sitemap_url) {
        if (empty($this->options['cloudflare_email']) || 
            empty($this->options['cloudflare_api_key']) || 
            empty($this->options['cloudflare_zone_id'])) {
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
            error_log('Erro ao limpar cache do Cloudflare: ' . $response->get_error_message());
        }
    }

    public function generate_sitemap() {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
        $urlset->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
        $xml->appendChild($urlset);

        // Buscar posts das últimas 48 horas
        $args = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $this->options['max_news'],
            'category__in' => isset($this->options['categories']) ? $this->options['categories'] : [],
            'date_query' => [
                [
                    'after' => '48 hours ago',
                    'inclusive' => true,
                ]
            ],
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                
                // Verificar se o post está excluído manualmente
                if (get_post_meta(get_the_ID(), '_simple_news_sitemap_exclude', true)) {
                    continue;
                }

                $url = $xml->createElement('url');
                
                // URL do post
                $loc = $xml->createElement('loc');
                $loc->appendChild($xml->createTextNode(get_permalink()));
                $url->appendChild($loc);

                // Informações de notícia
                $news = $xml->createElement('news:news');
                
                // Publicação
                $publication = $xml->createElement('news:publication');
                
                $name = $xml->createElement('news:name');
                $name->appendChild($xml->createTextNode('Central da Toca'));
                $publication->appendChild($name);
                
                $language = $xml->createElement('news:language');
                $language->appendChild($xml->createTextNode('pt-BR'));
                $publication->appendChild($language);
                
                $news->appendChild($publication);

                // Data de publicação
                $pub_date = $xml->createElement('news:publication_date');
                $pub_date->appendChild($xml->createTextNode(get_the_date('c')));
                $news->appendChild($pub_date);

                // Título
                $title = $xml->createElement('news:title');
                $title->appendChild($xml->createTextNode(html_entity_decode(get_the_title(), ENT_QUOTES, 'UTF-8')));
                $news->appendChild($title);

                $url->appendChild($news);
                $urlset->appendChild($url);
            }
        }
        
        wp_reset_postdata();

        // Atualizar a hora da última geração
        $this->options['last_update'] = current_time('mysql');
        update_option('simple_news_sitemap_options', $this->options);

        return $xml->saveXML();
    }

    public function update_sitemap() {
        $this->generate_sitemap();
    }
}

// Initialize the plugin
Simple_News_Sitemap::get_instance();
