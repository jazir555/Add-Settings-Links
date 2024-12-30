(function($) {
    $(document).ready(function() {
        // Cache frequently used selectors for performance optimization
        const $searchInput = $('#asl_plugin_search');
        const $tableRows   = $('.asl-settings-table tbody tr');
        const $errorMessages = $('.asl-error-message');
        const $textInputs = $('.asl-settings-table tbody tr td:nth-child(2) input[type="text"]');
        const $form = $('form#asl-settings-form'); // Ensure your form has the ID 'asl-settings-form'

        /**
         * Debounce function to limit the rate at which a function can fire.
         *
         * @param {Function} func - The function to debounce.
         * @param {number} wait - The number of milliseconds to delay.
         * @return {Function} - The debounced function.
         *
         * @example
         * // Will only execute `handleSearch` after 300ms have passed since the last call
         * $searchInput.on('keyup', debounce(handleSearch, 300));
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
            $tableRows.each(function() {
                const pluginName = $(this).find('td:first-child').text().toLowerCase();
                $(this).toggle(pluginName.includes(query));
            });
        };

        // Attach debounced search handler if elements exist
        if ($searchInput.length && $tableRows.length) {
            $searchInput.off('keyup').on('keyup', debounce(handleSearch, 300));
        }

        // Enhanced URL validation to include relative admin URLs
        const urlPattern = /^(https?:\/\/)?((([a-z\d]([a-z\d-]*[a-z\d])*)\.)+[a-z]{2,}|((\d{1,3}\.){3}\d{1,3}))(\:\d+)?(\/[-a-z\d%_.~+]*)*(\?[;&a-z\d%_.~+=-]*)?(\#[-a-z\d_]*)?$|^admin\.php\?page=[\w\-]+$/i;

        /**
         * Handler function for URL validation.
         *
         * @this {HTMLElement} - The input element being validated.
         */
        const handleURLValidation = function() {
            const $input = $(this);
            const errorMessage = $input.siblings('.asl-error-message');
            const raw = $input.val().trim();
            let allValid = true;

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

            if ($input.length && errorMessage.length) {
                if (!allValid) {
                    $input.css('border-color', 'red').attr('aria-invalid', 'true');
                    if (typeof ASL_Settings !== 'undefined' && ASL_Settings.invalid_url_message) {
                        errorMessage.text(ASL_Settings.invalid_url_message).show();
                    } else {
                        errorMessage.text('Invalid URL.').show();
                        console.warn('ASL_Settings.invalid_url_message is not defined.');
                    }
                } else {
                    $input.css('border-color', '').attr('aria-invalid', 'false');
                    errorMessage.text('').hide();
                }
            } else {
                console.warn('Error message element is missing for the input field.');
            }
        };

        // Attach debounced URL validation handler if input fields exist
        if ($textInputs.length) {
            $textInputs.off('input').on('input', debounce(handleURLValidation, 300));
        }

        /**
         * Accessibility Enhancement:
         * Automatically focus on the first invalid input field when the form is submitted.
         *
         * @param {jQuery.Event} e - The form submission event.
         */
        function focusFirstInvalidInput(e) {
            const $invalidInput = $textInputs.filter('[aria-invalid="true"]').first();
            if ($invalidInput.length) {
                e.preventDefault();
                $invalidInput.focus();
                // Replace alert with a more user-friendly notification if desired
                if (typeof ASL_Settings !== 'undefined' && ASL_Settings.invalid_url_message) {
                    alert(ASL_Settings.invalid_url_message);
                } else {
                    alert('One or more URLs are invalid. Please ensure correct formatting.');
                    console.warn('ASL_Settings.invalid_url_message is not defined.');
                }
            }
        }

        // Attach form submission handler to focus on the first invalid input
        if ($form.length) {
            $form.off('submit').on('submit', focusFirstInvalidInput);
        }
    });
})(jQuery);
