<?php
/*
Plugin Name: Add Settings Links
Description: Adds direct links to the settings pages for all plugins that do not have one.
Version: 1.5.0
Author: Jaz
Text Domain: add-settings-links
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define constants for transient caching
define('ASL_MENU_SLUGS_TRANSIENT', 'asl_cached_admin_menu_slugs');
define('ASL_MENU_SLUGS_TRANSIENT_EXPIRATION', 12 * HOUR_IN_SECONDS);

// Define constants for cached plugins
define('ASL_CACHED_PLUGINS_TRANSIENT', 'asl_cached_plugins');
define('ASL_CACHED_PLUGINS_EXPIRATION', DAY_IN_SECONDS);

// Initialize global variable for missing settings
global $asl_missing_settings;
$asl_missing_settings = array();

// Hook into plugins_loaded to load the text domain
add_action('plugins_loaded', 'asl_load_textdomain');

/**
 * Load the plugin text domain for translation.
 */
function asl_load_textdomain() {
    load_plugin_textdomain('add-settings-links', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Hook into admin_menu to cache menu slugs
add_action('admin_menu', 'asl_cache_admin_menu_slugs', 9999);

// Hook into plugin_action_links to add settings links
add_filter('plugin_action_links', 'asl_add_missing_settings_links', 10, 2);

// Hook into plugin activation/deactivation for cache invalidation
add_action('activated_plugin', 'asl_clear_cached_menu_slugs');
add_action('deactivated_plugin', 'asl_clear_cached_menu_slugs');

// Hook into upgrader_process_complete for dynamic cache invalidation
add_action('upgrader_process_complete', 'asl_dynamic_cache_invalidation', 10, 2);

// Hook into admin_init to register settings for manual overrides
add_action('admin_init', 'asl_register_settings');

// Hook into admin_menu to add settings page
add_action('admin_menu', 'asl_add_settings_page');

/**
 * Cache all admin menu slugs and their corresponding URLs.
 */
function asl_cache_admin_menu_slugs() {
    // Check if the slugs are already cached
    if (false !== get_transient(ASL_MENU_SLUGS_TRANSIENT)) {
        asl_log_debug("Menu slugs are already cached.");
        return;
    }

    global $menu, $submenu;
    $all_slugs = array();

    // Iterate through top-level menu items
    foreach ($menu as $item) {
        if (!empty($item[2])) {
            $slug = $item[2];
            $parent = isset($item[0]) ? $item[0] : '';
            $url = asl_construct_menu_url($slug);
            $all_slugs[] = array(
                'slug'   => $slug,
                'url'    => $url,
                'parent' => $parent,
            );
            asl_log_debug("Caching top-level slug: $slug with URL: $url");
        }
    }

    // Iterate through submenu items
    foreach ($submenu as $parent_slug => $items) {
        foreach ($items as $item) {
            if (!empty($item[2])) {
                $slug = $item[2];
                $url  = asl_construct_menu_url($slug, $parent_slug);
                $all_slugs[] = array(
                    'slug'   => $slug,
                    'url'    => $url,
                    'parent' => $parent_slug,
                );
                asl_log_debug("Caching submenu slug: $slug with URL: $url under parent: $parent_slug");
            }
        }
    }

    // Cache the slugs using a transient (per site in multisite)
    if (!empty($all_slugs)) {
        set_transient(ASL_MENU_SLUGS_TRANSIENT, $all_slugs, ASL_MENU_SLUGS_TRANSIENT_EXPIRATION);
        asl_log_debug("Menu slugs cached successfully.");
    } else {
        asl_log_debug("No menu slugs found to cache.");
    }
}

/**
 * Construct the full admin URL for a given slug and parent.
 *
 * @param string $slug         The menu slug.
 * @param string $parent_slug  The parent menu slug (optional).
 * @return string              The constructed URL.
 */
function asl_construct_menu_url($slug, $parent_slug = '') {
    if (empty($parent_slug)) {
        // Top-level menu
        if (strpos($slug, '.php') !== false) {
            return admin_url($slug);
        } else {
            return add_query_arg('page', $slug, admin_url('admin.php'));
        }
    } else {
        // Submenu item
        if (strpos($parent_slug, '.php') !== false) {
            return add_query_arg('page', $slug, admin_url($parent_slug));
        } else {
            return add_query_arg('page', $slug, admin_url('admin.php'));
        }
    }
}

/**
 * Add missing settings links to plugin action links.
 *
 * @param array  $links  Array of existing action links.
 * @param string $file   Plugin file path.
 * @return array         Modified array of action links.
 */
function asl_add_missing_settings_links($links, $file) {
    global $asl_missing_settings;

    // Check if a settings link already exists
    foreach ($links as $link) {
        // Use case-insensitive exact match for 'Settings'
        if (preg_match('/<a\s+href=.*?>\s*Settings\s*<\/a>/i', $link)) {
            return $links; // Settings link already exists
        }
    }

    $settings_added = false; // Flag to track if any settings link was added

    // Check for manual overrides
    $manual_overrides = get_option('asl_manual_overrides', array());
    if (isset($manual_overrides[$file]) && !empty($manual_overrides[$file])) {
        foreach ($manual_overrides[$file] as $settings_url) {
            if (!empty($settings_url)) {
                // Ensure the URL is a relative admin URL or a full URL
                if (strpos($settings_url, 'http') !== 0) {
                    $settings_url = admin_url($settings_url);
                }
                $settings_link = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'add-settings-links') . '</a>';
                array_unshift($links, $settings_link);
                $settings_added = true;
            }
        }
    }

    // If settings links were added via manual overrides, return early
    if ($settings_added) {
        return $links;
    }

    // Determine the plugin directory
    $plugin_basename = plugin_basename($file);
    $plugin_dir      = dirname($plugin_basename);

    // Attempt to find the settings URLs
    $settings_urls = asl_find_settings_url($plugin_dir, $plugin_basename);

    if ($settings_urls) {
        // Ensure $settings_urls is an array
        if (!is_array($settings_urls)) {
            $settings_urls = array($settings_urls);
        }

        foreach ($settings_urls as $settings_url) {
            if (!empty($settings_url)) {
                // Construct the full admin URL
                $full_url = (strpos($settings_url, 'http') === 0) ? $settings_url : admin_url($settings_url);
                $settings_link = '<a href="' . esc_url($full_url) . '">' . esc_html__('Settings', 'add-settings-links') . '</a>';
                array_unshift($links, $settings_link);
                $settings_added = true;
            }
        }
    }

    // If no settings link was added, aggregate the missing plugin
    if (!$settings_added) {
        // Add the plugin basename to the global missing settings array
        $asl_missing_settings[] = $plugin_basename;
        // Log the missing settings URL
        asl_log_debug("Settings URL not found for plugin: $plugin_basename");
    }

    return $links;
}

/**
 * Find the settings URLs for a given plugin directory and file.
 *
 * @param string $plugin_dir       The plugin directory name.
 * @param string $plugin_basename  The plugin basename (e.g., my-plugin/my-plugin.php).
 * @return array|false             Array of settings URLs or false if not found.
 */
function asl_find_settings_url($plugin_dir, $plugin_basename) {
    $cached_slugs = get_transient(ASL_MENU_SLUGS_TRANSIENT);

    if (empty($cached_slugs)) {
        asl_cache_admin_menu_slugs();
        $cached_slugs = get_transient(ASL_MENU_SLUGS_TRANSIENT);
    }

    if (empty($cached_slugs)) {
        asl_log_debug("Cached slugs are empty after caching attempt.");
        return false; // Unable to retrieve menu slugs
    }

    // Generate potential slugs based on plugin directory and basename
    $potential_slugs = asl_generate_potential_slugs($plugin_dir, $plugin_basename);

    $found_urls = array();

    foreach ($cached_slugs as $item) {
        if (in_array($item['slug'], $potential_slugs, true)) {
            asl_log_debug("Found settings URL for plugin '$plugin_basename': " . $item['url']);
            $found_urls[] = $item['url'];
        }
    }

    if (!empty($found_urls)) {
        // Return unique URLs to handle multiple settings pages
        return array_unique($found_urls);
    }

    asl_log_debug("No matching settings URL found for plugin '$plugin_basename'.");
    return false;
}

/**
 * Generate a list of potential slugs based on plugin directory and basename.
 *
 * @param string $plugin_dir       The plugin directory name.
 * @param string $plugin_basename  The plugin basename.
 * @return array                   Array of potential slugs.
 */
function asl_generate_potential_slugs($plugin_dir, $plugin_basename) {
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
        // Additional variations
        'settings', // Commonly used slug
        "{$plugin_dir}_settings",
        "{$basename}_settings",
    );

    return array_unique(array_map('sanitize_title', $variations));
}

