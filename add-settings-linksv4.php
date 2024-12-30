<?php
/**
 * Plugin Name: Add Settings Links
 * Description: Adds direct links to the settings pages for all plugins that do not have one (including multisite/network admin support).
 * Version: 1.7.0
 * Author: Jazir5
 * Text Domain: add-settings-links
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Transient keys and expiration constants for caching menu slugs and plugins (multisite-friendly).
 */
if (!defined('ASL_MENU_SLUGS_TRANSIENT')) {
    define('ASL_MENU_SLUGS_TRANSIENT', 'asl_cached_admin_menu_slugs');
    define('ASL_MENU_SLUGS_TRANSIENT_EXPIRATION', 12 * HOUR_IN_SECONDS);
}
if (!defined('ASL_CACHED_PLUGINS_TRANSIENT')) {
    define('ASL_CACHED_PLUGINS_TRANSIENT', 'asl_cached_plugins');
    define('ASL_CACHED_PLUGINS_TRANSIENT_EXPIRATION', DAY_IN_SECONDS);
}

if (!class_exists('AddSettingsLinks')):

/**
 * Class AddSettingsLinks
 *
 * Discovers potential “Settings” pages for installed plugins (for single-site or multisite “network” admin),
 * allows manual overrides, and displays aggregated notices for any plugin missing a recognized settings link.
 */
class AddSettingsLinks {

    /**
     * List of plugin basenames that have no recognized settings page.
     * We show them in one aggregated notice on relevant screens.
     *
     * @var string[]
     */
    private $missing_settings = [];

