(function($) {
    $(document).ready(function() {
        // Live-search filter targeting plugin names
        const searchInput = $('#asl_plugin_search');
        const tableRows   = $('.asl-settings-table tbody tr');

        if (searchInput.length && tableRows.length) {
            searchInput.on('keyup', function() {
                const query = $(this).val().toLowerCase();
                tableRows.each(function() {
                    const pluginName = $(this).find('td:first-child').text().toLowerCase();
                    $(this).toggle(pluginName.includes(query));
                });
            });
        }

        // Enhanced URL validation to include relative admin URLs
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
function debounce(func, wait) {
    let timeout;
    return function(...args) {
        const later = () => {
            clearTimeout(timeout);
            func.apply(this, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

searchInput.on('keyup', debounce(function() {
    const query = $(this).val().toLowerCase();
    tableRows.each(function() {
        const pluginName = $(this).find('td:first-child').text().toLowerCase();
        $(this).toggle(pluginName.includes(query));
    });
}, 300)); // Adjust the wait time as needed
