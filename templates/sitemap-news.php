<?php
/**
 * News Sitemap Template
 */

// Evitar acesso direto
if (!defined('ABSPATH')) {
    exit;
}

// Debug
error_log('Simple News Sitemap: Template loaded');
error_log('Simple News Sitemap: Found ' . count($posts) . ' posts');

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php
if ($posts && count($posts) > 0) :
    foreach ($posts as $post) :
        // Debug
        error_log('Simple News Sitemap: Processing post ' . $post->ID);
        
        // Pular posts excluídos
        if (simple_news_sitemap_is_post_excluded($post)) {
            error_log('Simple News Sitemap: Post ' . $post->ID . ' excluded');
            continue;
        }

        setup_postdata($post);
?>
<url>
    <loc><?php echo esc_url(get_permalink()); ?></loc>
    <news:news>
        <news:publication>
            <news:name><?php echo esc_xml(get_bloginfo('name')); ?></news:name>
            <news:language><?php echo esc_xml(substr(get_locale(), 0, 2)); ?></news:language>
        </news:publication>
        <news:publication_date><?php echo esc_xml(get_the_date('c')); ?></news:publication_date>
        <news:title><?php echo esc_xml(get_the_title()); ?></news:title>
    </news:news>
</url>
<?php 
    endforeach;
else:
    error_log('Simple News Sitemap: No posts found');
endif;
wp_reset_postdata();
?>
</urlset><?php 
error_log('Simple News Sitemap: Template finished');
exit; ?>
