<?php

/**
 * Client Functions
 *
 * Handles login, upload, file listing, and deletion for clients.
 */

defined('ABSPATH') || exit;

/**
 * Combined shortcode to handle login form, file upload, listing, and logout
 *
 * Shortcode: [dsfm_client_login]
 *
 * Displays:
 * - Login form if not logged in
 * - Logout link, upload form, file list with delete option if logged in
 *
 * Files are stored in a folder named after the sanitized username inside DSFM_UPLOAD_ROOT.
 * Metadata with upload timestamps is saved in a JSON file for display.
 *
 * Delete and upload actions show success messages without redirecting away.
 */
add_shortcode('dsfm_client_login', function () {
    if (!is_user_logged_in()) {
        ob_start();
        // Show WordPress login form with custom login URL support
        // This automatically works with iThemes Security, Wordfence, etc.
        wp_login_form([
            'redirect' => get_permalink(),
            'action' => wp_login_url()
        ]);
        return ob_get_clean();
    }

    $user = wp_get_current_user();
    $username = sanitize_file_name($user->user_login);
    $user_dir = trailingslashit(DSFM_UPLOAD_ROOT) . $username;
    if (!file_exists($user_dir)) {
        wp_mkdir_p($user_dir);
        dsfm_protect_upload_dir(DSFM_UPLOAD_ROOT);
    }

    $meta_file = $user_dir . '/file-metadata.json';

    // Load metadata
    $metadata = [];
    if (file_exists($meta_file)) {
        $metadata = json_decode(file_get_contents($meta_file), true) ?: [];
    }

    $message = '';

    // Handle file deletion via POST
    if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'] && !empty($_POST['dsfm_delete_file'])) {
        if (!isset($_POST['dsfm_delete_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dsfm_delete_nonce'])), 'dsfm_delete_file')) {
            $message = "<p class='dsfm-error'>" . esc_html(__('Security check failed for deletion.', 'darkstar-file-manager')) . "</p>";
        } else {
            $file_to_delete = basename(sanitize_file_name(wp_unslash($_POST['dsfm_delete_file'])));
            $file_path = realpath($user_dir . '/' . $file_to_delete);

            if ($file_path && strpos($file_path, $user_dir) === 0 && file_exists($file_path)) {
                wp_delete_file($file_path);
                if (isset($metadata[$file_to_delete])) {
                    unset($metadata[$file_to_delete]);
                    file_put_contents($meta_file, json_encode($metadata), LOCK_EX);
                }
                $message = "<p class='dsfm-success'>" . esc_html(__('File deleted successfully.', 'darkstar-file-manager')) . "</p>";
            } else {
                $message = "<p class='dsfm-error'>" . esc_html(__('File not found or cannot delete.', 'darkstar-file-manager')) . "</p>";
            }
        }
    }

    // Handle file upload
    if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'] && !empty($_FILES['dsfm_file']['name']) && isset($_FILES['dsfm_file']['tmp_name'])) {
        if (!isset($_POST['dsfm_upload_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dsfm_upload_nonce'])), 'dsfm_upload_file')) {
            $message = "<p class='dsfm-error'>" . esc_html(__('Security check failed. Please try again.', 'darkstar-file-manager')) . "</p>";
        } else {
            $rate_key     = 'dsfm_uploads_' . $user->ID . '_' . floor(time() / 3600);
            $upload_count = (int) get_transient($rate_key);
            if ($upload_count >= DSFM_MAX_UPLOADS_PER_HOUR) {
                $message = "<p class='dsfm-error'>" . esc_html(__('Upload rate limit exceeded. Please wait before uploading more files.', 'darkstar-file-manager')) . "</p>";
            } else {
                // Sanitize user-supplied file name and validate
                $file_input = [
                    'name'     => sanitize_file_name( wp_unslash( $_FILES['dsfm_file']['name'] ) ),
                    'type'     => isset( $_FILES['dsfm_file']['type'] ) ? sanitize_mime_type( wp_unslash( $_FILES['dsfm_file']['type'] ) ) : '',
                    'tmp_name' => $_FILES['dsfm_file']['tmp_name'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-generated temp path
                    'error'    => isset( $_FILES['dsfm_file']['error'] ) ? (int) $_FILES['dsfm_file']['error'] : UPLOAD_ERR_NO_FILE,
                    'size'     => isset( $_FILES['dsfm_file']['size'] ) ? (int) $_FILES['dsfm_file']['size'] : 0,
                ];
                $validation = dsfm_validate_upload( $file_input );
                if (!$validation['valid']) {
                    $message = "<p class='dsfm-error'>" . esc_html($validation['error']) . "</p>";
                } else {
                    $timestamp             = time();
                    if ( ! function_exists( 'wp_handle_upload' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                    }

                    $dsfm_client_dir_filter = function ( $dirs ) use ( $user_dir ) {
                        $dirs['path']   = $user_dir;
                        $dirs['url']    = '';
                        $dirs['subdir'] = '';
                        return $dirs;
                    };
                    add_filter( 'upload_dir', $dsfm_client_dir_filter );

                    $uploaded = wp_handle_upload(
                        $file_input,
                        [
                            'test_form'                => false,
                            'unique_filename_callback' => function ( $dir, $name, $ext ) use ( $timestamp ) {
                                $base = pathinfo( $name, PATHINFO_FILENAME );
                                return $timestamp . '-' . $base . $ext;
                            },
                        ]
                    );
                    remove_filter( 'upload_dir', $dsfm_client_dir_filter );

                    if ( isset( $uploaded['error'] ) ) {
                        $message = "<p class='dsfm-error'>" . esc_html( $uploaded['error'] ) . "</p>";
                    } else {
                        $safe_name            = basename( $uploaded['file'] );
                        set_transient( $rate_key, $upload_count + 1, HOUR_IN_SECONDS );
                        $metadata[$safe_name] = [
                            'timestamp'   => $timestamp,
                            'uploaded_by' => 'user',
                        ];
                        file_put_contents( $meta_file, json_encode( $metadata ), LOCK_EX );
                        $message = "<p class='dsfm-success'>" . esc_html( __( 'File uploaded successfully.', 'darkstar-file-manager' ) ) . "</p>";
                    }
                }
            }
        }
    }

    ob_start();

    $logout_url = wp_logout_url(get_permalink());
    printf(
        '<p>%s <a href="%s">%s</a></p>',
        esc_html(__('You are logged in.', 'darkstar-file-manager')),
        esc_url($logout_url),
        esc_html(__('Log out', 'darkstar-file-manager'))
    );

    echo wp_kses_post($message);

    // Separate files by uploader
    $admin_files = [];
    $client_files = [];

    foreach ($metadata as $file => $file_data) {
        // Handle both old format (timestamp only) and new format (array)
        $timestamp = is_array($file_data) ? $file_data['timestamp'] : $file_data;
        $uploaded_by = is_array($file_data) && isset($file_data['uploaded_by']) ? $file_data['uploaded_by'] : 'client';

        $file_path = $user_dir . '/' . $file;
        if (!file_exists($file_path)) continue;

        $file_info = [
            'file' => $file,
            'timestamp' => $timestamp,
            'uploaded_by' => $uploaded_by
        ];

        if ($uploaded_by === 'admin') {
            $admin_files[] = $file_info;
        } else {
            $client_files[] = $file_info;
        }
    }

    // Sort both arrays by timestamp (newest first)
    usort($admin_files, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    usort($client_files, fn($a, $b) => $b['timestamp'] - $a['timestamp']);

?>
    <?php if (!empty($admin_files)): ?>
        <div style="margin-bottom:30px;">
            <h3><?php echo esc_html(__('Documents for you', 'darkstar-file-manager')); ?></h3>
            <div class="file-viewer" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;">
                <div style="text-align:left;"><strong><?php echo esc_html(__('File Name', 'darkstar-file-manager')); ?></strong></div>
                <div style="text-align:right;"><strong><?php echo esc_html(__('Date Added', 'darkstar-file-manager')); ?></strong></div>

                <?php foreach ($admin_files as $file_info):
                    $file = $file_info['file'];
                    $timestamp = $file_info['timestamp'];
                    $url = wp_nonce_url(
                        add_query_arg(['dsfm_download' => $file], home_url()),
                        'dsfm_download_' . $file,
                        'dsfm_download_nonce'
                    );
                    $display_name = preg_replace('/^\d+-/', '', $file);
                ?>
                    <div style="text-align:left;"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($display_name); ?></a></div>
                    <div style="text-align:right;"><?php echo esc_html(gmdate('M j Y', $timestamp)); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <h3><?php echo esc_html(__('Your Uploaded Documents', 'darkstar-file-manager')); ?></h3>
    <form method="post" enctype="multipart/form-data" class="dsfm-upload-form">
        <label for="dsfm_file" class="dsfm-upload-label"><?php echo esc_html(__('Select a file to upload', 'darkstar-file-manager')); ?></label>
        <input type="file" name="dsfm_file" id="dsfm_file" required class="dsfm-upload-input" />
        <button type="submit" class="dsfm-upload-button"><?php echo esc_html(__('Upload File', 'darkstar-file-manager')); ?></button>
        <?php wp_nonce_field('dsfm_upload_file', 'dsfm_upload_nonce'); ?>
    </form>

    <div class="file-viewer" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;margin-top:20px;">
        <div style="text-align:left;"><strong><?php echo esc_html(__('File Name', 'darkstar-file-manager')); ?></strong></div>
        <div style="text-align:right;"><strong><?php echo esc_html(__('Date Added', 'darkstar-file-manager')); ?></strong></div>
        <div style="text-align:right;"><strong><?php echo esc_html(__('Delete', 'darkstar-file-manager')); ?></strong></div>

        <?php foreach ($client_files as $file_info):
            $file = $file_info['file'];
            $timestamp = $file_info['timestamp'];
            $url = wp_nonce_url(
                add_query_arg(['dsfm_download' => $file], home_url()),
                'dsfm_download_' . $file,
                'dsfm_download_nonce'
            );
            $display_name = preg_replace('/^\d+-/', '', $file);
        ?>
            <div style="text-align:left;"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($display_name); ?></a></div>
            <div style="text-align:right;"><?php echo esc_html(gmdate('M j Y', $timestamp)); ?></div>
            <div style="text-align:right;">
                <form method="post" style="margin:0;">
                    <input type="hidden" name="dsfm_delete_file" value="<?php echo esc_attr($file); ?>">
                    <?php wp_nonce_field('dsfm_delete_file', 'dsfm_delete_nonce'); ?>
                    <?php /* translators: %s is the filename */ ?>
                    <button type="submit" onclick="return confirm('<?php echo esc_js(__('Delete this file?', 'darkstar-file-manager')); ?>');" aria-label="<?php echo esc_attr(sprintf(__('Delete %s', 'darkstar-file-manager'), $display_name)); ?>">&times;</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
<?php

    return ob_get_clean();
});

