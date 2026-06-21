<?php
/**
 * Plugin Name: Contact Form 7 Working PDF Generator
 * Plugin URI: https://github.com/cf7-pdf/working-generator
 * Description: Multi-purpose PDF generator for Contact Form 7 with image handling, email tracking, and auto-cleanup.
 * Version: 4.1.5
 * Requires at least: 5.0
 * Tested up to: 6.5
 * Requires PHP: 7.4
 * Tested PHP: 8.3
 * Author: CF7 PDF Team
 * License: GPL-2.0+
 * Text Domain: cf7-working-pdf
 * Domain Path: /languages
 *
 * @package CF7_Working_PDF_Generator
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CF7_WORKING_PDF_VERSION', '4.1.5');
define('CF7_WORKING_PDF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CF7_WORKING_PDF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CF7_WORKING_PDF_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 *
 * @since 1.0.0
 */
class CF7_Working_PDF_Generator {

    /**
     * Singleton instance
     *
     * @var CF7_Working_PDF_Generator|null
     */
    private static $instance = null;

    /**
     * Database version for migrations
     *
     * @var string
     */
    private $db_version = '1.1';

    /**
     * Default plugin settings
     *
     * @var array
     */
    private $default_settings = array(
        'enable_pdf' => true,
        'pdf_design' => 'modern',
        'header_color' => '#D0A959',
        'store_submissions' => true,
        'auto_delete_enabled' => false,
        'auto_delete_days' => 30,
        'delete_images_only' => false,
        'success_redirect_url' => '',
        'max_image_size' => 1024,
        'image_quality' => 85,
        'pdf_title' => 'Form Submission',
        'company_name' => '',
        'enable_debug_log' => false,
    );

    /**
     * Get singleton instance
     *
     * @return CF7_Working_PDF_Generator
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 20);
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize the plugin
     *
     * @return void
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('cf7-working-pdf', false, dirname(CF7_WORKING_PDF_PLUGIN_BASENAME) . '/languages');

        // Check for Contact Form 7
        if (!class_exists('WPCF7')) {
            add_action('admin_notices', array($this, 'cf7_missing_notice'));
            return;
        }

        // Include required files
        $this->include_files();

        // Register hooks
        $this->register_hooks();
    }

    /**
     * Include required files
     *
     * @return void
     */
    private function include_files() {
        $pdf_generator_file = CF7_WORKING_PDF_PLUGIN_DIR . 'includes/pdf-generator.php';

        if (!file_exists($pdf_generator_file)) {
            add_action('admin_notices', array($this, 'pdf_generator_missing_notice'));
            return;
        }

        require_once $pdf_generator_file;
    }

    /**
     * Register all hooks
     *
     * @return void
     */
    private function register_hooks() {
        // Form processing
        add_action('wpcf7_before_send_mail', array($this, 'process_form_submission'), 10, 3);

        // Database updates
        add_action('wp_loaded', array($this, 'check_database_update'), 10);

        // Admin
        add_action('admin_menu', array($this, 'add_admin_menus'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_filter('plugin_action_links_' . CF7_WORKING_PDF_PLUGIN_BASENAME, array($this, 'add_settings_link'));

        // Environment check notices
        add_action('admin_notices', array($this, 'check_environment_notices'));

        // Frontend
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));

        // AJAX handlers
        add_action('wp_ajax_cf7_working_pdf_get_submission', array($this, 'ajax_get_submission'));
        add_action('wp_ajax_cf7_working_pdf_delete_submission', array($this, 'ajax_delete_submission'));
        add_action('wp_ajax_cf7_working_pdf_delete_image', array($this, 'ajax_delete_image'));
        add_action('wp_ajax_cf7_working_pdf_download_pdf', array($this, 'ajax_download_pdf'));
        add_action('wp_ajax_cf7_working_pdf_cleanup_old_data', array($this, 'ajax_manual_cleanup'));

        // Scheduled cleanup
        add_action('cf7_working_pdf_auto_cleanup', array($this, 'run_auto_cleanup'));

        // Schedule cleanup cron if enabled
        $settings = $this->get_settings();
        if ($settings['auto_delete_enabled'] && !wp_next_scheduled('cf7_working_pdf_auto_cleanup')) {
            wp_schedule_event(time(), 'daily', 'cf7_working_pdf_auto_cleanup');
        }
    }

    /**
     * Get plugin settings with defaults
     *
     * @return array
     */
    public function get_settings() {
        $saved_settings = get_option('cf7_working_pdf_settings', array());
        return wp_parse_args($saved_settings, $this->default_settings);
    }

    /**
     * Admin notice for missing CF7
     *
     * @return void
     */
    public function cf7_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                printf(
                    /* translators: %s: Plugin name */
                    esc_html__('%s requires Contact Form 7 plugin to be installed and activated.', 'cf7-working-pdf'),
                    '<strong>Contact Form 7 Working PDF Generator</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Admin notice for missing PDF generator file
     *
     * @return void
     */
    public function pdf_generator_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php esc_html_e('Contact Form 7 Working PDF Generator: Required file pdf-generator.php is missing.', 'cf7-working-pdf'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Check environment and display admin notices for issues
     *
     * @return void
     */
    public function check_environment_notices() {
        // Only show on plugin pages
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'cf7-working-pdf') === false) {
            return;
        }

        $notices = array();

        // Check GD library
        if (!extension_loaded('gd')) {
            $notices[] = array(
                'type' => 'warning',
                'message' => __('GD library is not installed. Image optimization will be disabled. Contact your hosting provider to enable PHP GD extension.', 'cf7-working-pdf'),
            );
        }

        // Check FPDF
        $fpdf_path = CF7_WORKING_PDF_PLUGIN_DIR . 'includes/fpdf/fpdf.php';
        if (!file_exists($fpdf_path)) {
            $notices[] = array(
                'type' => 'error',
                'message' => __('FPDF library is missing. PDF generation will not work. Please reinstall the plugin.', 'cf7-working-pdf'),
            );
        }

        // Check upload directory
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/';
        if (file_exists($pdf_dir) && !is_writable($pdf_dir)) {
            $notices[] = array(
                'type' => 'error',
                'message' => sprintf(
                    /* translators: %s: directory path */
                    __('PDF upload directory is not writable: %s. Please check file permissions (755 recommended).', 'cf7-working-pdf'),
                    '<code>' . esc_html($pdf_dir) . '</code>'
                ),
            );
        }

        // Check disk space
        $free_space = @disk_free_space($upload_dir['basedir']);
        if ($free_space !== false && $free_space < 100 * 1024 * 1024) {
            $notices[] = array(
                'type' => 'warning',
                'message' => sprintf(
                    /* translators: %s: available space */
                    __('Low disk space warning: Only %s available. PDF generation may fail.', 'cf7-working-pdf'),
                    size_format($free_space)
                ),
            );
        }

        // Check memory limit
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($memory_limit < 128 * 1024 * 1024) {
            $notices[] = array(
                'type' => 'warning',
                'message' => sprintf(
                    /* translators: %s: current memory limit */
                    __('PHP memory limit is low (%s). Recommend at least 128MB for processing large images.', 'cf7-working-pdf'),
                    size_format($memory_limit)
                ),
            );
        }

        // Check for recent errors
        $last_error = get_option('cf7_working_pdf_last_error');
        if ($last_error && isset($last_error['time'])) {
            $error_time = strtotime($last_error['time']);
            // Show if error was within last 24 hours
            if ($error_time && (time() - $error_time) < 86400) {
                $notices[] = array(
                    'type' => 'error',
                    'message' => sprintf(
                        /* translators: 1: error message, 2: time ago */
                        __('Recent PDF generation error: %1$s (occurred %2$s ago)', 'cf7-working-pdf'),
                        esc_html($last_error['message']),
                        human_time_diff($error_time)
                    ),
                    'dismissible' => true,
                );
            }
        }

        // Display notices
        foreach ($notices as $notice) {
            $dismissible = isset($notice['dismissible']) && $notice['dismissible'] ? ' is-dismissible' : '';
            printf(
                '<div class="notice notice-%s%s"><p>%s</p></div>',
                esc_attr($notice['type']),
                esc_attr($dismissible),
                wp_kses($notice['message'], array('code' => array()))
            );
        }
    }

