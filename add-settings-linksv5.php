<?php
/**
 * Plugin Name: Add Settings Links
 * Description: Adds direct links to the settings pages for all plugins that do not have one (including multisite/network admin support).
 * Version: 1.7.3
 * Author: Jazir5
 * Text Domain: add-settings-links
 * Domain Path: /languages
 */

namespace ASL;

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

/**
 * Enhanced Settings Detection Trait
 *
 * Provides improved methods for detecting WordPress plugin settings pages
 * through multiple detection strategies.
 */
trait ASL_EnhancedSettingsDetection
{
    /**
     * Common settings-related terms across multiple languages.
     */
    private static $settings_terms = [
        'en' => ['settings', 'options', 'preferences', 'configuration'],
        'de' => ['einstellungen', 'optionen', 'konfiguration'],
        'es' => ['ajustes', 'opciones', 'configuración'],
        'fr' => ['paramètres', 'options', 'configuration'],
        // Add more languages as needed
    ];

    /**
     * An "extended" approach to find potential settings URLs, combining multiple strategies.
     *
     * 1) If available, uses find_settings_in_admin_menu() from the main class to glean URLs from the cached WP admin menu.
     * 2) Static file analysis of plugin files (regex searching for known patterns).
     * 3) Option table analysis for plugin-specific settings.
     * 4) Hook analysis for admin-related callbacks.
     *
     * @param string $plugin_dir       Plugin directory name.
     * @param string $plugin_basename  Plugin basename.
     * @return string[]|false          Array of discovered URLs or false if none found.
     */
    private function extended_find_settings_url(string $plugin_dir, string $plugin_basename)
    {
        $found_urls = [];

        // 1. Use the main class’s method if it exists (e.g., scanning the cached WP admin menu).
        if (method_exists($this, 'find_settings_in_admin_menu')) {
            $menu_urls = $this->find_settings_in_admin_menu($plugin_dir, $plugin_basename);
            if ($menu_urls) {
                $found_urls = array_merge($found_urls, $menu_urls);
            }
        }

        // 2. Static file analysis (advanced).
        $file_urls = $this->analyze_plugin_files($plugin_basename);
        if ($file_urls) {
            $found_urls = array_merge($found_urls, $file_urls);
        }

        // 3. Option table analysis.
        $option_urls = $this->analyze_options_table($plugin_dir, $plugin_basename);
        if ($option_urls) {
            $found_urls = array_merge($found_urls, $option_urls);
        }

        // 4. Hook analysis.
        $hook_urls = $this->analyze_registered_hooks($plugin_dir);
        if ($hook_urls) {
            $found_urls = array_merge($found_urls, $hook_urls);
        }

        return !empty($found_urls) ? array_unique($found_urls) : false;
    }

    /**
     * Analyze plugin files for potential settings pages.
     *
     * @param string $plugin_basename Plugin basename.
     * @return array                  Array of discovered admin URLs.
     */
    private function analyze_plugin_files(string $plugin_basename): array
    {
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_basename);
        if (!is_dir($plugin_dir)) {
            return [];
        }

        $found_urls = [];
        $files = $this->recursively_scan_directory($plugin_dir, ['php']);

        foreach ($files as $file) {
            // Skip vendor directories
            if (strpos($file, '/vendor/') !== false) {
                continue;
            }
            $content = @file_get_contents($file);
            if (!$content) {
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

        return array_unique($found_urls);
    }

    /**
     * Analyze the WP options table for plugin-specific setting references.
     *
     * @param string $plugin_dir       Plugin directory name.
     * @param string $plugin_basename  Plugin basename.
     * @return array                   Array of discovered admin URLs.
     */
    private function analyze_options_table(string $plugin_dir, string $plugin_basename): array
    {
        global $wpdb;
        $found_urls = [];

        $plugin_prefix = str_replace('-', '_', sanitize_title($plugin_dir)) . '_';
        // Search for plugin-specific options
        $options = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like($plugin_prefix) . '%',
                '%' . $wpdb->esc_like('_' . $plugin_dir) . '%'
            )
        );

        if ($options) {
            // If plugin has registered options, guess potential settings pages from known patterns
            $all_terms = [];
            foreach (self::$settings_terms as $langArr) {
                $all_terms = array_merge($all_terms, $langArr);
            }
            $all_terms = array_unique($all_terms);

            // For each known settings word, guess a "page=" slug
            foreach ($all_terms as $pattern) {
                $potential_url = 'admin.php?page=' . $plugin_dir . '-' . $pattern;
                if ($this->is_valid_admin_url($potential_url)) {
                    $found_urls[] = admin_url($potential_url);
                }
            }
        }

