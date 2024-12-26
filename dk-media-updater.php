<?php

/**
* Plugin Name: DK Media GmbH → Updater
* Plugin URI: https://www.daniel-knoden.de/
* Description: Acts as an proxy server to allow or disallow plugin updates.
* Version: 0.1
* Requires at least: 5.6
* Requires PHP: 7.0
* Author: Daniel Knoden
* Author URI: https://www.daniel-knoden.de/
* License: GPL v2 or later
* License URI: https://www.gnu.org/licenses/gpl-2.0.html
* Text Domain: dk-media-updater
* Domain Path: /languages
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Endpoint registrieren
add_action('rest_api_init', function () {
    register_rest_route('dkm-plugins/v1', '/update', [
        'methods' => 'GET',
        'callback' => 'dkmu_handle_update_request',
        'permission_callback' => '__return_true',
    ]);
});

// Manual Definitions
define( 'DKMP_SLUG', 'dk-media-pro' );
define( 'DKMU_GITHUB_USERNAME', 'dkndn' );
define( 'DKMU_GITHUB_REPO_NAME', 'dk-media-pro' );
define( 'DKMU_GITHUB_BRANCH', 'main' );
define( 'DKMU_GITHUB_ACCESS_TOKEN', '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' );


/**
 * Sends a request to the current GitHub repo.
 * It checks the version of the plugin.php main file.
 * Responses with update data if everthing is valid.
 *
 * @param WP_REST_Request $request Die Anfrage
 * @return WP_REST_Response JSON-Daten für das Plugin-Update
 */
function dkmu_handle_update_request(WP_REST_Request $request) {

    $plugin_slug = $request->get_param('plugin_slug');

    // Validierung der Anfrage
    if (!$plugin_slug || $plugin_slug !== DKMP_SLUG) {
        return new WP_REST_Response(['error' => 'Invalid plugin slug'], 400);
    }

    // RAW GITHUB Call
    $filename = DKMP_SLUG . '.php';
    $api_url = 'https://raw.githubusercontent.com/' . DKMU_GITHUB_USERNAME . '/' . DKMU_GITHUB_REPO_NAME . '/' . DKMU_GITHUB_BRANCH .'/' . $filename;
    
    // Datei abrufen
    $response = wp_remote_get(
        $api_url,
        [
            'headers' => [
                'Authorization' => 'token ' . DKMU_GITHUB_ACCESS_TOKEN,
                'User-Agent' => 'Update-Server',
            ],
        ]
    );

    if (is_wp_error($response)) {
        return new WP_REST_Response(['error' => 'Error connecting to GitHub'], 500);
    }

    $plugin_data = wp_remote_retrieve_body($response);

    if (empty($plugin_data)) {
        return new WP_REST_Response(['error' => "$filename not found"], 404);
    }

    // Temporäre Datei erstellen
    if (!function_exists('wp_tempnam')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $temp_file = wp_tempnam();
    file_put_contents($temp_file, $plugin_data);

    // Plugin-Daten auslesen
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin_headers = get_plugin_data($temp_file);

    // Temporäre Datei löschen
    unlink($temp_file);

    if (empty($plugin_headers['Version'])) {
        return new WP_REST_Response(['error' => 'Invalid plugin metadata'], 500);
    }

    // Generiere Download-URL für ZIP-Archiv des Branches
    $download_url = 'https://github.com/' . DKMU_GITHUB_USERNAME . '/' . DKMU_GITHUB_REPO_NAME . '/archive/' . DKMU_GITHUB_BRANCH . '.zip';

    // Update-Informationen zurückgeben
    return new WP_REST_Response([
        'version' => $plugin_headers['Version'],
        'download_url' => $download_url,
        'slug' => $plugin_slug,
        'tested' => $plugin_headers['RequiresWP'] ?? '6.4',
        'requires' => $plugin_headers['RequiresPHP'] ?? '5.8',
    ]);
}