    /**
     * Constructor: sets up WordPress hooks and filters for single-site + network usage.
     */
    public function __construct() {
        // 1. Load plugin text domain for translations.
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // 2. Conditionally cache admin menu slugs (single-site or network).
        add_action('admin_menu', [$this, 'maybe_cache_admin_menu_slugs'], 9999);

        // 3. Add or skip plugin settings links on the plugins screens (single-site or network).
        add_filter('plugin_action_links', [$this, 'maybe_add_settings_links'], 10, 2);

        // 4. Clear cached slugs whenever a plugin is activated/deactivated.
        add_action('activated_plugin', [$this, 'clear_cached_menu_slugs']);
        add_action('deactivated_plugin', [$this, 'clear_cached_menu_slugs']);

        // 5. Invalidate cached slugs on plugin/theme updates/installs.
        add_action('upgrader_process_complete', [$this, 'dynamic_cache_invalidation'], 10, 2);

        // 6. Register manual overrides on relevant admin screens.
        add_action('admin_init', [$this, 'maybe_register_settings']);

        // 7. Add our plugin’s settings page under “Settings” (also works in network admin if you prefer).
        add_action('admin_menu', [$this, 'maybe_add_settings_page']);

        // 8. Display an aggregated notice about missing settings pages (single-site + network).
        add_action('admin_notices', [$this, 'maybe_display_admin_notices']);
        add_action('network_admin_notices', [$this, 'maybe_display_admin_notices']);
        
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Loads the plugin's text domain for i18n.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'add-settings-links',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Checks whether the plugin should run certain logic on the current screen.
     *
     * @param string[] $valid_screens Array of screen IDs on which the plugin logic applies.
     * @return bool True if the current screen is in $valid_screens; otherwise false.
     */
    private function should_run_on_screen(array $valid_screens): bool {
        // Not in admin or network admin, or no get_current_screen => skip
        if ((!is_admin() && !is_network_admin()) || !function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }
        return in_array($screen->id, $valid_screens, true);
    }

    /**
     * Conditionally cache admin menu slugs if on relevant screens.
     */
    public function maybe_cache_admin_menu_slugs(): void {
        // Examples of relevant screens: plugins, plugins-network, settings_page_asl_settings, etc.
        $valid_screens = [
            'plugins',
            'plugins-network',
            'settings_page_asl_settings',
            'options-general',
            'options-general-network',
        ];
        if (!$this->should_run_on_screen($valid_screens)) {
            return;
        }
        $this->cache_admin_menu_slugs();
    }
    /**
     * Enhanced Settings Detection Trait
     * 
     * Provides improved methods for detecting WordPress plugin settings pages
     * through multiple detection strategies.
     */
    trait EnhancedSettingsDetection {
    /**
     * Common settings-related terms across multiple languages
     */
        private static $settings_terms = [
            'en' => ['settings', 'options', 'preferences', 'configuration'],
            'de' => ['einstellungen', 'optionen', 'konfiguration'],
            'es' => ['ajustes', 'opciones', 'configuración'],
            'fr' => ['paramètres', 'options', 'configuration'],
            // Add more languages as needed
    ];
    /**
     * Actually caches admin menu slugs (top-level + submenu) in a transient.
     */
    private function cache_admin_menu_slugs(): void {
        if (false !== get_transient(ASL_MENU_SLUGS_TRANSIENT)) {
            $this->log_debug('Admin menu slugs are already cached. Skipping rebuild.');
            return;
        }

        global $menu, $submenu;
        $all_slugs = [];

        // Gather top-level items
        if (!empty($menu) && is_array($menu)) {
            foreach ($menu as $item) {
                if (!empty($item[2])) {
                    $slug   = $item[2];
                    // item[0] might be the menu title
                    $parent = $item[0] ?? '';
                    $url    = $this->construct_menu_url($slug);
                    $all_slugs[] = [
                        'slug'   => $slug,
                        'url'    => $url,
                        'parent' => $parent,
                    ];
                    $this->log_debug("Caching top-level slug: $slug => $url");
                }
            }
        }

        // Gather submenu items
        if (!empty($submenu) && is_array($submenu)) {
            foreach ($submenu as $parent_slug => $items) {
                foreach ((array)$items as $item) {
                    if (!empty($item[2])) {
                        $slug  = $item[2];
                        $url   = $this->construct_menu_url($slug, $parent_slug);
                        $all_slugs[] = [
                            'slug'   => $slug,
                            'url'    => $url,
                            'parent' => $parent_slug,
                        ];
                        $this->log_debug("Caching submenu slug: $slug => $url (parent: $parent_slug)");
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
     * Helper to construct a full admin URL for a given slug (and optional parent slug).
     */
    private function construct_menu_url(string $slug, string $parent_slug = ''): string {
        if (empty($parent_slug)) {
            if (strpos($slug, '.php') !== false) {
                return admin_url($slug);
            }
            return add_query_arg('page', $slug, admin_url('admin.php'));
        }
        if (strpos($parent_slug, '.php') !== false) {
            return add_query_arg('page', $slug, admin_url($parent_slug));
        }
        return add_query_arg('page', $slug, admin_url('admin.php'));
    }

    /**
     * Possibly add or skip “Settings” links on the plugins screens (single-site or network).
     */
    public function maybe_add_settings_links(array $links, string $file): array {
        // Only run if on plugins or plugins-network screen
        $valid_screens = ['plugins', 'plugins-network'];
        if (!$this->should_run_on_screen($valid_screens)) {
            return $links;
        }
        return $this->add_missing_settings_links($links, $file);
    }

    /**
     * If a plugin has no recognized “Settings” link, inject one (manual overrides or discovered slugs).
     */
    private function add_missing_settings_links(array $links, string $file): array {
        // Show “Settings” only to users who can manage_options (including super admins in network).
        if (!current_user_can('manage_options')) {
            return $links;
        }

        // If it already has a “settings-like” link, skip
        if ($this->plugin_has_settings_link($links)) {
            return $links;
        }

        $settings_added   = false;
        $manual_overrides = get_option('asl_manual_overrides', []);

        // 1. Manual overrides
        if (!empty($manual_overrides[$file])) {
            foreach ((array)$manual_overrides[$file] as $settings_url) {
                $settings_url = trim($settings_url);
                if (!$settings_url) {
                    continue;
                }
                if (strpos($settings_url, 'http') !== 0) {
                    $settings_url = admin_url($settings_url);
                }

                if (!$this->link_already_exists($links, $settings_url)) {
                    $links = $this->prepend_settings_link($links, $settings_url);
                    $settings_added = true;
                }
            }
        }

        if ($settings_added) {
            return $links;
        }

        // 2. Auto-discovery from cached slugs
        $plugin_basename = plugin_basename($file);
        $plugin_dir      = dirname($plugin_basename);
        $settings_urls   = $this->find_settings_url($plugin_dir, $plugin_basename);

        if (!empty($settings_urls)) {
            foreach ((array)$settings_urls as $url) {
                $url = trim($url);
                if (!$url) {
                    continue;
                }
                $full_url = (strpos($url, 'http') === 0) ? $url : admin_url($url);
                if (!$this->link_already_exists($links, $full_url)) {
                    $links = $this->prepend_settings_link($links, $full_url);
                    $settings_added = true;
                }
            }
        }

        // 3. If no link found, track as missing
        if (!$settings_added) {
            $this->missing_settings[] = $plugin_basename;
            $this->log_debug("No recognized settings link found for plugin: $plugin_basename");
        }

        return $links;
    }

    /**
     * Prepends a “Settings” link to an array of plugin action links.
     */
    private function prepend_settings_link(array $links, string $settings_url): array {
        $html = sprintf(
            '<a href="%s">%s</a>',
            esc_url($settings_url),
            esc_html__('Settings', 'add-settings-links')
        );
        array_unshift($links, $html);
        return $links;
    }

    /**
     * Checks if the plugin’s action links already have a recognized “settings-like” link.
     */
    private function plugin_has_settings_link(array $links): bool {
        if (empty($links)) {
            return false;
        }

        // Make synonyms filterable for easier customization if desired
        $synonyms = apply_filters('add_settings_links_synonyms', [
            'settings', 'setting', 'configure', 'config',
            'options', 'option', 'manage', 'setup',
            'admin', 'preferences', 'prefs',
        ]);

        foreach ($links as $link_html) {
            if (preg_match('/<a\s[^>]*>([^<]+)<\/a>/i', $link_html, $m)) {
                $text = strtolower(trim($m[1]));
                foreach ($synonyms as $word) {
                    if (strpos($text, $word) !== false) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Determine if a URL is already present (to avoid duplicates).
     */
    private function link_already_exists(array $links, string $new_url): bool {
        $parsed_new = parse_url($new_url);
        if (!$parsed_new) {
            return false;
        }
        foreach ($links as $html) {
            if (preg_match('/href=[\'"]([^\'"]+)[\'"]/', $html, $m)) {
                $parsed_existing = parse_url($m[1]);
                if (!$parsed_existing) {
                    continue;
                }
                if ($this->urls_are_equivalent($parsed_existing, $parsed_new)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Compare two parsed URLs to see if they effectively represent the same admin link.
     */
    private function urls_are_equivalent(array $existing, array $new): bool {
        // If both have a host, compare host + path + optional scheme
        if (!empty($existing['host']) && !empty($new['host'])) {
            $same_scheme = (isset($existing['scheme'], $new['scheme']))
                ? ($existing['scheme'] === $new['scheme'])
                : true; 
            $same_host = ($existing['host'] === $new['host']);
            $same_path = (!empty($existing['path']) && !empty($new['path']))
                ? ($existing['path'] === $new['path'])
                : false;
            if ($same_scheme && $same_host && $same_path) {
                return true;
            }
        } else {
            // If no host, compare path and optional “page” param
            if (isset($existing['path'], $new['path']) && $existing['path'] === $new['path']) {
                parse_str($existing['query'] ?? '', $ex_q);
                parse_str($new['query'] ?? '', $nw_q);
                if (!empty($ex_q['page']) && !empty($nw_q['page'])) {
                    return ($ex_q['page'] === $nw_q['page']);
                }
                // No “page” param => path match is enough
                return true;
            }
        }
        return false;
    }

    /**
     * Find possible plugin settings URLs by comparing cached admin slugs with potential slugs.
     */
    private function find_settings_url(string $plugin_dir, string $plugin_basename) {
        $cached_slugs = get_transient(ASL_MENU_SLUGS_TRANSIENT);
        if (empty($cached_slugs) || !is_array($cached_slugs)) {
            $this->cache_admin_menu_slugs();
            $cached_slugs = get_transient(ASL_MENU_SLUGS_TRANSIENT);
        }
        if (empty($cached_slugs) || !is_array($cached_slugs)) {
            $this->log_debug('Cannot find potential settings slugs. Cache is empty or invalid.');
            return false;
        }

        $potential = $this->generate_potential_slugs($plugin_dir, $plugin_basename);
        $found_urls = [];
        foreach ($cached_slugs as $item) {
            if (isset($item['slug'], $item['url']) && in_array($item['slug'], $potential, true)) {
                $this->log_debug("Found potential settings URL for plugin '$plugin_basename': {$item['url']}");
                $found_urls[] = $item['url'];
            }
        }
        return !empty($found_urls) ? array_unique($found_urls) : false;
    }

    /**
     * Generate potential admin “page” slugs based on plugin directory/file naming.
     */
    private function generate_potential_slugs(string $plugin_dir, string $plugin_basename): array {
        $basename = basename($plugin_basename, '.php');
        $patterns = [
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
            'settings',
            "{$plugin_dir}_settings",
            "{$basename}_settings",
            "{$plugin_dir}-settings",
            "{$basename}-settings",
            $basename . 'options',
            $basename . 'settings',
        ];
        return array_unique(array_map('sanitize_title', $patterns));
    }

    /**
     * Clear cached admin menu slugs + plugin data.
     */
    public function clear_cached_menu_slugs(): void {
        delete_transient(ASL_MENU_SLUGS_TRANSIENT);
        delete_transient(ASL_CACHED_PLUGINS_TRANSIENT);
        $this->log_debug('Cleared cached menu slugs and plugin list transient.');
    }

    /**
     * Invalidates the slug cache upon plugin or theme updates/installs.
     */
    public function dynamic_cache_invalidation($upgrader, $options): void {
        if (!is_array($options)) {
            $this->log_debug('dynamic_cache_invalidation called with non-array $options, skipping.');
            return;
        }
        if (!empty($options['type']) && in_array($options['type'], ['plugin', 'theme'], true)) {
            $this->clear_cached_menu_slugs();
        }
    }

    /**
     * Registers the “manual overrides” settings only on relevant screens (e.g., single-site or network “options-general”).
     */
    public function maybe_register_settings(): void {
        $valid_screens = ['options-general', 'options-general-network', 'settings_page_asl_settings'];
        if (!$this->should_run_on_screen($valid_screens)) {
            return;
        }
        $this->register_settings();
    }

    /**
     * Actually register the “asl_manual_overrides” setting, plus its section and field.
     */
    private function register_settings(): void {
        register_setting('asl_settings_group', 'asl_manual_overrides', [$this, 'sanitize_manual_overrides']);

        add_settings_section(
            'asl_settings_section',
            __('Manual Settings Overrides', 'add-settings-links'),
            [$this, 'settings_section_callback'],
            'asl_settings'
        );

        add_settings_field(
            'asl_manual_overrides_field',
            __('Manual Overrides', 'add-settings-links'),
            [$this, 'manual_overrides_field_callback'],
            'asl_settings',
            'asl_settings_section'
        );
    }

    /**
     * Conditionally add the “Add Settings Links” page under “Settings,” visible in single-site or network admin.
     */
    public function maybe_add_settings_page(): void {
        // If you only want single-site usage, you could remove is_network_admin().
        if (is_admin() || is_network_admin()) {
            add_options_page(
                __('Add Settings Links', 'add-settings-links'),
                __('Add Settings Links', 'add-settings-links'),
                'manage_options',
                'asl_settings',
                [$this, 'render_settings_page']
            );
        }
    }

    /**
     * Renders a short description for the manual overrides settings section.
     */
    public function settings_section_callback(): void {
        echo '<p>' . esc_html__(
            'Specify custom settings page URLs for plugins with multiple or unconventional settings pages.',
            'add-settings-links'
        ) . '</p>';
    }

    /**
     * Renders a table of installed plugins, letting users manually specify extra “Settings” URLs.
     */
    public function manual_overrides_field_callback(): void {
        $manual_overrides = get_option('asl_manual_overrides', []);
        $plugins          = $this->get_all_plugins();
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
                    $existing = isset($manual_overrides[$plugin_file])
                        ? (array)$manual_overrides[$plugin_file]
                        : [];
                    $existing_str = implode(',', $existing);
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
     * Sanitize user input from the manual overrides fields, keeping only valid URLs.
     */
    public function sanitize_manual_overrides($input): array {
        if (!is_array($input)) {
            return [];
        }
        $sanitized = [];
        foreach ($input as $plugin_file => $raw_value) {
            $url_candidates = array_map('trim', explode(',', (string)$raw_value));
            $valid_urls = [];
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
     * Renders the “Add Settings Links” settings page with the manual override form.
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
     * Retrieve and cache the installed plugins list (single site or multisite).
     */
    private function get_all_plugins(): array {
        $cached_plugins = get_transient(ASL_CACHED_PLUGINS_TRANSIENT);
        if (false === $cached_plugins) {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $cached_plugins = get_plugins();
            set_transient(ASL_CACHED_PLUGINS_TRANSIENT, $cached_plugins, ASL_CACHED_PLUGINS_TRANSIENT_EXPIRATION);
            $this->log_debug('Installed plugins list was just cached.');
        } else {
            $this->log_debug('Installed plugins list retrieved from cache.');
        }
        return (array) $cached_plugins;
    }

    /**
     * Possibly display a single aggregated admin notice if any plugins were missing a recognized “Settings” page.
     * Runs on both single-site + network admin pages if desired.
     */
    public function maybe_display_admin_notices(): void {
        // Show notice only if on certain screens
        $valid_screens = [
            'plugins', 'plugins-network',
            'settings_page_asl_settings',
            'options-general', 'options-general-network',
        ];
        if (!$this->should_run_on_screen($valid_screens)) {
            return;
        }
        $this->display_admin_notices();
    }

    /**
     * Output a single aggregated notice about plugins lacking recognized settings pages, then reset.
     */
    private function display_admin_notices(): void {
        if (!empty($this->missing_settings)) {
            $class   = 'notice notice-warning is-dismissible';
            $plugins = implode(', ', array_map('esc_html', $this->missing_settings));
            $msg     = sprintf(
                __('Add Settings Links: No recognized settings URL found for the following plugins: %s.', 'add-settings-links'),
                $plugins
            );
            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), wp_kses_post($msg));
            $this->missing_settings = [];
        }
    }

    /**
     * Clears the cached menu slugs and plugin data.
     */
    public function clear_cached_menu_slugs(): void {
        delete_transient(ASL_MENU_SLUGS_TRANSIENT);
        delete_transient(ASL_CACHED_PLUGINS_TRANSIENT);
        $this->log_debug('Cleared cached menu slugs and plugin list transient.');
    }

    /**
     * Logs debug info if WP_DEBUG + WP_DEBUG_LOG are enabled.
     */
    private function log_debug(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // If you want line numbers, you could do something like:
            // $trace = debug_backtrace(); $line = $trace[0]['line'] ?? '?';
            if (is_multisite()) {
                error_log('[Add Settings Links][Site ID ' . get_current_blog_id() . '] ' . $message);
            } else {
                error_log('[Add Settings Links] ' . $message);
            }
        }
    }
}

    /**
 * Enhanced Settings Detection Trait
 * 
 * Provides improved methods for detecting WordPress plugin settings pages
 * through multiple detection strategies.
 */
trait EnhancedSettingsDetection {
    /**
     * Common settings-related terms across multiple languages
     */
    private static $settings_terms = [
        'en' => ['settings', 'options', 'preferences', 'configuration'],
        'de' => ['einstellungen', 'optionen', 'konfiguration'],
        'es' => ['ajustes', 'opciones', 'configuración'],
        'fr' => ['paramètres', 'options', 'configuration'],
        // Add more languages as needed
    ];

    /**
     * Find potential settings URLs using multiple detection strategies
     *
     * @param string $plugin_dir Plugin directory name
     * @param string $plugin_basename Plugin basename
     * @return array|false Array of discovered URLs or false if none found
     */
    private function find_settings_url(string $plugin_dir, string $plugin_basename) {
        $found_urls = [];
        
        // 1. Check cached admin menu slugs (existing approach)
        $menu_urls = $this->find_settings_in_admin_menu($plugin_dir, $plugin_basename);
        if ($menu_urls) {
            $found_urls = array_merge($found_urls, $menu_urls);
        }

        // 2. Static file analysis
        $file_urls = $this->analyze_plugin_files($plugin_basename);
        if ($file_urls) {
            $found_urls = array_merge($found_urls, $file_urls);
        }

        // 3. Option table analysis
        $option_urls = $this->analyze_options_table($plugin_dir, $plugin_basename);
        if ($option_urls) {
            $found_urls = array_merge($found_urls, $option_urls);
        }

        // 4. Hook analysis
        $hook_urls = $this->analyze_registered_hooks($plugin_dir);
        if ($hook_urls) {
            $found_urls = array_merge($found_urls, $hook_urls);
        }

        return !empty($found_urls) ? array_unique($found_urls) : false;
    }

    /**
     * Analyze plugin files for potential settings pages
     *
     * @param string $plugin_basename Plugin basename
     * @return array|false Array of discovered URLs or false
     */
    private function analyze_plugin_files(string $plugin_basename): array|false {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_basename);
        if (!is_dir($plugin_dir)) {
            return false;
        }

        $found_urls = [];
        $files = $this->recursively_scan_directory($plugin_dir, ['php']);

        foreach ($files as $file) {
            // Skip vendor directories
            if (strpos($file, '/vendor/') !== false) {
                continue;
            }

            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            // Look for common settings page registration patterns
            $patterns = [
                'add_menu_page',
                'add_options_page',
                'add_submenu_page',
                'register_setting',
                'add_settings_section',
                'settings_fields',
                'options-general.php'
            ];

            foreach ($patterns as $pattern) {
                if (strpos($content, $pattern) !== false) {
                    // Extract potential URLs using regex
                    if (preg_match_all('/[\'"]([^\'"]*(settings|options|config)[^\'"]*)[\'"]/', $content, $matches)) {
                        foreach ($matches[1] as $match) {
                            if ($this->is_valid_admin_url($match)) {
                                $found_urls[] = admin_url($match);
                            }
                        }
                    }
                }
            }
        }

        return !empty($found_urls) ? array_unique($found_urls) : false;
    }

    /**
     * Analyze options table for plugin-specific settings
     *
     * @param string $plugin_dir Plugin directory
     * @param string $plugin_basename Plugin basename
     * @return array|false Array of discovered URLs or false
     */
    private function analyze_options_table(string $plugin_dir, string $plugin_basename): array|false {
        global $wpdb;
        
        $found_urls = [];
        $plugin_prefix = str_replace('-', '_', sanitize_title($plugin_dir)) . '_';
        
        // Search for plugin-specific options
        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                OR option_name LIKE %s",
                $wpdb->esc_like($plugin_prefix) . '%',
                '%' . $wpdb->esc_like('_' . $plugin_dir) . '%'
            )
        );

        if (!empty($options)) {
            // If plugin has registered options, check for settings pages
            $settings_patterns = array_merge(...array_values(self::$settings_terms));
            
            foreach ($settings_patterns as $pattern) {
                $potential_url = 'admin.php?page=' . $plugin_dir . '-' . $pattern;
                if ($this->is_valid_admin_url($potential_url)) {
                    $found_urls[] = admin_url($potential_url);
                }
            }
        }

        return !empty($found_urls) ? array_unique($found_urls) : false;
    }

    /**
     * Analyze registered hooks for settings-related callbacks
     *
     * @param string $plugin_dir Plugin directory
     * @return array|false Array of discovered URLs or false
     */
    private function analyze_registered_hooks(string $plugin_dir): array|false {
        global $wp_filter;
        
        $found_urls = [];
        $settings_hooks = [
            'admin_menu',
            'admin_init',
            'network_admin_menu',
            'options_page'
        ];

        foreach ($settings_hooks as $hook) {
            if (isset($wp_filter[$hook])) {
                foreach ($wp_filter[$hook] as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        if (is_array($callback['function'])) {
                            $class = is_object($callback['function'][0]) 
                                ? get_class($callback['function'][0])
                                : $callback['function'][0];
                                
                            if (stripos($class, $plugin_dir) !== false) {
                                // Plugin has registered admin hooks, look for settings URLs
                                $reflection = new ReflectionMethod($class, $callback['function'][1]);
                                $content = file_get_contents($reflection->getFileName());
                                
                                if (preg_match_all('/[\'"]([^\'"]*(settings|options|config)[^\'"]*)[\'"]/', $content, $matches)) {
                                    foreach ($matches[1] as $match) {
                                        if ($this->is_valid_admin_url($match)) {
                                            $found_urls[] = admin_url($match);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return !empty($found_urls) ? array_unique($found_urls) : false;
    }

    /**
     * Recursively scan directory for files with specific extensions
     *
     * @param string $dir Directory path
     * @param array $extensions Array of file extensions to look for
     * @return array Array of file paths
     */
    private function recursively_scan_directory(string $dir, array $extensions): array {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
public function enqueue_admin_assets($hook) {
    if (strpos($hook, 'asl_settings') === false) {
        return;
    }

    wp_enqueue_style(
        'asl-admin-css',
        plugin_dir_url(__FILE__) . 'css/asl-admin.css',
        [],
        '1.0.0'
    );

    wp_enqueue_script(
        'asl-admin-js',
        plugin_dir_url(__FILE__) . 'js/asl-admin.js',
        ['jquery'],
        '1.0.0',
        true
    );

    // Localize script for translation strings
    wp_localize_script('asl-admin-js', 'ASL_Settings', [
        'invalid_url_message' => __('One or more URLs are invalid. Please ensure correct formatting.', 'add-settings-links'),
    ]);
    }

    /**
     * Validate if a given path could be a valid admin URL
     *
     * @param string $path Admin URL path
     * @return bool Whether path could be valid
     */
    private function is_valid_admin_url(string $path): bool {
        // Basic validation - could be expanded
        return (
            strpos($path, '.php') !== false ||
            strpos($path, 'page=') !== false
        ) && !preg_match('/[<>"\'&]/', $path);
    }
}

// Instantiate once on load
new AddSettingsLinks();

endif; // class_exists check
