# Simple News Sitemap

Plugin WordPress otimizado para gerar sitemap de notícias para Google News, compatível com outros plugins de SEO.

## Características

- Gera sitemap XML compatível com Google News (`news-sitemap.xml`)
- Inclui apenas posts das últimas 48 horas
- Otimizado para performance:
  - Cache inteligente com headers HTTP
  - Queries otimizadas
  - Sem impacto no desempenho do site
- Zero configuração - funciona automaticamente
- Sem envio de pings para serviços externos
- Totalmente compatível com outros plugins de SEO e sitemap
- Filtros para personalização:
  - `simple_news_sitemap_exclude_post` - Excluir posts específicos

## Instalação

1. Faça upload dos arquivos do plugin para o diretório `/wp-content/plugins/simple-news-sitemap`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. O sitemap estará disponível em `/news-sitemap.xml`

## Uso com Google News

1. Acesse o [Google Search Console](https://search.google.com/search-console)
2. Adicione o sitemap: `/news-sitemap.xml`
3. O Google News começará a indexar seus posts automaticamente

## Compatibilidade

O plugin é compatível com:
- Yoast SEO
- All in One SEO
- Rank Math
- XML Sitemap & Google News
- Outros plugins de SEO e sitemap

## Requisitos

- WordPress 5.0 ou superior
- PHP 7.0 ou superior

## Licença

GPL v2 ou posterior
