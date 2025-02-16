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

// Registrar o feed do sitemap
add_action('init', function() {
    add_feed('sitemap-news', 'simple_news_sitemap_generate');
});

// Adicionar regra de rewrite
add_action('init', function() {
    add_rewrite_rule(
        '^sitemap-news\.xml$',
        'index.php?feed=sitemap-news',
        'top'
    );
});

// Função para gerar o sitemap
function simple_news_sitemap_generate() {
    // Desabilitar cache
    header('Content-Type: application/xml; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, private');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Consultar posts
    $args = array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => 1000,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
        'date_query' => array(
            array(
                'after' => '48 hours ago',
                'inclusive' => true,
            ),
        ),
    );

    $query = new WP_Query($args);

    // Gerar XML
    echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . PHP_EOL;

    while ($query->have_posts()) {
        $query->the_post();
        echo '<url>' . PHP_EOL;
        echo '  <loc>' . esc_url(get_permalink()) . '</loc>' . PHP_EOL;
        echo '  <news:news>' . PHP_EOL;
        echo '    <news:publication>' . PHP_EOL;
        echo '      <news:name>' . esc_xml(get_bloginfo('name')) . '</news:name>' . PHP_EOL;
        echo '      <news:language>' . esc_xml(substr(get_locale(), 0, 2)) . '</news:language>' . PHP_EOL;
        echo '    </news:publication>' . PHP_EOL;
        echo '    <news:publication_date>' . esc_xml(get_the_date('c')) . '</news:publication_date>' . PHP_EOL;
        echo '    <news:title>' . esc_xml(get_the_title()) . '</news:title>' . PHP_EOL;
        echo '  </news:news>' . PHP_EOL;
        echo '</url>' . PHP_EOL;
    }

    wp_reset_postdata();
    echo '</urlset>';
    exit;
}
