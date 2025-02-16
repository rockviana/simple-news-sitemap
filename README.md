# Simple News Sitemap

Plugin WordPress otimizado para geração de sitemap para Google News.

## Características

- Geração atômica do sitemap
- Cache em dois níveis (objeto e transient)
- Atualização automática ao publicar/editar posts
- Máximo de 1000 notícias mais recentes
- Zero configuração necessária

## Instalação

1. Faça upload do plugin para `/wp-content/plugins/`
2. Ative o plugin
3. O sitemap estará disponível em `/news-sitemap.xml`

## Requisitos

- WordPress 6.0+
- PHP 7.0+
- Extensão DOM do PHP

## Como Funciona

O plugin mantém um sitemap XML otimizado com suas notícias mais recentes. O arquivo é atualizado automaticamente quando:

- Um post é publicado
- Um post é atualizado
- Um post é movido para a lixeira
- Um post é restaurado da lixeira

O sitemap é gerado de forma atômica para evitar arquivos corrompidos e usa cache em múltiplas camadas para máxima performance.
