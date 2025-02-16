<?php
/**
 * Plugin Name: Simple News Sitemap
 * Plugin URI: https://crivo.tech
 * Description: Gerador otimizado de sitemap para Google News
 * Version: 1.0.0
 * Author: Roque Viana
 * Author URI: https://crivo.tech
 * License: GPL v2 or later
 * Text Domain: simple-news-sitemap
 */

if (!defined('ABSPATH')) {
    exit;
}

class Simple_News_Sitemap {
    private static $instance = null;
    private $max_news = 1000;
    private $cache_group = 'simple_news_sitemap';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Inicializar cache
        if (wp_using_ext_object_cache()) {
            wp_cache_add_global_groups($this->cache_group);
        }
        
        $this->init_hooks();
    }

    private function init_hooks() {
        // Adicionar regra de rewrite
        add_action('init', [$this, 'add_feed']);
        
        // Hooks para limpar cache
        add_action('save_post', [$this, 'clear_cache'], 10, 2);
        add_action('delete_post', [$this, 'clear_cache']);
        add_action('trash_post', [$this, 'clear_cache']);
        add_action('untrash_post', [$this, 'clear_cache']);
    }

    public function add_feed() {
        add_feed('news-sitemap', [$this, 'generate_sitemap']);
        
        // Registrar regra de rewrite para news-sitemap.xml
        add_rewrite_rule(
            '^news-sitemap\.xml$',
            'index.php?feed=news-sitemap',
            'top'
        );
    }

    public function clear_cache() {
        wp_cache_delete('news_posts', $this->cache_group);
    }

    private function get_news_posts() {
        $cache_key = 'news_posts';
        $posts = wp_cache_get($cache_key, $this->cache_group);
        
        if (false === $posts) {
            $args = [
                'post_type' => 'post',
                'post_status' => 'publish',
                'posts_per_page' => $this->max_news,
                'orderby' => 'date',
                'order' => 'DESC',
                'no_found_rows' => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
                'date_query' => [
                    [
                        'after' => '48 hours ago',
                        'inclusive' => true,
                    ],
                ],
            ];
            
            $query = new WP_Query($args);
            $posts = $query->posts;
            
            wp_cache_set($cache_key, $posts, $this->cache_group, 300);
        }
        
        return $posts;
    }

    public function generate_sitemap() {
        // Desabilitar cache
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: application/xml; charset=UTF-8');
        
        // Iniciar XML
        echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . PHP_EOL;
        
        $posts = $this->get_news_posts();
        
        foreach ($posts as $post) {
            echo '<url>' . PHP_EOL;
            echo '  <loc>' . esc_url(get_permalink($post)) . '</loc>' . PHP_EOL;
            echo '  <news:news>' . PHP_EOL;
            echo '    <news:publication>' . PHP_EOL;
            echo '      <news:name>' . esc_xml(get_bloginfo('name')) . '</news:name>' . PHP_EOL;
            echo '      <news:language>' . esc_xml(substr(get_locale(), 0, 2)) . '</news:language>' . PHP_EOL;
            echo '    </news:publication>' . PHP_EOL;
            echo '    <news:publication_date>' . esc_xml(get_the_date('c', $post)) . '</news:publication_date>' . PHP_EOL;
            echo '    <news:title>' . esc_xml($post->post_title) . '</news:title>' . PHP_EOL;
            echo '  </news:news>' . PHP_EOL;
            echo '</url>' . PHP_EOL;
        }
        
        echo '</urlset>';
        exit;
    }
}

// Inicializar plugin
add_action('init', ['Simple_News_Sitemap', 'get_instance']);
