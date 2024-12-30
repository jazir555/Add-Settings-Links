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

// PHP Version Check
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        esc_html_e('Add Settings Links requires PHP version 7.4 or higher. Please update your PHP version.', 'add-settings-links');
        echo '</p></div>';
    });
    return;
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

        $transient_key = $this->get_transient_key('asl_analyze_plugin_files_' . md5($plugin_basename));
        $cached_urls = get_transient($transient_key);
        if ($cached_urls !== false) {
            $this->log_debug('Retrieved plugin file analysis from cache.');
            return $cached_urls;
        }

        $found_urls = [];
        $files = $this->recursively_scan_directory($plugin_dir, ['php']);

        foreach ($files as $file) {
            // Skip vendor directories
            if (stripos($file, '/vendor/') !== false) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                $this->log_debug("Failed to read file: $file");
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
                if (stripos($content, $pattern) !== false) { // Case-insensitive search
                    // Extract potential URLs using regex
                    if (preg_match_all('/[\'"]([^\'"]*(settings|options|config)[^\'"]*)[\'"]/', $content, $matches)) {
                        foreach ($matches[1] as $match) {
                            if ($this->is_valid_admin_url($match)) {
                                $found_urls[] = admin_url($match);
                            }
                        }
                    }
                    break; // Stop checking other patterns once a match is found
                }
            }
        }

        $found_urls = array_unique($found_urls);
        set_transient($transient_key, $found_urls, ASL_MENU_SLUGS_TRANSIENT_EXPIRATION);
        $this->log_debug('Plugin file analysis cached.');

        return $found_urls;
    }
    function asl_render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Add Settings Links', 'add-settings-links'); ?></h1>
            <form id="asl-settings-form" method="post" action="options.php">
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

        // Possible prefixes based on plugin directory and basename
        $possible_prefixes = [
            str_replace('-', '_', sanitize_title($plugin_dir)) . '_',
            str_replace('-', '_', sanitize_title($plugin_basename)) . '_',
            sanitize_title($plugin_dir) . '_',
            sanitize_title($plugin_basename) . '_',
        ];

        // Build the LIKE patterns
        $like_patterns = [];
        foreach ($possible_prefixes as $prefix) {
            $like_patterns[] = $wpdb->esc_like($prefix) . '%';
        }

        if (empty($like_patterns)) {
            return [];
        }

        // Construct the SQL query with dynamic placeholders
        $placeholders = implode(' OR option_name LIKE ', array_fill(0, count($like_patterns), '%s'));
        $query = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE " . $placeholders;

        // Execute the query
        $options = $wpdb->get_results(
            $wpdb->prepare(
                $query,
                ...$like_patterns
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

                // Additionally, try using plugin basename
                $potential_url_basename = 'admin.php?page=' . $plugin_basename . '-' . $pattern;
                if ($this->is_valid_admin_url($potential_url_basename)) {
                    $found_urls[] = admin_url($potential_url_basename);
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
        $cache_key = 'asl_reflection_' . md5($classOrObject . $method . ($isStatic ? '_static' : ''));
        $cached_urls = get_transient($cache_key);
        if ($cached_urls !== false) {
            $this->log_debug('Retrieved reflection URLs from cache.');
            return $cached_urls;
        }

        try {
            $reflection = $isStatic
                ? new \ReflectionMethod($classOrObject, $method)
                : new \ReflectionMethod(get_class($classOrObject), $method);

            $content = file_get_contents($reflection->getFileName());
            if ($content && preg_match_all('/[\'"]([^\'"]*(settings|options|config)[^\'"]*)[\'"]/', $content, $matches)) {
                foreach ($matches[1] as $match) {
                    if ($this->is_valid_admin_url($match)) {
                        $found[] = admin_url($match);
                    }
                }
            }
        } catch (\ReflectionException $e) {
            // Log the exception for debugging
            $this->log_debug('ReflectionException: ' . $e->getMessage());
        }

        $found = array_unique($found);
        set_transient($cache_key, $found, ASL_MENU_SLUGS_TRANSIENT_EXPIRATION);
        $this->log_debug('Reflection URLs cached.');

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
        // Allow only specific admin pages, extendable via filter
        $allowed_pages = apply_filters('asl_allowed_admin_pages', [
            'admin.php',
            'options-general.php',
            // Add more known admin pages as needed
        ]);

        // Parse the URL
        $parsed = parse_url($path);
        if (!$parsed || !isset($parsed['path'])) {
            return false;
        }

        $page = basename($parsed['path']);
        if (!in_array($page, $allowed_pages, true)) {
            return false;
        }

        // Ensure no disallowed characters
        if (preg_match('/[<>"\'&]/', $path)) {
            return false;
        }

        // Optionally, enforce specific query parameters
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
            if (isset($query_params['page'])) {
                // Validate the 'page' parameter format
                if (!preg_match('/^[\w\-]+$/', $query_params['page'])) {
                    return false;
                }
            }
        }

        return true;
    }
}

