<?php

/**
 * Plugin Name: Darkstar File Manager
 * Plugin URI: https://github.com/justinblayney/darkstar-file-manager
 * Description: Secure file management system allowing administrators to share files with users and users to upload their own documents.
 * Version: 1.0.2
 * Author: Darkstar Media
 * Author URI: https://www.darkstarmedia.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Text Domain: darkstar-file-manager
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

// On activation: create the upload directory (if needed) and protect it immediately
register_activation_hook(__FILE__, 'dsfm_activate');
function dsfm_activate()
{
    $upload_dir  = wp_upload_dir();
    $upload_root = get_option('dsfm_upload_root', $upload_dir['basedir'] . '/darkstar-file-manager');
    if (!file_exists($upload_root)) {
        wp_mkdir_p($upload_root);
    }
    dsfm_protect_upload_dir($upload_root);
}

// Register privacy policy content shown in WP Admin → Privacy Policy guide
add_action('admin_init', 'dsfm_privacy_policy_content');
function dsfm_privacy_policy_content()
{
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }
    wp_add_privacy_policy_content(
        'Darkstar File Manager',
        wp_kses_post(
            '<p>' . __(
                'This plugin stores uploaded files on your server and saves metadata (filenames, upload timestamps, and whether the file was uploaded by an admin or the client) in per-user JSON files. No data is transmitted to external servers. All files are associated with WordPress user accounts and are only accessible to the owning user and site administrators.',
                'darkstar-file-manager'
            ) . '</p>'
        )
    );
}

// Register strings with Polylang for String Translations interface
// Polylang will automatically intercept standard WordPress translation functions
// when active, so we use __() and _e() throughout the plugin
add_action('plugins_loaded', 'dsfm_register_polylang_strings');
function dsfm_register_polylang_strings()
{
    if (function_exists('pll_register_string')) {
        // Client-facing strings
        pll_register_string('You are logged in.', 'You are logged in.', 'darkstar-file-manager');
        pll_register_string('Log out', 'Log out', 'darkstar-file-manager');
        pll_register_string('Select a file to upload', 'Select a file to upload', 'darkstar-file-manager');
        pll_register_string('Upload File', 'Upload File', 'darkstar-file-manager');
        pll_register_string('File Name', 'File Name', 'darkstar-file-manager');
        pll_register_string('Date Added', 'Date Added', 'darkstar-file-manager');
        pll_register_string('Delete', 'Delete', 'darkstar-file-manager');
        pll_register_string('Delete this file?', 'Delete this file?', 'darkstar-file-manager');
        pll_register_string('Delete %s', 'Delete %s', 'darkstar-file-manager');

        // Success/Error messages
        pll_register_string('Security check failed for deletion.', 'Security check failed for deletion.', 'darkstar-file-manager');
        pll_register_string('File deleted successfully.', 'File deleted successfully.', 'darkstar-file-manager');
        pll_register_string('File not found or cannot delete.', 'File not found or cannot delete.', 'darkstar-file-manager');
        pll_register_string('Security check failed. Please try again.', 'Security check failed. Please try again.', 'darkstar-file-manager');
        pll_register_string('File uploaded successfully.', 'File uploaded successfully.', 'darkstar-file-manager');
        pll_register_string('Error uploading file.', 'Error uploading file.', 'darkstar-file-manager');

        // Admin strings
        pll_register_string('View Documents', 'View Documents', 'darkstar-file-manager');
        pll_register_string('Client Documents', 'Client Documents', 'darkstar-file-manager');
        pll_register_string('Access denied.', 'Access denied.', 'darkstar-file-manager');
        pll_register_string('No user selected.', 'No user selected.', 'darkstar-file-manager');
        pll_register_string('User not found.', 'User not found.', 'darkstar-file-manager');
        pll_register_string('Documents for %s', 'Documents for %s', 'darkstar-file-manager');
        pll_register_string('No documents found.', 'No documents found.', 'darkstar-file-manager');
        pll_register_string('File', 'File', 'darkstar-file-manager');
        pll_register_string('Date', 'Date', 'darkstar-file-manager');
        pll_register_string('File not found.', 'File not found.', 'darkstar-file-manager');

        // Settings strings
        pll_register_string('Darkstar File Manager Settings', 'Darkstar File Manager Settings', 'darkstar-file-manager');
        pll_register_string('Darkstar File Manager', 'Darkstar File Manager', 'darkstar-file-manager');
        pll_register_string('Settings saved.', 'Settings saved.', 'darkstar-file-manager');
        pll_register_string('Darkstar File Manager Settings', 'Darkstar File Manager Settings', 'darkstar-file-manager');
        pll_register_string('Upload Folder Path', 'Upload Folder Path', 'darkstar-file-manager');
        pll_register_string('Absolute server path. E.g., /var/www/html/client-docs', 'Absolute server path. E.g., /var/www/html/client-docs', 'darkstar-file-manager');

        // Admin delete strings
        pll_register_string('Delete Selected', 'Delete Selected', 'darkstar-file-manager');
        pll_register_string('Delete selected files?', 'Delete selected files?', 'darkstar-file-manager');
        pll_register_string('%d file(s) deleted successfully.', '%d file(s) deleted successfully.', 'darkstar-file-manager');

        // Admin upload strings
        pll_register_string('Upload Document for Client', 'Upload Document for Client', 'darkstar-file-manager');
        pll_register_string('Uploaded By', 'Uploaded By', 'darkstar-file-manager');

        // Client view strings
        pll_register_string('Documents for you', 'Documents for you', 'darkstar-file-manager');
        pll_register_string('Your Uploaded Documents', 'Your Uploaded Documents', 'darkstar-file-manager');
    }
}

if (!defined('DSFM_UPLOAD_ROOT')) {
    $dsfm_upload_dir = wp_upload_dir();
    define('DSFM_UPLOAD_ROOT', get_option('dsfm_upload_root', $dsfm_upload_dir['basedir'] . '/darkstar-file-manager'));
}

if (!defined('DSFM_MAX_UPLOADS_PER_HOUR')) {
    define('DSFM_MAX_UPLOADS_PER_HOUR', 20);
}

/**
 * Write a protective .htaccess and index.php to the upload root directory.
 * Safe to call multiple times — skips files that already exist.
 *
 * @param string $dir Absolute path to the directory to protect.
 */