/**
 * Clear the cached menu slugs when necessary.
 * Hooked to plugin activation, deactivation, and other relevant actions.
 */
function asl_clear_cached_menu_slugs() {
    delete_transient(ASL_MENU_SLUGS_TRANSIENT);
    // Also clear cached plugins to ensure fresh data
    delete_transient(ASL_CACHED_PLUGINS_TRANSIENT);
}

/**
 * Dynamically invalidate cache upon plugin updates or installations.
 *
 * @param WP_Upgrader $upgrader        The upgrader instance.
 * @param array       $options         Array of bulk update arguments.
 */
function asl_dynamic_cache_invalidation($upgrader, $options) {
    if (isset($options['type']) && in_array($options['type'], array('plugin', 'theme'), true)) {
        asl_clear_cached_menu_slugs();
    }
}

// Hook into activation and deactivation
register_activation_hook(__FILE__, 'asl_clear_cached_menu_slugs');
register_deactivation_hook(__FILE__, 'asl_clear_cached_menu_slugs');

/**
 * Register settings for manual overrides.
 */
function asl_register_settings() {
    register_setting('asl_settings_group', 'asl_manual_overrides', 'asl_sanitize_manual_overrides');

    add_settings_section(
        'asl_settings_section',
        __('Manual Settings Overrides', 'add-settings-links'),
        'asl_settings_section_callback',
        'asl_settings'
    );

    add_settings_field(
        'asl_manual_overrides_field',
        __('Manual Overrides', 'add-settings-links'),
        'asl_manual_overrides_field_callback',
        'asl_settings',
        'asl_settings_section'
    );
}

