<?php
/**
 * Admin Settings Page
 *
 * Displays plugin settings using WordPress Settings API with comprehensive options
 * for PDF generation, storage management, and auto-cleanup features.
 *
 * @package CF7_Working_PDF_Generator
 * @since 4.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Verify user capabilities
if (!current_user_can('manage_options')) {
    wp_die(
        esc_html__('You do not have sufficient permissions to access this page.', 'cf7-working-pdf'),
        esc_html__('Permission Denied', 'cf7-working-pdf'),
        array('response' => 403)
    );
}

// Get current settings
$settings = CF7_Working_PDF_Generator::get_instance()->get_settings();

// Get storage statistics
$upload_dir = wp_upload_dir();
$images_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/images/';
$disk_usage = 0;
$file_count = 0;

if (is_dir($images_dir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($images_dir));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $disk_usage += $file->getSize();
            $file_count++;
        }
    }
}

// Get next scheduled cleanup
$next_cleanup = wp_next_scheduled('cf7_working_pdf_auto_cleanup');
?>

<div class="wrap cf7-working-pdf-settings">
    <h1><?php esc_html_e('Contact Form 7 PDF Generator Settings', 'cf7-working-pdf'); ?></h1>

    <?php settings_errors('cf7_working_pdf_settings'); ?>

    <!-- Storage Overview (outside the form) -->
    <div class="cf7-settings-card cf7-storage-overview">
        <h2><span class="dashicons dashicons-database"></span> <?php esc_html_e('Storage Overview', 'cf7-working-pdf'); ?></h2>
        <div class="storage-stats">
            <div class="storage-stat">
                <span class="stat-value"><?php echo esc_html(size_format($disk_usage, 2)); ?></span>
                <span class="stat-label"><?php esc_html_e('Disk Usage', 'cf7-working-pdf'); ?></span>
            </div>
            <div class="storage-stat">
                <span class="stat-value"><?php echo esc_html(number_format_i18n($file_count)); ?></span>
                <span class="stat-label"><?php esc_html_e('Image Files', 'cf7-working-pdf'); ?></span>
            </div>
            <div class="storage-stat">
                <span class="stat-value">
                    <?php
                    if ($next_cleanup && $settings['auto_delete_enabled']) {
                        echo esc_html(human_time_diff($next_cleanup, time()));
                    } else {
                        esc_html_e('Disabled', 'cf7-working-pdf');
                    }
                    ?>
                </span>
                <span class="stat-label"><?php esc_html_e('Next Cleanup', 'cf7-working-pdf'); ?></span>
            </div>
        </div>
        <?php if ($settings['auto_delete_enabled']): ?>
            <button type="button" id="run-cleanup-now" class="button button-secondary">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('Run Cleanup Now', 'cf7-working-pdf'); ?>
            </button>
            <span id="cleanup-result" style="margin-left: 10px;"></span>
        <?php endif; ?>
    </div>

    <form method="post" action="options.php">
        <?php
        // Output security fields for the registered setting group
        settings_fields('cf7_working_pdf_settings_group');

        // Output setting sections and their fields
        do_settings_sections('cf7-working-pdf-settings');

        // Output save settings button
        submit_button(__('Save Settings', 'cf7-working-pdf'));
        ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Initialize color picker
    if ($.fn.wpColorPicker) {
        $('.color-picker').wpColorPicker();
    }

    // Image quality slider
    $('#image_quality').on('input', function() {
        $('#image_quality_value').text($(this).val() + '%');
    });

    // Auto-delete toggle
    $('#auto_delete_enabled').on('change', function() {
        var isEnabled = $(this).is(':checked');
        $('#auto_delete_days, #delete_images_only').closest('tr').css('opacity', isEnabled ? '1' : '0.5');
        $('#auto_delete_days, #delete_images_only').prop('disabled', !isEnabled);
    });

    // Initialize auto-delete state on page load
    (function() {
        var isEnabled = $('#auto_delete_enabled').is(':checked');
        $('#auto_delete_days, #delete_images_only').closest('tr').css('opacity', isEnabled ? '1' : '0.5');
        if (!isEnabled) {
            $('#auto_delete_days, #delete_images_only').prop('disabled', true);
        }
    })();

    // Run cleanup now button
    $('#run-cleanup-now').on('click', function() {
        var $btn = $(this);
        var $result = $('#cleanup-result');

        if (!confirm(cf7_working_pdf_ajax.confirm_cleanup)) {
            return;
        }

        $btn.prop('disabled', true).text('<?php echo esc_js(__('Running...', 'cf7-working-pdf')); ?>');
        $result.text('');

        $.ajax({
            url: cf7_working_pdf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cf7_working_pdf_cleanup_old_data',
                nonce: cf7_working_pdf_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.css('color', 'green').text(response.data.message);
                    // Refresh page after 2 seconds to update stats
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $result.css('color', 'red').text(response.data || '<?php echo esc_js(__('Error running cleanup.', 'cf7-working-pdf')); ?>');
                }
            },
            error: function() {
                $result.css('color', 'red').text('<?php echo esc_js(__('Error running cleanup.', 'cf7-working-pdf')); ?>');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> <?php echo esc_js(__('Run Cleanup Now', 'cf7-working-pdf')); ?>');
            }
        });
    });
});
</script>
