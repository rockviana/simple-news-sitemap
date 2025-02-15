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
            'max_news' => 50
        ]);

        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('save_post', [$this, 'update_sitemap']);
        add_action('delete_post', [$this, 'update_sitemap']);
        add_action('add_meta_boxes', [$this, 'add_exclude_meta_box']);
        add_action('save_post', [$this, 'save_exclude_meta']);
    }

    public function init() {
        add_rewrite_rule(
            'news-sitemap\.xml$',
            'index.php?simple_news_sitemap=1',
            'top'
        );
        add_filter('query_vars', function($vars) {
            $vars[] = 'simple_news_sitemap';
            return $vars;
        });
        add_action('template_redirect', [$this, 'serve_sitemap']);
    }

    public function serve_sitemap() {
        if (get_query_var('simple_news_sitemap') == 1) {
            header('Content-Type: application/xml; charset=UTF-8');
            if (file_exists($this->sitemap_path)) {
                readfile($this->sitemap_path);
            } else {
                $this->generate_sitemap();
                readfile($this->sitemap_path);
            }
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
    }

    public function sanitize_options($input) {
        $input['categories'] = array_map('intval', $input['categories']);
        $input['max_news'] = intval($input['max_news']);
        $this->generate_sitemap();
        return $input;
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

    public function generate_sitemap() {
        $posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $this->options['max_news'],
            'category__in' => $this->options['categories'],
            'date_query' => [
                [
                    'after' => '48 hours ago',
                    'inclusive' => true,
                ]
            ]
        ]);

        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlset->setAttribute('xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
        $xml->appendChild($urlset);

        foreach ($posts as $post) {
            if (get_post_meta($post->ID, '_simple_news_sitemap_exclude', true)) {
                continue;
            }

            $url = $xml->createElement('url');
            
            $loc = $xml->createElement('loc', get_permalink($post));
            $url->appendChild($loc);

            $news = $xml->createElement('news:news');
            
            $publication = $xml->createElement('news:publication');
            
            $name = $xml->createElement('news:name', 'Central da Toca');
            $publication->appendChild($name);
            
            $language = $xml->createElement('news:language', 'pt-BR');
            $publication->appendChild($language);
            
            $news->appendChild($publication);

            $pub_date = $xml->createElement('news:publication_date', 
                get_the_date('c', $post));
            $news->appendChild($pub_date);

            $title = $xml->createElement('news:title', 
                html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'));
            $news->appendChild($title);

            $url->appendChild($news);
            $urlset->appendChild($url);
        }

        $xml->save($this->sitemap_path);
    }

    public function update_sitemap() {
        $this->generate_sitemap();
    }
}

// Initialize the plugin
Simple_News_Sitemap::get_instance();