/**
 * Settings section callback.
 */
function asl_settings_section_callback() {
    echo '<p>' . esc_html__('Specify manual settings URLs for plugins that have multiple settings pages or unconventional menu structures.', 'add-settings-links') . '</p>';
}

/**
 * Settings field callback.
 */
function asl_manual_overrides_field_callback() {
    $manual_overrides = get_option('asl_manual_overrides', array());

    // Fetch all installed plugins with caching
    $plugins = asl_get_all_plugins();
    ?>
    <input type="text" id="asl_plugin_search" placeholder="<?php esc_attr_e('Search Plugins...', 'add-settings-links'); ?>" style="width:100%; margin-bottom: 10px;" />
    <table class="widefat fixed asl-settings-table" cellspacing="0">
        <thead>
            <tr>
                <th><?php esc_html_e('Plugin', 'add-settings-links'); ?></th>
                <th><?php esc_html_e('Manual Settings URLs (comma-separated for multiple)', 'add-settings-links'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($plugins as $plugin_file => $plugin_data) : ?>
                <tr>
                    <td><?php echo esc_html($plugin_data['Name']); ?></td>
                    <td>
                        <input type="text" name="asl_manual_overrides[<?php echo esc_attr($plugin_file); ?>]" value="<?php echo isset($manual_overrides[$plugin_file]) ? esc_attr(implode(',', (array)$manual_overrides[$plugin_file])) : ''; ?>" style="width:100%;" />
                        <p class="description"><?php esc_html_e('Enter one or multiple settings URLs separated by commas.', 'add-settings-links'); ?></p>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <style>
        /* Optional: Add some basic styling for better UX */
        .asl-settings-table tbody tr:hover {
            background-color: #f1f1f1;
        }
    </style>
    <script>
        // JavaScript for real-time search/filter on the settings page and URL validation
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('asl_plugin_search');
            const tableRows = document.querySelectorAll('.asl-settings-table tbody tr');

            searchInput.addEventListener('keyup', function() {
                const query = this.value.toLowerCase();

                tableRows.forEach(function(row) {
                    const pluginName = row.querySelector('td:first-child').textContent.toLowerCase();
                    if (pluginName.includes(query)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });

            // URL Validation Feedback
            const urlInputs = document.querySelectorAll('.asl-settings-table tbody tr td:nth-child(2) input[type="text"]');

            urlInputs.forEach(function(input) {
                input.addEventListener('blur', function() {
                    const urls = this.value.split(',');
                    let allValid = true;
                    const urlPattern = new RegExp('^(https?:\\/\\/)?'+ // protocol
                        '((([a-z\\d]([a-z\\d-]*[a-z\\d])*)\\.)+[a-z]{2,}|' + // domain name
                        '((\\d{1,3}\\.){3}\\d{1,3}))' + // OR ip (v4) address
                        '(\\:\\d+)?(\\/[-a-z\\d%_.~+]*)*' + // port and path
                        '(\\?[;&a-z\\d%_.~+=-]*)?' + // query string
                        '(\\#[-a-z\\d_]*)?$','i'); // fragment locator

                    urls.forEach(function(url) {
                        if (url.trim() !== '' && !urlPattern.test(url.trim())) {
                            allValid = false;
                        }
                    });

                    if (!allValid) {
                        alert('<?php echo esc_js( __('One or more URLs entered are invalid. Please ensure they are correctly formatted.', 'add-settings-links') ); ?>');
                    }
                });
            });
        });
    </script>
    <?php
}

/**
 * Sanitize manual overrides input.
 *
 * @param array $input The raw input.
 * @return array       The sanitized input.
 */
function asl_sanitize_manual_overrides($input) {
    $sanitized = array();

    if (is_array($input)) {
        foreach ($input as $plugin_file => $urls) {
            $urls = array_map('trim', explode(',', $urls));
            // Validate each URL
            $valid_urls = array();
            foreach ($urls as $url) {
                $url = esc_url_raw($url);
                if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $valid_urls[] = $url;
                }
            }
            if (!empty($valid_urls)) {
                $sanitized[$plugin_file] = $valid_urls;
            }
        }
    }

    return $sanitized;
}

/**
 * Add settings page to the admin menu.
 */
function asl_add_settings_page() {
    add_options_page(
        __('Add Settings Links', 'add-settings-links'),
        __('Add Settings Links', 'add-settings-links'),
        'manage_options',
        'asl_settings',
        'asl_render_settings_page'
    );
}

/**
 * Render the settings page.
 */
function asl_render_settings_page() {
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
 * Log debug messages if WP_DEBUG is enabled.
 *
 * @param string $message The message to log.
 */
function asl_log_debug($message) {
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        if (is_multisite()) {
            error_log('[Add Settings Links][Site ID ' . get_current_blog_id() . '] ' . $message);
        } else {
            error_log('[Add Settings Links] ' . $message);
        }
    }
}

/**
 * Get all installed plugins with caching.
 *
 * @return array Array of installed plugins.
 */
function asl_get_all_plugins() {
    $cached_plugins = get_transient(ASL_CACHED_PLUGINS_TRANSIENT);

    if (false === $cached_plugins) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $cached_plugins = get_plugins();
        set_transient(ASL_CACHED_PLUGINS_TRANSIENT, $cached_plugins, ASL_CACHED_PLUGINS_EXPIRATION);
        asl_log_debug("Installed plugins cached.");
    } else {
        asl_log_debug("Installed plugins retrieved from cache.");
    }

    return $cached_plugins;
}

/**
 * Display an aggregated admin notice if no settings URL is found for any plugins.
 */
function asl_display_aggregated_admin_notices() {
    global $asl_missing_settings;

    if (!empty($asl_missing_settings)) {
        $class = 'notice notice-warning is-dismissible';
        $plugins = implode(', ', array_map('esc_html', $asl_missing_settings));
        $message = sprintf(
            /* translators: %s: List of plugin basenames */
            __('Add Settings Links: No settings URL found for the following plugins: %s.', 'add-settings-links'),
            $plugins
        );

        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));

        // Reset the global variable after displaying the notice
        $asl_missing_settings = array();
    }
}

// Hook into admin_notices to display aggregated notices
add_action('admin_notices', 'asl_display_aggregated_admin_notices');
