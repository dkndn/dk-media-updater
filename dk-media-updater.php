<?php

/**
* Plugin Name: DK Media GmbH → Updater
* Plugin URI: https://www.daniel-knoden.de/
* Description: Acts as an proxy server to allow or disallow plugin updates.
* Version: 0.8
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
add_filter( 'pre_set_site_transient_update_plugins', 'dkmu_check_plugin_updates_from_server' );
add_filter( 'plugin_row_meta', 'dkmu_add_check_updates_link', 10, 2);
add_action( 'admin_init', 'dkmu_handle_check_updates_action' );

// Endpoint registrieren
add_action('rest_api_init', function () {
    // Checks if updates are available
    register_rest_route('dkm-plugins/v1', '/update', [
        'methods' => 'GET',
        'callback' => 'dkmu_handle_update_request',
        'permission_callback' => '__return_true',
    ]);

    // Acts as proxy URL so clients don't download from GitHub directly
    register_rest_route('dkm-plugins/v1', '/download-zip', [
        'methods' => 'GET',
        'callback' => 'dkmu_proxy_download_zip_request',
        'permission_callback' => '__return_true',
    ]);
});

/** HELPER AND CONFIG FUNCTIONS **/
function dkmu_get_plugin_updater_config(){
    return [
        'dk-media-pro' => [
            'githubUserName' => 'dkndn',
            'githubRepoName' => 'dk-media-pro',
            'githubBranchName' => 'main',
        ],
        'dk-media-updater' => [
            'githubUserName' => 'dkndn',
            'githubRepoName' => 'dk-media-updater',
            'githubBranchName' => 'main',
        ]
    ];
}

/**
 * Holt die ZIP-Datei von GitHub und gibt sie an den Client zurück
 *
 * @param WP_REST_Request $request Die Anfrage
 * @return WP_REST_Response
 */
function dkmu_proxy_download_zip_request(WP_REST_Request $request) {
    $plugin_slug = $request->get_param('plugin_slug');

    $updater_config = dkmu_get_plugin_updater_config();

    // Validierung der Anfrage
    if (!$plugin_slug || !array_key_exists($plugin_slug, $updater_config) ) {
        return new WP_REST_Response(['error' => 'Invalid plugin slug'], 400);
    }

    $access_token = dkmu_get_access_token();

    // Validierung der Anfrage
    if (!$access_token) {
        return new WP_REST_Response(['error' => 'GitHub Access Token not provided'], 400);
    }

    $githubUser     = $updater_config[$plugin_slug]['githubUserName'];
    $githubRepo     = $updater_config[$plugin_slug]['githubRepoName'];
    $githubBranch   = $updater_config[$plugin_slug]['githubBranchName'];

    // GitHub ZIP-URL für den Branch
    $zip_url = "https://api.github.com/repos/$githubUser/$githubRepo/zipball/$githubBranch";

    // GitHub API-Aufruf vorbereiten
    $response = wp_remote_get(
        $zip_url,
        [
            'headers' => [
                'Authorization' => "token $access_token",
                'User-Agent' => 'Update-Server',
            ],
        ]
    );

    if (is_wp_error($response)) {
        return new WP_REST_Response(['error' => 'Error connecting to GitHub'], 500);
    }

    $http_code = wp_remote_retrieve_response_code($response);

    // Sicherstellen, dass der API-Call erfolgreich war
    if ($http_code !== 200) {
        return new WP_REST_Response(['error' => 'Failed to fetch ZIP file'], $http_code);
    }

    // Dateiinhalt abrufen
    $file_content = wp_remote_retrieve_body($response);

    // Header für die Rückgabe der Datei setzen
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $plugin_slug . '.zip"');
    header('Content-Length: ' . strlen($file_content));

    echo $file_content;
    exit; // Beende den weiteren WordPress-Output
}

function dkmu_get_access_token(){
    return defined('DKMU_GITHUB_ACCESS_TOKEN') ? DKMU_GITHUB_ACCESS_TOKEN : '';
}

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

    $updater_config = dkmu_get_plugin_updater_config();

    // Validierung der Anfrage
    if (!$plugin_slug || !array_key_exists($plugin_slug, $updater_config) ) {
        return new WP_REST_Response(['error' => 'Invalid plugin slug'], 400);
    }

    $access_token = dkmu_get_access_token();

    // Validierung der Anfrage
    if (!$access_token) {
        return new WP_REST_Response(['error' => 'GitHub Access Token not provided'], 400);
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
                'Authorization' => 'token ' . $access_token,
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
    $download_url = rest_url("dkm-plugins/v1/download-zip?plugin_slug=$plugin_slug");

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

    if (!isset($transient->checked[$plugin_file])) {
        return $transient; // Plugin ist nicht registriert
    }
    
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
            'url'           => 'https://your-update-server2.com', // Info-Seite
        ];
    }

    return $transient;
}

/** CHECK MANUAL FOR UPDATES **/
function dkmu_handle_check_updates_action() {
    if (!isset($_GET['action'], $_GET['plugin_slug']) || $_GET['action'] !== 'check_plugin_updates') {
        return;
    }

    $plugin_slug = sanitize_text_field($_GET['plugin_slug']);
    if ($plugin_slug !== DKMU_PLUGIN_SLUG) {
        return;
    }

    // Forcierten Update-Check durchführen
    delete_site_transient('update_plugins');
    wp_update_plugins();

    // Benutzer zurückleiten mit Erfolgsmeldung
    wp_redirect(admin_url('plugins.php?checked_plugin=' . rawurlencode($plugin_slug) . '&update_checked=true'));
    exit;
}

function dkmu_add_check_updates_link($links, $file) {
    // Prüfen, ob wir unser Plugin bearbeiten
    if ($file !== plugin_basename(__FILE__)) {
        return $links;
    }

    // Admin-URL für den Update-Check
    $check_updates_url = admin_url('plugins.php?action=check_plugin_updates&plugin_slug=' . rawurlencode(DKMU_PLUGIN_SLUG));

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