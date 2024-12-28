<?php

/**
* Plugin Name: DK Media GmbH → Updater
* Plugin URI: https://www.daniel-knoden.de/
* Description: Acts as an proxy server to allow or disallow plugin updates.
* Version: 0.9.3
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

/****************************************/
/** START: HELPER AND CONFIG FUNCTIONS **/

function dkmu_get_plugin_updater_config(){
    // constant configured in wp-config.php
    return defined('DKMU_PLUGIN_UPDATER_CONFIG') ? DKMU_PLUGIN_UPDATER_CONFIG : [];
}

function dkmu_get_access_token(){
    // constant configured in wp-config.php
    return defined('DKMU_GITHUB_ACCESS_TOKEN') ? DKMU_GITHUB_ACCESS_TOKEN : '';
}

function dkmu_get_update_server_url(){
    // constant configured in wp-config.php
    return defined('DKMU_PLUGIN_UPDATE_SERVER_URL') ? DKMU_PLUGIN_UPDATE_SERVER_URL : '';
}

/** END: HELPER AND CONFIG FUNCTIONS **/
/**************************************/

/**
 * Holt die ZIP-Datei aus dem Lokalen Speicher und streamt sie an den Client zurück
 *
 * @param WP_REST_Request $request Die Anfrage
 * @return WP_REST_Response
 */
function dkmu_proxy_download_zip_request(WP_REST_Request $request) {

    $plugin_slug = $request->get_param('plugin_slug');
    $version = $request->get_param('version');

    $updater_config = dkmu_get_plugin_updater_config();

    // Validierung der Anfrage
    if (!$plugin_slug || !$version || !array_key_exists($plugin_slug, $updater_config) ) {
        return new WP_REST_Response(['error' => 'Invalid plugin slug or version missing'], 400);
    }

    // Todo: License check, ob Client berechtigt ist, Uploads zu bekommen
    // ...

    $upload_dir = wp_upload_dir();
    $this_plugin = DKMU_PLUGIN_SLUG;
    $zip_path = $upload_dir['basedir'] . "/{$this_plugin}/plugin-updates/{$plugin_slug}/{$version}/{$plugin_slug}.zip";

    if (!file_exists($zip_path)) {
        return new WP_REST_Response(['error' => 'File not found'], 404);
    }

    // Datei streamen
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zip_path) . '"');
    header('Content-Length: ' . filesize($zip_path));

    readfile($zip_path);
    exit;

}

/**
 * Sends a request to the current GitHub repo.
 * It checks the version of the plugin-slug.php main file.
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
    $api_url = "https://raw.githubusercontent.com/{$githubUser}/{$githubRepo}/{$githubBranch}/{$filename}";
    
    // Datei abrufend
    $response = wp_remote_get(
        $api_url,
        [
            'headers' => [
                'Authorization' => "token {$access_token}",
                'User-Agent' => 'Update-Server',
            ],
        ]
    );

    if (is_wp_error($response)) {
        return new WP_REST_Response(['error' => 'Error connecting to GitHub'], 500);
    }

    $plugin_data = wp_remote_retrieve_body($response);

    if (empty($plugin_data) || wp_remote_retrieve_response_code($response) !== 200) {
        return new WP_REST_Response(['error' => "$filename not found or access token no longer valid"], 404);
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
    
    $latest_version = $plugin_headers['Version'];

    // Lokale Datei prüfen oder erstellen
    $zip_path = dkmu_prepare_plugin_version($plugin_slug, $latest_version);
    
    if (is_wp_error($zip_path)) {
        return new WP_REST_Response(['error' => $zip_path->get_error_message()], 500);
    }

    // Proxy-Link erstellen
    $proxy_url = add_query_arg(
        ['plugin_slug' => $plugin_slug, 'version' => $latest_version],
        rest_url('dkm-plugins/v1/download-zip')
    );

    // Generiere Download-URL für ZIP-Archiv des Branches
    if( defined('DKMU_PLUGIN_SLUG') && DKMU_PLUGIN_SLUG === $plugin_slug ){
        // Wenn das Plugin sich selbst updaten will, dann direkt von GitHub
        $download_url = "https://github.com/$githubUser/$githubRepo/archive/refs/heads/$githubBranch.zip";
    }else{
        // Wenn Client-Plugins sich updaten wollen, dann über diese Proxy-URL
        $download_url = rest_url("dkm-plugins/v1/download-zip?plugin_slug=$plugin_slug");
    }

    // Update-Informationen zurückgeben
    return new WP_REST_Response([
        'version'       => $plugin_headers['Version'],
        // 'download_url'  => $download_url,
        'download_url'  => $proxy_url,
        'slug'          => $plugin_slug,
        'tested'        => $plugin_headers['RequiresWP'] ?? '6.4',
        'requires'      => $plugin_headers['RequiresPHP'] ?? '7.0',
    ]);
}

/**
 * Checks on GitHub for most up to date version and downloads it locally
 * and stores it in a folder name which is named by the version.
 * Uses that zip file to distribute to clients via a proxy link.
 *
 * @param [type] $plugin_slug
 * @param [type] $version
 * @return void
 */