    /**
     * Process Contact Form 7 submission
     *
     * @param WPCF7_ContactForm $contact_form The contact form object
     * @param bool              $abort        Whether to abort
     * @param WPCF7_Submission  $submission   The submission object
     * @return void
     */
    public function process_form_submission($contact_form, &$abort, $submission) {
        // IMPORTANT: This plugin should NEVER prevent form submission
        // All operations are wrapped in try-catch to ensure form submission succeeds
        // even if PDF generation or image processing fails

        try {
            $settings = $this->get_settings();

            // Check if PDF generation is enabled
            if (!$settings['enable_pdf']) {
                return;
            }

            $form_id = $contact_form->id();
            $posted_data = $submission->get_posted_data();
            $uploaded_files = $submission->uploaded_files();

            // Store submission if enabled
            $submission_id = 0;
            if ($settings['store_submissions']) {
                try {
                    $this->ensure_database_tables();
                    $submission_id = $this->store_submission($contact_form, $posted_data);
                } catch (Exception $e) {
                    $this->log_error('Database error storing submission: ' . $e->getMessage());
                    // Continue without storing - don't block form submission
                }
            }

            // Process image uploads first (this is independent of PDF)
            $image_links = array();
            try {
                $image_links = $this->process_image_uploads($uploaded_files, $submission_id);
            } catch (Exception $e) {
                $this->log_error('Image processing error: ' . $e->getMessage());
                // Continue without images - don't block form submission
            }

            // Generate PDF
            $pdf_path = false;
            try {
                $data = array(
                    'form_id' => $form_id,
                    'submission_id' => $submission_id,
                    'submission_data' => $posted_data,
                    'files' => $uploaded_files,
                    'settings' => $settings,
                );

                $pdf_path = cf7_working_pdf_generate($data, 'submission-' . $submission_id . '.pdf');
            } catch (Exception $e) {
                $this->log_error('PDF generation error: ' . $e->getMessage());
                // Continue without PDF - don't block form submission
            }

            // Only attach PDF if it was generated successfully
            if ($pdf_path && file_exists($pdf_path)) {
                // Modify CF7 email to include PDF and image links
                add_filter('wpcf7_mail_components', function($components) use ($pdf_path, $image_links) {
                    try {
                        // Attach PDF
                        if (!isset($components['attachments']) || !is_array($components['attachments'])) {
                            $components['attachments'] = array();
                        }
                        $components['attachments'][] = $pdf_path;

                        // Add image links to email body
                        if (!empty($image_links)) {
                            $components['body'] = $this->add_image_links_to_email($components['body'], $image_links);
                        }
                    } catch (Exception $e) {
                        // Log but don't fail - email should still send
                        error_log('[CF7_PDF] Error modifying email: ' . $e->getMessage());
                    }

                    return $components;
                }, 10, 1);

                // Track email status
                try {
                    $this->setup_email_tracking($submission_id, $pdf_path, $contact_form);
                } catch (Exception $e) {
                    $this->log_error('Email tracking setup error: ' . $e->getMessage());
                }
            }

        } catch (Exception $e) {
            // Catch-all: NEVER let this plugin prevent form submission
            $this->log_error('Critical error in form processing (form submission will continue): ' . $e->getMessage());
        } catch (Error $e) {
            // Catch PHP 7+ fatal errors too
            $this->log_error('Critical PHP error in form processing (form submission will continue): ' . $e->getMessage());
        }

        // Note: We never set $abort = true - this plugin should never block form submission
    }

    /**
     * Add image links to email body
     * Formats the email with basic form fields first, then image download URLs at the end
     * Uses HTML hyperlinks for clickable image download links
     *
     * @param string $body        The email body
     * @param array  $image_links Array of image links
     * @return string Modified email body
     */
    private function add_image_links_to_email($body, $image_links) {
        // Use <br> tags for HTML emails, with \n fallback for plain text
        $br = "<br>\n";

        // Add visual separator before images section with HTML formatting
        $image_section = $br . $br;
        $image_section .= "============================================================" . $br;
        $image_section .= "<strong>" . __('UPLOADED IMAGES', 'cf7-working-pdf') . "</strong>" . $br;
        $image_section .= "============================================================" . $br . $br;

        // List all image download URLs as clickable hyperlinks in ordered list
        $image_section .= "<ol style=\"margin: 10px 0; padding-left: 20px;\">";
        foreach ($image_links as $link_data) {
            $image_section .= sprintf(
                '<li style="margin-bottom: 10px;"><a href="%s">%s - Download</a></li>',
                esc_url($link_data['url']),
                esc_html($link_data['label'])
            );
        }
        $image_section .= "</ol>" . $br;

        $image_section .= "============================================================" . $br;

        return $body . $image_section;
    }

    /**
     * Setup email tracking hooks
     *
     * @param int               $submission_id The submission ID
     * @param string            $pdf_path      Path to the PDF file
     * @param WPCF7_ContactForm $contact_form  The contact form object
     * @return void
     */
    private function setup_email_tracking($submission_id, $pdf_path, $contact_form) {
        // Success hook
        add_action('wpcf7_mail_sent', function() use ($submission_id, $pdf_path, $contact_form) {
            if ($submission_id) {
                $mail_prop = $contact_form->prop('mail');
                $this->store_email_status($submission_id, array(
                    'recipient' => isset($mail_prop['recipient']) ? $mail_prop['recipient'] : '',
                    'status' => 'success',
                    'timestamp' => current_time('mysql'),
                ));
            }
            // Clean up temporary PDF
            if (file_exists($pdf_path) && is_writable($pdf_path)) {
                unlink($pdf_path);
            }
        });

        // Failure hook
        add_action('wpcf7_mail_failed', function() use ($submission_id, $pdf_path, $contact_form) {
            if ($submission_id) {
                $mail_prop = $contact_form->prop('mail');
                $this->store_email_status($submission_id, array(
                    'recipient' => isset($mail_prop['recipient']) ? $mail_prop['recipient'] : '',
                    'status' => 'failed',
                    'timestamp' => current_time('mysql'),
                ));
            }
            // Clean up temporary PDF
            if (file_exists($pdf_path) && is_writable($pdf_path)) {
                unlink($pdf_path);
            }
        });
    }

    /**
     * Process and store uploaded images
     *
     * @param array $uploaded_files Array of uploaded files
     * @param int   $submission_id  The submission ID
     * @return array Array of image links
     */
    private function process_image_uploads($uploaded_files, $submission_id) {
        $image_links = array();
        $upload_dir = wp_upload_dir();

        // Check for upload directory errors
        if (!empty($upload_dir['error'])) {
            $this->log_error('Upload directory error: ' . $upload_dir['error']);
            return $image_links;
        }

        $permanent_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/images/' . gmdate('Y-m') . '/';

        // Create directory if needed with error handling
        if (!file_exists($permanent_dir)) {
            $mkdir_result = wp_mkdir_p($permanent_dir);
            if (!$mkdir_result) {
                $this->log_error('Failed to create directory: ' . $permanent_dir);
                return $image_links;
            }
            // Allow access to images
            $htaccess_content = "Order deny,allow\nAllow from all\n";
            $htaccess_result = file_put_contents($permanent_dir . '.htaccess', $htaccess_content);
            if ($htaccess_result === false) {
                $this->log_error('Failed to create .htaccess in: ' . $permanent_dir);
                // Continue anyway, not critical
            }
        }

        // Verify directory is writable
        if (!is_writable($permanent_dir)) {
            $this->log_error('Directory not writable: ' . $permanent_dir);
            return $image_links;
        }

        // Check available disk space
        $free_space = @disk_free_space($permanent_dir);
        if ($free_space !== false && $free_space < 10 * 1024 * 1024) {
            $this->log_error('Insufficient disk space for image uploads: ' . size_format($free_space) . ' available');
            return $image_links;
        }

        foreach ($uploaded_files as $field_name => $file_paths) {
            if (!is_array($file_paths)) {
                $file_paths = array($file_paths);
            }

            foreach ($file_paths as $index => $file_path) {
                if (!$this->is_valid_image($file_path)) {
                    $this->log_info('Skipped invalid image: ' . basename($file_path) . ' for field: ' . $field_name);
                    continue;
                }

                // Check source file size
                $file_size = filesize($file_path);
                if ($file_size === false) {
                    $this->log_error('Cannot read file size: ' . $file_path);
                    continue;
                }

                // Check if we have enough space for this file
                if ($free_space !== false && $file_size > $free_space - 5 * 1024 * 1024) {
                    $this->log_error('Not enough space to copy file: ' . basename($file_path));
                    continue;
                }

                // Generate safe filename
                $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                $safe_extension = in_array($extension, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true) ? $extension : 'jpg';
                $filename = sprintf(
                    '%d-%s-%d-%d.%s',
                    $submission_id,
                    sanitize_file_name($field_name),
                    time(),
                    $index,
                    $safe_extension
                );
                $destination = $permanent_dir . $filename;

                // Attempt to copy with error handling
                $copy_result = copy($file_path, $destination);
                if ($copy_result) {
                    // Verify the copy was successful
                    if (!file_exists($destination) || filesize($destination) !== $file_size) {
                        $this->log_error('File copy verification failed: ' . $filename);
                        if (file_exists($destination)) {
                            unlink($destination);
                        }
                        continue;
                    }

                    $file_url = $upload_dir['baseurl'] . '/cf7-working-pdfs/images/' . gmdate('Y-m') . '/' . $filename;
                    $image_links[] = array(
                        'label' => $this->format_field_label($field_name) . ($index > 0 ? ' ' . ($index + 1) : ''),
                        'url' => $file_url,
                        'path' => $destination,
                    );

                    // Store image metadata
                    if ($submission_id) {
                        $this->store_image_metadata($submission_id, $field_name, $filename, $file_url, $destination);
                    }

                    // Update free space estimate
                    if ($free_space !== false) {
                        $free_space -= $file_size;
                    }
                } else {
                    $this->log_error('Failed to copy image: ' . basename($file_path) . ' to ' . $destination);
                }
            }
        }

        return $image_links;
    }

