<?php
/**
 * News Sitemap Template
 */

if (!defined('ABSPATH')) {
    exit;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
<?php
if ($posts && count($posts) > 0) :
    foreach ($posts as $post) :
        setup_postdata($post);
        
        // Pular posts excluídos
        if (GoogleNewsSitemapGenerator::getInstance()->is_post_excluded($post)) continue;
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
endif;
wp_reset_postdata();
?>
</urlset><?php exit; ?>