function dkmu_prepare_plugin_version($plugin_slug, $version) {
    $upload_dir = wp_upload_dir();
    $this_plugin = DKMU_PLUGIN_SLUG;
    $base_path = $upload_dir['basedir'] . "/{$this_plugin}/plugin-updates/{$plugin_slug}/{$version}/";
    $zip_path = $base_path . "{$plugin_slug}.zip";

    // Falls die Datei bereits existiert, überspringen
    if (file_exists($zip_path)) {
        return $zip_path;
    }

    wp_mkdir_p($base_path);

    // GitHub-ZIP herunterladen
    $updater_config = dkmu_get_plugin_updater_config();
    $access_token = dkmu_get_access_token();

    $githubUser     = $updater_config[$plugin_slug]['githubUserName'];
    $githubRepo     = $updater_config[$plugin_slug]['githubRepoName'];
    $githubBranch   = $updater_config[$plugin_slug]['githubBranchName'];

    // GitHub ZIP-URL für den Branch
    $zip_url = "https://api.github.com/repos/$githubUser/$githubRepo/zipball/$githubBranch";

    $response = wp_remote_get($zip_url, [
        'headers' => [
            'Authorization' => "token $access_token",
            'User-Agent' => 'Update-Server',
        ],
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('download_error', 'Failed to download ZIP from GitHub');
    }

    $temp_file = wp_tempnam();
    file_put_contents($temp_file, wp_remote_retrieve_body($response));

    // Temporär entpacken
    WP_Filesystem();
    $temp_dir = $upload_dir['basedir'] . '/temp-unzip/';
    wp_mkdir_p($temp_dir);

    $unzip_result = unzip_file($temp_file, $temp_dir);
    unlink($temp_file);

    if (is_wp_error($unzip_result)) {
        return $unzip_result;
    }

    // Originalordner finden und umbenennen
    $original_folder = glob($temp_dir . '*')[0];
    $target_folder = $base_path . $plugin_slug;

    if (!rename($original_folder, $target_folder)) {
        return new WP_Error('rename_error', 'Failed to rename extracted folder');
    }

    // ZIP neu erstellen
    $zip_result = dkmu_create_zip($target_folder, $zip_path);

    // Entpackten Ordner löschen
    dkmu_delete_folder($target_folder);
    dkmu_delete_folder($temp_dir);

    if (is_wp_error($zip_result)) {
        return $zip_result;
    }

    return $zip_path;
}

/**
 * Deletes a folder on the local storage recursively.
 *
 * @param [type] $folder
 * @return void
 */
function dkmu_delete_folder($folder) {
    if (!is_dir($folder)) {
        return false; // Verzeichnis existiert nicht
    }

    $files = array_diff(scandir($folder), ['.', '..']); // Inhalt des Ordners
    foreach ($files as $file) {
        $path = $folder . DIRECTORY_SEPARATOR . $file;
        is_dir($path) ? dkmu_delete_folder($path) : unlink($path); // Ordner oder Datei löschen
    }

    return rmdir($folder); // Hauptverzeichnis löschen
}

/**
 * Zips a folder on the local storage.
 *
 * @param [type] $source
 * @param [type] $destination
 * @return void
 */
function dkmu_create_zip($source, $destination) {
    if (!extension_loaded('zip') || !file_exists($source)) {
        return new WP_Error('zip_error', 'ZIP extension not available or source does not exist');
    }

    $zip = new ZipArchive();
    if (!$zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        return new WP_Error('zip_error', 'Unable to create ZIP archive');
    }

    $source = realpath($source);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if (!$file->isDir()) {
            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($source) + 1);
            $zip->addFile($file_path, $relative_path);
        }
    }

    $zip->close();

    return true;
}

/**
 * Hooks into WordPress update system to call to own update server
 * instead to the default WP plugin repository.
 *
 * @param mixed $transient
 * @return mixed
 */
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
    $update_server_url = dkmu_get_update_server_url() . DKMU_PLUGIN_SLUG;
    $response = wp_remote_get($update_server_url);

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

/**
 * Handles the click on "check for updates" from the plugins admin page.
 *
 * @return void
 */
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

/**
 * Adds a link to the plugin row in the admin backend allowing the
 * user to check manually for new updates.
 *
 * @param [type] $links
 * @param [type] $file
 * @return void
 */
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