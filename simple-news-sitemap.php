<?php
/**
 * Plugin Name: Simple News Sitemap
 * Plugin URI: https://crivo.tech
 * Description: Gerador de sitemap otimizado para Google News, sem conflitos com outros plugins de SEO
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
    private $lastPostModified = 0;

    const SITEMAP_SCOPE = 'news';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'init'), 0);
        add_action('pre_get_posts', array($this, 'filter_news_posts'));
        add_action('publish_post', array($this, 'track_modified_posts'));
        add_action('transition_post_status', array($this, 'track_post_status'), 10, 3);
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function init() {
        add_feed(self::SITEMAP_SCOPE, array($this, 'render_sitemap'));
        $this->add_rewrite_rules();
    }

    public function activate() {
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        update_option('simple_news_sitemap_version', '1.0.0');
    }

    public function deactivate() {
        flush_rewrite_rules();
        delete_option('simple_news_sitemap_version');
    }

    private function add_rewrite_rules() {
        global $wp_rewrite;
        add_rewrite_rule(
            'sitemap-' . self::SITEMAP_SCOPE . '\.xml$',
            'index.php?feed=' . self::SITEMAP_SCOPE,
            'top'
        );
    }

    public function track_modified_posts($post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_type == 'post') {
            $modified = strtotime($post->post_modified_gmt);
            $this->lastPostModified = max($this->lastPostModified, $modified);
        }
    }

    public function track_post_status($new_status, $old_status, $post) {
        if ($post->post_type == 'post') {
            $this->track_modified_posts($post->ID);
        }
    }

    public function filter_news_posts($query) {
        if ($query->is_feed(self::SITEMAP_SCOPE)) {
            $query->set('post_type', 'post');
            $query->set('posts_per_page', 1000);
            $query->set('orderby', 'modified');
            $query->set('order', 'DESC');
            $query->set('date_query', array(
                array(
                    'after' => '48 hours ago',
                    'inclusive' => true,
                )
            ));
            
            // Otimizações de performance
            $query->set('no_found_rows', true);
            $query->set('update_post_term_cache', false);
            $query->set('update_post_meta_cache', false);
        }
    }

    public function render_sitemap() {
        global $wp_query;

        // Headers
        header('Content-Type: application/xml; charset=' . get_bloginfo('charset'));
        header('X-Robots-Tag: noindex, follow');

        // Cache control
        $expires = 3600;
        header('Cache-Control: maxage=' . $expires);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $this->lastPostModified) . ' GMT');

        // Carregar template
        require_once dirname(__FILE__) . '/templates/sitemap-news.php';
        exit;
    }

    public function is_post_excluded($post) {
        // Posts excluídos por padrão (customize conforme necessário)
        $excluded_types = array('nav_menu_item', 'revision', 'attachment');
        if (in_array($post->post_type, $excluded_types)) return true;

        // Permitir filtro de exclusão
        return apply_filters('simple_news_sitemap_exclude_post', false, $post);
    }

    public function get_sitemap_url() {
        return home_url('/sitemap-' . self::SITEMAP_SCOPE . '.xml');
    }
}

// Inicializar
GoogleNewsSitemapGenerator::get_instance();
