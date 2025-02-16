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

define('SIMPLE_NEWS_SITEMAP_VERSION', '1.0.0');
define('SIMPLE_NEWS_SITEMAP_PATH', plugin_dir_path(__FILE__));

function simple_news_sitemap_init() {
    // Registrar regras de rewrite
    global $wp_rewrite;
    add_rewrite_rule('news-sitemap\.xml$', 'index.php?simple_news_sitemap=1', 'top');
    
    // Registrar variável de query
    add_filter('query_vars', function($vars) {
        $vars[] = 'simple_news_sitemap';
        return $vars;
    });
    
    // Handler do sitemap
    add_action('template_redirect', 'simple_news_sitemap_template');
}
add_action('init', 'simple_news_sitemap_init');

function simple_news_sitemap_template() {
    $is_sitemap = intval(get_query_var('simple_news_sitemap'));
    if ($is_sitemap !== 1) return;

    // Headers
    header('Content-Type: application/xml; charset=' . get_bloginfo('charset'));
    header('X-Robots-Tag: noindex, follow');

    // Cache
    $now = time();
    $expires = 3600;
    header('Cache-Control: maxage=' . $expires);
    header('Expires: ' . gmdate('D, d M Y H:i:s', $now + $expires) . ' GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $now) . ' GMT');

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
    require_once SIMPLE_NEWS_SITEMAP_PATH . 'templates/sitemap-news.php';
    exit;
}

function simple_news_sitemap_activate() {
    // Adicionar regras
    simple_news_sitemap_init();
    
    // Atualizar regras
    flush_rewrite_rules();
    
    // Salvar versão
    update_option('simple_news_sitemap_version', SIMPLE_NEWS_SITEMAP_VERSION);
}
register_activation_hook(__FILE__, 'simple_news_sitemap_activate');

function simple_news_sitemap_deactivate() {
    // Limpar regras
    flush_rewrite_rules();
    
    // Remover opções
    delete_option('simple_news_sitemap_version');
}
register_deactivation_hook(__FILE__, 'simple_news_sitemap_deactivate');

function simple_news_sitemap_is_post_excluded($post) {
    // Posts excluídos por padrão
    $excluded_types = array('nav_menu_item', 'revision', 'attachment');
    if (in_array($post->post_type, $excluded_types)) return true;

    // Permitir filtro de exclusão
    return apply_filters('simple_news_sitemap_exclude_post', false, $post);
}