        return array_unique($found_urls);
    }

    /**
     * Analyze registered hooks for settings-related callbacks.
     *
     * @param string $plugin_dir Plugin directory name.
     * @return array             Array of discovered admin URLs.
     */
    private function analyze_registered_hooks(string $plugin_dir): array
    {
        global $wp_filter;
        $found_urls = [];
        $settings_hooks = [
            'admin_menu',
            'admin_init',
            'network_admin_menu',
            'options_page',
        ];

        foreach ($settings_hooks as $hook) {
            if (!isset($wp_filter[$hook])) {
                continue;
            }

            // WP 5.7+ organizes callbacks differently
            foreach ($wp_filter[$hook] as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    if (is_array($callback['function'])) {
                        $classOrObject = $callback['function'][0];
                        $method        = $callback['function'][1];

                        // Check if it belongs to this plugin
                        if (is_object($classOrObject)) {
                            $className = get_class($classOrObject);
                            if (stripos($className, $plugin_dir) !== false) {
                                $found_urls = array_merge(
                                    $found_urls,
                                    $this->extract_urls_via_reflection($classOrObject, $method)
                                );
                            }
                        } elseif (is_string($classOrObject) && stripos($classOrObject, $plugin_dir) !== false) {
                            // It's a static call
                            $found_urls = array_merge(
                                $found_urls,
                                $this->extract_urls_via_reflection($classOrObject, $method, true)
                            );
                        }
                    }
                }
            }
        }

        return array_unique($found_urls);
    }

    /**
     * Use reflection to read file content for a given class method, searching for possible settings URLs.
     *
     * @param mixed  $classOrObject Class name or object.
     * @param string $method        Method name.
     * @param bool   $isStatic      Whether the method is static.
     * @return array                Array of discovered admin URLs.
     */
    private function extract_urls_via_reflection($classOrObject, string $method, bool $isStatic = false): array
    {
        $found = [];
        try {
            $reflection = $isStatic
                ? new \ReflectionMethod($classOrObject, $method)
                : new \ReflectionMethod(get_class($classOrObject), $method);

            $content = @file_get_contents($reflection->getFileName());
            if ($content && preg_match_all('/[\'"]([^\'"]*(settings|options|config)[^\'"]*)[\'"]/', $content, $matches)) {
                foreach ($matches[1] as $match) {
                    if ($this->is_valid_admin_url($match)) {
                        $found[] = admin_url($match);
                    }
                }
            }
        } catch (\ReflectionException $e) {
            // Optionally log or handle reflection errors
            $this->log_debug('ReflectionException: ' . $e->getMessage());
        }
        return $found;
    }

    /**
     * Recursively scan directory for files with specific extensions.
     *
     * @param string $dir        Directory path.
     * @param array  $extensions Array of file extensions to include.
     * @return array             Array of file paths.
     */
    private function recursively_scan_directory(string $dir, array $extensions): array
    {
        $files = [];
        if (!class_exists('RecursiveIteratorIterator') || !class_exists('RecursiveDirectoryIterator')) {
            return $files; // Not available in this PHP environment
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\UnexpectedValueException $e) {
            // Directory read error
            $this->log_debug('UnexpectedValueException: ' . $e->getMessage());
        }
        return $files;
    }

    /**
     * Validate if a given path could be a valid admin URL.
     *
     * @param string $path URL path.
     * @return bool        True if valid, false otherwise.
     */
    private function is_valid_admin_url(string $path): bool
    {
        // Basic validation
        return (
            strpos($path, '.php') !== false ||
            strpos($path, 'page=') !== false
        ) && !preg_match('/[<>"\'&]/', $path);
    }
}

if (!class_exists('ASL_AddSettingsLinks')):

/**
 * Class AddSettingsLinks
 *
 * Discovers potential “Settings” pages for installed plugins (for single-site or multisite “network” admin),
 * allows manual overrides, and displays aggregated notices for any plugin missing a recognized settings link.
 */