if (!class_exists(__NAMESPACE__ . '\\ASL_AddSettingsLinks')) {

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

            // 3. Dynamically add plugin action links filters for all plugins.
            add_action('admin_init', [$this, 'add_dynamic_plugin_action_links']);

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
         * Dynamically add plugin action links filters for all installed plugins.
         */
        public function add_dynamic_plugin_action_links(): void
        {
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $all_plugins = get_plugins();
            foreach ($all_plugins as $plugin_file => $plugin_data) {
                add_filter('plugin_action_links_' . $plugin_file, function($links, $file) use ($plugin_file, $plugin_data) {
                    return $this->maybe_add_settings_links($links, $plugin_file, $plugin_data);
                }, 10, 2); // Corrected to accept 2 arguments
            }
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
        public function enqueue_admin_assets(string $hook): void
        {
            // Only enqueue on the plugin's settings page
            if ($hook !== 'settings_page_asl_settings') {
                return;
            }
        
            // Define file paths
            $css_path = plugin_dir_path(__FILE__) . 'css/asl-admin.css';
            $js_path = plugin_dir_path(__FILE__) . 'js/asl-admin.js';
            $version = '1.0.0';
        
            // Enqueue CSS if the file exists
            if (file_exists($css_path)) {
                wp_enqueue_style(
                    'asl-admin-css',
                    plugin_dir_url(__FILE__) . 'css/asl-admin.css',
                    [],
                    $version
                );
            } else {
                $this->log_debug('CSS file asl-admin.css not found.');
            }
        
            // Enqueue JS if the file exists
            if (file_exists($js_path)) {
                wp_enqueue_script(
                    'asl-admin-js',
                    plugin_dir_url(__FILE__) . 'js/asl-admin.js',
                    ['jquery'],
                    $version,
                    true
                );
        
                // Localize script for translation strings
                wp_localize_script(
                    'asl-admin-js',
                    'ASL_Settings',
                    [
                        'invalid_url_message' => __('One or more URLs are invalid. Please ensure correct formatting.', 'add-settings-links'),
                    ]
                );
            } else {
                $this->log_debug('JavaScript file asl-admin.js not found.');
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
            $transient_key = $this->get_transient_key(ASL_MENU_SLUGS_TRANSIENT);
            $cached_slugs = get_transient($transient_key);

            // If empty, try caching now
            if (empty($cached_slugs) || !is_array($cached_slugs)) {
                $this->cache_admin_menu_slugs();
                $cached_slugs = get_transient($transient_key);
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
            $transient_key = $this->get_transient_key(ASL_MENU_SLUGS_TRANSIENT);
            if (false !== get_transient($transient_key)) {
                $this->log_debug('Admin menu slugs are already cached. Skipping rebuild.');
                return;
            }

            global $menu, $submenu;
            $all_slugs = [];

            // Gather top-level items
            if (!empty($menu) && is_array($menu)) {
                foreach ($menu as $item) {
                    if (!empty($item[2])) {
                        $slug   = sanitize_text_field($item[2]);
                        $parent = isset($item[0]) ? sanitize_text_field($item[0]) : '';
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
                            $slug  = sanitize_text_field($item[2]);
                            $url   = $this->construct_menu_url($slug, $parent_slug);
                            $all_slugs[] = [
                                'slug'   => $slug,
                                'url'    => $url,
                                'parent' => sanitize_text_field($parent_slug),
                            ];
                            $this->log_debug("Caching submenu slug: $slug => $url (parent: $parent_slug)");
                        }
                    }
                }
            }

            if (!empty($all_slugs)) {
                set_transient($transient_key, $all_slugs, ASL_MENU_SLUGS_TRANSIENT_EXPIRATION);
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
         * @param array  $links        Array of existing plugin action links.
         * @param string $plugin_file  Plugin file path.
         * @param array  $plugin_data  Plugin data array.
         * @return array               Modified array of plugin action links.
         */
        public function maybe_add_settings_links(array $links, string $plugin_file, array $plugin_data): array
        {
            // Prevent adding settings link to the plugin itself
            if ($plugin_file === plugin_basename(__FILE__)) {
                return $links;
            }

            if (!current_user_can('manage_options')) {
                return $links;
            }
            if ($this->plugin_has_settings_link($links)) {
                return $links;
            }

            $settings_added   = false;
            $manual_overrides = get_option('asl_manual_overrides', []);

            // 1. Manual overrides
            if (!empty($manual_overrides[$plugin_file])) {
                foreach ((array)$manual_overrides[$plugin_file] as $settings_url) {
                    $settings_url = trim($settings_url);
                    if (!$settings_url) {
                        continue;
                    }
                    if (strpos($settings_url, 'http') !== 0) {
                        $settings_url = admin_url($settings_url);
                    }
                    if (!$this->link_already_exists($links, $settings_url)) {
                        $links = $this->prepend_settings_link($links, [$settings_url], $plugin_data['Name']);
                        $settings_added = true;
                    }
                }
            }
            if ($settings_added) {
                return $links;
            }

            // 2. Use the trait’s extended detection approach
            $plugin_basename_clean = plugin_basename($plugin_file);
            $plugin_dir_clean      = dirname($plugin_basename_clean);
            $urls = $this->extended_find_settings_url($plugin_dir_clean, $plugin_basename_clean);

            if (!empty($urls)) {
                foreach ($urls as $url) {
                    $url = trim($url);
                    if (!$url) {
                        continue;
                    }
                    $full_url = (strpos($url, 'http') === 0) ? $url : admin_url($url);
                    if (!$this->link_already_exists($links, $full_url)) {
                        $links = $this->prepend_settings_link($links, [$full_url], $plugin_data['Name']);
                        $settings_added = true;
                    }
                }
            }

            // 3. If still no link found
            if (!$settings_added) {
                $this->missing_settings[] = $plugin_basename_clean;
                $this->log_debug("No recognized settings link found for plugin: $plugin_basename_clean");
            }

            return $links;
        }

        /**
         * Prepends a “Settings” link to an array of plugin action links.
         * Handles multiple settings URLs by creating an accessible dropdown menu.
         *
         * @param array  $links          Array of existing plugin action links.
         * @param array  $settings_urls  Array of settings page URLs.
         * @param string $plugin_name    (Optional) Name of the plugin for label clarity.
         * @return array                 Modified array with the new settings link(s).
         */
        private function prepend_settings_link(array $links, array $settings_urls, string $plugin_name = ''): array
        {
            if (empty($settings_urls)) {
                return $links;
            }

            if (count($settings_urls) === 1) {
                // Single settings URL, add as a single link
                $label = !empty($plugin_name) ? sprintf(__('Settings for %s', 'add-settings-links'), $plugin_name) : __('Settings', 'add-settings-links');
                $html = sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($settings_urls[0]),
                    esc_html($label)
                );
                array_unshift($links, $html);
            } else {
                // Multiple settings URLs, add as an accessible dropdown
                $dropdown_id = 'asl-dropdown-' . sanitize_title_with_dashes($plugin_name) . '-' . uniqid();
                $dropdown = '<div class="dropdown" role="menu" aria-haspopup="true" aria-expanded="false">';
                $dropdown .= sprintf(
                    '<button class="dropbtn" id="%s-button" aria-controls="%s-menu">%s</button>',
                    esc_attr($dropdown_id),
                    esc_attr($dropdown_id),
                    esc_html__('Settings', 'add-settings-links')
                );
                $dropdown .= sprintf(
                    '<div class="dropdown-content" id="%s-menu" role="menu" aria-labelledby="%s-button">',
                    esc_attr($dropdown_id),
                    esc_attr($dropdown_id)
                );
                foreach ($settings_urls as $index => $url) {
                    $label = sprintf(__('Settings %d', 'add-settings-links'), $index + 1);
                    if (!empty($plugin_name)) {
                        $label = sprintf(__('Settings for %s %d', 'add-settings-links'), $plugin_name, $index + 1);
                    }
                    $dropdown .= sprintf(
                        '<a href="%s" role="menuitem">%s</a>',
                        esc_url($url),
                        esc_html($label)
                    );
                }
                $dropdown .= '</div></div>';
                array_unshift($links, $dropdown);
            }

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
            $transient_key_slugs = $this->get_transient_key(ASL_MENU_SLUGS_TRANSIENT);
            $transient_key_plugins = $this->get_transient_key(ASL_CACHED_PLUGINS_TRANSIENT);
            delete_transient($transient_key_slugs);
            delete_transient($transient_key_plugins);
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
                        $plugin_file_safe = sanitize_text_field($plugin_file);
                        $existing = isset($manual_overrides[$plugin_file_safe])
                            ? (array)$manual_overrides[$plugin_file_safe]
                            : [];
                        $existing_str = implode(',', $existing);
                        ?>
                        <tr>
                            <td><?php echo esc_html($plugin_data['Name']); ?></td>
                            <td>
                                <input
                                    type="text"
                                    name="asl_manual_overrides[<?php echo esc_attr($plugin_file_safe); ?>]"
                                    value="<?php echo esc_attr($existing_str); ?>"
                                    style="width:100%;"
                                    aria-describedby="asl_manual_overrides_description_<?php echo esc_attr($plugin_file_safe); ?>"
                                />
                                <p
                                    class="description"
                                    id="asl_manual_overrides_description_<?php echo esc_attr($plugin_file_safe); ?>"
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

            <?php
        }

        public function sanitize_manual_overrides($input): array
        {
            if (!is_array($input)) {
                return [];
            }
            $sanitized = [];
            foreach ($input as $plugin_file => $raw_value) {
                $plugin_file_safe = sanitize_text_field($plugin_file);
                $url_candidates = array_map('trim', explode(',', (string)$raw_value));
                $valid_urls = [];
                foreach ($url_candidates as $candidate) {
                    $candidate = esc_url_raw($candidate);
                    if (!empty($candidate)) {
                        // Check if it's an absolute URL
                        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                            $valid_urls[] = $candidate;
                        }
                        // Check if it's a valid relative admin URL
                        elseif (preg_match('/^admin\.php\?page=[\w\-]+$/', $candidate)) {
                            $valid_urls[] = $candidate;
                        }
                    }
                }
                if (!empty($valid_urls)) {
                    $sanitized[$plugin_file_safe] = $valid_urls;
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
            $transient_key = $this->get_transient_key(ASL_CACHED_PLUGINS_TRANSIENT);
            $cached_plugins = get_transient($transient_key);
            if (false === $cached_plugins) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $cached_plugins = get_plugins();
                set_transient($transient_key, $cached_plugins, ASL_CACHED_PLUGINS_TRANSIENT_EXPIRATION);
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
                $current_screen = get_current_screen();
                $is_network = is_network_admin();

                $msg = sprintf(
                    __('Add Settings Links: No recognized settings URL found for the following plugins: %s.', 'add-settings-links'),
                    $plugins
                );

                // Optionally, customize the message based on context
                if ($is_network) {
                    $msg .= ' ' . __('You can manually add settings URLs in the network settings page.', 'add-settings-links');
                } else {
                    $msg .= ' ' . __('You can manually add settings URLs in the Add Settings Links settings page.', 'add-settings-links');
                }

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

        /**
         * Generates a transient key scoped to the current site in multisite environments.
         *
         * @param string $base_key Base transient key.
         * @return string          Scoped transient key.
         */
        private function get_transient_key(string $base_key): string
        {
            if (is_multisite()) {
                return $base_key . '_site_' . get_current_blog_id();
            }
            return $base_key;
        }
    }

    // Instantiate once on load
    new ASL_AddSettingsLinks();

} // End if class_exists
