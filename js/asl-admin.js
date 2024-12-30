(function($) {
    $(document).ready(function() {
        const searchInput = $('#asl_plugin_search');
        const tableRows   = $('.asl-settings-table tbody tr');

        // 1. Live-search filter
        if (searchInput.length && tableRows.length) {
            searchInput.on('keyup', function() {
                const query = $(this).val().toLowerCase();
                tableRows.each(function() {
                    const pluginName = $(this).find('td:first-child').text().toLowerCase();
                    $(this).toggle(pluginName.includes(query));
                });
            });
        }

        // 2. Enhanced URL validation to include relative admin URLs
        const urlPattern = /^(https?:\/\/)?((([a-z\d]([a-z\d-]*[a-z\d])*)\.)+[a-z]{2,}|((\d{1,3}\.){3}\d{1,3}))(\:\d+)?(\/[-a-z\d%_.~+]*)*(\?[;&a-z\d%_.~+=-]*)?(\#[-a-z\d_]*)?$|^admin\.php\?page=[\w\-]+$/i;

        $('.asl-settings-table tbody tr td:nth-child(2) input[type="text"]').each(function() {
            $(this).on('blur', function() {
                const errorMessage = $(this).siblings('.asl-error-message');
                let allValid = true;
                const raw = $(this).val().trim();
                if (raw !== '') {
                    const urls = raw.split(',');
                    for (let i = 0; i < urls.length; i++) {
                        const check = urls[i].trim();
                        if (check !== '' && !urlPattern.test(check)) {
                            allValid = false;
                            break;
                        }
                    }
                }

                if (!allValid) {
                    $(this).css('border-color', 'red');
                    errorMessage.text(ASL_Settings.invalid_url_message).show();
                } else {
                    $(this).css('border-color', '');
                    errorMessage.text('').hide();
                }
            });
        });
    });
})(jQuery);
jQuery(document).ready(function($) {
    $('#asl_plugin_search').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $('.asl-settings-table tbody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
});
