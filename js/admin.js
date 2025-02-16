jQuery(document).ready(function($) {
    // Gerenciar visibilidade das configurações do Cloudflare
    function toggleCloudflareSettings() {
        var $settings = $('#cloudflare_settings');
        if ($('input[name="simple_news_sitemap_options[enable_cloudflare]"]').is(':checked')) {
            $settings.slideDown();
        } else {
            $settings.slideUp();
        }
    }

    $('input[name="simple_news_sitemap_options[enable_cloudflare]"]').on('change', toggleCloudflareSettings);
    toggleCloudflareSettings();

    // Gerenciar geração manual do sitemap
    var isGenerating = false;
    var progressInterval;

    $('#generate-sitemap-form').on('submit', function(e) {
        if (isGenerating) {
            e.preventDefault();
            return;
        }

        isGenerating = true;
        var $button = $(this).find('button[type="submit"]');
        var $progress = $('#generation-progress');
        
        $button.prop('disabled', true).text('Gerando...');
        $progress.show().html('<p>Iniciando geração do sitemap...</p>');

        // Iniciar verificação de progresso
        progressInterval = setInterval(checkProgress, 1000);
    });

    function checkProgress() {
        $.ajax({
            url: simpleNewsSitemap.ajax_url,
            type: 'POST',
            data: {
                action: 'check_sitemap_progress',
                nonce: simpleNewsSitemap.nonce
            },
            success: function(response) {
                if (response.status === 'complete') {
                    clearInterval(progressInterval);
                    isGenerating = false;
                    
                    var $button = $('#generate-sitemap-form button[type="submit"]');
                    var $progress = $('#generation-progress');
                    
                    $button.prop('disabled', false).text('Gerar Sitemap');
                    $progress.html('<p class="success">Sitemap gerado com sucesso!</p>');
                    
                    // Recarregar página após 2 segundos
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else if (response.message) {
                    $('#generation-progress').html('<p>' + response.message + '</p>');
                }
            },
            error: function() {
                clearInterval(progressInterval);
                isGenerating = false;
                
                var $button = $('#generate-sitemap-form button[type="submit"]');
                var $progress = $('#generation-progress');
                
                $button.prop('disabled', false).text('Gerar Sitemap');
                $progress.html('<p class="error">Erro ao verificar progresso</p>');
            }
        });
    }

    // Gerenciar tabs na página de configurações
    var $tabs = $('.nav-tab-wrapper .nav-tab');
    var $panels = $('.tab-panel');

    $tabs.on('click', function(e) {
        e.preventDefault();
        var target = $(this).attr('href');

        // Atualizar tabs
        $tabs.removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        // Atualizar painéis
        $panels.hide();
        $(target).show();

        // Salvar tab ativa no localStorage
        localStorage.setItem('activeTab', target);
    });

    // Restaurar última tab ativa
    var activeTab = localStorage.getItem('activeTab');
    if (activeTab) {
        $tabs.filter('[href="' + activeTab + '"]').trigger('click');
    } else {
        $tabs.first().trigger('click');
    }
});
