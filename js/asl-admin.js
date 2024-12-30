(function($) {
    $(document).ready(function() {
        // Live-search filter targeting plugin names with debounce
        const searchInput = $('#asl_plugin_search');
        const tableRows   = $('.asl-settings-table tbody tr');

        /**
         * Debounce function to limit the rate at which a function can fire.
         *
         * @param {Function} func - The function to debounce.
         * @param {number} wait - The number of milliseconds to delay.
         * @return {Function} - The debounced function.
         *
         * @example
         * // Will only execute `handleSearch` after 300ms have passed since the last call
         * searchInput.on('keyup', debounce(handleSearch, 300));
         */
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                const context = this;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), wait);
            };
        }

        /**
         * Handler function for live-search filtering.
         *
         * @this {HTMLElement} - The search input element.
         */
        const handleSearch = function() {
            const query = $(this).val().toLowerCase();
            tableRows.each(function() {
                const pluginName = $(this).find('td:first-child').text().toLowerCase();
                $(this).toggle(pluginName.includes(query));
            });
        };

        // Attach debounced search handler if elements exist
        if (searchInput.length && tableRows.length) {
            searchInput.on('keyup', debounce(handleSearch, 300));
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
                    $(this).css('border-color', 'red').attr('aria-invalid', 'true');
                    errorMessage.text(ASL_Settings.invalid_url_message).show();
                } else {
                    $(this).css('border-color', '').attr('aria-invalid', 'false');
                    errorMessage.text('').hide();
                }
            });
        });
    });
})(jQuery);
