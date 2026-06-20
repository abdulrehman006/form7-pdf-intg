<?php
/**
 * Admin Submissions Page
 *
 * Displays and manages form submissions with search, filtering, and bulk actions.
 *
 * @package CF7_Working_PDF_Generator
 * @since 4.0.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// CRITICAL FIX #2: Add capability check
if (!current_user_can('manage_options')) {
    wp_die(
        esc_html__('You do not have sufficient permissions to access this page.', 'cf7-working-pdf'),
        esc_html__('Permission Denied', 'cf7-working-pdf'),
        array('response' => 403)
    );
}

global $wpdb;
$submissions_table = $wpdb->prefix . 'cf7_working_pdf_submissions';
$images_table = $wpdb->prefix . 'cf7_working_pdf_images';
$email_status_table = $wpdb->prefix . 'cf7_working_pdf_email_status';

/**
 * Helper function to delete submission and all related data (cascade delete)
 * CRITICAL FIX #6: Properly delete all related data
 *
 * @param int $submission_id The submission ID to delete
 * @return bool True on success, false on failure
 */
if (!function_exists('cf7_working_pdf_delete_submission_cascade')) {
function cf7_working_pdf_delete_submission_cascade($submission_id) {
    global $wpdb;
    $images_table = $wpdb->prefix . 'cf7_working_pdf_images';
    $email_status_table = $wpdb->prefix . 'cf7_working_pdf_email_status';
    $submissions_table = $wpdb->prefix . 'cf7_working_pdf_submissions';

    $submission_id = absint($submission_id);
    if (!$submission_id) {
        return false;
    }

    // Delete physical image files first
    $images = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT file_path FROM {$images_table} WHERE submission_id = %d",
            $submission_id
        )
    );

    foreach ($images as $image) {
        if (!empty($image->file_path) && file_exists($image->file_path) && is_writable($image->file_path)) {
            unlink($image->file_path);
        }
    }

    // Delete image records
    $wpdb->delete($images_table, array('submission_id' => $submission_id), array('%d'));

    // Delete email status records (CRITICAL FIX #6)
    $wpdb->delete($email_status_table, array('submission_id' => $submission_id), array('%d'));

    // Delete submission record
    $result = $wpdb->delete($submissions_table, array('id' => $submission_id), array('%d'));

    return $result !== false;
}
}

// Handle bulk actions
$bulk_action_message = '';
$bulk_action_type = '';

if (isset($_POST['action']) || isset($_POST['action2'])) {
    // Check which action selector was used
    $action = '';
    if (isset($_POST['action']) && $_POST['action'] !== '-1') {
        $action = sanitize_text_field($_POST['action']);
    } elseif (isset($_POST['action2']) && $_POST['action2'] !== '-1') {
        $action = sanitize_text_field($_POST['action2']);
    }

    if ($action === 'bulk_delete' && !empty($_POST['submissions'])) {
        // Verify nonce
        if (!check_admin_referer('cf7_working_pdf_bulk_action')) {
            wp_die(esc_html__('Security check failed.', 'cf7-working-pdf'));
        }

        $submission_ids = array_map('absint', $_POST['submissions']);
        $deleted_count = 0;

        foreach ($submission_ids as $submission_id) {
            if (cf7_working_pdf_delete_submission_cascade($submission_id)) {
                $deleted_count++;
            }
        }

        if ($deleted_count > 0) {
            $bulk_action_message = sprintf(
                /* translators: %d: number of deleted submissions */
                _n(
                    '%d submission and all related data deleted successfully.',
                    '%d submissions and all related data deleted successfully.',
                    $deleted_count,
                    'cf7-working-pdf'
                ),
                $deleted_count
            );
            $bulk_action_type = 'success';
        } else {
            $bulk_action_message = __('No submissions were deleted.', 'cf7-working-pdf');
            $bulk_action_type = 'warning';
        }
    }

    // Handle delete by date range
    if ($action === 'delete_by_date' && !empty($_POST['delete_start_date']) && !empty($_POST['delete_end_date'])) {
        if (!check_admin_referer('cf7_working_pdf_bulk_action')) {
            wp_die(esc_html__('Security check failed.', 'cf7-working-pdf'));
        }

        $delete_start = sanitize_text_field($_POST['delete_start_date']);
        $delete_end = sanitize_text_field($_POST['delete_end_date']);

        // Validate date format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $delete_start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delete_end)) {
            // Get submissions in date range - CRITICAL FIX #1: Use prepared statement properly
            $submissions_to_delete = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$submissions_table} WHERE DATE(submission_date) BETWEEN %s AND %s",
                    $delete_start,
                    $delete_end
                )
            );

            $deleted_count = 0;
            foreach ($submissions_to_delete as $submission_id) {
                if (cf7_working_pdf_delete_submission_cascade($submission_id)) {
                    $deleted_count++;
                }
            }

            if ($deleted_count > 0) {
                $bulk_action_message = sprintf(
                    /* translators: %1$d: number of submissions, %2$s: start date, %3$s: end date */
                    __('%1$d submissions from %2$s to %3$s deleted successfully with all related data.', 'cf7-working-pdf'),
                    $deleted_count,
                    esc_html($delete_start),
                    esc_html($delete_end)
                );
                $bulk_action_type = 'success';
            } else {
                $bulk_action_message = __('No submissions found in the specified date range.', 'cf7-working-pdf');
                $bulk_action_type = 'warning';
            }
        } else {
            $bulk_action_message = __('Invalid date format. Please use YYYY-MM-DD format.', 'cf7-working-pdf');
            $bulk_action_type = 'error';
        }
    }
}

