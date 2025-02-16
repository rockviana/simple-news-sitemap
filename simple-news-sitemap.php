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

// Constantes
define('SIMPLE_NEWS_SITEMAP_VERSION', '1.0.0');
define('SIMPLE_NEWS_SITEMAP_FILE', __FILE__);
define('SIMPLE_NEWS_SITEMAP_PATH', dirname(SIMPLE_NEWS_SITEMAP_FILE));

// Hooks principais
add_action('init', 'simple_news_sitemap_init');
add_action('do_feed_sitemap-news', 'simple_news_sitemap_do_feed');
add_filter('generate_rewrite_rules', 'simple_news_sitemap_rewrite_rules');

// Hooks de ativação/desativação
register_activation_hook(SIMPLE_NEWS_SITEMAP_FILE, 'simple_news_sitemap_activate');
register_deactivation_hook(SIMPLE_NEWS_SITEMAP_FILE, 'simple_news_sitemap_deactivate');

/**
 * Inicialização
 */
function simple_news_sitemap_init() {
    // Registrar feed
    add_feed('sitemap-news', 'simple_news_sitemap_do_feed');
}

/**
 * Regras de rewrite
 */
function simple_news_sitemap_rewrite_rules($wp_rewrite) {
    $new_rules = array(
        'sitemap-news\.xml$' => $wp_rewrite->index . '?feed=sitemap-news',
    );
    $wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
    return $wp_rewrite;
}

/**
 * Gerar feed do sitemap
 */
function simple_news_sitemap_do_feed() {
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

    // Carregar template
    require_once SIMPLE_NEWS_SITEMAP_PATH . '/templates/sitemap-news.php';
    exit;
}

/**
 * Ativação
 */
function simple_news_sitemap_activate() {
    // Registrar feed
    simple_news_sitemap_init();
    
    // Atualizar regras
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
    
    // Salvar versão
    update_option('simple_news_sitemap_version', SIMPLE_NEWS_SITEMAP_VERSION);
}

/**
 * Desativação
 */
function simple_news_sitemap_deactivate() {
    // Limpar regras
    global $wp_rewrite;
    $wp_rewrite->flush_rules();
    
    // Remover opções
    delete_option('simple_news_sitemap_version');
}

/**
 * Verificar exclusão de posts
 */
function simple_news_sitemap_is_post_excluded($post) {
    if (!$post) return true;

    // Posts excluídos por padrão
    $excluded_types = array('nav_menu_item', 'revision', 'attachment');
    if (in_array($post->post_type, $excluded_types)) return true;

    // Permitir filtro de exclusão
    return apply_filters('simple_news_sitemap_exclude_post', false, $post);
}