function dsfm_protect_upload_dir($dir)
{
    if (!file_exists($dir)) {
        return;
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        $rules  = "# Deny direct file access\n";
        $rules .= "<IfModule mod_authz_core.c>\n";
        $rules .= "    Require all denied\n";
        $rules .= "</IfModule>\n";
        $rules .= "<IfModule !mod_authz_core.c>\n";
        $rules .= "    Order deny,allow\n";
        $rules .= "    Deny from all\n";
        $rules .= "</IfModule>\n";
        file_put_contents($htaccess, $rules);
    }

    $index = $dir . '/index.php';
    if (!file_exists($index)) {
        file_put_contents($index, "<?php // Silence is golden\n");
    }
}

/**
 * Validate uploaded file
 *
 * @param array $file $_FILES array element
 * @return array ['valid' => bool, 'error' => string|null]
 */
function dsfm_validate_upload($file)
{
    // Get settings
    $max_size = get_option('dsfm_max_file_size', 50); // MB
    $allowed_types = get_option('dsfm_allowed_types', 'pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,gif,webp,zip');
    $allowed_types_array = array_map('trim', explode(',', $allowed_types));

    // Check if file was uploaded
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => __('File upload failed. Please try again.', 'darkstar-file-manager')];
    }

    // Check file size
    $file_size_mb = $file['size'] / 1024 / 1024;
    if ($file_size_mb > $max_size) {
        /* translators: %d is the maximum allowed file size in megabytes */
        return ['valid' => false, 'error' => sprintf(__('File size exceeds maximum allowed size of %d MB.', 'darkstar-file-manager'), $max_size)];
    }

    // Check file extension
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_types_array)) {
        /* translators: 1: file extension (e.g. exe), 2: comma-separated list of allowed extensions */
        return ['valid' => false, 'error' => sprintf(__('File type .%1$s is not allowed. Allowed types: %2$s', 'darkstar-file-manager'), $file_ext, $allowed_types)];
    }

    // Run WordPress's built-in file type and extension check
    $wp_check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    if (empty($wp_check['ext']) || empty($wp_check['type'])) {
        return ['valid' => false, 'error' => __('File type not permitted.', 'darkstar-file-manager')];
    }

    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    // Common safe MIME types
    $allowed_mimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'text/csv',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/zip',
        'application/x-zip-compressed',
    ];

    if (!in_array($mime_type, $allowed_mimes)) {
        /* translators: %s is the detected MIME type of the uploaded file */
        return ['valid' => false, 'error' => sprintf(__('File MIME type (%s) is not allowed for security reasons.', 'darkstar-file-manager'), $mime_type)];
    }

    // ZIP bomb check — verify uncompressed content does not exceed 512 MB
    if ($file_ext === 'zip' && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) === true) {
            $total_uncompressed = 0;
            $max_uncompressed   = 512 * 1024 * 1024; // 512 MB
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total_uncompressed += $stat['size'];
                if ($total_uncompressed > $max_uncompressed) {
                    $zip->close();
                    return ['valid' => false, 'error' => __('ZIP file uncompressed content exceeds the 512 MB safety limit.', 'darkstar-file-manager')];
                }
            }
            $zip->close();
        }
    }

    return ['valid' => true, 'error' => null];
}

add_action('wp_enqueue_scripts', 'dsfm_enqueue_assets');
function dsfm_enqueue_assets()
{
    // Only enqueue on pages where shortcode is used
    if (is_singular() && has_shortcode(get_post()->post_content, 'dsfm_client_login')) {
        wp_enqueue_style('dsfm-client-style', plugin_dir_url(__FILE__) . 'assets/css/client-docs.css', [], '1.0');
        wp_enqueue_script('dsfm-client-script', plugin_dir_url(__FILE__) . 'assets/js/client-docs.js', [], '1.0', true);
    }
}

require_once plugin_dir_path(__FILE__) . 'includes/client-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