// Pagination
$current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($current_page - 1) * $per_page;

// Search and Date Filter
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$date_filter = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : '';
$start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

// CRITICAL FIX #1: Build WHERE clause safely with proper prepared statements
$where_conditions = array();
$where_values = array();

if (!empty($search)) {
    $search_like = '%' . $wpdb->esc_like($search) . '%';
    $where_conditions[] = "(form_title LIKE %s OR form_data_json LIKE %s)";
    $where_values[] = $search_like;
    $where_values[] = $search_like;
}

if (!empty($date_filter)) {
    switch ($date_filter) {
        case 'last_week':
            $where_conditions[] = "submission_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'last_month':
            $where_conditions[] = "submission_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'custom':
            if (!empty($start_date) && !empty($end_date)) {
                // Validate date format
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                    $where_conditions[] = "DATE(submission_date) BETWEEN %s AND %s";
                    $where_values[] = $start_date;
                    $where_values[] = $end_date;
                }
            }
            break;
    }
}

// Build the WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = ' WHERE ' . implode(' AND ', $where_conditions);
}

// Get total count - CRITICAL FIX #1: Use prepared statement for count query
if (!empty($where_values)) {
    $count_query = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$submissions_table}" . $where_clause,
        $where_values
    );
    $total_items = $wpdb->get_var($count_query);
} else {
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$submissions_table}" . $where_clause);
}

// Get submissions - CRITICAL FIX #1: Use prepared statement for data query
$query_values = array_merge($where_values, array($per_page, $offset));
$submissions_query = "SELECT * FROM {$submissions_table}" . $where_clause . " ORDER BY submission_date DESC LIMIT %d OFFSET %d";
$submissions = $wpdb->get_results($wpdb->prepare($submissions_query, $query_values));

// Calculate pagination
$total_pages = ceil($total_items / $per_page);

// Get statistics
$stats = array(
    'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$submissions_table}"),
    'today' => $wpdb->get_var("SELECT COUNT(*) FROM {$submissions_table} WHERE DATE(submission_date) = CURDATE()"),
    'week' => $wpdb->get_var("SELECT COUNT(*) FROM {$submissions_table} WHERE submission_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
    'month' => $wpdb->get_var("SELECT COUNT(*) FROM {$submissions_table} WHERE submission_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)")
);

// Get disk usage for images
$upload_dir = wp_upload_dir();
$images_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/images/';
$disk_usage = 0;
if (is_dir($images_dir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($images_dir));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $disk_usage += $file->getSize();
        }
    }
}
$disk_usage_formatted = size_format($disk_usage, 2);
?>

