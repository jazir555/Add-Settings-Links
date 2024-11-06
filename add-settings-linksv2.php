<?php
/*
Plugin Name: Add Settings Links
Description: Adds direct links to the settings pages for all plugins that do not have one.
Version: 1.2
Author: Jaz
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define constants for transient caching
define('ASL_MENU_SLUGS_TRANSIENT', 'cached_admin_menu_slugs');
define('ASL_MENU_SLUGS_TRANSIENT_EXPIRATION', 12 * HOUR_IN_SECONDS);

// Hook into admin_menu to cache menu slugs
add_action('admin_menu', 'asl_cache_admin_menu_slugs', 9999);

// Hook into plugin_action_links to add settings links
add_filter('plugin_action_links', 'asl_add_missing_settings_links', 10, 2);

/**
 * Cache all admin menu slugs and their corresponding URLs.
 */
function asl_cache_admin_menu_slugs() {
    // Check if the slugs are already cached
    if (false !== get_transient(ASL_MENU_SLUGS_TRANSIENT)) {
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
            }
        }
    }

    // Cache the slugs using a transient
    set_transient(ASL_MENU_SLUGS_TRANSIENT, $all_slugs, ASL_MENU_SLUGS_TRANSIENT_EXPIRATION);
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
            return admin_url('admin.php?page=' . $slug);
        }
    } else {
        // Submenu item
        if (strpos($parent_slug, '.php') !== false) {
            return admin_url($parent_slug . '?page=' . $slug);
        } else {
            return admin_url('admin.php?page=' . $slug);
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
    // Check if a settings link already exists
    foreach ($links as $link) {
        // Use case-insensitive exact match for 'Settings'
        if (preg_match('/<a\s+href=.*?>\s*Settings\s*<\/a>/i', $link)) {
            return $links; // Settings link already exists
        }
    }

    // Check for manual overrides
    $manual_overrides = apply_filters('asl_manual_settings_links', array());
    if (isset($manual_overrides[$file])) {
        $settings_url = $manual_overrides[$file];
        if (!empty($settings_url)) {
            $settings_link = '<a href="' . esc_url($settings_url) . '">' . __('Settings') . '</a>';
            array_unshift($links, $settings_link);
            return $links;
        }
    }

    // Determine the plugin directory
    $plugin_basename = plugin_basename($file);
    $plugin_dir      = dirname($plugin_basename);

    // Attempt to find the settings URL
    $settings_url = asl_find_settings_url($plugin_dir, $plugin_basename);

    if ($settings_url) {
        // Prepend the Settings link
        $settings_link = '<a href="' . esc_url(admin_url($settings_url)) . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
    } else {
        // Optionally log if settings URL not found
        asl_log_debug("Settings URL not found for plugin: $plugin_basename");
    }

    return $links;
}

/**
 * Find the settings URL for a given plugin directory and file.
 *
 * @param string $plugin_dir       The plugin directory name.
 * @param string $plugin_basename  The plugin basename (e.g., my-plugin/my-plugin.php).
 * @return string|false            The settings URL or false if not found.
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

    foreach ($cached_slugs as $item) {
        if (in_array($item['slug'], $potential_slugs, true)) {
            asl_log_debug("Found settings URL for plugin '$plugin_basename': " . $item['url']);
            return $item['url'];
        }
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
    return array_unique(array_map('sanitize_title', array(
        $plugin_dir,
        $basename,
        str_replace('-', '_', $plugin_dir),
        str_replace('_', '-', $plugin_dir),
        str_replace('-', '_', $basename),
        str_replace('_', '-', $basename),
    )));
}

/**
 * Clear the cached menu slugs when necessary.
 * Hook this function to actions like plugin activation/deactivation if needed.
 */
function asl_clear_cached_menu_slugs() {
    delete_transient(ASL_MENU_SLUGS_TRANSIENT);
}

// Clear cache on plugin activation and deactivation
register_activation_hook(__FILE__, 'asl_clear_cached_menu_slugs');
register_deactivation_hook(__FILE__, 'asl_clear_cached_menu_slugs');

/**
 * Log debug messages if WP_DEBUG is enabled.
 *
 * @param string $message The message to log.
 */
function asl_log_debug($message) {
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        error_log('[Add Settings Links] ' . $message);
    }
}
