# Simple News Sitemap

Um plugin WordPress leve e eficiente que gera automaticamente um Sitemap de Notícias (news-sitemap.xml) seguindo as diretrizes mais recentes do Google News.

## Descrição

O Simple News Sitemap é um plugin desenvolvido por Roque Viana da Crivo.tech para o portal Central da Toca. Ele gera e mantém automaticamente um sitemap de notícias compatível com o Google News, incluindo apenas as notícias publicadas nas últimas 48 horas.

## Características

- Geração automática do arquivo news-sitemap.xml
- Compatível com WordPress 6.x ou superior
- Compatível com Rank Math SEO
- Painel de configuração para selecionar categorias incluídas
- Opção para excluir posts individuais do sitemap
- Atualização automática quando posts são modificados
- Limite configurável de notícias (máximo 50, conforme exigido pelo Google News)

## Instalação

1. Faça upload da pasta `simple-news-sitemap` para o diretório `/wp-content/plugins/`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. Configure o plugin em 'Configurações > Simple News Sitemap'

## Configuração

1. Acesse o painel de administração do WordPress
2. Navegue até 'Configurações > Simple News Sitemap'
3. Selecione as categorias que devem ser incluídas no sitemap
4. Defina o número máximo de notícias (máximo 50)
5. Salve as configurações

## Uso

Após a instalação e configuração, o sitemap estará disponível em:

```
https://www.centraldatoca.com.br/news-sitemap.xml
```

Para excluir um post específico do sitemap:

1. Edite o post desejado
2. Localize a caixa "Simple News Sitemap" no painel lateral
3. Marque a opção "Excluir do Sitemap de Notícias"
4. Atualize ou publique o post

## Requisitos

- WordPress 6.x ou superior
- PHP 7.0 ou superior

## Suporte

Para suporte, entre em contato com a Crivo.tech.

## Licença

Este plugin está licenciado sob a GPL v2 ou posterior.
