<?php
/**
 * Plugin Name: Simple News Sitemap
 * Plugin URI: https://crivo.tech
 * Description: Gerador de sitemap otimizado para Google News
 * Version: 1.0.0
 * Author: Roque Viana
 * Author URI: https://crivo.tech
 * License: GPL v2 or later
 * Text Domain: simple-news-sitemap
 */

if (!defined('ABSPATH')) {
    exit;
}

class GoogleNewsSitemapGenerator {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new GoogleNewsSitemapGenerator();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function init() {
        if (!get_option('simple_news_sitemap_activated')) {
            $this->activate();
        }
        add_action('do_robots', array($this, 'do_robots'));
        add_action('template_redirect', array($this, 'template_redirect'));
    }

    public function admin_init() {
        register_setting('simple-news-sitemap', 'simple_news_sitemap_options');
    }

    public function admin_menu() {
        add_options_page(
            'News Sitemap',
            'News Sitemap',
            'manage_options',
            'simple-news-sitemap',
            array($this, 'options_page')
        );
    }

    public function options_page() {
        ?>
        <div class="wrap">
            <h2>News Sitemap</h2>
            <p>Seu sitemap de notícias está disponível em: <a href="<?php echo $this->get_sitemap_url(); ?>" target="_blank"><?php echo esc_html($this->get_sitemap_url()); ?></a></p>
        </div>
        <?php
    }

    public function activate() {
        add_option('simple_news_sitemap_activated', true);
        add_option('simple_news_sitemap_options', array());
        $this->add_rewrite_rules();
        flush_rewrite_rules();
    }

    public function deactivate() {
        delete_option('simple_news_sitemap_activated');
        delete_option('simple_news_sitemap_options');
        remove_action('do_robots', array($this, 'do_robots'));
        flush_rewrite_rules();
    }

    private function add_rewrite_rules() {
        add_rewrite_rule(
            'sitemap-news\.xml$',
            'index.php?simple-news-sitemap=true',
            'top'
        );
        add_filter('query_vars', array($this, 'add_query_vars'));
    }

    public function add_query_vars($vars) {
        $vars[] = 'simple-news-sitemap';
        return $vars;
    }

    public function template_redirect() {
        global $wp_query;
        if (isset($wp_query->query_vars['simple-news-sitemap'])) {
            $this->generate_sitemap();
            exit;
        }
    }

    public function do_robots() {
        echo 'Sitemap: ' . $this->get_sitemap_url() . "\n";
    }

    private function get_sitemap_url() {
        return home_url('/sitemap-news.xml');
    }

    private function generate_sitemap() {
        global $wpdb;

        // Headers
        header('Content-Type: application/xml; charset=' . get_bloginfo('charset'));
        header('X-Robots-Tag: noindex, follow');

        // Cache
        $now = time();
        header('Cache-Control: maxage=300');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $now) . ' GMT');
        header('Expires: ' . gmdate('D, d M Y H:i:s', $now + 300) . ' GMT');

        // Query posts
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 1000,
            'orderby' => 'modified',
            'order' => 'DESC',
            'date_query' => array(
                array(
                    'after' => '48 hours ago',
                    'inclusive' => true,
                ),
            ),
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        ));

        // Load template
        require_once dirname(__FILE__) . '/templates/sitemap-news.php';
    }

    public function is_post_excluded($post) {
        if (!$post) return true;

        $excluded_types = array('nav_menu_item', 'revision', 'attachment');
        if (in_array($post->post_type, $excluded_types)) return true;

        return apply_filters('simple_news_sitemap_exclude_post', false, $post);
    }
}

// Initialize
GoogleNewsSitemapGenerator::getInstance();
