<?php
// Carregar WordPress
require_once('../../../wp-load.php');

// Simular request para o sitemap
$_GET['news_sitemap'] = 1;

// Inicializar o plugin
$plugin = Simple_News_Sitemap::get_instance();

// Gerar sitemap
$plugin->maybe_generate_sitemap();