<div class="wrap cf7-working-submissions-admin">
    <h1 class="wp-heading-inline">
        <?php esc_html_e('Contact Form Submissions', 'cf7-working-pdf'); ?>
        <span class="count">(<?php echo esc_html($total_items); ?>)</span>
    </h1>

    <?php if (!empty($bulk_action_message)): ?>
        <div class="notice notice-<?php echo esc_attr($bulk_action_type); ?> is-dismissible">
            <p><?php echo esc_html($bulk_action_message); ?></p>
        </div>
    <?php endif; ?>

    <div class="cf7-submissions-header">
        <div class="cf7-submissions-stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo esc_html($stats['total']); ?></div>
                <div class="stat-label"><?php esc_html_e('Total Submissions', 'cf7-working-pdf'); ?></div>
            </div>

            <div class="stat-box">
                <div class="stat-number"><?php echo esc_html($stats['today']); ?></div>
                <div class="stat-label"><?php esc_html_e('Today', 'cf7-working-pdf'); ?></div>
            </div>

            <div class="stat-box">
                <div class="stat-number"><?php echo esc_html($stats['week']); ?></div>
                <div class="stat-label"><?php esc_html_e('This Week', 'cf7-working-pdf'); ?></div>
            </div>

            <div class="stat-box">
                <div class="stat-number"><?php echo esc_html($stats['month']); ?></div>
                <div class="stat-label"><?php esc_html_e('This Month', 'cf7-working-pdf'); ?></div>
            </div>

            <div class="stat-box stat-box-storage">
                <div class="stat-number"><?php echo esc_html($disk_usage_formatted); ?></div>
                <div class="stat-label"><?php esc_html_e('Storage Used', 'cf7-working-pdf'); ?></div>
            </div>
        </div>
    </div>

    <div class="cf7-submissions-filters">
        <form method="get" class="search-form">
            <input type="hidden" name="page" value="cf7-working-pdf-submissions" />
            <div class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search submissions...', 'cf7-working-pdf'); ?>" />
                <button type="submit" class="button"><?php esc_html_e('Search', 'cf7-working-pdf'); ?></button>
                <?php if ($search || $date_filter): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cf7-working-pdf-submissions')); ?>" class="button"><?php esc_html_e('Clear', 'cf7-working-pdf'); ?></a>
                <?php endif; ?>
            </div>
            <div class="date-filter-box">
                <select name="date_filter" id="date-filter">
                    <option value=""><?php esc_html_e('All Dates', 'cf7-working-pdf'); ?></option>
                    <option value="last_week" <?php selected($date_filter, 'last_week'); ?>><?php esc_html_e('Last Week', 'cf7-working-pdf'); ?></option>
                    <option value="last_month" <?php selected($date_filter, 'last_month'); ?>><?php esc_html_e('Last Month', 'cf7-working-pdf'); ?></option>
                    <option value="custom" <?php selected($date_filter, 'custom'); ?>><?php esc_html_e('Custom Date', 'cf7-working-pdf'); ?></option>
                </select>
                <div class="custom-date-range" style="display: <?php echo $date_filter === 'custom' ? 'inline-flex' : 'none'; ?>;">
                    <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>" />
                    <span class="date-separator"><?php esc_html_e('to', 'cf7-working-pdf'); ?></span>
                    <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>" />
                </div>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'cf7-working-pdf'); ?></button>
            </div>
        </form>
    </div>

    <!-- Delete by Date Range Section -->
    <div class="cf7-delete-by-date-section">
        <details class="cf7-delete-accordion">
            <summary class="cf7-delete-accordion-header">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('Delete Submissions by Date Range', 'cf7-working-pdf'); ?>
            </summary>
            <div class="cf7-delete-accordion-content">
                <form method="post" id="delete-by-date-form">
                    <?php wp_nonce_field('cf7_working_pdf_bulk_action'); ?>
                    <input type="hidden" name="action" value="delete_by_date" />
                    <div class="delete-date-inputs">
                        <label>
                            <?php esc_html_e('From:', 'cf7-working-pdf'); ?>
                            <input type="date" name="delete_start_date" required />
                        </label>
                        <label>
                            <?php esc_html_e('To:', 'cf7-working-pdf'); ?>
                            <input type="date" name="delete_end_date" required />
                        </label>
                        <button type="submit" class="button button-delete" onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete all submissions in this date range? This will also delete all associated images and cannot be undone.', 'cf7-working-pdf'); ?>');">
                            <span class="dashicons dashicons-trash"></span>
                            <?php esc_html_e('Delete All in Range', 'cf7-working-pdf'); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php esc_html_e('Warning: This will permanently delete all submissions within the selected date range, including all associated images and email records.', 'cf7-working-pdf'); ?>
                    </p>
                </form>
            </div>
        </details>
    </div>

    <form method="post" id="submissions-filter">
        <?php wp_nonce_field('cf7_working_pdf_bulk_action'); ?>

        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select name="action" id="bulk-action-selector-top">
                    <option value="-1"><?php esc_html_e('Bulk Actions', 'cf7-working-pdf'); ?></option>
                    <option value="bulk_delete"><?php esc_html_e('Delete Selected', 'cf7-working-pdf'); ?></option>
                </select>
                <input type="submit" id="doaction" class="button action" value="<?php esc_attr_e('Apply', 'cf7-working-pdf'); ?>" />
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    printf(
                        /* translators: %s: number of items */
                        esc_html(_n('%s item', '%s items', $total_items, 'cf7-working-pdf')),
                        esc_html(number_format_i18n($total_items))
                    );
                    ?>
                </span>
                <?php
                $page_links = paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $current_page
                ));

                if ($page_links) {
                    echo '<span class="pagination-links">' . wp_kses_post($page_links) . '</span>';
                }
                ?>
            </div>
            <?php endif; ?>
        </div>

        <table class="wp-list-table widefat fixed striped submissions">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1" />
                    </td>
                    <th class="manage-column column-form"><?php esc_html_e('Form', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-data"><?php esc_html_e('Submission Data', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-date"><?php esc_html_e('Date', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-actions"><?php esc_html_e('Actions', 'cf7-working-pdf'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr class="no-items">
                        <td colspan="5">
                            <div class="cf7-no-submissions">
                                <div class="cf7-no-submissions-icon">📄</div>
                                <h3><?php esc_html_e('No submissions found', 'cf7-working-pdf'); ?></h3>
                                <p><?php esc_html_e('When someone submits a contact form, it will appear here.', 'cf7-working-pdf'); ?></p>
                                <?php if ($search || $date_filter): ?>
                                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=cf7-working-pdf-submissions')); ?>" class="button"><?php esc_html_e('View All Submissions', 'cf7-working-pdf'); ?></a></p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $submission):
                        $form_data = json_decode($submission->form_data_json, true);
                        if (!is_array($form_data)) {
                            $form_data = array();
                        }
                        $preview_data = array_slice($form_data, 0, 3, true);
                    ?>
                        <tr data-submission-id="<?php echo esc_attr($submission->id); ?>">
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="submissions[]" value="<?php echo esc_attr($submission->id); ?>" />
                            </th>

                            <td class="column-form">
                                <div class="form-info">
                                    <strong><?php echo esc_html($submission->form_title); ?></strong>
                                    <div class="form-meta">
                                        <span class="form-id"><?php esc_html_e('ID:', 'cf7-working-pdf'); ?> <?php echo esc_html($submission->form_id); ?></span>
                                    </div>
                                </div>
                            </td>

                            <td class="column-data">
                                <div class="submission-preview">
                                    <?php foreach ($preview_data as $key => $value):
                                        $clean_value = is_array($value) ? implode(', ', $value) : $value;
                                        $clean_value = wp_strip_all_tags($clean_value);
                                        $clean_value = html_entity_decode($clean_value, ENT_QUOTES, 'UTF-8');
                                        // Normalize smart quotes to straight quotes (fixes â€™ display issue)
                                        $clean_value = CF7_Working_PDF_Generator::normalize_quotes($clean_value);

                                        // Check if this is a file attachment
                                        $is_attachment = false;
                                        if (preg_match('/\.(jpg|jpeg|png|gif|pdf|doc|docx|txt|zip|rar)$/i', $clean_value)) {
                                            $is_attachment = true;
                                            $clean_value = '📎 ' . basename($clean_value);
                                        }

                                        $clean_value = strlen($clean_value) > 50 ? substr($clean_value, 0, 50) . '...' : $clean_value;
                                    ?>
                                        <div class="field-preview">
                                            <span class="field-label"><?php echo esc_html(ucwords(str_replace(array('-', '_'), ' ', $key))); ?>:</span>
                                            <span class="field-value <?php echo $is_attachment ? 'attachment-file' : ''; ?>"><?php echo esc_html($clean_value); ?></span>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if (count($form_data) > 3): ?>
                                        <div class="more-fields">
                                            <span class="fields-count">+<?php echo esc_html(count($form_data) - 3); ?> <?php esc_html_e('more fields', 'cf7-working-pdf'); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <button type="button" class="button-link view-full-submission" data-submission-id="<?php echo esc_attr($submission->id); ?>">
                                        <?php esc_html_e('View Full Submission', 'cf7-working-pdf'); ?>
                                    </button>
                                </div>
                            </td>

                            <td class="column-date">
                                <div class="submission-date">
                                    <?php $submission_timestamp = strtotime($submission->submission_date); ?>
                                    <strong><?php echo esc_html(wp_date('M j, Y', $submission_timestamp)); ?></strong>
                                    <div class="submission-time"><?php echo esc_html(wp_date('g:i A', $submission_timestamp)); ?></div>
                                    <div class="time-ago"><?php echo esc_html(human_time_diff($submission_timestamp, current_time('timestamp'))); ?> <?php esc_html_e('ago', 'cf7-working-pdf'); ?></div>
                                </div>
                            </td>

                            <td class="column-actions">
                                <div class="row-actions">
                                    <span class="view">
                                        <button type="button" class="button-link view-submission" data-submission-id="<?php echo esc_attr($submission->id); ?>">
                                            <span class="dashicons dashicons-visibility"></span>
                                            <?php esc_html_e('View', 'cf7-working-pdf'); ?>
                                        </button>
                                    </span>

                                    <span class="download">
                                        <button type="button" class="button-link download-pdf" data-submission-id="<?php echo esc_attr($submission->id); ?>">
                                            <span class="dashicons dashicons-pdf"></span>
                                            <?php esc_html_e('PDF', 'cf7-working-pdf'); ?>
                                        </button>
                                    </span>

                                    <span class="delete">
                                        <button type="button" class="button-link delete-submission" data-submission-id="<?php echo esc_attr($submission->id); ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                            <?php esc_html_e('Delete', 'cf7-working-pdf'); ?>
                                        </button>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-2" />
                    </td>
                    <th class="manage-column column-form"><?php esc_html_e('Form', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-data"><?php esc_html_e('Submission Data', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-date"><?php esc_html_e('Date', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-actions"><?php esc_html_e('Actions', 'cf7-working-pdf'); ?></th>
                </tr>
            </tfoot>
        </table>

        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select name="action2" id="bulk-action-selector-bottom">
                    <option value="-1"><?php esc_html_e('Bulk Actions', 'cf7-working-pdf'); ?></option>
                    <option value="bulk_delete"><?php esc_html_e('Delete Selected', 'cf7-working-pdf'); ?></option>
                </select>
                <input type="submit" id="doaction2" class="button action" value="<?php esc_attr_e('Apply', 'cf7-working-pdf'); ?>" />
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    printf(
                        esc_html(_n('%s item', '%s items', $total_items, 'cf7-working-pdf')),
                        esc_html(number_format_i18n($total_items))
                    );
                    ?>
                </span>
                <?php
                if ($page_links) {
                    echo '<span class="pagination-links">' . wp_kses_post($page_links) . '</span>';
                }
                ?>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Submission Details Modal -->
<div id="submission-modal" class="cf7-modal" style="display: none;">
    <div class="cf7-modal-content">
        <div class="cf7-modal-header">
            <h3><?php esc_html_e('Submission Details', 'cf7-working-pdf'); ?></h3>
            <button type="button" class="cf7-modal-close" aria-label="<?php esc_attr_e('Close', 'cf7-working-pdf'); ?>">&times;</button>
        </div>
        <div class="cf7-modal-body">
            <div id="submission-details">
                <div class="cf7-loading">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e('Loading...', 'cf7-working-pdf'); ?>
                </div>
            </div>
        </div>
        <div class="cf7-modal-footer">
            <button type="button" class="button cf7-modal-close"><?php esc_html_e('Close', 'cf7-working-pdf'); ?></button>
            <button type="button" class="button button-primary" id="modal-download-pdf"><?php esc_html_e('Download PDF', 'cf7-working-pdf'); ?></button>
        </div>
    </div>
</div>
