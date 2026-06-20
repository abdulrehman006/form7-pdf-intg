<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'cf7_working_pdf_submissions';

// Handle bulk actions
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && !empty($_POST['submissions'])) {
    check_admin_referer('cf7_working_pdf_bulk_action');
    
    $submission_ids = array_map('intval', $_POST['submissions']);
    $placeholders = implode(',', array_fill(0, count($submission_ids), '%d'));
    $query = $wpdb->prepare("DELETE FROM {$table_name} WHERE id IN ({$placeholders})", $submission_ids);
    
    $deleted = $wpdb->query($query);
    
    if ($deleted !== false) {
        echo '<div class="notice notice-success"><p>' . sprintf(__('%d submissions deleted successfully.', 'cf7-working-pdf'), $deleted) . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . __('Error deleting submissions.', 'cf7-working-pdf') . '</p></div>';
    }
}

// Pagination
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Search and Date Filter
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$date_filter = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : '';
$start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

$where_clauses = array();
if ($search) {
    $where_clauses[] = $wpdb->prepare("form_title LIKE %s OR form_data_json LIKE %s", '%' . $search . '%', '%' . $search . '%');
}

if ($date_filter) {
    if ($date_filter === 'last_week') {
        $where_clauses[] = "submission_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($date_filter === 'last_month') {
        $where_clauses[] = "submission_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($date_filter === 'custom' && $start_date && $end_date) {
        $where_clauses[] = $wpdb->prepare("submission_date BETWEEN %s AND %s", $start_date, $end_date);
    }
}

$where_clause = '';
if (!empty($where_clauses)) {
    $where_clause = ' WHERE ' . implode(' AND ', $where_clauses);
}

// Get total count
$total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}" . $where_clause);

// Get submissions
$submissions = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$table_name}" . $where_clause . " ORDER BY submission_date DESC LIMIT %d OFFSET %d",
    $per_page,
    $offset
));

// Calculate pagination
$total_pages = ceil($total_items / $per_page);
?>

