# Simple News Sitemap

Um plugin WordPress leve e eficiente que gera automaticamente um Sitemap de Notícias (news-sitemap.xml) seguindo as diretrizes mais recentes do Google News.

## Descrição

O Simple News Sitemap é um plugin desenvolvido por Roque Viana da Crivo.tech para o portal Central da Toca. Ele gera e mantém automaticamente um sitemap de notícias compatível com o Google News, incluindo apenas as notícias mais recentes com prioridade dinâmica baseada em data e popularidade.

## Características

- Geração automática do arquivo news-sitemap.xml
- Compatível com WordPress 6.x ou superior
- Compatível com Rank Math SEO
- Sistema de cache em múltiplas camadas para melhor performance
- Prioridade dinâmica baseada em data e popularidade
- Suporte a limpeza de cache do Cloudflare e SiteGround
- Ping automático para Google e Bing
- Painel de configuração completo com:
  - Seleção de categorias incluídas
  - Configuração de prioridade dinâmica
  - Gerenciamento de cache
  - Modo debug para troubleshooting
- Opção para excluir posts individuais do sitemap
- Atualização automática quando posts são modificados
- Limite configurável de notícias (1-1000)
- Sistema robusto de logging e monitoramento
- Geração atômica do sitemap para evitar arquivos corrompidos

## Instalação

1. Faça upload da pasta `simple-news-sitemap` para o diretório `/wp-content/plugins/`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. Configure o plugin em 'Configurações > Simple News Sitemap'

## Configuração

1. Acesse o painel de administração do WordPress
2. Navegue até 'Configurações > Simple News Sitemap'
3. Configure as opções básicas:
   - Selecione as categorias que devem ser incluídas
   - Defina o número máximo de notícias
   - Configure a prioridade base para o cálculo dinâmico
4. Configure as opções de cache (opcional):
   - Habilite integração com Cloudflare
   - Configure limpeza de cache do SiteGround
5. Configure as opções avançadas:
   - Selecione serviços de ping (Google/Bing)
   - Ative o modo debug se necessário
6. Salve as configurações

## Uso

Após a instalação e configuração, o sitemap estará disponível em:

```
https://seu-site.com/news-sitemap.xml
```

Para excluir um post específico do sitemap:

1. Edite o post desejado
2. Localize a caixa "Simple News Sitemap" no painel lateral
3. Marque a opção "Excluir do Sitemap de Notícias"
4. Atualize ou publique o post

## Recursos Avançados

### Prioridade Dinâmica

O plugin calcula automaticamente a prioridade de cada notícia baseado em:
- Data de publicação (posts mais recentes têm prioridade maior)
- Número de comentários (posts mais comentados têm prioridade maior)
- Prioridade base configurável

### Sistema de Cache

O plugin implementa um sistema de cache em múltiplas camadas:
- Cache de objeto do WordPress
- Cache de arquivo
- Suporte a Cloudflare
- Suporte a SiteGround
- Limpeza automática quando necessário

### Logging e Debug

O sistema de logging fornece:
- Logs detalhados de operações
- Rotação automática de logs
- Interface administrativa para visualização
- Modo debug para troubleshooting

## Requisitos

- WordPress 6.x ou superior
- PHP 7.0 ou superior
- Permissões de escrita em wp-content e wp-content/uploads
- Módulos PHP: dom, simplexml

## Suporte

Para suporte, entre em contato com a Crivo.tech.

## Licença

Este plugin está licenciado sob a GPL v2 ou posterior.

## Changelog

### 1.0.0
- Lançamento inicial

### 1.1.0
- Adicionado sistema de prioridade dinâmica
- Melhorias no sistema de cache
- Adicionado suporte a Cloudflare e SiteGround
- Interface administrativa melhorada
- Sistema de logging aprimorado
- Correções de bugs e melhorias de performance
