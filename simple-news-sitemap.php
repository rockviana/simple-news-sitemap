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

// Constantes
define('SIMPLE_NEWS_SITEMAP_VERSION', '1.0.0');
define('SIMPLE_NEWS_SITEMAP_PATH', plugin_dir_path(__FILE__));

// Adicionar regras de rewrite
add_action('init', 'simple_news_sitemap_add_rewrite_rules');
function simple_news_sitemap_add_rewrite_rules() {
    add_rewrite_rule(
        '^news-sitemap\.xml$',
        'index.php?simple_news_sitemap=1',
        'top'
    );
}

// Registrar query var
add_filter('query_vars', 'simple_news_sitemap_add_query_vars');
function simple_news_sitemap_add_query_vars($vars) {
    $vars[] = 'simple_news_sitemap';
    return $vars;
}

// Handler do template
add_action('parse_request', 'simple_news_sitemap_parse_request');
function simple_news_sitemap_parse_request($wp) {
    if (isset($wp->query_vars['simple_news_sitemap']) && $wp->query_vars['simple_news_sitemap'] === '1') {
        simple_news_sitemap_generate();
        exit;
    }
}

// Gerar sitemap
function simple_news_sitemap_generate() {
    global $post;

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
    $args = array(
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
    );

    $posts = get_posts($args);

    // Carregar template
    require_once SIMPLE_NEWS_SITEMAP_PATH . 'templates/sitemap-news.php';
    exit;
}

// Ativação
register_activation_hook(__FILE__, 'simple_news_sitemap_activate');
function simple_news_sitemap_activate() {
    simple_news_sitemap_add_rewrite_rules();
    flush_rewrite_rules();
    update_option('simple_news_sitemap_version', SIMPLE_NEWS_SITEMAP_VERSION);
}

// Desativação
register_deactivation_hook(__FILE__, 'simple_news_sitemap_deactivate');
function simple_news_sitemap_deactivate() {
    flush_rewrite_rules();
    delete_option('simple_news_sitemap_version');
}

// Verificar exclusão de posts
function simple_news_sitemap_is_post_excluded($post) {
    if (!$post) return true;

    // Posts excluídos por padrão
    $excluded_types = array('nav_menu_item', 'revision', 'attachment');
    if (in_array($post->post_type, $excluded_types)) return true;

    // Permitir filtro de exclusão
    return apply_filters('simple_news_sitemap_exclude_post', false, $post);
}

// Adicionar link nas ferramentas do admin
add_action('admin_menu', 'simple_news_sitemap_add_menu');
function simple_news_sitemap_add_menu() {
    add_management_page(
        'News Sitemap',
        'News Sitemap',
        'manage_options',
        'simple-news-sitemap',
        'simple_news_sitemap_admin_page'
    );
}

// Página de admin
function simple_news_sitemap_admin_page() {
    $sitemap_url = home_url('/news-sitemap.xml');
    ?>
    <div class="wrap">
        <h1>News Sitemap</h1>
        <p>Seu sitemap está disponível em: <a href="<?php echo esc_url($sitemap_url); ?>" target="_blank"><?php echo esc_html($sitemap_url); ?></a></p>
    </div>
    <?php
}
