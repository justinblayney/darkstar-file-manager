<?php
defined('ABSPATH') || exit;

add_action('admin_enqueue_scripts', function ($hook_suffix) {
    if ('admin_page_dsfm-view-user-docs' !== $hook_suffix) {
        return;
    }
    wp_enqueue_script(
        'dsfm-admin',
        plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js',
        [],
        '1.0.0',
        true
    );
});

// Add "View Documents" link to user row actions for admins
add_filter('user_row_actions', 'dsfm_add_user_docs_link', 10, 2);
function dsfm_add_user_docs_link($actions, $user)
{
    if (!current_user_can('manage_options')) return $actions;

    $url = add_query_arg([
        'page' => 'dsfm-view-user-docs',
        'user_id' => $user->ID
    ], admin_url('users.php'));

    $actions['view_docs'] = '<a href="' . esc_url($url) . '">' . esc_html(__('View Documents', 'darkstar-file-manager')) . '</a>';
    return $actions;
}


// Register under users.php so WordPress can always resolve the page title.
add_action('admin_menu', function () {
    add_submenu_page(
        'users.php',
        __('User Documents', 'darkstar-file-manager'),
        __('User Documents', 'darkstar-file-manager'),
        'manage_options',
        'dsfm-view-user-docs',
        'dsfm_render_user_docs_page'
    );
});

// Hide the menu item via CSS — keeps the page registered for title resolution.
add_action('admin_head', function () {
    echo '<style>#adminmenu a[href="users.php?page=dsfm-view-user-docs"] { display: none !important; }</style>';
});


