<?php
/*
Plugin Name: Add Settings Links
Description: Adds direct links to the settings pages for all plugins that do not have one.
Version: 1.0
Author: Jaz
*/

add_action('admin_menu', 'cache_admin_menu_slugs', 9999);
add_filter('plugin_action_links', 'add_missing_settings_links', 10, 2);

function cache_admin_menu_slugs() {
    global $menu, $submenu;
    $all_slugs = array();

    foreach ($menu as $item) {
        if (!empty($item[2])) {
            $all_slugs[] = array(
                'slug' => $item[2],
                'url' => (strpos($item[2], '.php') !== false) ? $item[2] : 'admin.php?page=' . $item[2]
            );
        }
    }

    foreach ($submenu as $parent => $items) {
        foreach ($items as $item) {
            if (!empty($item[2])) {
                $all_slugs[] = array(
                    'slug' => $item[2],
                    'url' => (strpos($parent, '.php') !== false) ? $parent . '?page=' . $item[2] : 'admin.php?page=' . $item[2]
                );
            }
        }
    }

    update_option('cached_admin_menu_slugs', $all_slugs);
}

function add_missing_settings_links($links, $file) {
    $has_settings_link = false;
    foreach ($links as $link) {
        if (strpos($link, 'settings') !== false) {
            $has_settings_link = true;
            break;
        }
    }

    if (!$has_settings_link) {
        $plugin_page = plugin_basename($file);
        $plugin_dir = dirname($plugin_page);
        
        $settings_url = find_settings_url($plugin_dir, $plugin_page);
        if ($settings_url) {
            $settings_link = '<a href="' . admin_url($settings_url) . '">Settings</a>';
            array_unshift($links, $settings_link);
        } else {
            $common_settings_paths = array(
                'options-general.php?page=',
                'admin.php?page=',
            );
            foreach ($common_settings_paths as $path) {
                $settings_link = '<a href="' . admin_url($path . $plugin_dir) . '">Settings</a>';
                array_unshift($links, $settings_link);
                break;
            }
        }
    }
    return $links;
}

function find_settings_url($plugin_dir, $plugin_page) {
    $cached_slugs = get_option('cached_admin_menu_slugs', array());
    $potential_slugs = generate_potential_slugs($plugin_dir, $plugin_page);

    foreach ($cached_slugs as $item) {
        if (check_slug_match($item['slug'], $potential_slugs)) {
            return $item['url'];
        }
    }

    return false;
}

function generate_potential_slugs($plugin_dir, $plugin_page) {
    $basename = basename($plugin_page, '.php');
    return array(
        $plugin_dir,
        sanitize_title($plugin_dir),
        $basename,
        sanitize_title($basename),
        str_replace('-', '_', $plugin_dir),
        str_replace('_', '-', $plugin_dir),
        str_replace('-', '_', $basename),
        str_replace('_', '-', $basename),
    );
}

function check_slug_match($slug, $potential_slugs) {
    foreach ($potential_slugs as $potential_slug) {
        if (stripos($slug, $potential_slug) !== false) {
            return true;
        }
    }
    return false;
}