<div class="wrap cf7-working-submissions-admin">
    <h1 class="wp-heading-inline">
        <?php _e('Contact Form Submissions', 'cf7-working-pdf'); ?>
        <span class="count">(<?php echo $total_items; ?>)</span>
    </h1>
    
    <div class="cf7-submissions-header">
        <div class="cf7-submissions-stats">
            <div class="stat-box">
                <div class="stat-number"><?php echo $total_items; ?></div>
                <div class="stat-label"><?php _e('Total Submissions', 'cf7-working-pdf'); ?></div>
            </div>
            
            <div class="stat-box">
                <div class="stat-number"><?php echo $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE DATE(submission_date) = CURDATE()"); ?></div>
                <div class="stat-label"><?php _e('Today', 'cf7-working-pdf'); ?></div>
            </div>
            
            <div class="stat-box">
                <div class="stat-number"><?php echo $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE submission_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)"); ?></div>
                <div class="stat-label"><?php _e('This Week', 'cf7-working-pdf'); ?></div>
            </div>
            
            <div class="stat-box">
                <div class="stat-number"><?php echo $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE submission_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"); ?></div>
                <div class="stat-label"><?php _e('This Month', 'cf7-working-pdf'); ?></div>
            </div>
        </div>
    </div>
    
    <div class="cf7-submissions-filters">
        <form method="get" class="search-form">
            <input type="hidden" name="page" value="cf7-working-pdf-submissions" />
            <div class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Search submissions...', 'cf7-working-pdf'); ?>" />
                <button type="submit" class="button"><?php _e('Search', 'cf7-working-pdf'); ?></button>
                <?php if ($search || $date_filter): ?>
                    <a href="<?php echo admin_url('admin.php?page=cf7-working-pdf-submissions'); ?>" class="button"><?php _e('Clear', 'cf7-working-pdf'); ?></a>
                <?php endif; ?>
            </div>
            <div class="date-filter-box">
                <select name="date_filter" id="date-filter">
                    <option value=""><?php _e('All Dates', 'cf7-working-pdf'); ?></option>
                    <option value="last_week" <?php selected($date_filter, 'last_week'); ?>><?php _e('Last Week', 'cf7-working-pdf'); ?></option>
                    <option value="last_month" <?php selected($date_filter, 'last_month'); ?>><?php _e('Last Month', 'cf7-working-pdf'); ?></option>
                    <option value="custom" <?php selected($date_filter, 'custom'); ?>><?php _e('Custom Date', 'cf7-working-pdf'); ?></option>
                </select>
                <div class="custom-date-range" style="display: <?php echo $date_filter === 'custom' ? 'inline-block' : 'none'; ?>;">
                    <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>" placeholder="<?php _e('Start Date', 'cf7-working-pdf'); ?>" />
                    <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>" placeholder="<?php _e('End Date', 'cf7-working-pdf'); ?>" />
                </div>
                <button type="submit" class="button"><?php _e('Filter', 'cf7-working-pdf'); ?></button>
            </div>
        </form>
    </div>
    
    <form method="post" id="submissions-filter">
        <?php wp_nonce_field('cf7_working_pdf_bulk_action'); ?>
        
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <select name="action" id="bulk-action-selector-top">
                    <option value="-1"><?php _e('Bulk Actions', 'cf7-working-pdf'); ?></option>
                    <option value="bulk_delete"><?php _e('Delete', 'cf7-working-pdf'); ?></option>
                </select>
                <input type="submit" id="doaction" class="button action" value="<?php _e('Apply', 'cf7-working-pdf'); ?>" />
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="tablenav-pages">
                <span class="displaying-num"><?php printf(__('%s items', 'cf7-working-pdf'), $total_items); ?></span>
                <?php
                $page_links = paginate_links(array(
                    'base' => admin_url('admin.php?page=cf7-working-pdf-submissions&paged=%#%&s=' . urlencode($search) . '&date_filter=' . urlencode($date_filter) . '&start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date)),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $page
                ));
                
                if ($page_links) {
                    echo '<span class="pagination-links">' . $page_links . '</span>';
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
                    <th class="manage-column column-form"><?php _e('Form', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-data"><?php _e('Submission Data', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-date"><?php _e('Date', 'cf7-working-pdf'); ?></th>
                    <th class="manage-column column-actions"><?php _e('Actions', 'cf7-working-pdf'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr class="no-items">
                        <td colspan="5">
                            <div class="cf7-no-submissions">
                                <div class="cf7-no-submissions-icon">📄</div>
                                <h3><?php _e('No submissions found', 'cf7-working-pdf'); ?></h3>
                                <p><?php _e('When someone submits a contact form, it will appear here.', 'cf7-working-pdf'); ?></p>
                                <?php if ($search || $date_filter): ?>
                                    <p><a href="<?php echo admin_url('admin.php?page=cf7-working-pdf-submissions'); ?>" class="button"><?php _e('View All Submissions', 'cf7-working-pdf'); ?></a></p>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $submission): 
                        $form_data = json_decode($submission->form_data_json, true);
                        $preview_data = array_slice($form_data, 0, 3, true);
                    ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="submissions[]" value="<?php echo $submission->id; ?>" />
                            </th>
                            
                            <td class="column-form">
                                <div class="form-info">
                                    <strong><?php echo esc_html($submission->form_title); ?></strong>
                                    <div class="form-meta">
                                        <span class="form-id">ID: <?php echo $submission->form_id; ?></span>
                                        <?php if (!empty($submission->user_ip)): ?>
                                            <span class="user-ip">IP: <?php echo esc_html($submission->user_ip); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            
                            <td class="column-data">
                                <div class="submission-preview">
                                    <?php foreach ($preview_data as $key => $value): 
                                        $clean_value = is_array($value) ? implode(', ', $value) : $value;
                                        $clean_value = strip_tags($clean_value);
                                        $clean_value = html_entity_decode($clean_value, ENT_QUOTES, 'UTF-8');
                                        
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
                                            <span class="fields-count">+<?php echo count($form_data) - 3; ?> <?php _e('more fields', 'cf7-working-pdf'); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="button-link view-full-submission" data-submission-id="<?php echo $submission->id; ?>">
                                        <?php _e('View Full Submission', 'cf7-working-pdf'); ?>
                                    </button>
                                </div>
                            </td>
                            
                            <td class="column-date">
                                <div class="submission-date">
                                    <strong><?php echo mysql2date('M j, Y', $submission->submission_date); ?></strong>
                                    <div class="submission-time"><?php echo mysql2date('g:i A', $submission->submission_date); ?></div>
                                    <div class="time-ago"><?php echo human_time_diff(strtotime($submission->submission_date), current_time('timestamp')); ?> <?php _e('ago', 'cf7-working-pdf'); ?></div>
                                </div>
                            </td>
                            
                            <td class="column-actions">
                                <div class="row-actions">
                                    <span class="view">
                                        <button type="button" class="button-link view-submission" data-submission-id="<?php echo $submission->id; ?>">
                                            <span class="dashicons dashicons-visibility"></span>
                                            <?php _e('View', 'cf7-working-pdf'); ?>
                                        </button>
                                    </span>
                                    
                                    <span class="delete">
                                        <button type="button" class="button-link delete-submission" data-submission-id="<?php echo $submission->id; ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                            <?php _e('Delete', 'cf7-working-pdf'); ?>
                                        </button>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select name="action2" id="bulk-action-selector-bottom">
                    <option value="-1"><?php _e('Bulk Actions', 'cf7-working-pdf'); ?></option>
                    <option value="bulk_delete"><?php _e('Delete', 'cf7-working-pdf'); ?></option>
                </select>
                <input type="submit" id="doaction2" class="button action" value="<?php _e('Apply', 'cf7-working-pdf'); ?>" />
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="tablenav-pages">
                <span class="displaying-num"><?php printf(__('%s items', 'cf7-working-pdf'), $total_items); ?></span>
                <?php
                if ($page_links) {
                    echo '<span class="pagination-links">' . $page_links . '</span>';
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
            <h3><?php _e('Submission Details', 'cf7-working-pdf'); ?></h3>
            <button type="button" class="cf7-modal-close">&times;</button>
        </div>
        <div class="cf7-modal-body">
            <div id="submission-details"></div>
        </div>
        <div class="cf7-modal-footer">
            <button type="button" class="button cf7-modal-close"><?php _e('Close', 'cf7-working-pdf'); ?></button>
            <button type="button" class="button button-primary" id="modal-download-pdf"><?php _e('Download PDF', 'cf7-working-pdf'); ?></button>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Select all checkbox
    $('#cb-select-all-1').on('change', function() {
        $('input[name="submissions[]"]').prop('checked', $(this).prop('checked'));
    });
    
    // Individual checkboxes
    $('input[name="submissions[]"]').on('change', function() {
        var allChecked = $('input[name="submissions[]"]:checked').length === $('input[name="submissions[]"]').length;
        $('#cb-select-all-1').prop('checked', allChecked);
    });
    
    // Bulk actions
    $('#doaction, #doaction2').on('click', function(e) {
        var action = $(this).prev('select').val();
        if (action === 'bulk_delete') {
            if (!confirm(cf7_working_pdf_ajax.confirm_bulk_delete)) {
                e.preventDefault();
                return false;
            }
        }
    });
    
    // Delete individual submission
    $('.delete-submission').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(cf7_working_pdf_ajax.confirm_delete)) {
            return;
        }
        
        var submissionId = $(this).data('submission-id');
        var $row = $(this).closest('tr');
        
        $.ajax({
            url: cf7_working_pdf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cf7_working_pdf_delete_submission',
                submission_id: submissionId,
                nonce: cf7_working_pdf_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(function() {
                        $(this).remove();
                    });
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert('Error deleting submission.');
            }
        });
    });
    
    // Download PDF
    $('.download-pdf, #modal-download-pdf').on('click', function(e) {
        e.preventDefault();
        
        var submissionId = $(this).data('submission-id') || $('#submission-modal').data('submission-id');
        
        // Create a temporary form to trigger download
        var form = $('<form>', {
            'method': 'POST',
            'action': cf7_working_pdf_ajax.ajax_url
        }).append(
            $('<input>', {
                'type': 'hidden',
                'name': 'action',
                'value': 'cf7_working_pdf_download_pdf'
            }),
            $('<input>', {
                'type': 'hidden',
                'name': 'submission_id',
                'value': submissionId
            }),
            $('<input>', {
                'type': 'hidden',
                'name': 'nonce',
                'value': cf7_working_pdf_ajax.nonce
            })
        );
        
        $('body').append(form);
        form.submit();
        form.remove();
    });
    
    // View submission modal
    $('.view-submission, .view-full-submission').on('click', function(e) {
        e.preventDefault();
        
        var submissionId = $(this).data('submission-id');
        $('#submission-modal').data('submission-id', submissionId);
        
        // Load submission data
        $.ajax({
            url: cf7_working_pdf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cf7_working_pdf_get_submission',
                submission_id: submissionId,
                nonce: cf7_working_pdf_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#submission-details').html(response.data.html);
                    $('#submission-modal').show();
                } else {
                    alert(response.data);
                }
            },
            error: function() {
                alert('Error loading submission details.');
            }
        });
    });
    
    // Close modal
    $('.cf7-modal-close').on('click', function() {
        $('#submission-modal').hide();
    });
    
    // Close modal on background click
    $('#submission-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // Show/hide custom date inputs
    $('#date-filter').on('change', function() {
        if ($(this).val() === 'custom') {
            $('.custom-date-range').show();
        } else {
            $('.custom-date-range').hide();
        }
    });
});
</script>