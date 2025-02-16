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
    private $sitemap_path;
    private $site_url;
    private $max_news = 1000;
    private $cache_group = 'simple_news_sitemap';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->site_url = get_site_url();
        $upload_dir = wp_upload_dir();
        $this->sitemap_path = trailingslashit($upload_dir['basedir']) . 'news-sitemap.xml';
        
        // Inicializar cache
        if (wp_using_ext_object_cache()) {
            wp_cache_add_global_groups($this->cache_group);
        }
        
        $this->init_hooks();
    }

    private function init_hooks() {
        // Hooks para atualização do sitemap
        add_action('save_post', [$this, 'update_sitemap'], 10, 2);
        add_action('delete_post', [$this, 'update_sitemap']);
        add_action('trash_post', [$this, 'update_sitemap']);
        add_action('untrash_post', [$this, 'update_sitemap']);
        
        // Rewrite rule para o sitemap
        add_action('init', [$this, 'add_sitemap_rewrite']);
        add_filter('query_vars', [$this, 'add_sitemap_query_var']);
        add_action('template_redirect', [$this, 'serve_sitemap']);
        
        // Gerar sitemap inicial se não existir
        add_action('init', [$this, 'maybe_generate_sitemap']);
    }

    public function add_sitemap_rewrite() {
        add_rewrite_rule('^news-sitemap\.xml$', 'index.php?simple_news_sitemap=1', 'top');
    }

    public function add_sitemap_query_var($vars) {
        $vars[] = 'simple_news_sitemap';
        return $vars;
    }

    public function serve_sitemap() {
        if (get_query_var('simple_news_sitemap')) {
            header('Content-Type: application/xml; charset=UTF-8');
            readfile($this->sitemap_path);
            exit;
        }
    }

    public function maybe_generate_sitemap() {
        if (!file_exists($this->sitemap_path)) {
            $this->generate_sitemap();
        }
    }

    public function update_sitemap($post_id, $post = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ($post && 'publish' !== $post->post_status) {
            return;
        }

        $this->generate_sitemap();
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
            ];
            
            $query = new WP_Query($args);
            $posts = $query->posts;
            
            wp_cache_set($cache_key, $posts, $this->cache_group, 300); // Cache por 5 minutos
        }
        
        return $posts;
    }

    private function generate_sitemap() {
        $posts = $this->get_news_posts();
        
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        
        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urlset->setAttribute('xmlns:news', 'http://www.google.com/schemas/sitemap-news/0.9');
        $xml->appendChild($urlset);
        
        foreach ($posts as $post) {
            $url = $xml->createElement('url');
            
            // Loc
            $loc = $xml->createElement('loc', esc_url(get_permalink($post)));
            $url->appendChild($loc);
            
            // News tags
            $news = $xml->createElement('news:news');
            
            // Publication
            $publication = $xml->createElement('news:publication');
            $name = $xml->createElement('news:name', esc_html(get_bloginfo('name')));
            $lang = $xml->createElement('news:language', substr(get_locale(), 0, 2));
            $publication->appendChild($name);
            $publication->appendChild($lang);
            $news->appendChild($publication);
            
            // Publication Date
            $pub_date = $xml->createElement('news:publication_date', get_the_date('c', $post));
            $news->appendChild($pub_date);
            
            // Title
            $title = $xml->createElement('news:title', esc_html($post->post_title));
            $news->appendChild($title);
            
            $url->appendChild($news);
            $urlset->appendChild($url);
        }
        
        // Salvar sitemap atomicamente
        $temp_file = $this->sitemap_path . '.tmp';
        $xml->save($temp_file);
        rename($temp_file, $this->sitemap_path);
        
        // Limpar cache
        wp_cache_delete('news_posts', $this->cache_group);
    }
}

// Inicializar plugin
add_action('plugins_loaded', ['Simple_News_Sitemap', 'get_instance']);
