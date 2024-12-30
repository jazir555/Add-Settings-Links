<?php
/*
Plugin Name: Add Settings Links
Description: Adds direct links to the settings pages for all plugins that do not have one.
Version: 1.6.0
Author: Jaz
Text Domain: add-settings-links
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Transient keys and expiration constants for caching menu slugs and plugins.
 */
if (!defined('ASL_MENU_SLUGS_TRANSIENT')) {
    define('ASL_MENU_SLUGS_TRANSIENT', 'asl_cached_admin_menu_slugs');
    define('ASL_MENU_SLUGS_TRANSIENT_EXPIRATION', 12 * HOUR_IN_SECONDS);
}
if (!defined('ASL_CACHED_PLUGINS_TRANSIENT')) {
    define('ASL_CACHED_PLUGINS_TRANSIENT', 'asl_cached_plugins');
    define('ASL_CACHED_PLUGINS_EXPIRATION', DAY_IN_SECONDS);
}

if (!class_exists('AddSettingsLinks')) {

    /**
     * Class AddSettingsLinks
     *
     * Integrates an auto-detection of “Settings” pages for installed plugins
     * on the Plugins page, allows manual overrides in a dedicated settings page,
     * and logs any missing settings pages in an admin notice.
     */
    class AddSettingsLinks {

        /**
         * Holds any plugins for which no recognized settings page was found.
         *
         * @var string[]
         */
        private $missing_settings = array();

        /**
         * Constructor: sets up WordPress action/filter hooks.
         */
        public function __construct() {
            // 1. Load plugin text domain for translations.
            add_action('plugins_loaded', array($this, 'load_textdomain'));

            // 2. Hook into 'admin_menu' to conditionally cache admin menu slugs.
            add_action('admin_menu', array($this, 'maybe_cache_admin_menu_slugs'), 9999);

            // 3. Add or skip “Settings” links for each plugin on the Plugins screen only.
            add_filter('plugin_action_links', array($this, 'maybe_add_settings_links'), 10, 2);

            // 4. Clear the cached slugs whenever a plugin is activated or deactivated.
            add_action('activated_plugin', array($this, 'clear_cached_menu_slugs'));
            add_action('deactivated_plugin', array($this, 'clear_cached_menu_slugs'));

            // 5. Invalidate cached slugs on plugin/theme updates or installations.
            add_action('upgrader_process_complete', array($this, 'dynamic_cache_invalidation'), 10, 2);

            // 6. Register manual overrides on certain admin pages only.
            add_action('admin_init', array($this, 'maybe_register_settings'));

            // 7. Add our plugin’s own settings page under “Settings.”
            add_action('admin_menu', array($this, 'maybe_add_settings_page'));

            // 8. Display a single admin notice if any plugin lacks a recognized settings link.
            add_action('admin_notices', array($this, 'maybe_display_admin_notices'));
        }

        /**
         * Load the plugin text domain for translations.
         */
        public function load_textdomain(): void {
            load_plugin_textdomain('add-settings-links', false, dirname(plugin_basename(__FILE__)) . '/languages');
        }

        /**
         * Conditionally cache admin menu slugs if we're on a relevant admin screen.
         *
         * Runs at priority 9999 to ensure the full admin menu is registered.
         */
        public function maybe_cache_admin_menu_slugs(): void {
            // Check if in admin and if get_current_screen() is available
            if (!is_admin() || !function_exists('get_current_screen')) {
                return;
            }

            $screen = get_current_screen();
            if ($screen && in_array($screen->id, array('plugins', 'settings_page_asl_settings', 'options-general'), true)) {
                $this->cache_admin_menu_slugs();
            }
        }

        /**
         * Actually cache all admin menu slugs in a transient, if not already cached.
         */
        private function cache_admin_menu_slugs(): void {
            if (false !== get_transient(ASL_MENU_SLUGS_TRANSIENT)) {
                $this->log_debug('Menu slugs are already cached. Skipping rebuild.');
                return;
            }

            global $menu, $submenu;
            $all_slugs = array();

            // Gather top-level menu items
            if (!empty($menu) && is_array($menu)) {
                foreach ($menu as $item) {
                    if (!empty($item[2])) {
                        $slug   = $item[2];
                        $parent = isset($item[0]) ? $item[0] : '';
                        $url    = $this->construct_menu_url($slug);

                        $all_slugs[] = array(
                            'slug'   => $slug,
                            'url'    => $url,
                            'parent' => $parent,
                        );
                        $this->log_debug("Caching top-level slug: $slug with URL: $url");
                    }
                }
            }

            // Gather submenu items
            if (!empty($submenu) && is_array($submenu)) {
                foreach ($submenu as $parent_slug => $items) {
                    foreach ((array)$items as $item) {
                        if (!empty($item[2])) {
                            $slug   = $item[2];
                            $url    = $this->construct_menu_url($slug, $parent_slug);
                            $all_slugs[] = array(
                                'slug'   => $slug,
                                'url'    => $url,
                                'parent' => $parent_slug,
                            );
                            $this->log_debug("Caching submenu slug: $slug with URL: $url under parent: $parent_slug");
                        }
                    }
                }
            }

            if (!empty($all_slugs)) {
                set_transient(ASL_MENU_SLUGS_TRANSIENT, $all_slugs, ASL_MENU_SLUGS_TRANSIENT_EXPIRATION);
                $this->log_debug('Menu slugs have been cached successfully.');
            } else {
                $this->log_debug('No admin menu slugs found to cache.');
            }
        }

        /**
         * Construct a complete admin URL from a given slug (plus optional parent slug).
         */
        private function construct_menu_url(string $slug, string $parent_slug = ''): string {
            // If there's no parent, it's a top-level item.
            if (empty($parent_slug)) {
                if (strpos($slug, '.php') !== false) {
                    return admin_url($slug);
                }
                return add_query_arg('page', $slug, admin_url('admin.php'));
            }

            // If the parent has `.php`, we append ?page=child_slug to it.
            if (strpos($parent_slug, '.php') !== false) {
                return add_query_arg('page', $slug, admin_url($parent_slug));
            }
            return add_query_arg('page', $slug, admin_url('admin.php'));
        }

        /**
         * Hook into “plugin_action_links” on the Plugins page to add a “Settings” link.
         */
        public function maybe_add_settings_links(array $links, string $file): array {
            // If not an admin screen or get_current_screen() not available, do nothing
            if (!is_admin() || !function_exists('get_current_screen')) {
                return $links;
            }

            $screen = get_current_screen();
            // Only manipulate if on the plugins page
            if (!$screen || $screen->id !== 'plugins') {
                return $links;
            }

            // Proceed with adding missing settings link if needed
            return $this->add_missing_settings_links($links, $file);
        }

        /**
         * Inject a “Settings” link for any plugin that lacks one, using auto-discovery + manual overrides.
         */
        private function add_missing_settings_links(array $links, string $file): array {
            // Only show “Settings” to users who can manage options (optional).
            if (!current_user_can('manage_options')) {
                return $links;
            }

            // If the plugin already has a “settings-like” link, skip adding a new one.
            if ($this->plugin_has_settings_link($links)) {
                return $links;
            }

            $settings_added   = false;
            $manual_overrides = get_option('asl_manual_overrides', array());

            // 1. Check for manual overrides
            if (!empty($manual_overrides[$file])) {
                foreach ((array) $manual_overrides[$file] as $settings_url) {
                    $settings_url = trim($settings_url);
                    if (!$settings_url) {
                        continue;
                    }
                    // Convert to absolute admin URL if not fully qualified
                    if (strpos($settings_url, 'http') !== 0) {
                        $settings_url = admin_url($settings_url);
                    }

                    // Avoid duplicates
                    if (!$this->link_already_exists($links, $settings_url)) {
                        $action_link_html = sprintf(
                            '<a href="%1$s">%2$s</a>',
                            esc_url($settings_url),
                            esc_html__('Settings', 'add-settings-links')
                        );
                        array_unshift($links, $action_link_html);
                        $settings_added = true;
                    }
                }
            }

            if ($settings_added) {
                return $links; // Done if we added a link from manual overrides
            }

            // 2. Attempt auto-discovery from cached admin menu slugs
            $plugin_basename = plugin_basename($file);
            $plugin_dir      = dirname($plugin_basename);
            $settings_urls   = $this->find_settings_url($plugin_dir, $plugin_basename);

            if (!empty($settings_urls)) {
                foreach ((array)$settings_urls as $settings_url) {
                    $settings_url = trim($settings_url);
                    if (!$settings_url) {
                        continue;
                    }
                    // Convert to absolute admin URL
                    $full_url = (strpos($settings_url, 'http') === 0)
                        ? $settings_url
                        : admin_url($settings_url);

                    // Avoid duplicates
                    if (!$this->link_already_exists($links, $full_url)) {
                        $action_link_html = sprintf(
                            '<a href="%1$s">%2$s</a>',
                            esc_url($full_url),
                            esc_html__('Settings', 'add-settings-links')
                        );
                        array_unshift($links, $action_link_html);
                        $settings_added = true;
                    }
                }
            }

            // 3. If no link was added, mark this plugin as missing.
            if (!$settings_added) {
                $this->missing_settings[] = $plugin_basename;
                $this->log_debug("No recognized settings link found for plugin: $plugin_basename");
            }

            return $links;
        }

        /**
         * Checks if the plugin’s action links already have a “settings-like” link.
         */
        private function plugin_has_settings_link(array $links): bool {
            if (empty($links)) {
                return false;
            }

            // Common synonyms that indicate a “settings” link.
            $synonyms = array(
                'settings', 'setting', 'configure', 'config',
                'options', 'option', 'manage', 'setup',
                'admin', 'preferences', 'prefs',
            );

            foreach ($links as $link_html) {
                if (preg_match('/<a\s.*?>([^<]+)<\/a>/i', $link_html, $matches)) {
                    $link_text = strtolower(trim($matches[1]));
                    foreach ($synonyms as $word) {
                        if (strpos($link_text, $word) !== false) {
                            return true; // Found an existing link
                        }
                    }
                }
            }
            return false;
        }

        /**
         * Determine if a URL is already present in the existing plugin action links (to avoid duplicates).
         */
        private function link_already_exists(array $links, string $new_url): bool {
            $new_url_parsed = parse_url($new_url);
            if (!$new_url_parsed) {
                return false; // If parse_url fails, can't reliably compare.
            }

            foreach ($links as $link_html) {
                if (preg_match('/href=[\'"]([^\'"]+)[\'"]/', $link_html, $matches)) {
                    $existing_href = $matches[1];
                    $existing_parsed = parse_url($existing_href);
                    if (!$existing_parsed) {
                        continue;
                    }

                    // If both have hosts, compare scheme + host + path
                    if (!empty($existing_parsed['host']) && !empty($new_url_parsed['host'])) {
                        $same_scheme = isset($existing_parsed['scheme'], $new_url_parsed['scheme'])
                            ? ($existing_parsed['scheme'] === $new_url_parsed['scheme'])
                            : true;
                        $same_host = ($existing_parsed['host'] === $new_url_parsed['host']);
                        $same_path = (!empty($existing_parsed['path']) && !empty($new_url_parsed['path']))
                            ? ($existing_parsed['path'] === $new_url_parsed['path'])
                            : false;

                        if ($same_scheme && $same_host && $same_path) {
                            return true;
                        }
                    } else {
                        // If there's no host, compare path (and 'page' query param)
                        if (isset($existing_parsed['path'], $new_url_parsed['path']) &&
                            $existing_parsed['path'] === $new_url_parsed['path']) {

                            parse_str($existing_parsed['query'] ?? '', $existing_qs);
                            parse_str($new_url_parsed['query'] ?? '', $new_qs);

                            if (!empty($existing_qs['page']) && !empty($new_qs['page'])) {
                                if ($existing_qs['page'] === $new_qs['page']) {
                                    return true;
                                }
                            } else {
                                // Path alone is enough
                                return true;
                            }
                        }
                    }
                }
            }
            return false;
        }

        /**
         * Attempt to discover plugin settings URLs by matching admin slugs.
         */
        private function find_settings_url(string $plugin_dir, string $plugin_basename) {
            $cached_slugs = get_transient(ASL_MENU_SLUGS_TRANSIENT);
            if (empty($cached_slugs)) {
                $this->cache_admin_menu_slugs();
                $cached_slugs = get_transient(ASL_MENU_SLUGS_TRANSIENT);
            }

            if (empty($cached_slugs) || !is_array($cached_slugs)) {
                $this->log_debug('Cannot find potential settings slugs. Cache is empty or invalid.');
                return false;
            }

            // Generate potential slugs from plugin folder + file naming
            $potential_slugs = $this->generate_potential_slugs($plugin_dir, $plugin_basename);

            $found_urls = array();
            foreach ($cached_slugs as $item) {
                if (!isset($item['slug'], $item['url'])) {
                    continue;
                }
                if (in_array($item['slug'], $potential_slugs, true)) {
                    $this->log_debug("Found potential settings URL for plugin '$plugin_basename': " . $item['url']);
                    $found_urls[] = $item['url'];
                }
            }

            return !empty($found_urls) ? array_unique($found_urls) : false;
        }

        /**
         * Generate a set of potential “page” slugs from plugin directory + file naming patterns.
         */
        private function generate_potential_slugs(string $plugin_dir, string $plugin_basename): array {
            $basename = basename($plugin_basename, '.php');

            $variations = array(
                $plugin_dir,
                $basename,
                str_replace('-', '_', $plugin_dir),
                str_replace('_', '-', $plugin_dir),
                str_replace('-', '_', $basename),
                str_replace('_', '-', $basename),
                strtolower($basename),
                strtolower($plugin_dir),
                ucwords($basename),
                ucwords($plugin_dir),
                // Extra “settings” patterns
                'settings',
                "{$plugin_dir}_settings",
                "{$basename}_settings",
                "{$plugin_dir}-settings",
                "{$basename}-settings",
                $basename . 'options',
                $basename . 'settings',
            );

            $slugs = array_map('sanitize_title', $variations);
            return array_unique($slugs);
        }

        /**
         * Clear the cached admin menu slugs and plugin data.
         */
        public function clear_cached_menu_slugs(): void {
            delete_transient(ASL_MENU_SLUGS_TRANSIENT);
            delete_transient(ASL_CACHED_PLUGINS_TRANSIENT);
            $this->log_debug('Cleared cached menu slugs and plugin list transient.');
        }

        /**
         * Invalidate the slug cache on plugin/theme updates or installations.
         */
        public function dynamic_cache_invalidation($upgrader, $options): void {
            // Ensure $options is indeed an array
            if (!is_array($options)) {
                $this->log_debug('dynamic_cache_invalidation called with non-array $options, skipping.');
                return;
            }
            if (!empty($options['type']) && in_array($options['type'], array('plugin', 'theme'), true)) {
                $this->clear_cached_menu_slugs();
            }
        }

        /**
         * Registers the “manual overrides” settings if we’re on relevant admin screens.
         */
        public function maybe_register_settings(): void {
            if (!is_admin() || !function_exists('get_current_screen')) {
                return;
            }
            $screen = get_current_screen();
            if ($screen && in_array($screen->id, array('options-general', 'settings_page_asl_settings'), true)) {
                $this->register_settings();
            }
        }

        /**
         * Register the “asl_manual_overrides” setting & fields.
         */
        private function register_settings(): void {
            register_setting(
                'asl_settings_group',
                'asl_manual_overrides',
                array($this, 'sanitize_manual_overrides')
            );

            add_settings_section(
                'asl_settings_section',
                __('Manual Settings Overrides', 'add-settings-links'),
                array($this, 'settings_section_callback'),
                'asl_settings'
            );

            add_settings_field(
                'asl_manual_overrides_field',
                __('Manual Overrides', 'add-settings-links'),
                array($this, 'manual_overrides_field_callback'),
                'asl_settings',
                'asl_settings_section'
            );
        }

        /**
         * Conditionally add the “Add Settings Links” page under “Settings”.
         */
        public function maybe_add_settings_page(): void {
            if (is_admin()) {
                add_options_page(
                    __('Add Settings Links', 'add-settings-links'),
                    __('Add Settings Links', 'add-settings-links'),
                    'manage_options',
                    'asl_settings',
                    array($this, 'render_settings_page')
                );
            }
        }

        /**
         * Render callback text for the “Manual Settings Overrides” section.
         */
        public function settings_section_callback(): void {
            echo '<p>' . esc_html__(
                'Specify custom settings page URLs for plugins with multiple or unconventional settings pages.',
                'add-settings-links'
            ) . '</p>';
        }

        /**
         * Render table of installed plugins & fields for manual overrides.
         */
        public function manual_overrides_field_callback(): void {
            $manual_overrides = get_option('asl_manual_overrides', array());
            $plugins = $this->get_all_plugins();
            ?>
            <input
                type="text"
                id="asl_plugin_search"
                placeholder="<?php esc_attr_e('Search Plugins...', 'add-settings-links'); ?>"
                style="width:100%; margin-bottom: 10px;"
            />
            <table class="widefat fixed asl-settings-table" cellspacing="0">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Plugin', 'add-settings-links'); ?></th>
                        <th><?php esc_html_e('Manual Settings URLs (comma-separated)', 'add-settings-links'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plugins as $plugin_file => $plugin_data) :
                        $existing_urls = isset($manual_overrides[$plugin_file])
                            ? (array) $manual_overrides[$plugin_file]
                            : array();
                        $existing_str  = implode(',', $existing_urls);
                        ?>
                        <tr>
                            <td><?php echo esc_html($plugin_data['Name']); ?></td>
                            <td>
                                <input
                                    type="text"
                                    name="asl_manual_overrides[<?php echo esc_attr($plugin_file); ?>]"
                                    value="<?php echo esc_attr($existing_str); ?>"
                                    style="width:100%;"
                                    aria-describedby="asl_manual_overrides_description_<?php echo esc_attr($plugin_file); ?>"
                                />
                                <p
                                    class="description"
                                    id="asl_manual_overrides_description_<?php echo esc_attr($plugin_file); ?>"
                                >
                                    <?php esc_html_e(
                                        'Enter one or multiple settings URLs separated by commas.',
                                        'add-settings-links'
                                    ); ?>
                                </p>
                                <span class="asl-error-message" style="color: red; display: none;"></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <style>
                .asl-settings-table tbody tr:hover {
                    background-color: #f1f1f1;
                }
                .asl-error-message {
                    font-size: 0.9em;
                }
            </style>

            <script>
            (function() {
                document.addEventListener('DOMContentLoaded', function() {
                    const searchInput = document.getElementById('asl_plugin_search');
                    const tableRows   = document.querySelectorAll('.asl-settings-table tbody tr');

                    // 1. Live-search filter
                    if (searchInput && tableRows) {
                        searchInput.addEventListener('keyup', function() {
                            const query = this.value.toLowerCase();
                            tableRows.forEach(function(row) {
                                const pluginName = row.querySelector('td:first-child').textContent.toLowerCase();
                                row.style.display = pluginName.includes(query) ? '' : 'none';
                            });
                        });
                    }

                    // 2. Basic URL validation
                    const urlPattern = new RegExp('^(https?:\\/\\/)?'
                        + '((([a-z\\d]([a-z\\d-]*[a-z\\d])*)\\.)+[a-z]{2,}|' 
                        + '((\\d{1,3}\\.){3}\\d{1,3}))'
                        + '(\\:\\d+)?(\\/[-a-z\\d%_.~+]*)*'
                        + '(\\?[;&a-z\\d%_.~+=-]*)?'
                        + '(\\#[-a-z\\d_]*)?$','i');

                    const urlInputs = document.querySelectorAll('.asl-settings-table tbody tr td:nth-child(2) input[type="text"]');
                    urlInputs.forEach(function(input) {
                        input.addEventListener('blur', function() {
                            const errorMessage = this.parentNode.querySelector('.asl-error-message');
                            if (!errorMessage) return;

                            let allValid = true;
                            const raw = this.value.trim();
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
                                this.style.borderColor = 'red';
                                errorMessage.textContent = '<?php echo esc_js(
                                    __('One or more URLs are invalid. Please ensure correct formatting.', 'add-settings-links')
                                ); ?>';
                                errorMessage.style.display = 'block';
                            } else {
                                this.style.borderColor = '';
                                errorMessage.textContent = '';
                                errorMessage.style.display = 'none';
                            }
                        });
                    });
                });
            })();
            </script>
            <?php
        }

        /**
         * Sanitize user input from the manual overrides fields.
         */
        public function sanitize_manual_overrides($input): array {
            if (!is_array($input)) {
                return array();
            }

            $sanitized = array();
            foreach ($input as $plugin_file => $raw_value) {
                $url_candidates = array_map('trim', explode(',', (string) $raw_value));
                $valid_urls = array();
                foreach ($url_candidates as $candidate) {
                    $candidate = esc_url_raw($candidate);
                    if (!empty($candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
                        $valid_urls[] = $candidate;
                    }
                }
                if (!empty($valid_urls)) {
                    $sanitized[$plugin_file] = $valid_urls;
                }
            }
            return $sanitized;
        }

        /**
         * Render the “Add Settings Links” settings page with the manual overrides form.
         */
        public function render_settings_page(): void {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Add Settings Links', 'add-settings-links'); ?></h1>
                <form method="post" action="options.php">
                    <?php
                        settings_fields('asl_settings_group');
                        do_settings_sections('asl_settings');
                        submit_button();
                    ?>
                </form>
            </div>
            <?php
        }

        /**
         * Retrieve (and cache) the installed plugins list. Used for the manual override table.
         */
        private function get_all_plugins(): array {
            $cached_plugins = get_transient(ASL_CACHED_PLUGINS_TRANSIENT);
            if (false === $cached_plugins) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $cached_plugins = get_plugins();
                set_transient(ASL_CACHED_PLUGINS_TRANSIENT, $cached_plugins, ASL_CACHED_PLUGINS_EXPIRATION);
                $this->log_debug('Installed plugins list cached.');
            } else {
                $this->log_debug('Installed plugins list retrieved from cache.');
            }
            return (array) $cached_plugins;
        }

        /**
         * Display a single aggregated admin notice if any plugins were missing a recognized settings link.
         */
        public function maybe_display_admin_notices(): void {
            // Show notice only if in admin area + get_current_screen() is available
            if (!is_admin() || !function_exists('get_current_screen')) {
                return;
            }
            $screen = get_current_screen();
            if ($screen && in_array($screen->id, array('plugins', 'settings_page_asl_settings', 'options-general'), true)) {
                $this->display_admin_notices();
            }
        }

        /**
         * Actually output the missing-settings notice, then reset the list.
         */
        private function display_admin_notices(): void {
            if (!empty($this->missing_settings)) {
                $class   = 'notice notice-warning is-dismissible';
                $plugins = implode(', ', array_map('esc_html', $this->missing_settings));
                $message = sprintf(
                    __('Add Settings Links: No recognized settings URL found for the following plugins: %s.', 'add-settings-links'),
                    $plugins
                );

                printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses_post($message));
                $this->missing_settings = array();
            }
        }

        /**
         * Clear the cached menu slugs and plugin data.
         */
        public function clear_cached_menu_slugs(): void {
            delete_transient(ASL_MENU_SLUGS_TRANSIENT);
            delete_transient(ASL_CACHED_PLUGINS_TRANSIENT);
            $this->log_debug('Cached menu slugs and plugin list transient have been cleared.');
        }

        /**
         * Debug logging if WP_DEBUG + WP_DEBUG_LOG are enabled.
         */
        private function log_debug(string $message): void {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                if (is_multisite()) {
                    error_log('[Add Settings Links][Site ID ' . get_current_blog_id() . '] ' . $message);
                } else {
                    error_log('[Add Settings Links] ' . $message);
                }
            }
        }
    }

    // Instantiate the plugin class immediately.
    new AddSettingsLinks();
}
