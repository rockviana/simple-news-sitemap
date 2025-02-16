# Simple News Sitemap

Plugin WordPress otimizado para gerar sitemap de notícias para Google News, sem conflitos com outros plugins de SEO.

## Características

- Gera sitemap XML compatível com Google News (`sitemap-news.xml`)
- Inclui apenas posts das últimas 48 horas
- Otimizado para performance:
  - Cache inteligente com headers HTTP
  - Queries otimizadas
  - Sem impacto no desempenho do site
- Zero configuração - funciona automaticamente
- Sem envio de pings para serviços externos
- Sem conflitos com outros plugins de SEO
- Filtros para personalização:
  - `simple_news_sitemap_exclude_post` - Excluir posts específicos

## Instalação

1. Faça upload dos arquivos do plugin para o diretório `/wp-content/plugins/simple-news-sitemap`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. O sitemap estará disponível em `/sitemap-news.xml`

## Requisitos

- WordPress 5.0 ou superior
- PHP 7.0 ou superior

## Licença

GPL v2 ou posterior