    /**
     * Check if file is a valid image
     *
     * @param string $file_path Path to the file
     * @return bool
     */
    private function is_valid_image($file_path) {
        if (empty($file_path) || !file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp');
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowed_extensions, true)) {
            return false;
        }

        // Verify it's actually an image using getimagesize
        $image_info = @getimagesize($file_path);
        return $image_info !== false;
    }

    /**
     * Format field name as human-readable label
     *
     * @param string $key Field name
     * @return string Formatted label
     */
    private function format_field_label($key) {
        $label = str_replace(array('your-', 'your_', 'cf7_', 'wpcf7_'), '', $key);
        $label = str_replace(array('-', '_'), ' ', $label);
        return ucwords($label);
    }

    /**
     * Store submission in database
     *
     * @param WPCF7_ContactForm $contact_form The contact form
     * @param array             $posted_data  The posted data
     * @return int The submission ID
     * @throws Exception If database insert fails
     */
    private function store_submission($contact_form, $posted_data) {
        global $wpdb;

        $form_data = array();
        foreach ($posted_data as $key => $value) {
            // Skip internal fields
            if (strpos($key, '_wpcf7') === 0 || strpos($key, 'g-recaptcha') === 0) {
                continue;
            }
            // Use wp_kses_post to preserve more characters while still sanitizing
            // This keeps quotes and special characters intact
            $form_data[$key] = is_array($value)
                ? implode(', ', array_map(array($this, 'sanitize_form_value'), $value))
                : $this->sanitize_form_value($value);
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'cf7_working_pdf_submissions',
            array(
                'form_id' => absint($contact_form->id()),
                'form_title' => sanitize_text_field($contact_form->title()),
                'form_data_json' => wp_json_encode($form_data),
                'submission_date' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s')
        );

        if ($result === false) {
            throw new Exception('Database insert failed: ' . $wpdb->last_error);
        }

        return $wpdb->insert_id;
    }

    /**
     * Store image metadata
     *
     * @param int    $submission_id The submission ID
     * @param string $field_name    The field name
     * @param string $filename      The filename
     * @param string $file_url      The file URL
     * @param string $file_path     The file path
     * @return void
     */
    private function store_image_metadata($submission_id, $field_name, $filename, $file_url, $file_path) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cf7_working_pdf_images',
            array(
                'submission_id' => absint($submission_id),
                'field_name' => sanitize_text_field($field_name),
                'filename' => sanitize_file_name($filename),
                'file_url' => esc_url_raw($file_url),
                'file_path' => sanitize_text_field($file_path),
                'upload_date' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Store email status
     *
     * @param int   $submission_id The submission ID
     * @param array $email_status  The email status data
     * @return void
     */
    private function store_email_status($submission_id, $email_status) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cf7_working_pdf_email_status',
            array(
                'submission_id' => absint($submission_id),
                'status_data' => wp_json_encode($email_status),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s')
        );
    }

    /**
     * Ensure database tables exist
     *
     * @return void
     * @throws Exception If table creation fails
     */
    private function ensure_database_tables() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'cf7_working_pdf_submissions',
            $wpdb->prefix . 'cf7_working_pdf_images',
            $wpdb->prefix . 'cf7_working_pdf_email_status',
        );

        foreach ($tables as $table) {
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($table_exists !== $table) {
                $this->create_database_tables();
                break;
            }
        }
    }

    /**
     * Create database tables
     *
     * @return void
     */
    private function create_database_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$wpdb->prefix}cf7_working_pdf_submissions (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id bigint(20) UNSIGNED NOT NULL,
            form_title varchar(255) NOT NULL,
            form_data_json longtext NOT NULL,
            submission_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY submission_date (submission_date)
        ) $charset_collate;

        CREATE TABLE {$wpdb->prefix}cf7_working_pdf_images (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) UNSIGNED NOT NULL,
            field_name varchar(255) NOT NULL,
            filename varchar(255) NOT NULL,
            file_url text NOT NULL,
            file_path text NOT NULL,
            upload_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY submission_id (submission_id)
        ) $charset_collate;

        CREATE TABLE {$wpdb->prefix}cf7_working_pdf_email_status (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) UNSIGNED NOT NULL,
            status_data text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY submission_id (submission_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Add admin menus
     *
     * @return void
     */
    public function add_admin_menus() {
        add_submenu_page(
            'wpcf7',
            __('PDF Submissions', 'cf7-working-pdf'),
            __('PDF Submissions', 'cf7-working-pdf'),
            'manage_options',
            'cf7-working-pdf-submissions',
            array($this, 'render_submissions_page')
        );

        add_submenu_page(
            'wpcf7',
            __('PDF Settings', 'cf7-working-pdf'),
            __('PDF Settings', 'cf7-working-pdf'),
            'manage_options',
            'cf7-working-pdf-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Add settings link to plugins page
     *
     * @param array $links Plugin action links
     * @return array Modified links
     */
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=cf7-working-pdf-settings')),
            esc_html__('Settings', 'cf7-working-pdf')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Register plugin settings using WordPress Settings API
     *
     * @return void
     */
    public function register_settings() {
        // Register the settings option
        register_setting(
            'cf7_working_pdf_settings_group',
            'cf7_working_pdf_settings',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => $this->default_settings,
            )
        );

        // PDF Generation Section
        add_settings_section(
            'cf7_working_pdf_section_pdf',
            __('PDF Generation', 'cf7-working-pdf'),
            array($this, 'render_section_pdf'),
            'cf7-working-pdf-settings'
        );

        add_settings_field(
            'enable_pdf',
            __('Enable PDF Generation', 'cf7-working-pdf'),
            array($this, 'render_field_checkbox'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_pdf',
            array(
                'id' => 'enable_pdf',
                'description' => __('Generate PDF documents from form submissions.', 'cf7-working-pdf'),
            )
        );

        add_settings_field(
            'pdf_title',
            __('PDF Title', 'cf7-working-pdf'),
            array($this, 'render_field_text'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_pdf',
            array(
                'id' => 'pdf_title',
                'class' => 'regular-text',
                'description' => __('Title displayed at the top of the PDF document.', 'cf7-working-pdf'),
            )
        );

        add_settings_field(
            'company_name',
            __('Company Name', 'cf7-working-pdf'),
            array($this, 'render_field_text'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_pdf',
            array(
                'id' => 'company_name',
                'class' => 'regular-text',
                'description' => __('Company name to display in the PDF header.', 'cf7-working-pdf'),
            )
        );

        add_settings_field(
            'pdf_design',
            __('PDF Design', 'cf7-working-pdf'),
            array($this, 'render_field_select'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_pdf',
            array(
                'id' => 'pdf_design',
                'options' => array(
                    'modern' => __('Modern', 'cf7-working-pdf'),
                    'classic' => __('Classic', 'cf7-working-pdf'),
                    'minimal' => __('Minimal', 'cf7-working-pdf'),
                ),
            )
        );

        add_settings_field(
            'header_color',
            __('Header Color', 'cf7-working-pdf'),
            array($this, 'render_field_color'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_pdf',
            array(
                'id' => 'header_color',
                'description' => __('Color used for PDF headers and section titles.', 'cf7-working-pdf'),
            )
        );

        // Image Settings Section
        add_settings_section(
            'cf7_working_pdf_section_images',
            __('Image Settings', 'cf7-working-pdf'),
            array($this, 'render_section_images'),
            'cf7-working-pdf-settings'
        );

        add_settings_field(
            'max_image_size',
            __('Max Image Size (px)', 'cf7-working-pdf'),
            array($this, 'render_field_number'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_images',
            array(
                'id' => 'max_image_size',
                'min' => 256,
                'max' => 4096,
                'step' => 128,
                'class' => 'small-text',
                'description' => __('Maximum dimension (width or height) for optimized images.', 'cf7-working-pdf'),
            )
        );

        add_settings_field(
            'image_quality',
            __('Image Quality (%)', 'cf7-working-pdf'),
            array($this, 'render_field_range'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_images',
            array(
                'id' => 'image_quality',
                'min' => 1,
                'max' => 100,
                'step' => 1,
                'description' => __('JPEG compression quality. Lower values = smaller file sizes.', 'cf7-working-pdf'),
            )
        );

        // Storage Settings Section
        add_settings_section(
            'cf7_working_pdf_section_storage',
            __('Storage Settings', 'cf7-working-pdf'),
            array($this, 'render_section_storage'),
            'cf7-working-pdf-settings'
        );

        add_settings_field(
            'store_submissions',
            __('Store Submissions', 'cf7-working-pdf'),
            array($this, 'render_field_checkbox'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_storage',
            array(
                'id' => 'store_submissions',
                'description' => __('Store form submissions in the database for later viewing.', 'cf7-working-pdf'),
            )
        );

        // Auto-Delete Section
        add_settings_section(
            'cf7_working_pdf_section_autodelete',
            __('Auto-Delete (Storage Management)', 'cf7-working-pdf'),
            array($this, 'render_section_autodelete'),
            'cf7-working-pdf-settings'
        );

        add_settings_field(
            'auto_delete_enabled',
            __('Enable Auto-Delete', 'cf7-working-pdf'),
            array($this, 'render_field_checkbox'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_autodelete',
            array(
                'id' => 'auto_delete_enabled',
                'description' => __('Automatically delete old data based on the settings below.', 'cf7-working-pdf'),
            )
        );

        add_settings_field(
            'auto_delete_days',
            __('Delete After (Days)', 'cf7-working-pdf'),
            array($this, 'render_field_number'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_autodelete',
            array(
                'id' => 'auto_delete_days',
                'min' => 1,
                'max' => 365,
                'class' => 'small-text',
                'description' => __('Delete submissions older than this many days. Runs daily.', 'cf7-working-pdf'),
            )
        );

        add_settings_field(
            'delete_images_only',
            __('Delete Images Only', 'cf7-working-pdf'),
            array($this, 'render_field_checkbox'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_autodelete',
            array(
                'id' => 'delete_images_only',
                'description' => __('Keep submission data but delete image files to save disk space.', 'cf7-working-pdf'),
            )
        );

        // Form Behavior Section
        add_settings_section(
            'cf7_working_pdf_section_behavior',
            __('Form Behavior', 'cf7-working-pdf'),
            array($this, 'render_section_behavior'),
            'cf7-working-pdf-settings'
        );

        add_settings_field(
            'success_redirect_url',
            __('Success Redirect URL', 'cf7-working-pdf'),
            array($this, 'render_field_url'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_behavior',
            array(
                'id' => 'success_redirect_url',
                'class' => 'large-text',
                'placeholder' => 'https://example.com/thank-you/',
                'description' => __('Redirect users to this URL after successful form submission. Leave empty to use default CF7 behavior.', 'cf7-working-pdf'),
            )
        );

        // Advanced Section
        add_settings_section(
            'cf7_working_pdf_section_advanced',
            __('Advanced Settings', 'cf7-working-pdf'),
            array($this, 'render_section_advanced'),
            'cf7-working-pdf-settings'
        );

        add_settings_field(
            'enable_debug_log',
            __('Enable Debug Logging', 'cf7-working-pdf'),
            array($this, 'render_field_checkbox'),
            'cf7-working-pdf-settings',
            'cf7_working_pdf_section_advanced',
            array(
                'id' => 'enable_debug_log',
                'description' => __('Log debug information to the WordPress debug log. Only enable for troubleshooting.', 'cf7-working-pdf'),
            )
        );
    }

    /**
     * Sanitize settings before saving
     *
     * @param array $input The submitted settings
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        // Boolean fields
        $sanitized['enable_pdf'] = isset($input['enable_pdf']) ? true : false;
        $sanitized['store_submissions'] = isset($input['store_submissions']) ? true : false;
        $sanitized['auto_delete_enabled'] = isset($input['auto_delete_enabled']) ? true : false;
        $sanitized['delete_images_only'] = isset($input['delete_images_only']) ? true : false;
        $sanitized['enable_debug_log'] = isset($input['enable_debug_log']) ? true : false;

        // Text fields
        $sanitized['pdf_design'] = sanitize_text_field($input['pdf_design'] ?? 'modern');
        $sanitized['pdf_title'] = sanitize_text_field($input['pdf_title'] ?? 'Form Submission');
        $sanitized['company_name'] = sanitize_text_field($input['company_name'] ?? '');

        // Color field
        $sanitized['header_color'] = sanitize_hex_color($input['header_color'] ?? '#D0A959');
        if (empty($sanitized['header_color'])) {
            $sanitized['header_color'] = '#D0A959';
        }

        // Number fields
        $sanitized['auto_delete_days'] = absint($input['auto_delete_days'] ?? 30);
        $sanitized['max_image_size'] = absint($input['max_image_size'] ?? 1024);
        $sanitized['image_quality'] = min(100, max(1, absint($input['image_quality'] ?? 85)));

        // URL field
        $sanitized['success_redirect_url'] = esc_url_raw($input['success_redirect_url'] ?? '');

        // Update cron schedule based on auto_delete_enabled
        $timestamp = wp_next_scheduled('cf7_working_pdf_auto_cleanup');
        if ($sanitized['auto_delete_enabled']) {
            if (!$timestamp) {
                wp_schedule_event(time(), 'daily', 'cf7_working_pdf_auto_cleanup');
            }
        } else {
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'cf7_working_pdf_auto_cleanup');
            }
        }

        return $sanitized;
    }

    /**
     * Render section: PDF Generation
     */
    public function render_section_pdf() {
        echo '<p>' . esc_html__('Configure how PDFs are generated from form submissions.', 'cf7-working-pdf') . '</p>';
    }

    /**
     * Render section: Images
     */
    public function render_section_images() {
        echo '<p>' . esc_html__('Configure image optimization settings for PDF generation.', 'cf7-working-pdf') . '</p>';
    }

    /**
     * Render section: Storage
     */
    public function render_section_storage() {
        echo '<p>' . esc_html__('Configure how form submissions are stored.', 'cf7-working-pdf') . '</p>';
    }

    /**
     * Render section: Auto-Delete
     */
    public function render_section_autodelete() {
        echo '<p>' . esc_html__('Automatically delete old submissions and images to save server space.', 'cf7-working-pdf') . '</p>';
    }

    /**
     * Render section: Behavior
     */
    public function render_section_behavior() {
        echo '<p>' . esc_html__('Configure form submission behavior.', 'cf7-working-pdf') . '</p>';
    }

    /**
     * Render section: Advanced
     */
    public function render_section_advanced() {
        echo '<p>' . esc_html__('Advanced settings for troubleshooting and debugging.', 'cf7-working-pdf') . '</p>';
    }

    /**
     * Render checkbox field
     *
     * @param array $args Field arguments
     */
    public function render_field_checkbox($args) {
        $settings = $this->get_settings();
        $id = $args['id'];
        $value = isset($settings[$id]) ? $settings[$id] : false;
        ?>
        <label class="cf7-toggle">
            <input type="checkbox" name="cf7_working_pdf_settings[<?php echo esc_attr($id); ?>]" id="<?php echo esc_attr($id); ?>" value="1" <?php checked($value, true); ?>>
            <span class="cf7-toggle-slider"></span>
        </label>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render text field
     *
     * @param array $args Field arguments
     */
    public function render_field_text($args) {
        $settings = $this->get_settings();
        $id = $args['id'];
        $value = isset($settings[$id]) ? $settings[$id] : '';
        $class = isset($args['class']) ? $args['class'] : 'regular-text';
        ?>
        <input type="text" name="cf7_working_pdf_settings[<?php echo esc_attr($id); ?>]" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="<?php echo esc_attr($class); ?>">
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render select field
     *
     * @param array $args Field arguments
     */
    public function render_field_select($args) {
        $settings = $this->get_settings();
        $id = $args['id'];
        $value = isset($settings[$id]) ? $settings[$id] : '';
        $options = isset($args['options']) ? $args['options'] : array();
        ?>
        <select name="cf7_working_pdf_settings[<?php echo esc_attr($id); ?>]" id="<?php echo esc_attr($id); ?>">
            <?php foreach ($options as $option_value => $option_label): ?>
                <option value="<?php echo esc_attr($option_value); ?>" <?php selected($value, $option_value); ?>><?php echo esc_html($option_label); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render color field
     *
     * @param array $args Field arguments
     */
    public function render_field_color($args) {
        $settings = $this->get_settings();
        $id = $args['id'];
        $value = isset($settings[$id]) ? $settings[$id] : '#D0A959';
        ?>
        <input type="text" name="cf7_working_pdf_settings[<?php echo esc_attr($id); ?>]" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" class="color-picker">
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render number field
     *
     * @param array $args Field arguments
     */
    public function render_field_number($args) {
        $settings = $this->get_settings();
        $id = $args['id'];
        $value = isset($settings[$id]) ? $settings[$id] : 0;
        $min = isset($args['min']) ? $args['min'] : 0;
        $max = isset($args['max']) ? $args['max'] : 9999;
        $step = isset($args['step']) ? $args['step'] : 1;
        $class = isset($args['class']) ? $args['class'] : 'small-text';
        ?>
        <input type="number" name="cf7_working_pdf_settings[<?php echo esc_attr($id); ?>]" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="<?php echo esc_attr($step); ?>" class="<?php echo esc_attr($class); ?>">
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render range field
     *
     * @param array $args Field arguments
     */
    public function render_field_range($args) {
        $settings = $this->get_settings();
        $id = $args['id'];
        $value = isset($settings[$id]) ? $settings[$id] : 85;
        $min = isset($args['min']) ? $args['min'] : 1;
        $max = isset($args['max']) ? $args['max'] : 100;
        $step = isset($args['step']) ? $args['step'] : 1;
        ?>
        <input type="range" name="cf7_working_pdf_settings[<?php echo esc_attr($id); ?>]" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($value); ?>" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="<?php echo esc_attr($step); ?>">
        <span id="<?php echo esc_attr($id); ?>_value"><?php echo esc_html($value); ?>%</span>
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render URL field
     *
     * @param array $args Field arguments
     */
    public function render_field_url($args) {
        $settings = $this->get_settings();
        $id = $args['id'];
        $value = isset($settings[$id]) ? $settings[$id] : '';
        $class = isset($args['class']) ? $args['class'] : 'regular-text';
        $placeholder = isset($args['placeholder']) ? $args['placeholder'] : '';
        ?>
        <input type="url" name="cf7_working_pdf_settings[<?php echo esc_attr($id); ?>]" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_url($value); ?>" class="<?php echo esc_attr($class); ?>" placeholder="<?php echo esc_attr($placeholder); ?>">
        <?php if (!empty($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    /**
     * Render settings page
     *
     * @return void
     */
    public function render_settings_page() {
        // Settings are now handled by WordPress Settings API via register_setting() and sanitize_settings()
        include CF7_WORKING_PDF_PLUGIN_DIR . 'includes/admin-settings.php';
    }

    /**
     * Render submissions page
     *
     * @return void
     */
    public function render_submissions_page() {
        include CF7_WORKING_PDF_PLUGIN_DIR . 'includes/admin-submissions.php';
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook The current admin page hook
     * @return void
     */
    public function admin_enqueue_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'cf7-working-pdf') === false && strpos($hook, 'wpcf7') === false) {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        wp_enqueue_style(
            'cf7-working-pdf-admin',
            CF7_WORKING_PDF_PLUGIN_URL . 'assets/admin.css',
            array(),
            CF7_WORKING_PDF_VERSION
        );

        wp_enqueue_script(
            'cf7-working-pdf-admin',
            CF7_WORKING_PDF_PLUGIN_URL . 'assets/admin.js',
            array('jquery', 'wp-color-picker'),
            CF7_WORKING_PDF_VERSION,
            true
        );

        wp_localize_script('cf7-working-pdf-admin', 'cf7_working_pdf_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cf7_working_pdf_nonce'),
            'confirm_delete' => __('Are you sure you want to delete this submission? This will also delete all associated images.', 'cf7-working-pdf'),
            'confirm_bulk_delete' => __('Are you sure you want to delete the selected submissions? This will also delete all associated images and cannot be undone.', 'cf7-working-pdf'),
            'confirm_image_delete' => __('Are you sure you want to delete this image?', 'cf7-working-pdf'),
            'confirm_cleanup' => __('Are you sure you want to run cleanup now? This will delete old data based on your settings.', 'cf7-working-pdf'),
            'loading' => __('Loading...', 'cf7-working-pdf'),
            'error' => __('An error occurred. Please try again.', 'cf7-working-pdf'),
        ));
    }

    /**
     * Enqueue frontend scripts and styles
     *
     * @return void
     */
    public function frontend_enqueue_scripts() {
        // Only enqueue if CF7 is present
        if (!function_exists('wpcf7_enqueue_scripts')) {
            return;
        }

        wp_enqueue_script('jquery');

        wp_enqueue_script(
            'cf7-working-pdf-frontend',
            CF7_WORKING_PDF_PLUGIN_URL . 'assets/frontend.js',
            array('jquery'),
            CF7_WORKING_PDF_VERSION,
            true
        );

        wp_enqueue_style(
            'cf7-working-pdf-frontend',
            CF7_WORKING_PDF_PLUGIN_URL . 'assets/frontend.css',
            array(),
            CF7_WORKING_PDF_VERSION
        );

        // Pass settings to frontend
        $settings = $this->get_settings();
        wp_localize_script('cf7-working-pdf-frontend', 'cf7_working_pdf_frontend', array(
            'redirect_url' => $settings['success_redirect_url'],
        ));
    }

    /**
     * AJAX: Get submission details
     *
     * @return void
     */
    public function ajax_get_submission() {
        check_ajax_referer('cf7_working_pdf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'cf7-working-pdf'));
        }

        $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        if (!$submission_id) {
            wp_send_json_error(__('Invalid submission ID.', 'cf7-working-pdf'));
        }

        global $wpdb;

        $submission = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cf7_working_pdf_submissions WHERE id = %d",
                $submission_id
            )
        );

        if (!$submission) {
            wp_send_json_error(__('Submission not found.', 'cf7-working-pdf'));
        }

        $form_data = json_decode($submission->form_data_json, true);
        if (!is_array($form_data)) {
            $form_data = array();
        }

        $images = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cf7_working_pdf_images WHERE submission_id = %d",
                $submission_id
            )
        );

        $email_status = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status_data FROM {$wpdb->prefix}cf7_working_pdf_email_status WHERE submission_id = %d ORDER BY id DESC LIMIT 1",
                $submission_id
            )
        );

        // Build HTML response
        $html = '<div class="submission-modal-content">';
        $html .= '<h3>' . esc_html($submission->form_title) . '</h3>';
        $html .= '<div class="submission-meta">';
        $html .= '<span><strong>' . esc_html__('Date:', 'cf7-working-pdf') . '</strong> ' . esc_html(wp_date('F j, Y g:i A', strtotime($submission->submission_date))) . '</span>';
        $html .= '<span><strong>' . esc_html__('Form ID:', 'cf7-working-pdf') . '</strong> ' . esc_html($submission->form_id) . '</span>';

        if ($email_status) {
            $status_data = json_decode($email_status->status_data, true);
            $status_class = isset($status_data['status']) && $status_data['status'] === 'success' ? 'status-success' : 'status-failed';
            $html .= '<span class="' . esc_attr($status_class) . '"><strong>' . esc_html__('Email:', 'cf7-working-pdf') . '</strong> ' . esc_html($status_data['status'] ?? 'unknown') . '</span>';
        }

        $html .= '</div>';
        $html .= '<div class="submission-fields">';

        foreach ($form_data as $key => $value) {
            if (strpos($key, '_wpcf7') === 0 || strpos($key, 'g-recaptcha') === 0) {
                continue;
            }
            // Normalize quotes to fix display of old data with smart quotes
            $display_value = self::normalize_quotes($value);
            $html .= '<div class="modal-field">';
            $html .= '<div class="modal-field-label">' . esc_html($this->format_field_label($key)) . '</div>';
            $html .= '<div class="modal-field-value">' . nl2br(esc_html($display_value)) . '</div>';
            $html .= '</div>';
        }

        if (!empty($images)) {
            $html .= '<div class="modal-field">';
            $html .= '<div class="modal-field-label">' . esc_html__('Images', 'cf7-working-pdf') . '</div>';
            $html .= '<div class="modal-field-value modal-images">';
            foreach ($images as $image) {
                $html .= '<div class="modal-image-item">';
                $html .= '<a href="' . esc_url($image->file_url) . '" target="_blank" download="' . esc_attr($image->filename) . '">';
                $html .= '<img src="' . esc_url($image->file_url) . '" alt="' . esc_attr($image->filename) . '" style="max-width: 100px; max-height: 100px;" />';
                $html .= '</a>';
                $html .= '<button type="button" class="button-link delete-image" data-image-id="' . esc_attr($image->id) . '">' . esc_html__('Delete', 'cf7-working-pdf') . '</button>';
                $html .= '</div>';
            }
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div></div>';

        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX: Delete submission
     *
     * @return void
     */
    public function ajax_delete_submission() {
        check_ajax_referer('cf7_working_pdf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'cf7-working-pdf'));
        }

        $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        if (!$submission_id) {
            wp_send_json_error(__('Invalid submission ID.', 'cf7-working-pdf'));
        }

        if ($this->delete_submission_cascade($submission_id)) {
            wp_send_json_success(array('message' => __('Submission and all related data deleted.', 'cf7-working-pdf')));
        } else {
            wp_send_json_error(__('Error deleting submission.', 'cf7-working-pdf'));
        }
    }

    /**
     * Delete submission with all related data
     *
     * @param int $submission_id The submission ID
     * @return bool Success status
     */
    public function delete_submission_cascade($submission_id) {
        global $wpdb;

        $submission_id = absint($submission_id);
        if (!$submission_id) {
            return false;
        }

        // Delete physical image files
        $images = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT file_path FROM {$wpdb->prefix}cf7_working_pdf_images WHERE submission_id = %d",
                $submission_id
            )
        );

        foreach ($images as $image) {
            if (!empty($image->file_path) && file_exists($image->file_path) && is_writable($image->file_path)) {
                unlink($image->file_path);
            }
        }

        // Delete database records
        $wpdb->delete($wpdb->prefix . 'cf7_working_pdf_images', array('submission_id' => $submission_id), array('%d'));
        $wpdb->delete($wpdb->prefix . 'cf7_working_pdf_email_status', array('submission_id' => $submission_id), array('%d'));
        $result = $wpdb->delete($wpdb->prefix . 'cf7_working_pdf_submissions', array('id' => $submission_id), array('%d'));

        return $result !== false;
    }

    /**
     * AJAX: Delete image
     *
     * @return void
     */
    public function ajax_delete_image() {
        check_ajax_referer('cf7_working_pdf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'cf7-working-pdf'));
        }

        $image_id = isset($_POST['image_id']) ? absint($_POST['image_id']) : 0;
        if (!$image_id) {
            wp_send_json_error(__('Invalid image ID.', 'cf7-working-pdf'));
        }

        global $wpdb;

        $image = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT file_path FROM {$wpdb->prefix}cf7_working_pdf_images WHERE id = %d",
                $image_id
            )
        );

        if (!$image) {
            wp_send_json_error(__('Image not found.', 'cf7-working-pdf'));
        }

        // Delete physical file
        if (!empty($image->file_path) && file_exists($image->file_path) && is_writable($image->file_path)) {
            unlink($image->file_path);
        }

        // Delete database record
        $wpdb->delete($wpdb->prefix . 'cf7_working_pdf_images', array('id' => $image_id), array('%d'));

        wp_send_json_success(__('Image deleted.', 'cf7-working-pdf'));
    }

    /**
     * AJAX: Download PDF - CRITICAL FIX #3
     *
     * @return void
     */
    public function ajax_download_pdf() {
        // Allow both GET and POST for download
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field($_REQUEST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'cf7_working_pdf_nonce')) {
            wp_die(__('Security check failed.', 'cf7-working-pdf'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'cf7-working-pdf'));
        }

        $submission_id = isset($_REQUEST['submission_id']) ? absint($_REQUEST['submission_id']) : 0;
        if (!$submission_id) {
            wp_die(__('Invalid submission ID.', 'cf7-working-pdf'));
        }

        global $wpdb;

        $submission = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cf7_working_pdf_submissions WHERE id = %d",
                $submission_id
            )
        );

        if (!$submission) {
            wp_die(__('Submission not found.', 'cf7-working-pdf'));
        }

        $form_data = json_decode($submission->form_data_json, true);
        if (!is_array($form_data)) {
            $form_data = array();
        }

        // Get images for this submission
        $images = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cf7_working_pdf_images WHERE submission_id = %d",
                $submission_id
            )
        );

        // Build files array from stored images
        $files = array();
        foreach ($images as $image) {
            if (!isset($files[$image->field_name])) {
                $files[$image->field_name] = array();
            }
            $files[$image->field_name][] = $image->file_path;
        }

        $settings = $this->get_settings();

        // Prepare data for PDF generation
        $data = array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission_id,
            'submission_data' => $form_data,
            'files' => $files,
            'settings' => $settings,
        );

        try {
            // Verify the PDF generator function exists
            if (!function_exists('cf7_working_pdf_generate')) {
                $this->log_error('PDF generator function not available');
                wp_die(__('PDF generator not available. Please check plugin installation.', 'cf7-working-pdf'));
            }

            $pdf_path = cf7_working_pdf_generate($data, 'submission-' . $submission_id . '.pdf');

            if ($pdf_path && file_exists($pdf_path)) {
                $filename = 'submission-' . $submission_id . '-' . gmdate('Y-m-d') . '.pdf';

                // Check if headers already sent
                if (headers_sent($file, $line)) {
                    $this->log_error("Headers already sent in {$file} on line {$line}");
                    wp_die(__('Cannot download PDF. Headers already sent.', 'cf7-working-pdf'));
                }

                // Clear any output buffers
                while (ob_get_level()) {
                    ob_end_clean();
                }

                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($pdf_path));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');

                readfile($pdf_path);

                // Clean up
                if (file_exists($pdf_path) && is_writable($pdf_path)) {
                    unlink($pdf_path);
                }
                exit;
            } else {
                $this->log_error('PDF generation returned empty or non-existent file');
                wp_die(__('Error generating PDF. Please try again.', 'cf7-working-pdf'));
            }
        } catch (Exception $e) {
            $this->log_error('AJAX PDF generation error (Exception): ' . $e->getMessage());
            wp_die(__('Error generating PDF: ', 'cf7-working-pdf') . esc_html($e->getMessage()));
        } catch (Error $e) {
            $this->log_error('AJAX PDF generation error (Error): ' . $e->getMessage());
            wp_die(__('Error generating PDF: ', 'cf7-working-pdf') . esc_html($e->getMessage()));
        }
    }

    /**
     * AJAX: Manual cleanup
     *
     * @return void
     */
    public function ajax_manual_cleanup() {
        check_ajax_referer('cf7_working_pdf_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'cf7-working-pdf'));
        }

        $result = $this->run_auto_cleanup();
        wp_send_json_success($result);
    }

    /**
     * Run auto cleanup of old data
     *
     * @return array Cleanup results
     */
    public function run_auto_cleanup() {
        global $wpdb;

        $settings = $this->get_settings();

        if (!$settings['auto_delete_enabled']) {
            return array(
                'message' => __('Auto-delete is disabled.', 'cf7-working-pdf'),
                'deleted_submissions' => 0,
                'deleted_images' => 0,
            );
        }

        $days = max(1, $settings['auto_delete_days']);
        $cutoff_timestamp = strtotime("-{$days} days");

        $deleted_submissions = 0;
        $deleted_images = 0;

        // Get upload directory for images
        $upload_dir = wp_upload_dir();
        $images_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/images/';

        // Delete all image files older than specified days
        // Images are stored in year-month subfolders like /2026-01/
        if (is_dir($images_dir)) {
            // Get all year-month subfolders
            $subfolders = glob($images_dir . '*', GLOB_ONLYDIR);

            foreach ($subfolders as $subfolder) {
                // Get all files in this subfolder
                $files = glob($subfolder . '/*');

                foreach ($files as $file) {
                    if (is_file($file)) {
                        $file_modified_time = filemtime($file);

                        // If file is older than cutoff date, delete it
                        if ($file_modified_time < $cutoff_timestamp) {
                            if (@unlink($file)) {
                                $deleted_images++;

                                // Also remove from database if exists
                                $wpdb->delete(
                                    $wpdb->prefix . 'cf7_working_pdf_images',
                                    array('file_path' => $file),
                                    array('%s')
                                );
                            }
                        }
                    }
                }

                // Remove empty subfolder after deleting files
                $remaining_files = glob($subfolder . '/*');
                if (empty($remaining_files)) {
                    @rmdir($subfolder);
                }
            }
        }

        $this->log_info(sprintf(
            'Auto cleanup completed: %d images deleted (older than %d days)',
            $deleted_images,
            $days
        ));

        return array(
            'message' => sprintf(
                __('Cleanup completed: %d images deleted (older than %d days).', 'cf7-working-pdf'),
                $deleted_images,
                $days
            ),
            'deleted_submissions' => $deleted_submissions,
            'deleted_images' => $deleted_images,
        );
    }

    /**
     * Check and run database updates
     *
     * @return void
     */
    public function check_database_update() {
        $current_version = get_option('cf7_working_pdf_db_version', '0');
        if (version_compare($current_version, $this->db_version, '<')) {
            $this->create_database_tables();
            update_option('cf7_working_pdf_db_version', $this->db_version);
        }
    }

    /**
     * Plugin activation
     *
     * @return void
     */
    public function activate() {
        $this->create_database_tables();

        // Set default settings if not exists
        if (!get_option('cf7_working_pdf_settings')) {
            add_option('cf7_working_pdf_settings', $this->default_settings);
        }

        // Create upload directories
        $upload_dir = wp_upload_dir();
        $dirs = array(
            $upload_dir['basedir'] . '/cf7-working-pdfs',
            $upload_dir['basedir'] . '/cf7-working-pdfs/images',
            $upload_dir['basedir'] . '/cf7-optimized-images',
        );

        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
            }
        }

        // Create .htaccess for PDF directory
        $pdf_htaccess = $upload_dir['basedir'] . '/cf7-working-pdfs/.htaccess';
        if (!file_exists($pdf_htaccess)) {
            $htaccess_content = "Order deny,allow\nDeny from all\n<Files *.pdf>\nAllow from all\n</Files>";
            file_put_contents($pdf_htaccess, $htaccess_content);
        }

        update_option('cf7_working_pdf_db_version', $this->db_version);

        // Schedule cleanup cron
        $settings = $this->get_settings();
        if ($settings['auto_delete_enabled'] && !wp_next_scheduled('cf7_working_pdf_auto_cleanup')) {
            wp_schedule_event(time(), 'daily', 'cf7_working_pdf_auto_cleanup');
        }
    }

    /**
     * Plugin deactivation
     *
     * @return void
     */
    public function deactivate() {
        // Clear scheduled events
        $timestamp = wp_next_scheduled('cf7_working_pdf_auto_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'cf7_working_pdf_auto_cleanup');
        }
    }

    /**
     * Log error message
     *
     * @param string $message The error message
     * @return void
     */
    private function log_error($message) {
        $settings = $this->get_settings();
        if ($settings['enable_debug_log']) {
            error_log('[CF7_PDF ERROR] ' . gmdate('Y-m-d H:i:s') . ' ' . $message);
        }
    }

    /**
     * Log info message
     *
     * @param string $message The info message
     * @return void
     */
    private function log_info($message) {
        $settings = $this->get_settings();
        if ($settings['enable_debug_log']) {
            error_log('[CF7_PDF INFO] ' . gmdate('Y-m-d H:i:s') . ' ' . $message);
        }
    }

    /**
     * Sanitize form value while preserving special characters like quotes
     * Converts smart quotes to straight quotes to prevent encoding issues
     *
     * @param string $value The value to sanitize
     * @return string Sanitized value
     */
    private function sanitize_form_value($value) {
        if (!is_string($value)) {
            $value = (string) $value;
        }

        // Remove any HTML tags for security
        $value = wp_strip_all_tags($value);

        // Convert smart quotes to straight quotes
        $value = self::normalize_quotes($value);

        // Trim whitespace
        return trim($value);
    }

    /**
     * Normalize quotes and special characters in text
     * This is a static method so it can be used throughout the plugin
     * Converts smart/curly quotes to straight quotes to prevent encoding issues
     *
     * @param string $text The text to normalize
     * @return string Normalized text with straight quotes
     */
    public static function normalize_quotes($text) {
        if (!is_string($text)) {
            $text = (string) $text;
        }

        // First fix any already-corrupted UTF-8 sequences (â€™ etc)
        // These appear when UTF-8 smart quotes are displayed as ISO-8859-1
        $corrupted_sequences = array(
            'â€™' => "'",   // Right single quote corrupted
            'â€˜' => "'",   // Left single quote corrupted
            'â€œ' => '"',   // Left double quote corrupted
            'â€' => '"',    // Right double quote corrupted (note: may be partial)
            'â€"' => '-',   // En dash corrupted
            'â€"' => '-',   // Em dash corrupted
            'â€¦' => '...', // Ellipsis corrupted
            'Â ' => ' ',    // Non-breaking space corrupted
        );

        $text = str_replace(array_keys($corrupted_sequences), array_values($corrupted_sequences), $text);

        // Convert UTF-8 smart/curly quotes to straight quotes
        $smart_quotes = array(
            "\xe2\x80\x98" => "'",  // ' left single quote
            "\xe2\x80\x99" => "'",  // ' right single quote (apostrophe)
            "\xe2\x80\x9c" => '"',  // " left double quote
            "\xe2\x80\x9d" => '"',  // " right double quote
            "\xe2\x80\xb2" => "'",  // ′ prime (feet)
            "\xe2\x80\xb3" => '"',  // ″ double prime (inches)
            "\xe2\x80\x93" => '-',  // – en dash
            "\xe2\x80\x94" => '-',  // — em dash
            "\xe2\x80\xa6" => '...', // … ellipsis
            "\xc2\xa0"     => ' ',  // non-breaking space
            "\xe2\x80\x9e" => '"',  // „ low double quote
            "\xe2\x80\x9a" => "'",  // ‚ low single quote
        );

        $text = strtr($text, $smart_quotes);

        // Also handle Windows-1252 encoded smart quotes (from MS Word/Office)
        $win1252_quotes = array(
            chr(145) => "'",  // left single quote
            chr(146) => "'",  // right single quote
            chr(147) => '"',  // left double quote
            chr(148) => '"',  // right double quote
            chr(150) => '-',  // en dash
            chr(151) => '-',  // em dash
            chr(133) => '...', // ellipsis
            chr(160) => ' ',  // non-breaking space
        );

        $text = strtr($text, $win1252_quotes);

        return $text;
    }
}

// Initialize the plugin
CF7_Working_PDF_Generator::get_instance();

//////////////////////////////
////////////////////////////
// ── Allow dynamic state values ────────────────────────────────────
add_filter( 'wpcf7_validate_select*', function( $result, $tag ) {
    if ( $tag->name === 'country' ) { $result->reset(); }
    return $result;
}, 10, 2 );

add_filter( 'wpcf7_validate_select', function( $result, $tag ) {
    if ( $tag->name === 'country' ) { $result->reset(); }
    return $result;
}, 10, 2 );

// ── Country → State + Phone JS ────────────────────────────────────
add_action('wp_footer', function() { ?>
<script>
document.addEventListener("DOMContentLoaded", function () {

  // ── Country data ─────────────────────────────────────────────
  var COUNTRIES = {
    "United States":          { code:"1",   states:["Alabama","Alaska","Arizona","Arkansas","California","Colorado","Connecticut","Delaware","Florida","Georgia","Hawaii","Idaho","Illinois","Indiana","Iowa","Kansas","Kentucky","Louisiana","Maine","Maryland","Massachusetts","Michigan","Minnesota","Mississippi","Missouri","Montana","Nebraska","Nevada","New Hampshire","New Jersey","New Mexico","New York","North Carolina","North Dakota","Ohio","Oklahoma","Oregon","Pennsylvania","Rhode Island","South Carolina","South Dakota","Tennessee","Texas","Utah","Vermont","Virginia","Washington","West Virginia","Wisconsin","Wyoming"], ph:"1-310-745-3292" },
    "Canada":                 { code:"1",   states:["Alberta","British Columbia","Manitoba","New Brunswick","Newfoundland and Labrador","Northwest Territories","Nova Scotia","Nunavut","Ontario","Prince Edward Island","Quebec","Saskatchewan","Yukon"], ph:"1-416-555-0100" },
    "United Kingdom":         { code:"44",  states:["England","Northern Ireland","Scotland","Wales"], ph:"44-7911-123456" },
    "Australia":              { code:"61",  states:["Australian Capital Territory","New South Wales","Northern Territory","Queensland","South Australia","Tasmania","Victoria","Western Australia"], ph:"61-2-9876-5432" },
    "Pakistan":               { code:"92",  states:["Azad Kashmir","Balochistan","Gilgit-Baltistan","Islamabad Capital Territory","Khyber Pakhtunkhwa","Punjab","Sindh"], ph:"92-300-1234567" },
    "India":                  { code:"91",  states:["Andhra Pradesh","Arunachal Pradesh","Assam","Bihar","Chhattisgarh","Goa","Gujarat","Haryana","Himachal Pradesh","Jharkhand","Karnataka","Kerala","Madhya Pradesh","Maharashtra","Manipur","Meghalaya","Mizoram","Nagaland","Odisha","Punjab","Rajasthan","Sikkim","Tamil Nadu","Telangana","Tripura","Uttar Pradesh","Uttarakhand","West Bengal"], ph:"91-98765-43210" },
    "United Arab Emirates":   { code:"971", states:["Abu Dhabi","Ajman","Dubai","Fujairah","Ras Al Khaimah","Sharjah","Umm Al Quwain"], ph:"971-50-123-4567" },
    "Saudi Arabia":           { code:"966", states:["Al Bahah","Al Jawf","Al Madinah","Al Qassim","Asir","Eastern Province","Hail","Jizan","Makkah","Najran","Northern Borders","Riyadh","Tabuk"], ph:"966-50-123-4567" },
    "Germany":                { code:"49",  states:["Baden-Württemberg","Bavaria","Berlin","Brandenburg","Bremen","Hamburg","Hesse","Lower Saxony","Mecklenburg-Vorpommern","North Rhine-Westphalia","Rhineland-Palatinate","Saarland","Saxony","Saxony-Anhalt","Schleswig-Holstein","Thuringia"], ph:"49-030-1234567" },
    "France":                 { code:"33",  states:["Auvergne-Rhône-Alpes","Bretagne","Grand Est","Hauts-de-France","Île-de-France","Normandie","Nouvelle-Aquitaine","Occitanie","Pays de la Loire","Provence-Alpes-Côte d'Azur"], ph:"33-1-23-45-67-89" },
    "Turkey":                 { code:"90",  states:["Ankara","Antalya","Bursa","İstanbul","İzmir","Konya"], ph:"90-532-123-4567" },
    "Qatar":                  { code:"974", states:["Ad Dawhah","Al Khawr","Al Rayyan","Al Wakrah","Ash Shamal"], ph:"974-5012-3456" },
    "Kuwait":                 { code:"965", states:["Al Ahmadi","Al Asimah","Al Farwaniyah","Al Jahra","Hawalli","Mubarak Al-Kabeer"], ph:"965-5012-3456" },
    "Bahrain":                { code:"973", states:["Capital","Central","Muharraq","Northern","Southern"], ph:"973-3612-3456" },
    "Jordan":                 { code:"962", states:["Amman","Aqaba","Irbid","Karak","Zarqa"], ph:"962-79-123-4567" },
    "Egypt":                  { code:"20",  states:["Alexandria","Cairo","Giza","Luxor","Aswan"], ph:"20-100-123-4567" },
    "Nigeria":                { code:"234", states:["Abuja","Lagos","Kano","Rivers","Oyo"], ph:"234-802-123-4567" },
    "South Africa":           { code:"27",  states:["Eastern Cape","Gauteng","KwaZulu-Natal","Western Cape"], ph:"27-82-123-4567" },
    "Brazil":                 { code:"55",  states:["Bahia","Minas Gerais","Rio de Janeiro","São Paulo"], ph:"55-11-91234-5678" },
    "Mexico":                 { code:"52",  states:["Ciudad de México","Jalisco","Nuevo León","Veracruz"], ph:"52-55-1234-5678" },
    "China":                  { code:"86",  states:["Beijing","Guangdong","Shanghai","Sichuan"], ph:"86-138-0013-8000" },
    "Japan":                  { code:"81",  states:["Aichi","Hokkaido","Osaka","Tokyo"], ph:"81-90-1234-5678" },
    "South Korea":            { code:"82",  states:["Busan","Gyeonggi-do","Incheon","Seoul"], ph:"82-10-1234-5678" },
    "Malaysia":               { code:"60",  states:["Johor","Kuala Lumpur","Penang","Selangor","Sabah","Sarawak"], ph:"60-12-345-6789" },
    "Singapore":              { code:"65",  states:["Central Region","East Region","North Region","West Region"], ph:"65-8123-4567" },
    "New Zealand":            { code:"64",  states:["Auckland","Canterbury","Otago","Wellington"], ph:"64-21-123-4567" },
    "Russia":                 { code:"7",   states:["Moscow","Saint Petersburg","Novosibirsk","Yekaterinburg"], ph:"7-912-345-67-89" },
    "Afghanistan":            { code:"93",  states:["Kabul","Kandahar","Herat","Balkh","Nangarhar"], ph:"93-700-123-456" },
    "Iran":                   { code:"98",  states:["Tehran","Isfahan","Fars","Khuzestan","Razavi Khorasan"], ph:"98-912-345-6789" },
    "Iraq":                   { code:"964", states:["Baghdad","Basra","Erbil","Mosul","Najaf"], ph:"964-770-123-4567" },
    "Lebanon":                { code:"961", states:["Beirut","Bekaa","Mount Lebanon","North Lebanon","South Lebanon"], ph:"961-3-123-456" },
    "Oman":                   { code:"968", states:["Muscat","Dhofar","Al Batinah","Al Dakhiliyah"], ph:"968-9123-4567" },
    "Indonesia":              { code:"62",  states:["Bali","Jakarta","Java","Sumatra"], ph:"62-812-3456-7890" },
    "Philippines":            { code:"63",  states:["Cebu","Davao","Metro Manila","Quezon City"], ph:"63-917-123-4567" },
    "Bangladesh":             { code:"880", states:["Chittagong","Dhaka","Rajshahi","Sylhet"], ph:"880-1712-345678" },
    "Italy":                  { code:"39",  states:["Lazio","Lombardy","Sicily","Tuscany","Veneto"], ph:"39-06-1234-5678" },
    "Spain":                  { code:"34",  states:["Andalusia","Catalonia","Madrid","Valencia"], ph:"34-612-345-678" }
  };

  // ── Grab your exact field IDs ─────────────────────────────────
  var countryEl = document.getElementById("country-select");
  var stateEl   = document.getElementById("state-select");
  var phoneEl   = document.getElementById("phone-input");

  if (!countryEl) return;

  // ── On country change ─────────────────────────────────────────
  countryEl.addEventListener("change", function () {
    var val     = this.value;
    var country = COUNTRIES[val];

    // ── Populate states ───────────────────────────────────────
    if (stateEl) {
      stateEl.value = "";
      stateEl.setAttribute("list", "state-options");

      var datalist = document.getElementById("state-options");
      if (!datalist) {
        datalist = document.createElement("datalist");
        datalist.id = "state-options";
        stateEl.parentNode.insertBefore(datalist, stateEl.nextSibling);
      }
      datalist.innerHTML = "";

      if (country && country.states) {
        country.states.forEach(function(s) {
          var opt = document.createElement("option");
          opt.value = s;
          datalist.appendChild(opt);
        });
        stateEl.placeholder = "Select or type state";
      } else {
        stateEl.placeholder = "Enter state / province";
      }
    }

    // ── Set phone prefix ──────────────────────────────────────
    if (phoneEl && country) {
      phoneEl.placeholder = country.ph;
      if (phoneEl.value === "" || /^\d+-?$/.test(phoneEl.value)) {
        phoneEl.value = country.code + "-";
      }
      phoneEl.setAttribute("data-code", country.code);
    }
  });

  // ── Phone format guard ────────────────────────────────────────
  if (phoneEl) {
    phoneEl.addEventListener("keydown", function(e) {
      var code   = this.getAttribute("data-code") || "";
      var prefix = code + "-";
      if ((e.key === "Backspace" || e.key === "Delete") && this.value.length <= prefix.length) {
        e.preventDefault();
      }
    });
    phoneEl.addEventListener("input", function() {
      var code = this.getAttribute("data-code") || "";
      if (code && !this.value.startsWith(code)) {
        this.value = code + "-" + this.value.replace(/^[-\d]*-?/, "");
      }
      this.value = this.value.replace(/[^\d-]/g, "");
    });
  }

});
</script>
<?php });
