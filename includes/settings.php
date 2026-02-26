<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_options_page(
        __('Darkstar File Manager Settings', 'darkstar-file-manager'),
        __('Darkstar File Manager', 'darkstar-file-manager'),
        'manage_options',
        'dsfm-settings',
        'dsfm_render_settings_page'
    );
});

function dsfm_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Access denied.', 'darkstar-file-manager'));
    }

    if (isset($_POST['dsfm_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dsfm_settings_nonce'])), 'dsfm_save_settings')) {
        if (isset($_POST['dsfm_upload_root'])) {
            $path = sanitize_text_field(wp_unslash($_POST['dsfm_upload_root']));
            update_option('dsfm_upload_root', $path);
        }
        if (isset($_POST['dsfm_max_file_size'])) {
            $max_size = intval($_POST['dsfm_max_file_size']);
            update_option('dsfm_max_file_size', max(1, min(100, $max_size))); // 1-100 MB
        }
        if (isset($_POST['dsfm_allowed_types'])) {
            $types = sanitize_text_field(wp_unslash($_POST['dsfm_allowed_types']));
            update_option('dsfm_allowed_types', $types);
        }
        echo '<div class="updated"><p>' . esc_html(__('Settings saved.', 'darkstar-file-manager')) . '</p></div>';
    }

    $current_path = get_option('dsfm_upload_root', dirname(ABSPATH) . '/client-docs');
    $max_file_size = get_option('dsfm_max_file_size', 50);
    $allowed_types = get_option('dsfm_allowed_types', 'pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,gif,webp,zip');

    // Path detection helper
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $doc_root       = isset($_SERVER['DOCUMENT_ROOT']) ? sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : '';
    $parent_dir     = $doc_root ? dirname($doc_root) : '';
    $suggested_path = $parent_dir ? $parent_dir . '/client-docs' : '';
    $path_exists    = file_exists($current_path);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- no WP equivalent for UI path status check
    $path_writable  = $path_exists && is_writable($current_path);
?>
    <div class="wrap">
        <h1><?php echo esc_html(__('Darkstar File Manager Settings', 'darkstar-file-manager')); ?></h1>

        <div class="notice notice-info" style="margin: 20px 0;">
            <h2><?php echo esc_html(__('How to Use This Plugin', 'darkstar-file-manager')); ?></h2>
            <ol>
                <li><strong><?php echo esc_html(__('Create a page with the shortcode:', 'darkstar-file-manager')); ?></strong> <code>[dsfm_client_login]</code> - <?php echo esc_html(__('This displays the client login form and document manager.', 'darkstar-file-manager')); ?></li>
                <li><strong><?php echo esc_html(__('Configure upload path below:', 'darkstar-file-manager')); ?></strong> <?php echo esc_html(__('For security, ensure the path is OUTSIDE your web root (e.g., /var/www/client-docs instead of /var/www/html/client-docs).', 'darkstar-file-manager')); ?></li>
                <li><strong><?php echo esc_html(__('Manage client files:', 'darkstar-file-manager')); ?></strong> <?php echo esc_html(__('Go to Users → hover over a user → click "View Documents" to upload files for that client.', 'darkstar-file-manager')); ?></li>
                <li><strong><?php echo esc_html(__('Clients can upload:', 'darkstar-file-manager')); ?></strong> <?php echo esc_html(__('When logged in, clients visit the page with the shortcode to view documents from you and upload their own files.', 'darkstar-file-manager')); ?></li>
            </ol>
            <p><strong><?php echo esc_html(__('Security Note:', 'darkstar-file-manager')); ?></strong> <?php echo esc_html(__('Files stored outside the web root cannot be accessed directly via URL. They can only be downloaded through the secure, authenticated download system.', 'darkstar-file-manager')); ?></p>
        </div>

        <form method="post">
            <?php wp_nonce_field('dsfm_save_settings', 'dsfm_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="dsfm_upload_root"><?php echo esc_html(__('Upload Folder Path', 'darkstar-file-manager')); ?></label></th>
                    <td>
                        <input name="dsfm_upload_root" type="text" id="dsfm_upload_root" value="<?php echo esc_attr($current_path); ?>" class="regular-text">

                        <!-- Path Detection Helper -->
                        <div style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                            <strong><?php echo esc_html(__('Path Detection Helper:', 'darkstar-file-manager')); ?></strong><br>
                            <span style="font-size: 12px;">
                                <?php echo esc_html(__('Web root:', 'darkstar-file-manager')); ?> <code><?php echo esc_html($doc_root); ?></code><br>
                                <?php echo esc_html(__('Suggested path (outside web root):', 'darkstar-file-manager')); ?> <code><?php echo esc_html($suggested_path); ?></code>
                                <button type="button" class="button button-small" onclick="document.getElementById('dsfm_upload_root').value='<?php echo esc_js($suggested_path); ?>'"><?php echo esc_html(__('Use This Path', 'darkstar-file-manager')); ?></button>
                                <br>
                                <?php echo esc_html(__('Current path status:', 'darkstar-file-manager')); ?>
                                <?php if ($path_exists): ?>
                                    <?php if ($path_writable): ?>
                                        <span style="color: #00a32a;">✓ <?php echo esc_html(__('Exists and writable', 'darkstar-file-manager')); ?></span>
                                    <?php else: ?>
                                        <span style="color: #d63638;">✗ <?php echo esc_html(__('Exists but not writable - check permissions', 'darkstar-file-manager')); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #dba617;">⚠ <?php echo esc_html(__('Does not exist - will be created on first upload', 'darkstar-file-manager')); ?></span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <p class="description">
                            <?php echo esc_html(__('Absolute server path where files will be stored.', 'darkstar-file-manager')); ?><br>
                            <strong><?php echo esc_html(__('Recommended:', 'darkstar-file-manager')); ?></strong> <?php echo esc_html(__('Path outside your web root for maximum security', 'darkstar-file-manager')); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dsfm_max_file_size"><?php echo esc_html(__('Maximum File Size (MB)', 'darkstar-file-manager')); ?></label></th>
                    <td>
                        <input name="dsfm_max_file_size" type="number" id="dsfm_max_file_size" value="<?php echo esc_attr($max_file_size); ?>" min="1" max="100" class="small-text">
                        <p class="description"><?php echo esc_html(__('Maximum file size allowed for uploads (1-100 MB). Default: 50 MB', 'darkstar-file-manager')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dsfm_allowed_types"><?php echo esc_html(__('Allowed File Types', 'darkstar-file-manager')); ?></label></th>
                    <td>
                        <input name="dsfm_allowed_types" type="text" id="dsfm_allowed_types" value="<?php echo esc_attr($allowed_types); ?>" class="large-text">
                        <p class="description">
                            <?php echo esc_html(__('Comma-separated list of allowed file extensions (without dots).', 'darkstar-file-manager')); ?><br>
                            <strong><?php echo esc_html(__('Default:', 'darkstar-file-manager')); ?></strong> <code>pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,gif,webp,zip</code>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>
<?php } ?>