function dsfm_render_user_docs_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Access denied.', 'darkstar-file-manager'));
    }

    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    if (!$user_id) {
        echo '<div class="wrap"><h1>' . esc_html(__('No user selected.', 'darkstar-file-manager')) . '</h1></div>';
        return;
    }

    $user_info = get_userdata($user_id);
    if (!$user_info) {
        echo '<div class="wrap"><h1>' . esc_html(__('User not found.', 'darkstar-file-manager')) . '</h1></div>';
        return;
    }

    $username = sanitize_file_name($user_info->user_login);
    $user_dir = trailingslashit(DSFM_UPLOAD_ROOT) . $username;
    $meta_file = $user_dir . '/file-metadata.json';

    // Handle individual file deletion
    if (isset($_POST['dsfm_admin_delete_file']) && isset($_POST['dsfm_admin_delete_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dsfm_admin_delete_nonce'])), 'dsfm_admin_delete_file')) {
        $file_to_delete = basename(sanitize_file_name(wp_unslash($_POST['dsfm_admin_delete_file'])));
        $resolved_path = realpath($user_dir . '/' . $file_to_delete);
        if ($resolved_path && strpos($resolved_path, $user_dir) === 0 && file_exists($resolved_path)) {
            wp_delete_file($resolved_path);
            $metadata = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
            if (isset($metadata[$file_to_delete])) {
                unset($metadata[$file_to_delete]);
                file_put_contents($meta_file, json_encode($metadata), LOCK_EX);
            }
            echo '<div class="updated"><p>' . esc_html(__('File deleted successfully.', 'darkstar-file-manager')) . '</p></div>';
        }
    }

    // Handle bulk deletion
    if (isset($_POST['dsfm_bulk_delete']) && isset($_POST['dsfm_files']) && is_array($_POST['dsfm_files']) && isset($_POST['dsfm_bulk_delete_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dsfm_bulk_delete_nonce'])), 'dsfm_bulk_delete')) {
        $metadata = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
        $deleted_count = 0;

        foreach (array_map('sanitize_file_name', wp_unslash($_POST['dsfm_files'])) as $file) {
            $file = basename($file);
            $resolved_path = realpath($user_dir . '/' . $file);

            if ($resolved_path && strpos($resolved_path, $user_dir) === 0 && file_exists($resolved_path)) {
                wp_delete_file($resolved_path);
                if (isset($metadata[$file])) {
                    unset($metadata[$file]);
                }
                $deleted_count++;
            }
        }

        if ($deleted_count > 0) {
            file_put_contents($meta_file, json_encode($metadata), LOCK_EX);
            /* translators: %d is the number of files deleted */
            echo '<div class="updated"><p>' . esc_html(sprintf(__('%d file(s) deleted successfully.', 'darkstar-file-manager'), $deleted_count)) . '</p></div>';
        }
    }

    // Handle admin file upload
    if (isset($_POST['dsfm_admin_upload']) && isset($_FILES['dsfm_admin_file']) && isset($_POST['dsfm_admin_upload_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dsfm_admin_upload_nonce'])), 'dsfm_admin_upload_file')) {
        if (!empty($_FILES['dsfm_admin_file']['name']) && isset($_FILES['dsfm_admin_file']['tmp_name'])) {
            $rate_key     = 'dsfm_uploads_' . get_current_user_id() . '_' . floor(time() / 3600);
            $upload_count = (int) get_transient($rate_key);
            if ($upload_count >= DSFM_MAX_UPLOADS_PER_HOUR) {
                echo '<div class="error"><p>' . esc_html(__('Upload rate limit exceeded. Please wait before uploading more files.', 'darkstar-file-manager')) . '</p></div>';
            } else {
                // Sanitize user-supplied file name and validate
                $file_input = [
                    'name'     => sanitize_file_name( wp_unslash( $_FILES['dsfm_admin_file']['name'] ) ),
                    'type'     => isset( $_FILES['dsfm_admin_file']['type'] ) ? sanitize_mime_type( wp_unslash( $_FILES['dsfm_admin_file']['type'] ) ) : '',
                    'tmp_name' => $_FILES['dsfm_admin_file']['tmp_name'], // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- server-generated temp path
                    'error'    => isset( $_FILES['dsfm_admin_file']['error'] ) ? (int) $_FILES['dsfm_admin_file']['error'] : UPLOAD_ERR_NO_FILE,
                    'size'     => isset( $_FILES['dsfm_admin_file']['size'] ) ? (int) $_FILES['dsfm_admin_file']['size'] : 0,
                ];
                $validation = dsfm_validate_upload( $file_input );
                if (!$validation['valid']) {
                    echo '<div class="error"><p>' . esc_html($validation['error']) . '</p></div>';
                } else {
                    if (!file_exists($user_dir)) {
                        wp_mkdir_p($user_dir);
                        dsfm_protect_upload_dir(DSFM_UPLOAD_ROOT);
                    }

                    $timestamp              = time();
                    $dsfm_admin_dir_filter  = function ( $dirs ) use ( $user_dir ) {
                        $dirs['path']   = $user_dir;
                        $dirs['url']    = '';
                        $dirs['subdir'] = '';
                        return $dirs;
                    };
                    add_filter( 'upload_dir', $dsfm_admin_dir_filter );

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
                    remove_filter( 'upload_dir', $dsfm_admin_dir_filter );

                    if ( isset( $uploaded['error'] ) ) {
                        echo '<div class="error"><p>' . esc_html( $uploaded['error'] ) . '</p></div>';
                    } else {
                        $safe_name            = basename( $uploaded['file'] );
                        set_transient( $rate_key, $upload_count + 1, HOUR_IN_SECONDS );
                        $metadata             = file_exists( $meta_file ) ? json_decode( file_get_contents( $meta_file ), true ) : [];
                        $metadata[$safe_name] = [
                            'timestamp'   => $timestamp,
                            'uploaded_by' => 'admin',
                        ];
                        file_put_contents( $meta_file, json_encode( $metadata ), LOCK_EX );
                        echo '<div class="updated"><p>' . esc_html( __( 'File uploaded successfully.', 'darkstar-file-manager' ) ) . '</p></div>';
                    }
                }
            }
        }
    }

    /* translators: %s is the client's username */
    echo '<div class="wrap"><h1>' . esc_html(sprintf(__('Documents for %s', 'darkstar-file-manager'), $user_info->user_login)) . '</h1>';

    // Admin upload form
    echo '<div style="background:#fff;padding:15px;margin:20px 0;border:1px solid #ddd;border-radius:4px;">';
    echo '<h2>' . esc_html(__('Upload Document for Client', 'darkstar-file-manager')) . '</h2>';
    echo '<form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;">';
    wp_nonce_field('dsfm_admin_upload_file', 'dsfm_admin_upload_nonce');
    echo '<input type="file" name="dsfm_admin_file" required>';
    echo '<button type="submit" name="dsfm_admin_upload" class="button button-primary">' . esc_html(__('Upload File', 'darkstar-file-manager')) . '</button>';
    echo '</form></div>';

    if (!file_exists($user_dir)) {
        echo '<p>' . esc_html(__('No documents found.', 'darkstar-file-manager')) . '</p></div>';
        return;
    }

    $metadata = file_exists($meta_file) ? json_decode(file_get_contents($meta_file), true) : [];
    if (empty($metadata)) {
        echo '<p>' . esc_html(__('No documents found.', 'darkstar-file-manager')) . '</p></div>';
        return;
    }

    echo '<form method="post">';
    wp_nonce_field('dsfm_bulk_delete', 'dsfm_bulk_delete_nonce');
    echo '<p><button type="submit" name="dsfm_bulk_delete" class="button button-secondary" onclick="return confirm(\'' . esc_js(__('Delete selected files?', 'darkstar-file-manager')) . '\');">' . esc_html(__('Delete Selected', 'darkstar-file-manager')) . '</button></p>';
    echo '<table class="widefat"><thead><tr><th style="width:30px;"><input type="checkbox" id="dsfm_select_all"></th><th>' . esc_html(__('File', 'darkstar-file-manager')) . '</th><th>' . esc_html(__('Date', 'darkstar-file-manager')) . '</th><th>' . esc_html(__('Uploaded By', 'darkstar-file-manager')) . '</th><th>' . esc_html(__('Delete', 'darkstar-file-manager')) . '</th></tr></thead><tbody>';

    foreach ($metadata as $file => $file_data) {
        // Handle both old format (timestamp only) and new format (array with timestamp and uploaded_by)
        $timestamp = is_array($file_data) ? $file_data['timestamp'] : $file_data;
        $uploaded_by = is_array($file_data) && isset($file_data['uploaded_by']) ? $file_data['uploaded_by'] : 'client';

        $download_url = wp_nonce_url(
            add_query_arg([
                'dsfm_admin_download' => $file,
                'user_id'            => $user_id,
            ], admin_url('users.php')),
            'dsfm_admin_dl_' . $file
        );

        echo '<tr>';
        echo '<td><input type="checkbox" name="dsfm_files[]" value="' . esc_attr($file) . '" class="dsfm_file_checkbox"></td>';
        echo '<td><a href="' . esc_url($download_url) . '">' . esc_html($file) . '</a></td>';
        echo '<td>' . esc_html(gmdate('M j Y', $timestamp)) . '</td>';
        echo '<td>' . esc_html(ucfirst($uploaded_by)) . '</td>';
        echo '<td><form method="post" style="display:inline;">';
        wp_nonce_field('dsfm_admin_delete_file', 'dsfm_admin_delete_nonce');
        echo '<input type="hidden" name="dsfm_admin_delete_file" value="' . esc_attr($file) . '">';
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'' . esc_js(__('Delete this file?', 'darkstar-file-manager')) . '\');" style="color:#b32d2e;">&times;</button>';
        echo '</form></td>';
        echo '</tr>';
    }

    echo '</tbody></table></form>';

    echo '</div>';
}

add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below after filename is extracted
    if (empty($_GET['dsfm_admin_download']) || empty($_GET['user_id'])) return;

    $filename = basename(sanitize_file_name(wp_unslash($_GET['dsfm_admin_download'])));

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'dsfm_admin_dl_' . $filename)) {
        wp_die(esc_html__('Security check failed.', 'darkstar-file-manager'));
    }

    $user_id   = intval($_GET['user_id']);
    $user_info = get_userdata($user_id);

    if (!$user_info) {
        wp_die(esc_html__('User not found.', 'darkstar-file-manager'));
    }

    $file = trailingslashit(DSFM_UPLOAD_ROOT) . sanitize_file_name($user_info->user_login) . "/$filename";

    if (file_exists($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace( [ '"', "\r", "\n" ], '', $filename ) . '"');
        header('Content-Length: ' . filesize($file));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming large files; WP_Filesystem::get_contents() loads entire file into memory
        readfile($file);
        exit;
    } else {
        wp_die(esc_html__('File not found.', 'darkstar-file-manager'));
    }
});
