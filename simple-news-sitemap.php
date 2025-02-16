<?php
/**
 * Plugin Name: Simple News Sitemap
 * Plugin URI: https://crivo.tech
 * Description: Gerador de sitemap otimizado para Google News, compatível com outros plugins de SEO
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

    const FEED_SLUG = 'news-sitemap';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hooks principais
        add_action('init', array($this, 'init'));
        add_action('pre_get_posts', array($this, 'filter_news_posts'));
        add_action('publish_post', array($this, 'track_modified_posts'));
        add_action('transition_post_status', array($this, 'track_post_status'), 10, 3);
        
        // Hooks de ativação/desativação
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Adicionar feed
        add_action('generate_rewrite_rules', array($this, 'add_feed_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_feed_query_var'));
        add_action('template_redirect', array($this, 'handle_feed_template'));
    }

    public function init() {
        add_feed(self::FEED_SLUG, array($this, 'render_sitemap'));
    }

    public function activate() {
        // Registrar feed
        $this->init();
        
        // Atualizar regras
        flush_rewrite_rules();
        
        // Salvar versão
        update_option('simple_news_sitemap_version', '1.0.0');
    }

    public function deactivate() {
        // Limpar regras
        flush_rewrite_rules();
        
        // Remover opções
        delete_option('simple_news_sitemap_version');
    }

    public function add_feed_rewrite_rules($wp_rewrite) {
        $feed_rules = array(
            'news-sitemap\.xml$' => 'index.php?feed=' . self::FEED_SLUG
        );
        $wp_rewrite->rules = $feed_rules + $wp_rewrite->rules;
        return $wp_rewrite->rules;
    }

    public function add_feed_query_var($query_vars) {
        $query_vars[] = 'feed';
        return $query_vars;
    }

    public function handle_feed_template() {
        $feed = get_query_var('feed');
        if ($feed === self::FEED_SLUG) {
            $this->render_sitemap();
            exit;
        }
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
        if ($query->is_feed(self::FEED_SLUG)) {
            // Filtrar apenas posts das últimas 48 horas
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
        // Posts excluídos por padrão
        $excluded_types = array('nav_menu_item', 'revision', 'attachment');
        if (in_array($post->post_type, $excluded_types)) return true;

        // Permitir filtro de exclusão
        return apply_filters('simple_news_sitemap_exclude_post', false, $post);
    }

    public function get_sitemap_url() {
        return home_url('/news-sitemap.xml');
    }
}

// Inicializar
GoogleNewsSitemapGenerator::get_instance();