/**
 * Handle file downloads for logged-in clients
 */
add_action('init', function () {
    if (!is_user_logged_in() || empty($_GET['dsfm_download'])) {
        return;
    }

    $filename = basename(sanitize_file_name(wp_unslash($_GET['dsfm_download'])));

    if (!isset($_GET['dsfm_download_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['dsfm_download_nonce'])), 'dsfm_download_' . $filename)) {
        wp_die(esc_html__('Security check failed.', 'darkstar-file-manager'));
    }

    $user = wp_get_current_user();
    $username = sanitize_file_name($user->user_login);
    $user_dir = trailingslashit(DSFM_UPLOAD_ROOT) . $username;
    $file_path = $user_dir . '/' . $filename;

    // Security check: ensure file exists and belongs to this user
    $real_path = realpath($file_path);
    $real_user_dir = realpath($user_dir);

    if ($real_path && $real_user_dir && strpos($real_path, rtrim($real_user_dir, '/') . '/') === 0 && file_exists($real_path)) {
        // Get the original filename without timestamp
        $display_name = preg_replace('/^\d+-/', '', $filename);

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace( [ '"', "\r", "\n" ], '', $display_name ) . '"');
        header('Content-Length: ' . filesize($real_path));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming large files; WP_Filesystem::get_contents() loads entire file into memory
        readfile($real_path);
        exit;
    }
});
