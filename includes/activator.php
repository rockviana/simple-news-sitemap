<?php
if (!defined('ABSPATH')) {
    exit;
}

class Simple_News_Sitemap_Activator {
    public static function activate() {
        // Criar diretórios necessários
        $upload_dir = wp_upload_dir();
        if (!file_exists($upload_dir['basedir'])) {
            wp_mkdir_p($upload_dir['basedir']);
        }

        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $dirs = [
            $plugin_dir . 'js',
            $plugin_dir . 'css',
            $plugin_dir . 'includes'
        ];

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
        }

        // Verificar permissões de escrita
        $paths_to_check = [
            $upload_dir['basedir'],
            WP_CONTENT_DIR,
            $plugin_dir . 'js',
            $plugin_dir . 'css'
        ];

        $errors = [];
        foreach ($paths_to_check as $path) {
            if (!is_writable($path)) {
                $errors[] = sprintf('O diretório %s não tem permissão de escrita', $path);
            }
        }

        if (!empty($errors)) {
            deactivate_plugins(plugin_basename(dirname(__FILE__) . '/simple-news-sitemap.php'));
            wp_die(
                'Erro ao ativar o plugin Simple News Sitemap:<br>' . 
                implode('<br>', $errors) . 
                '<br><br><a href="' . admin_url('plugins.php') . '">Voltar para plugins</a>'
            );
        }

        // Atualizar regras de rewrite
        flush_rewrite_rules();

        // Criar ou atualizar opções
        $default_options = [
            'categories' => [],
            'max_news' => 50,
            'enable_cloudflare' => false,
            'cloudflare_email' => '',
            'cloudflare_api_key' => '',
            'cloudflare_zone_id' => '',
            'enable_siteground' => true,
            'last_update' => '',
            'generation_log' => [],
            'debug_mode' => false,
            'ping_services' => [
                'google' => true,
                'bing' => true
            ],
            'dynamic_priority' => true,
            'base_priority' => 0.7,
            'sitemap_name' => 'news-sitemap.xml'
        ];

        $existing_options = get_option('simple_news_sitemap_options', []);
        $merged_options = wp_parse_args($existing_options, $default_options);
        
        update_option('simple_news_sitemap_options', $merged_options);

        // Agendar limpeza de logs
        if (!wp_next_scheduled('simple_news_sitemap_cleanup')) {
            wp_schedule_event(time(), 'daily', 'simple_news_sitemap_cleanup');
        }

        // Criar arquivo de log inicial
        $log_file = WP_CONTENT_DIR . '/simple-news-sitemap.log';
        if (!file_exists($log_file)) {
            $timestamp = current_time('mysql');
            $initial_log = "[{$timestamp}] [info] Plugin Simple News Sitemap ativado com sucesso\n";
            file_put_contents($log_file, $initial_log);
        }
    }
}