class ASL_AddSettingsLinks
{
    use ASL_EnhancedSettingsDetection; // Incorporate the trait here

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
    public function __construct()
    {
        // 1. Load plugin text domain for translations.
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // 2. Conditionally cache admin menu slugs (single-site or network).
        add_action('admin_menu', [$this, 'maybe_cache_admin_menu_slugs'], 9999);

        // 3. Add or skip plugin settings links on the plugins screens (single-site or network).
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'maybe_add_settings_links'], 10, 2);

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

        // 9. Enqueue Admin Scripts and Styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    /**
     * Loads the plugin's text domain for i18n.
     */
    public function load_textdomain(): void
    {
        load_plugin_textdomain(
            'add-settings-links',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Enqueue admin-specific scripts and styles.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_admin_assets($hook): void
    {
        if (strpos($hook, 'asl_settings') === false) {
            return;
        }

        // Enqueue CSS
        wp_enqueue_style(
            'asl-admin-css',
            plugin_dir_url(__FILE__) . 'css/asl-admin.css',
            [],
            '1.0.0'
        );

        // Enqueue JS
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
     * Provide a method for the trait to discover potential settings by scanning the cached admin menu.
     * This method is called only if `method_exists($this, 'find_settings_in_admin_menu')` is true.
     *
     * @param string $plugin_dir      e.g. "my-plugin"
     * @param string $plugin_basename e.g. "my-plugin/my-plugin.php"
     * @return string[] Potential admin URLs discovered from the WP menu, or empty array if none
     */
    private function find_settings_in_admin_menu(string $plugin_dir, string $plugin_basename): array
    {
        $found_urls = [];
        $cached_slugs = get_transient(ASL_MENU_SLUGS_TRANSIENT);

        // If empty, try caching now
        if (empty($cached_slugs) || !is_array($cached_slugs)) {
            $this->cache_admin_menu_slugs();
            $cached_slugs = get_transient(ASL_MENU_SLUGS_TRANSIENT);
        }
        if (empty($cached_slugs) || !is_array($cached_slugs)) {
            $this->log_debug('Cannot find potential settings slugs. Cache is empty or invalid (in find_settings_in_admin_menu).');
            return $found_urls;
        }

        // Generate potential slugs from the plugin’s folder + file naming
        $potential_slugs = $this->generate_potential_slugs($plugin_dir, $plugin_basename);

        // Compare against cached admin menu slugs
        foreach ($cached_slugs as $item) {
            if (isset($item['slug'], $item['url']) && in_array($item['slug'], $potential_slugs, true)) {
                $this->log_debug("Found potential admin menu URL for plugin '$plugin_basename': " . $item['url']);
                $found_urls[] = $item['url'];
            }
        }

        return array_unique($found_urls);
    }

    /**
     * Generate potential slugs based on plugin directory and basename.
     *
     * @param string $plugin_dir       Plugin directory name.
     * @param string $plugin_basename  Plugin basename.
     * @return string[]                Array of potential slugs.
     */
    private function generate_potential_slugs(string $plugin_dir, string $plugin_basename): array
    {
        $potential_slugs = [];

        // Use settings-related terms to generate potential slugs
        foreach (self::$settings_terms as $lang => $terms) {
            foreach ($terms as $term) {
                $potential_slugs[] = $plugin_dir . '-' . $term;
                $potential_slugs[] = $plugin_basename . '-' . $term;
            }
        }

        return array_unique($potential_slugs);
    }

    /**
     * Conditionally add the plugin's own settings page under "Settings".
     */
    public function maybe_add_settings_page(): void
    {
        add_options_page(
            __('Add Settings Links', 'add-settings-links'),
            __('Add Settings Links', 'add-settings-links'),
            'manage_options',
            'asl_settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Checks whether the plugin should run certain logic on the current screen.
     *
     * @param array $valid_screens Array of valid screen IDs.
     * @return bool                True if should run, false otherwise.
     */
    private function should_run_on_screen(array $valid_screens): bool
    {
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
    public function maybe_cache_admin_menu_slugs(): void
    {
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
     * Actually caches admin menu slugs (top-level + submenu) in a transient.
     */
    private function cache_admin_menu_slugs(): void
    {
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
     *
     * @param string $slug        Menu slug.
     * @param string $parent_slug Parent menu slug (if any).
     * @return string             Full admin URL.
     */
    private function construct_menu_url(string $slug, string $parent_slug = ''): string
    {
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
     * Possibly add or skip plugin settings links on single-site or network plugins pages.
     *
     * @param array  $links Array of existing plugin action links.
     * @param string $file  Plugin file path.
     * @return array         Modified array of plugin action links.
     */
    public function maybe_add_settings_links(array $links, string $file): array
    {
        $valid_screens = ['plugins', 'plugins-network'];
        if (!$this->should_run_on_screen($valid_screens)) {
            return $links;
        }
        return $this->add_missing_settings_links($links, $file);
    }

    /**
     * Add a “Settings” link if the plugin is missing one, either from manual overrides or from detection.
     *
     * @param array  $links Array of existing plugin action links.
     * @param string $file  Plugin file path.
     * @return array         Modified array of plugin action links.
     */
    private function add_missing_settings_links(array $links, string $file): array
    {
        if (!current_user_can('manage_options')) {
            return $links;
        }
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

        // 2. Use the trait’s extended detection approach
        $plugin_basename = plugin_basename($file);
        $plugin_dir      = dirname($plugin_basename);
        $urls = $this->extended_find_settings_url($plugin_dir, $plugin_basename);

        if (!empty($urls)) {
            foreach ($urls as $url) {
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

        // 3. If still no link found
        if (!$settings_added) {
            $this->missing_settings[] = $plugin_basename;
            $this->log_debug("No recognized settings link found for plugin: $plugin_basename");
        }

        return $links;
    }

    /**
     * Prepends a “Settings” link to an array of plugin action links.
     *
     * @param array  $links        Array of existing plugin action links.
     * @param string $settings_url Settings page URL.
     * @return array               Modified array with the new settings link.
     */
    private function prepend_settings_link(array $links, string $settings_url): array
    {
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
     *
     * @param array $links Array of existing plugin action links.
     * @return bool        True if a settings link exists, false otherwise.
     */
    private function plugin_has_settings_link(array $links): bool
    {
        if (empty($links)) {
            return false;
        }
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
     * Determine if a URL is already present in the plugin’s action links (to avoid duplicates).
     *
     * @param array  $links   Array of existing plugin action links.
     * @param string $new_url New settings page URL to check.
     * @return bool           True if the URL already exists, false otherwise.
     */
    private function link_already_exists(array $links, string $new_url): bool
    {
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
     *
     * @param array $existing Parsed URL array of the existing link.
     * @param array $new      Parsed URL array of the new link.
     * @return bool           True if URLs are equivalent, false otherwise.
     */
    private function urls_are_equivalent(array $existing, array $new): bool
    {
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
                return true;
            }
        }
        return false;
    }

    /**
     * Clears the cached menu slugs + plugin data.
     */
    public function clear_cached_menu_slugs(): void
    {
        delete_transient(ASL_MENU_SLUGS_TRANSIENT);
        delete_transient(ASL_CACHED_PLUGINS_TRANSIENT);
        $this->log_debug('Cleared cached menu slugs and plugin list transient.');
    }

    /**
     * Invalidates the slug cache upon plugin or theme updates/installs.
     *
     * @param object $upgrader Object instance of the upgrader.
     * @param array  $options  Array of upgrade options.
     */
    public function dynamic_cache_invalidation($upgrader, $options): void
    {
        if (!is_array($options)) {
            $this->log_debug('dynamic_cache_invalidation called with non-array $options, skipping.');
            return;
        }
        if (!empty($options['type']) && in_array($options['type'], ['plugin', 'theme'], true)) {
            $this->clear_cached_menu_slugs();
        }
    }

    /**
     * Registers the “manual overrides” settings only on relevant screens (single-site or network “options-general”).
     */
    public function maybe_register_settings(): void
    {
        $valid_screens = ['options-general', 'options-general-network', 'settings_page_asl_settings'];
        if (!$this->should_run_on_screen($valid_screens)) {
            return;
        }
        $this->register_settings();
    }

    /**
     * Actually register the “asl_manual_overrides” setting, plus its section and field.
     */
    private function register_settings(): void
    {
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
     * Renders a short description for the manual overrides settings section.
     */
    public function settings_section_callback(): void
    {
        echo '<p>' . esc_html__(
            'Specify custom settings page URLs for plugins with multiple or unconventional settings pages.',
            'add-settings-links'
        ) . '</p>';
    }

    /**
     * Renders a table of installed plugins, letting users manually specify extra “Settings” URLs.
     */
    public function manual_overrides_field_callback(): void
    {
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

        <?php
    }

    /**
     * Sanitize user input from the manual overrides fields, keeping only valid URLs.
     *
     * @param mixed $input Raw user input.
     * @return array       Sanitized array of URLs.
     */
    public function sanitize_manual_overrides($input): array
    {
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
    public function render_settings_page(): void
    {
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
     *
     * @return array Array of installed plugins.
     */
    private function get_all_plugins(): array
    {
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
     */
    public function maybe_display_admin_notices(): void
    {
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
    private function display_admin_notices(): void
    {
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
     * Logs debug info if WP_DEBUG + WP_DEBUG_LOG are enabled.
     *
     * @param string $message Debug message.
     */
    protected function log_debug(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            if (is_multisite()) {
                error_log('[Add Settings Links][Site ID ' . get_current_blog_id() . '] ' . $message);
            } else {
                error_log('[Add Settings Links] ' . $message);
            }
        }
    }
}

// Instantiate once on load
new ASL_AddSettingsLinks();

endif; // class_exists check
