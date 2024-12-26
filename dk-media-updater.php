<?php

/**
* Plugin Name: DK Media GmbH → Updater
* Plugin URI: https://www.daniel-knoden.de/
* Description: Acts as an proxy server to allow or disallow plugin updates.
* Version: 0.2
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

// Constants for this plugin
define( 'DKMU_PLUGIN_SLUG', 'dk-media-updater' );
define( 'DKMU_UPDATE_SERVER_URL', 'https://apiwp.daniel-knoden.de/wp-json/dkm-plugins/v1/update?plugin_slug=' . DKMU_PLUGIN_SLUG );

// Add plugin update routines
add_filter('pre_set_site_transient_update_plugins', 'dkmu_check_plugin_updates_from_server' );
add_filter( 'plugin_row_meta', 'dkmu_add_check_updates_link', 10, 2);

// Endpoint registrieren
add_action('rest_api_init', function () {
    register_rest_route('dkm-plugins/v1', '/update', [
        'methods' => 'GET',
        'callback' => 'dkmu_handle_update_request',
        'permission_callback' => '__return_true',
    ]);
});

// Manual Definitions
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

    $updater_config = [
        'dk-media-pro' => [
            'githubUserName' => 'dkndn',
            'githubRepoName' => 'dk-media-pro',
            'githubBranchName' => 'main',
        ],
        'dk-media-updater' => [
            'githubUserName' => 'dkndn',
            'githubRepoName' => 'dk-media-updater',
            'githubBranchName' => 'main',
        ],
    ];

    // Validierung der Anfrage
    if (!$plugin_slug || !array_key_exists($plugin_slug, $updater_config) ) {
        return new WP_REST_Response(['error' => 'Invalid plugin slug'], 400);
    }

    $githubUser     = $updater_config[$plugin_slug]['githubUserName'];
    $githubRepo     = $updater_config[$plugin_slug]['githubRepoName'];
    $githubBranch   = $updater_config[$plugin_slug]['githubBranchName'];
    $filename       = $plugin_slug . '.php';

    // RAW GITHUB Call
    $api_url = 'https://raw.githubusercontent.com/' . $githubUser . '/' . $githubRepo . '/' . $githubBranch .'/' . $filename;
    
    // Datei abrufend
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
    $download_url = 'https://github.com/' . $githubUser . '/' . $githubRepo . '/archive/' . $githubBranch . '.zip';

    // Update-Informationen zurückgeben
    return new WP_REST_Response([
        'version'       => $plugin_headers['Version'],
        'download_url'  => $download_url,
        'slug'          => $plugin_slug,
        'tested'        => $plugin_headers['RequiresWP'] ?? '6.4',
        'requires'      => $plugin_headers['RequiresPHP'] ?? '7.0',
        'all'           => $plugin_headers,
    ]);
}

function dkmu_check_plugin_updates_from_server($transient){
    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_file = plugin_basename(__FILE__);
    $current_version = $transient->checked[$plugin_file];

    // API-Request ausführen
    $response = wp_remote_get(DKMU_UPDATE_SERVER_URL);

    if (is_wp_error($response)) {
        return $transient; // Fehler, keine Updates
    }

    $update_data = json_decode(wp_remote_retrieve_body($response), true);

    // Überprüfen, ob ein Update verfügbar ist
    if (!empty($update_data['version']) && version_compare($update_data['version'], $current_version, '>')) {
        $transient->response[$plugin_file] = (object) [
            'slug'          => DKMU_PLUGIN_SLUG,
            'new_version'   => $update_data['version'],
            'package'       => $update_data['download_url'], // Download-URL für das Update
            'url'           => 'https://your-update-server.com', // Info-Seite
        ];
    }

    return $transient;
}

function dkmu_add_check_updates_link($links, $file) {
    // Prüfen, ob wir unser Plugin bearbeiten
    if ($file !== plugin_basename(__FILE__)) {
        return $links;
    }

    // Admin-URL für den Update-Check
    $check_updates_url = admin_url('plugins.php?action=check_plugin_updates&plugin_slug=' . rawurlencode(DKMP_PLUGIN_SLUG));

    // Link erstellen
    $check_updates_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url($check_updates_url),
        __('Check for Updates', 'dk-media-updater')
    );

    // Link hinzufügen
    $links[] = $check_updates_link;

    return $links;
}