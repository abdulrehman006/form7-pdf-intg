<?php
/**
 * Plugin Name: Contact Form 7 Working PDF Generator
 * Plugin URI: https://github.com/cf7-pdf/working-generator
 * Description: Optimized PDF generator for Contact Form 7 with image link handling and email status tracking.
 * Version: 4.0.4
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: CF7 PDF Team
 * License: GPL-2.0+
 * Text Domain: cf7-working-pdf
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CF7_WORKING_PDF_VERSION', '4.0.4');
define('CF7_WORKING_PDF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CF7_WORKING_PDF_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Plugin Class
 */
class CF7_Working_PDF_Generator {
    private static $instance = null;
    private $db_version = '1.0';
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 20);
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    public function init() {
        if (!class_exists('WPCF7')) {
            add_action('admin_notices', array($this, 'cf7_missing_notice'));
            return;
        }
        
        // Include PDF generator
        if (!file_exists(CF7_WORKING_PDF_PLUGIN_DIR . 'includes/pdf-generator.php')) {
            add_action('admin_notices', array($this, 'pdf_generator_missing_notice'));
            return;
        }
        require_once CF7_WORKING_PDF_PLUGIN_DIR . 'includes/pdf-generator.php';
        
        add_action('wpcf7_before_send_mail', array($this, 'process_form_submission'), 10, 3);
        add_action('wp_loaded', array($this, 'check_database_update'), 10);
        add_action('admin_menu', array($this, 'add_admin_menus'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
		add_action('wp_enqueue_scripts',  array($this, 'cf7wpdf_enqueue_assets'));

        // Register AJAX handlers
        add_action('wp_ajax_cf7_working_pdf_get_submission', array($this, 'ajax_get_submission'));
        add_action('wp_ajax_cf7_working_pdf_delete_submission', array($this, 'ajax_delete_submission'));
        add_action('wp_ajax_cf7_working_pdf_delete_image', array($this, 'ajax_delete_image'));
        add_action('wp_ajax_cf7_working_pdf_download_pdf', array($this, 'ajax_download_pdf'));
    }
    
    public function cf7_missing_notice() {
        echo '<div class="notice notice-error"><p>' . __('Contact Form 7 Working PDF Generator requires Contact Form 7 plugin to be installed and activated.', 'cf7-working-pdf') . '</p></div>';
    }
    
    public function pdf_generator_missing_notice() {
        echo '<div class="notice notice-error"><p>' . __('The Contact Form 7 Working PDF Generator plugin cannot find the pdf-generator.php file.', 'cf7-working-pdf') . '</p></div>';
    }
    
    /**
     * Process Contact Form 7 submission, generate PDF, handle images, and modify email
     */
    public function process_form_submission($contact_form, &$abort, $submission) {
        $form_id = $contact_form->id();
        $posted_data = $submission->get_posted_data();
        $uploaded_files = $submission->uploaded_files();
        
        $settings = get_option('cf7_working_pdf_settings', array(
            'enable_pdf' => true,
            'pdf_design' => 'modern',
            'header_color' => '#2271b1',
            'store_submissions' => true
        ));
        
        // Store submission
        $submission_id = 0;
        if ($settings['store_submissions']) {
            try {
                $this->ensure_database_table();
                $submission_id = $this->store_submission($contact_form, $posted_data);
            } catch (Exception $e) {
                error_log('[CF7_PDF] Database error: ' . $e->getMessage());
                add_action('admin_notices', array($this, 'database_error_notice'));
            }
        }
        
        try {
            // Generate PDF
              $data = [
        'form_id' => $contact_form->id(),
        'submission_data' => $submission->get_posted_data(),
        'files' => $submission->uploaded_files(),
    ];
         
            $pdf_path  = cf7_working_pdf_generate($data, 'submission-' . $form_id . '.pdf');
            $image_links = $this->process_image_uploads($uploaded_files, $submission_id);
            
                
              if ($pdf_path) {
                    // Modify CF7 email to include PDF and image links in HTML format
                    add_filter('wpcf7_mail_components', function($components, $contact_form, $mail) use ($pdf_path, $image_links, $submission_id) {
                        $components['attachments'] = array($pdf_path);
                        
                        // Check if the email supports HTML
                        if (!empty($image_links)) {
                            // Set HTML content type if not already set
                            if (empty($components['html'])) {
                                $components['html'] = true;
                                $components['body'] = wpautop($components['body']); // Convert plain text to HTML paragraphs
                            }
                            
                            // Add HTML-formatted image attachments section
                            $image_section = '<div style="font-family: Arial, sans-serif; margin-top: 20px; padding: 15px; border-top: 1px solid #e0e0e0;">';
                            $image_section .= '<h3 style="color: #333; font-size: 16px; margin: 0 0 10px;">Image Attachments</h3>';
                            $image_section .= '<p style="color: #666; font-size: 14px; margin: 0 0 10px;">Click the links below to download the attached images:</p>';
                            $image_section .= '<table style="width: 100%; border-collapse: collapse; font-size: 14px;">';
                            
                            foreach ($image_links as $link_data) {
                                $label = esc_html($link_data['label']);
                                $url = esc_url($link_data['url']);
                                $image_section .= '<tr>';
                                $image_section .= '<td style="padding: 8px; border-bottom: 1px solid #e0e0e0; color: #333;">' . $label . '</td>';
                                $image_section .= '<td style="padding: 8px; border-bottom: 1px solid #e0e0e0;"><a href="' . $url . '" style="color: #0073aa; text-decoration: none; font-weight: bold;">Download</a></td>';
                                $image_section .= '</tr>';
                            }
                            
                            $image_section .= '</table>';
                            $image_section .= '</div>';
                            
                            $components['body'] .= $image_section;
                        }
                        
                        return $components;
                    }, 10, 3);
                    
                    // Track email status
                    add_action('wpcf7_mail_sent', function($contact_form) use ($submission_id, $pdf_path) {
                        if ($submission_id) {
                            $this->store_email_status($submission_id, array(
                                'recipient' => $contact_form->prop('mail')['recipient'],
                                'status' => 'success',
                                'timestamp' => current_time('mysql')
                            ));
                        }
                        if (file_exists($pdf_path)) {
                            unlink($pdf_path);
                        }
                    });
                    
                    add_action('wpcf7_mail_failed', function($contact_form) use ($submission_id, $pdf_path) {
                        if ($submission_id) {
                            $this->store_email_status($submission_id, array(
                                'recipient' => $contact_form->prop('mail')['recipient'],
                                'status' => 'failed',
                                'timestamp' => current_time('mysql')
                            ));
                        }
                        if (file_exists($pdf_path)) {
                            unlink($pdf_path);
                        }
                    });
                }
            
        } catch (Exception $e) {
            error_log('[CF7_PDF] PDF generation error: ' . $e->getMessage());
            add_action('admin_notices', array($this, 'pdf_generation_error_notice'));
        }
    }
    
    /**
     * Process and upload images to permanent folder
     */
    private function process_image_uploads($uploaded_files, $submission_id) {
        $image_links = array();
        $upload_dir = wp_upload_dir();
        $permanent_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/images/' . date('Y-m') . '/';
        
        if (!file_exists($permanent_dir)) {
            wp_mkdir_p($permanent_dir);
            file_put_contents($permanent_dir . '/.htaccess', "Order deny,allow\nAllow from all");
        }
        
        foreach ($uploaded_files as $field_name => $file_paths) {
            if (!is_array($file_paths)) {
                $file_paths = array($file_paths);
            }
            
            foreach ($file_paths as $index => $file_path) {
                if ($this->is_image_file($file_path)) {
                    $filename = sanitize_file_name($submission_id . '-' . $field_name . '-' . time() . '-' . $index . '.' . pathinfo($file_path, PATHINFO_EXTENSION));
                    $destination = $permanent_dir . $filename;
                    
                    if (copy($file_path, $destination)) {
                        $file_url = $upload_dir['baseurl'] . '/cf7-working-pdfs/images/' . date('Y-m') . '/' . $filename;
                        $image_links[] = array(
                            'label' => $this->format_field_label($field_name) . ($index > 0 ? ' ' . ($index + 1) : ''),
                            'url' => $file_url,
                            'path' => $destination
                        );
                        
                        if ($submission_id) {
                            $this->store_image_metadata($submission_id, $field_name, $filename, $file_url, $destination);
                        }
                    }
                }
            }
        }
        
        return $image_links;
    }
    
    /**
     * Store image metadata in database
     */
    private function store_image_metadata($submission_id, $field_name, $filename, $file_url, $file_path) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cf7_working_pdf_images',
            array(
                'submission_id' => $submission_id,
                'field_name' => $field_name,
                'filename' => $filename,
                'file_url' => $file_url,
                'file_path' => $file_path,
                'upload_date' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Store email status in database
     */
    private function store_email_status($submission_id, $email_status) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'cf7_working_pdf_email_status',
            array(
                'submission_id' => $submission_id,
                'status_data' => json_encode($email_status),
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s')
        );
    }
    
    public function database_error_notice() {
        echo '<div class="notice notice-error"><p>' . __('Database error in Contact Form 7 Working PDF Generator. PDF was generated, but submission storage failed.', 'cf7-working-pdf') . '</p></div>';
    }
    
    public function pdf_generation_error_notice() {
        echo '<div class="notice notice-error"><p>' . __('Failed to generate PDF in Contact Form 7 Working PDF Generator. Form submission processed, but no PDF attached.', 'cf7-working-pdf') . '</p></div>';
    }
    
    private function store_submission($contact_form, $posted_data) {
        global $wpdb;
        $form_data = array();
        foreach ($posted_data as $key => $value) {
            if (strpos($key, '_wpcf7') === 0 || strpos($key, 'g-recaptcha') === 0) {
                continue;
            }
            $form_data[$key] = is_array($value) ? implode(', ', $value) : $value;
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'cf7_working_pdf_submissions',
            array(
                'form_id' => $contact_form->id(),
                'form_title' => $contact_form->title(),
                'form_data_json' => json_encode($form_data),
                'submission_date' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            throw new Exception('Database insert failed: ' . $wpdb->last_error);
        }
        
        return $wpdb->insert_id;
    }
    
    private function ensure_database_table() {
        global $wpdb;
        $tables = array(
            'submissions' => $wpdb->prefix . 'cf7_working_pdf_submissions',
            'images' => $wpdb->prefix . 'cf7_working_pdf_images',
            'email_status' => $wpdb->prefix . 'cf7_working_pdf_email_status'
        );
        
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                $this->create_database_tables();
                if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                    throw new Exception('Failed to create database table: ' . $table);
                }
            }
        }
    }
    
    private function is_image_file($file_path) {
        $image_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp');
        return in_array(strtolower(pathinfo($file_path, PATHINFO_EXTENSION)), $image_extensions);
    }
    
    
    
    private function format_field_label($key) {
        $label = str_replace(array('your-', 'your_', 'cf7_'), '', $key);
        $label = str_replace(array('-', '_'), ' ', $label);
        return ucwords($label);
    }
    
  
    
    private function is_file_upload($value) {
        if (empty($value) || !is_string($value)) {
            return false;
        }
        $extensions = array('jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt', 'zip', 'rar');
        return in_array(strtolower(pathinfo($value, PATHINFO_EXTENSION)), $extensions) || preg_match('/\.(jpg|jpeg|png|gif|pdf|doc|docx|txt|zip|rar)$/i', $value);
    }
    
    public function add_admin_menus() {
        add_submenu_page(
            'wpcf7',
            __('PDF Submissions', 'cf7-working-pdf'),
            __('PDF Submissions', 'cf7-working-pdf'),
            'manage_options',
            'cf7-working-pdf-submissions',
            array($this, 'submissions_page')
        );
        add_submenu_page(
            'wpcf7',
            __('PDF Settings', 'cf7-working-pdf'),
            __('PDF Settings', 'cf7-working-pdf'),
            'manage_options',
            'cf7-working-pdf-settings',
            array($this, 'settings_page')
        );
    }
    
    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=cf7-working-pdf-settings">' . __('Settings', 'cf7-working-pdf') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            check_admin_referer('cf7_working_pdf_settings');
            
            $settings = array(
                'enable_pdf' => isset($_POST['enable_pdf']) ? 1 : 0,
                'pdf_design' => sanitize_text_field($_POST['pdf_design']),
                'header_color' => sanitize_hex_color($_POST['header_color']),
                'store_submissions' => isset($_POST['store_submissions']) ? 1 : 0
            );
            
            update_option('cf7_working_pdf_settings', $settings);
            echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'cf7-working-pdf') . '</p></div>';
        }
        
        $settings = get_option('cf7_working_pdf_settings', array(
            'enable_pdf' => true,
            'pdf_design' => 'modern',
            'header_color' => '#2271b1',
            'store_submissions' => true
        ));
        
        include CF7_WORKING_PDF_PLUGIN_DIR . 'includes/admin-settings.php';
    }
    
      public function admin_enqueue_scripts($hook) {
        // Enqueue scripts for both submissions and settings pages
        if (strpos($hook, 'wpcf7') !== false || strpos($hook, 'cf7-working-pdf') !== false) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            wp_enqueue_script('cf7-working-pdf-admin', CF7_WORKING_PDF_PLUGIN_URL . 'assets/admin.js', array('jquery', 'wp-color-picker'), CF7_WORKING_PDF_VERSION, true);
			 wp_enqueue_style('cf7-working-pdf-admin', CF7_WORKING_PDF_PLUGIN_URL . 'assets/admin.css', array(), CF7_WORKING_PDF_VERSION);
            wp_localize_script('cf7-working-pdf-admin', 'cf7_working_pdf_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cf7_working_pdf_nonce'),
                'confirm_delete' => __('Are you sure you want to delete this submission?', 'cf7-working-pdf'),
                'confirm_image_delete' => __('Are you sure you want to delete this image?', 'cf7-working-pdf')
            ));
            error_log('CF7 PDF: Enqueued admin.js and localized cf7_working_pdf_ajax for hook: ' . $hook);
        }
    }
	
	// Enqueue CSS and JavaScript
 public function cf7wpdf_enqueue_assets() {
    // Only enqueue on pages with a Contact Form 7 form
    if (function_exists('wpcf7_enqueue_scripts')) {
        // Enqueue jQuery (required for cf7-conditional-test.js)
        wp_enqueue_script('jquery');

        // Enqueue JavaScript
        wp_enqueue_script(
            'cf7wpdf-conditional-test', // Handle
            plugins_url('assets/frontend.js', __FILE__), // Path
            ['jquery'], // Dependencies
            '1.0.1', // Version
            true // Load in footer
        );

        // Enqueue CSS
        wp_enqueue_style(
            'cf7wpdf-styles', // Handle
            plugins_url('assets/frontend.css', __FILE__), // Path
            []
        );
    }
}
    
    public function submissions_page() {
        include CF7_WORKING_PDF_PLUGIN_DIR . 'includes/admin-submissions.php';
    }
    
 
 public function ajax_get_submission() {
        check_ajax_referer('cf7_working_pdf_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'cf7-working-pdf'));
        }
        
        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        if (!$submission_id) {
            wp_send_json_error(__('Invalid submission ID.', 'cf7-working-pdf'));
        }
        
        global $wpdb;
        $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cf7_working_pdf_submissions WHERE id = %d", $submission_id));
        if (!$submission) {
            wp_send_json_error(__('Submission not found.', 'cf7-working-pdf'));
        }
        
        $form_data = json_decode($submission->form_data_json, true);
        $images = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cf7_working_pdf_images WHERE submission_id = %d", $submission_id));
        $email_status = $wpdb->get_row($wpdb->prepare("SELECT status_data FROM {$wpdb->prefix}cf7_working_pdf_email_status WHERE submission_id = %d", $submission_id));
        
        $html = '<div class="submission-modal-content">';
        $html .= '<h3>' . esc_html($submission->form_title) . '</h3>';
        $html .= '<div class="submission-meta">';
        $html .= '<span><strong>Date:</strong> ' . mysql2date('F j, Y g:i A', $submission->submission_date) . '</span>';
        $html .= '<span><strong>Form ID:</strong> ' . esc_html($submission->form_id) . '</span>';
        if ($email_status) {
            $status_data = json_decode($email_status->status_data, true);
            $html .= '<span><strong>Email Status:</strong> ' . esc_html($status_data['status']) . '</span>';
        }
        $html .= '</div>';
        
        $html .= '<div class="submission-fields">';
        foreach ($form_data as $key => $value) {
            if (strpos($key, '_wpcf7') === 0 || strpos($key, 'g-recaptcha') === 0) {
                continue;
            }
            $html .= '<div class="modal-field"><div class="modal-field-label">' . esc_html($this->format_field_label($key)) . '</div><div class="modal-field-value">' . nl2br(esc_html($value)) . '</div></div>';
        }
        
        if (!empty($images)) {
            $html .= '<div class="modal-field"><div class="modal-field-label">Images</div><div class="modal-field-value">';
            foreach ($images as $image) {
                $html .= '<div style="margin-bottom: 10px;"><a href="' . esc_url($image->file_url) . '" download="' . esc_attr($image->filename) . '">' . esc_html($image->filename) . '</a> <a href="#" class="delete-image" data-image-id="' . esc_attr($image->id) . '">Delete</a></div>';
            }
            $html .= '</div></div>';
        }
        
        $html .= '</div></div>';
        wp_send_json_success(array('html' => $html));
    }
    
    public function ajax_delete_submission() {
        check_ajax_referer('cf7_working_pdf_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'cf7-working-pdf'));
        }
        
        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        if (!$submission_id) {
            wp_send_json_error(__('Invalid submission ID.', 'cf7-working-pdf'));
        }
        
        global $wpdb;
        $images = $wpdb->get_results($wpdb->prepare("SELECT file_path FROM {$wpdb->prefix}cf7_working_pdf_images WHERE submission_id = %d", $submission_id));
        foreach ($images as $image) {
            if (file_exists($image->file_path)) {
                unlink($image->file_path);
            }
        }
        $wpdb->delete($wpdb->prefix . 'cf7_working_pdf_images', array('submission_id' => $submission_id), array('%d'));
        $result = $wpdb->delete($wpdb->prefix . 'cf7_working_pdf_submissions', array('id' => $submission_id), array('%d'));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => __('Submission deleted.', 'cf7-working-pdf')));
        } else {
            wp_send_json_error(__('Error deleting submission.', 'cf7-working-pdf'));
        }
    }
    
    public function ajax_delete_image() {
        check_ajax_referer('cf7_working_pdf_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'cf7-working-pdf'));
        }
        
        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
        if (!$image_id) {
            wp_send_json_error(__('Invalid image ID.', 'cf7-working-pdf'));
        }
        
        global $wpdb;
        $image = $wpdb->get_row($wpdb->prepare("SELECT file_path FROM {$wpdb->prefix}cf7_working_pdf_images WHERE id = %d", $image_id));
        
        if ($image && file_exists($image->file_path)) {
            unlink($image->file_path);
            $wpdb->delete($wpdb->prefix . 'cf7_working_pdf_images', array('id' => $image_id), array('%d'));
            wp_send_json_success(__('Image deleted.', 'cf7-working-pdf'));
        }
        wp_send_json_error(__('Error deleting image.', 'cf7-working-pdf'));
    }
    
    public function ajax_download_pdf() {
        check_ajax_referer('cf7_working_pdf_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'cf7-working-pdf'));
        }
        
        $submission_id = isset($_POST['submission_id']) ? intval($_POST['submission_id']) : 0;
        if (!$submission_id) {
            wp_send_json_error(__('Invalid submission ID.', 'cf7-working-pdf'));
        }
        
        global $wpdb;
        $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cf7_working_pdf_submissions WHERE id = %d", $submission_id));
        if (!$submission) {
            wp_send_json_error(__('Submission not found.', 'cf7-working-pdf'));
        }
        
        $form_data = json_decode($submission->form_data_json, true);
        $settings = get_option('cf7_working_pdf_settings', array());
        
        try {
            $html = $this->generate_pdf_html($form_data, $submission->form_id, $settings);
            $pdf_data = cf7_working_pdf_generate($html, 'submission-' . $submission->id . '.pdf');
            
            if ($pdf_data) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="submission-' . $submission->id . '-' . date('Y-m-d') . '.pdf"');
                header('Content-Length: ' . strlen($pdf_data));
                echo $pdf_data;
                wp_die();
            }
            wp_send_json_error(__('Error generating PDF.', 'cf7-working-pdf'));
        } catch (Exception $e) {
            error_log('[CF7_PDF] AJAX PDF generation error: ' . $e->getMessage());
            wp_send_json_error(__('Error generating PDF: ' . $e->getMessage(), 'cf7-working-pdf'));
        }
    }
    
    public function check_database_update() {
        $current_version = get_option('cf7_working_pdf_db_version', '0');
        if (version_compare($current_version, $this->db_version, '<')) {
            $this->create_database_tables();
            update_option('cf7_working_pdf_db_version', $this->db_version);
        }
    }
    
    private function create_database_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$wpdb->prefix}cf7_working_pdf_submissions (
            id int(11) NOT NULL AUTO_INCREMENT,
            form_id int(11) NOT NULL,
            form_title varchar(255) NOT NULL,
            form_data_json longtext NOT NULL,
            submission_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) $charset_collate;";
        
        $sql .= "CREATE TABLE {$wpdb->prefix}cf7_working_pdf_images (
            id int(11) NOT NULL AUTO_INCREMENT,
            submission_id int(11) NOT NULL,
            field_name varchar(255) NOT NULL,
            filename varchar(255) NOT NULL,
            file_url longtext NOT NULL,
            file_path longtext NOT NULL,
            upload_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY submission_id (submission_id)
        ) $charset_collate;";
        
        $sql .= "CREATE TABLE {$wpdb->prefix}cf7_working_pdf_email_status (
            id int(11) NOT NULL AUTO_INCREMENT,
            submission_id int(11) NOT NULL,
            status_data longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY submission_id (submission_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function activate() {
        $this->create_database_tables();
        add_option('cf7_working_pdf_settings', array(
            'enable_pdf' => true,
            'pdf_design' => 'modern',
            'header_color' => '#2271b1',
            'store_submissions' => true
        ));
        
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/cf7-working-pdfs';
        $image_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/images';
        
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
            file_put_contents($pdf_dir . '/.htaccess', "Order deny,allow\nDeny from all\n<Files *.pdf>\nAllow from all\n</Files>");
        }
        
        if (!file_exists($image_dir)) {
            wp_mkdir_p($image_dir);
            file_put_contents($image_dir . '/.htaccess', "Order deny,allow\nAllow from all");
        }
        
        update_option('cf7_working_pdf_db_version', $this->db_version);
    }
}
CF7_Working_PDF_Generator::getInstance();
?>