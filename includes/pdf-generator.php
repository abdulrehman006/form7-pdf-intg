<?php
/**
 * Multi-Purpose PDF Generator for Contact Form 7
 *
 * Creates professional PDF documents from any CF7 form submission.
 * Supports multiple design templates (Modern, Classic, Minimal) with customizable colors.
 *
 * @package CF7_Working_PDF_Generator
 * @since 4.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PDF Generator Engine Class
 */
class CF7_Working_PDF_Engine {

    /**
     * Design template configurations
     *
     * @var array
     */
    private static $templates = array(
        'modern' => array(
            'font_family' => 'Helvetica',
            'header_height' => 25,
            'section_style' => 'rounded',
            'use_alternating_rows' => true,
            'border_style' => 0,
            'cell_padding' => 8,
        ),
        'classic' => array(
            'font_family' => 'Times',
            'header_height' => 20,
            'section_style' => 'bordered',
            'use_alternating_rows' => false,
            'border_style' => 1,
            'cell_padding' => 6,
        ),
        'minimal' => array(
            'font_family' => 'Helvetica',
            'header_height' => 15,
            'section_style' => 'underline',
            'use_alternating_rows' => false,
            'border_style' => 0,
            'cell_padding' => 5,
        ),
    );

    /**
     * Current settings
     *
     * @var array
     */
    private static $settings = array();

    /**
     * Generate PDF from form data
     *
     * @param array  $data     Form data including submission_data, files, and settings
     * @param string $filename Output filename
     * @return string|false Path to generated PDF or false on failure
     */
    public static function generate_pdf($data, $filename = 'document.pdf') {
        // Pre-flight environment checks
        $env_check = self::check_environment();
        if (is_wp_error($env_check)) {
            self::log_error('Environment check failed: ' . $env_check->get_error_message());
            return false;
        }

        // Include FPDF
        $fpdf_path = defined('CF7_WORKING_PDF_PLUGIN_DIR')
            ? CF7_WORKING_PDF_PLUGIN_DIR . 'includes/fpdf/fpdf.php'
            : '';

        if (!file_exists($fpdf_path)) {
            self::log_error('FPDF file not found at: ' . $fpdf_path);
            return false;
        }

        require_once $fpdf_path;

        // Set execution limits for PDF generation
        $original_time_limit = ini_get('max_execution_time');
        $original_memory_limit = ini_get('memory_limit');

        // Increase limits if possible
        @set_time_limit(300); // 5 minutes
        @ini_set('memory_limit', '256M');

        // Extract and store settings
        self::$settings = isset($data['settings']) && is_array($data['settings'])
            ? $data['settings']
            : self::get_default_settings();

        $result = self::generate_document($data, $filename);

        // Restore original limits
        @set_time_limit($original_time_limit);
        @ini_set('memory_limit', $original_memory_limit);

        return $result;
    }

    /**
     * Check environment before PDF generation
     *
     * @return true|WP_Error True if OK, WP_Error on failure
     */
    private static function check_environment() {
        // Check memory availability (need at least 64MB free)
        $memory_limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $memory_used = memory_get_usage(true);
        $memory_available = $memory_limit - $memory_used;

        if ($memory_available < 64 * 1024 * 1024) {
            return new WP_Error(
                'memory_low',
                sprintf('Insufficient memory. Available: %s, Required: 64MB', size_format($memory_available))
            );
        }

        // Check disk space (need at least 50MB free)
        $upload_dir = wp_upload_dir();
        $free_space = @disk_free_space($upload_dir['basedir']);

        if ($free_space !== false && $free_space < 50 * 1024 * 1024) {
            return new WP_Error(
                'disk_full',
                sprintf('Insufficient disk space. Available: %s, Required: 50MB', size_format($free_space))
            );
        }

        // Check if upload directory is writable
        $pdf_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/';
        if (file_exists($pdf_dir) && !is_writable($pdf_dir)) {
            return new WP_Error(
                'not_writable',
                'PDF directory is not writable: ' . $pdf_dir
            );
        }

        return true;
    }

    /**
     * Get default settings
     *
     * @return array
     */
    private static function get_default_settings() {
        return array(
            'pdf_design' => 'modern',
            'header_color' => '#2271b1',
            'pdf_title' => 'Form Submission',
            'company_name' => '',
            'max_image_size' => 1024,
            'image_quality' => 85,
        );
    }

    /**
     * Convert hex color to RGB array
     *
     * @param string $hex Hex color code
     * @return array RGB values
     */
    private static function hex_to_rgb($hex) {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return array(
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    /**
     * Generate the PDF document
     *
     * @param array  $data     Form data
     * @param string $filename Output filename
     * @return string|false
     */
    private static function generate_document($data, $filename) {
        // Extract data
        $form_id = isset($data['form_id']) ? absint($data['form_id']) : 0;
        $submission_id = isset($data['submission_id']) ? absint($data['submission_id']) : 0;
        $submission_data = isset($data['submission_data']) && is_array($data['submission_data'])
            ? $data['submission_data']
            : array();
        $files = isset($data['files']) && is_array($data['files'])
            ? $data['files']
            : array();

        // Get template configuration
        $design = isset(self::$settings['pdf_design']) ? self::$settings['pdf_design'] : 'modern';
        $template = isset(self::$templates[$design]) ? self::$templates[$design] : self::$templates['modern'];

        // Get header color
        $header_color = isset(self::$settings['header_color']) ? self::$settings['header_color'] : '#2271b1';
        $rgb = self::hex_to_rgb($header_color);

        // Get titles
        $pdf_title = isset(self::$settings['pdf_title']) ? self::$settings['pdf_title'] : 'Form Submission';
        $company_name = isset(self::$settings['company_name']) ? self::$settings['company_name'] : '';

        try {
            // Initialize PDF
            $pdf = new FPDF('P', 'mm', 'A4');
            $pdf->SetMargins(10, 10, 10);
            $pdf->SetAutoPageBreak(true, 15);
            $pdf->AddPage();
            $pdf->SetFont($template['font_family'], '', 10);

            // Generate header based on design - use submission_id for display
            self::generate_header($pdf, $template, $rgb, $pdf_title, $company_name, $submission_id);

            // Process form fields into sections
            $sections = self::organize_form_data($submission_data);

            // Generate sections
            foreach ($sections as $section_name => $fields) {
                if (empty($fields)) {
                    continue;
                }
                self::generate_section($pdf, $template, $rgb, $section_name, $fields);
            }

            // Generate images section if files exist
            if (!empty($files)) {
                self::generate_images_section($pdf, $template, $rgb, $files);
            }

            // Generate footer
            self::generate_footer($pdf, $template, $submission_id);

            // Save PDF - use submission_id for filename
            return self::save_pdf($pdf, $submission_id);

        } catch (Exception $e) {
            self::log_error('PDF generation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate PDF header based on design template
     *
     * @param FPDF   $pdf           PDF instance
     * @param array  $template      Template configuration
     * @param array  $rgb           Header color RGB
     * @param string $title         PDF title
     * @param string $company_name  Company name
     * @param int    $submission_id Submission/Entry ID
     */
    private static function generate_header($pdf, $template, $rgb, $title, $company_name, $submission_id) {
        $design = isset(self::$settings['pdf_design']) ? self::$settings['pdf_design'] : 'modern';

        switch ($design) {
            case 'modern':
                // Full-width colored header
                $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
                $pdf->Rect(0, 0, 210, $template['header_height'], 'F');

                // Title in white
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont($template['font_family'], 'B', 18);
                $pdf->SetXY(10, 8);
                $pdf->Cell(150, 10, $title, 0, 0, 'L');

                // Submission ID badge
                $pdf->SetFont($template['font_family'], '', 10);
                $pdf->SetXY(160, 8);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
                $pdf->Cell(40, 10, 'ID: ' . $submission_id, 0, 0, 'C', true);

                // Company name if set
                if (!empty($company_name)) {
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont($template['font_family'], '', 10);
                    $pdf->SetXY(10, 18);
                    $pdf->Cell(150, 5, $company_name, 0, 0, 'L');
                }

                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetY($template['header_height'] + 10);
                break;

            case 'classic':
                // Bordered header with lines
                $pdf->SetFont($template['font_family'], 'B', 16);
                $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
                $pdf->Cell(190, 10, $title, 0, 1, 'C');

                if (!empty($company_name)) {
                    $pdf->SetFont($template['font_family'], 'I', 11);
                    $pdf->SetTextColor(100, 100, 100);
                    $pdf->Cell(190, 6, $company_name, 0, 1, 'C');
                }

                // Decorative lines
                $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
                $pdf->SetLineWidth(0.5);
                $pdf->Line(10, $pdf->GetY() + 2, 200, $pdf->GetY() + 2);
                $pdf->SetLineWidth(0.2);
                $pdf->Line(10, $pdf->GetY() + 4, 200, $pdf->GetY() + 4);

                // Submission ID on right
                $pdf->SetFont($template['font_family'], '', 10);
                $pdf->SetTextColor(100, 100, 100);
                $pdf->SetXY(150, 10);
                $pdf->Cell(50, 6, 'ID: ' . $submission_id, 0, 0, 'R');

                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetY($pdf->GetY() + 15);
                break;

            case 'minimal':
                // Simple text header
                $pdf->SetFont($template['font_family'], 'B', 14);
                $pdf->SetTextColor(50, 50, 50);
                $pdf->Cell(150, 8, $title, 0, 0, 'L');

                $pdf->SetFont($template['font_family'], '', 9);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->Cell(40, 8, '#' . $submission_id, 0, 1, 'R');

                if (!empty($company_name)) {
                    $pdf->SetFont($template['font_family'], '', 10);
                    $pdf->Cell(190, 5, $company_name, 0, 1, 'L');
                }

                $pdf->SetTextColor(0, 0, 0);
                $pdf->Ln(8);
                break;
        }

        // Add submission date
        $pdf->SetFont($template['font_family'], '', 9);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(190, 5, 'Submitted: ' . current_time('F j, Y \a\t g:i A'), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(5);
    }

    /**
     * Organize form data into logical sections
     *
     * @param array $submission_data Raw form data
     * @return array Organized sections
     */
    private static function organize_form_data($submission_data) {
        $sections = array(
            'BASIC INFORMATION' => array(),
            'PROCEDURES OF INTEREST' => array(),
            'MEDICAL HISTORY' => array(),
        );

        // Fields that belong to PROCEDURES OF INTEREST section
        $procedure_fields = array(
            'procedure', 'procedures', 'procedure-interest', 'procedures-of-interest',
            'other-procedure', 'other-procedure-text',
            'procedure-describe', 'procedure-description', 'procedure-concerns',
        );

        // Fields that belong to MEDICAL HISTORY section
        $medical_fields = array(
            'height', 'weight',
            'losing-weight', 'process-losing-weight',
            'goal-weight', 'pounds-away', 'pounds-goal',
            'weight-loss-med', 'weight-loss-medication', 'semaglutide',
            'medical-condition', 'conditions',
            'medication', 'current-medication',
            'allerg', 'allergies',
            'smoke', 'tobacco', 'nicotine',
            'blood-clot', 'clot',
            'surgery-interest', 'when-surgery', 'interested-surgery',
            'past-surgery', 'past-surgeries', 'surgeries-past', 'surgeries-had',
            'complication', 'complications', 'experience-complication',
            'satisfied', 'previous-plastic', 'aesthetic-procedure',
            'previous-treatment', 'treatments-face', 'treatments-neck',
            'threads', 'sculptra', 'radiesse',
            'consent',
        );

        // Custom label mapping for MEDICAL HISTORY fields
        $medical_label_overrides = array(
            'height'                    => 'Height',
            'weight'                    => 'Weight',
            'weight-loss'               => 'Are you currently in the process of losing weight?',
            'losing-weight'             => 'Are you currently in the process of losing weight?',
            'process-losing-weight'     => 'Are you currently in the process of losing weight?',
            'goal-weight'               => 'If yes, how many pounds away are you from your goal weight?',
            'pounds-away'               => 'If yes, how many pounds away are you from your goal weight?',
            'pounds-goal'               => 'If yes, how many pounds away are you from your goal weight?',
            'weight-loss-medications'   => 'Are you currently using any weight loss medications (e.g., Ozempic, Wegovy, Mounjaro, or other semaglutide)?',
            'weight-loss-med'           => 'Are you currently using any weight loss medications (e.g., Ozempic, Wegovy, Mounjaro, or other semaglutide)?',
            'medical-conditions'        => 'What medical conditions do you have?',
            'medical-condition'         => 'What medical conditions do you have?',
            'conditions'                => 'What medical conditions do you have?',
            'current-medications'       => 'Current medications',
            'current-medication'        => 'Current medications',
            'medications'               => 'Current medications',
            'allergies'                 => 'Allergies to any medications?',
            'allergies-medications'     => 'Allergies to any medications?',
            'smoking'                   => 'Do you currently smoke tobacco or use nicotine?',
            'currently-smoke'           => 'Do you currently smoke tobacco or use nicotine?',
            'smoke-tobacco'             => 'Do you currently smoke tobacco or use nicotine?',
            'tobacco-history'           => 'Have you ever used tobacco or nicotine products?',
            'ever-tobacco'              => 'Have you ever used tobacco or nicotine products?',
            'tobacco-products'          => 'Have you ever used tobacco or nicotine products?',
            'used-tobacco'              => 'Have you ever used tobacco or nicotine products?',
            'blood-clot'                => 'Have you ever had a blood clot?',
            'blood-clots'               => 'Have you ever had a blood clot?',
            'bloodclot'                 => 'Have you ever had a blood clot?',
            'surgery-timeline'          => 'When are you interested in having surgery?',
            'when-surgery'              => 'When are you interested in having surgery?',
            'surgery-interest'          => 'When are you interested in having surgery?',
            'interested-surgery'        => 'When are you interested in having surgery?',
            'past-surgeries'            => 'What surgeries have you had in the past? (List procedure and year)',
            'surgeries-past'            => 'What surgeries have you had in the past? (List procedure and year)',
            'surgeries-had'             => 'What surgeries have you had in the past? (List procedure and year)',
            'complications'             => 'Did you experience any complications from these surgeries?',
            'complications-surgeries'   => 'Did you experience any complications from these surgeries?',
            'experience-complications'  => 'Did you experience any complications from these surgeries?',
            'satisfaction'              => 'Are you satisfied with your previous plastic surgery or cosmetic treatments? What aspects of the results did you like most, and were there any aspects you liked less or wish had been different?',
            'satisfied-procedures'      => 'Are you satisfied with your previous plastic surgery or cosmetic treatments? What aspects of the results did you like most, and were there any aspects you liked less or wish had been different?',
            'previous-plastic'          => 'Are you satisfied with your previous plastic surgery or cosmetic treatments? What aspects of the results did you like most, and were there any aspects you liked less or wish had been different?',
            'satisfied'                 => 'Are you satisfied with your previous plastic surgery or cosmetic treatments? What aspects of the results did you like most, and were there any aspects you liked less or wish had been different?',
            'recent-treatments'         => 'Have you had threads, Sculptra, or Radiesse in the past 6 months?',
            'previous-treatments'       => 'Previous treatments on face or neck (select all that apply)',
            'treatments-face'           => 'Previous treatments on face or neck (select all that apply)',
            'treatments-neck'           => 'Previous treatments on face or neck (select all that apply)',
            'threads-sculptra'          => 'Have you had threads, Sculptra, or Radiesse in the past 6 months?',
            'threads-past'              => 'Have you had threads, Sculptra, or Radiesse in the past 6 months?',
            'consent'                   => 'I grant Allen Foulad MD Inc permission to review my photographs for consultation purposes and to respond to my inquiry via email. I also agree to the terms of use.',
        );

        // Fields that belong to BASIC INFORMATION section
        $basic_info_fields = array(
            'name', 'first-name', 'last-name', 'your-name', 'full-name',
            'date-of-birth', 'dob', 'birth', 'birthday',
            'sex', 'gender',
            'email', 'your-email', 'mail',
            'phone', 'tel', 'telephone', 'your-phone', 'mobile',
            'address', 'street', 'city', 'state', 'zip', 'postal', 'country',
            'company', 'organization',
            'preferred-contact', 'contact-method',
            'best-time', 'time-to-contact',
            'how-did-you-hear', 'referral', 'hear-about',
        );

        // Custom label mapping for BASIC INFORMATION fields (match form labels exactly)
        $label_overrides = array(
            'first-name'               => 'First Name',
            'last-name'                => 'Last Name',
            'date-of-birth'            => 'Date of Birth',
            'dob'                      => 'Date of Birth',
            'sex'                      => 'Sex',
            'gender'                   => 'Sex',
            'phone'                    => 'Phone',
            'your-phone'               => 'Phone',
            'tel'                      => 'Phone',
            'telephone'                => 'Phone',
            'mobile'                   => 'Phone',
            'email'                    => 'Email',
            'your-email'               => 'Email',
            'mail'                     => 'Email',
            'address'                  => 'Address',
            'street'                   => 'Address',
            'city'                     => 'City',
            'state'                    => 'State (Territory/Province)',
            'zip'                      => 'Zip Code (Postal Code)',
            'zip-code'                 => 'Zip Code (Postal Code)',
            'postal'                   => 'Zip Code (Postal Code)',
            'postal-code'              => 'Zip Code (Postal Code)',
            'country'                  => 'Country',
            'preferred-contact-method' => 'Preferred Contact Method',
            'preferred-contact'        => 'Preferred Contact Method',
            'contact-method'           => 'Preferred Contact Method',
            'best-time-to-contact'     => 'Best Time to Contact You',
            'best-time'                => 'Best Time to Contact You',
            'time-to-contact'          => 'Best Time to Contact You',
            'how-did-you-hear'         => 'How Did You Hear About Dr. Foulad?',
            'hear-about'               => 'How Did You Hear About Dr. Foulad?',
            'referral'                 => 'How Did You Hear About Dr. Foulad?',
        );

        // Fields to completely exclude from PDF output
        $excluded_fields = array(
            'preferred-contact-method', 'preferred-contact', 'contact-method',
            'best-time-to-contact', 'best-time', 'time-to-contact', 'contact-time',
        );

        foreach ($submission_data as $key => $value) {
            // Skip internal CF7 fields
            if (strpos($key, '_wpcf7') === 0 || strpos($key, 'g-recaptcha') === 0) {
                continue;
            }

            // Skip excluded fields (Preferred Contact Method, Best Time to Contact)
            if (in_array($key, $excluded_fields, true)) {
                continue;
            }

            // Skip empty values
            if (empty($value) || (is_string($value) && trim($value) === '')) {
                continue;
            }

            // Skip image/file upload fields (they contain hash values or file paths)
            // These are displayed in the separate "Uploaded Images" section
            if (self::is_file_field($key, $value)) {
                continue;
            }

            // Determine section
            $key_lower = strtolower($key);

            // Check if procedure field - store RAW value (not formatted) for custom rendering
            $is_procedure = false;
            foreach ($procedure_fields as $proc_field) {
                if (strpos($key_lower, $proc_field) !== false) {
                    $is_procedure = true;
                    break;
                }
            }

            if ($is_procedure) {
                // Store with original key for procedure section custom rendering
                $sections['PROCEDURES OF INTEREST'][$key] = $value;
                continue;
            }

            // Check if medical history field - store with label for mixed rendering
            $is_medical = false;
            foreach ($medical_fields as $med_field) {
                if (strpos($key_lower, $med_field) !== false) {
                    $is_medical = true;
                    break;
                }
            }

            if ($is_medical) {
                $med_label = isset($medical_label_overrides[$key]) ? $medical_label_overrides[$key] : self::format_field_label($key);
                // Store as array with label, raw value, and original key to preserve order
                $sections['MEDICAL HISTORY'][] = array(
                    'label' => $med_label,
                    'value' => $value,
                    'key'   => $key,
                );
                continue;
            }

            // Format the value for other sections
            $formatted_value = self::format_field_value($value);

            // Check if basic info field
            $is_basic_info = false;
            foreach ($basic_info_fields as $basic_field) {
                if (strpos($key_lower, $basic_field) !== false) {
                    $is_basic_info = true;
                    break;
                }
            }

            // Get label - use override if available, otherwise format from key
            $label = isset($label_overrides[$key]) ? $label_overrides[$key] : self::format_field_label($key);

            if ($is_basic_info) {
                $sections['BASIC INFORMATION'][$label] = $formatted_value;
            } else {
                // All remaining fields go to MEDICAL HISTORY
                $med_label = isset($medical_label_overrides[$key]) ? $medical_label_overrides[$key] : $label;
                $sections['MEDICAL HISTORY'][] = array(
                    'label' => $med_label,
                    'value' => $value,
                    'key'   => $key,
                );
            }
        }

        // Remove empty sections
        return array_filter($sections, function($fields) {
            return !empty($fields);
        });
    }

    /**
     * Format field value for display
     *
     * @param mixed $value Field value
     * @return string Formatted value
     */
    private static function format_field_value($value) {
        if (is_array($value)) {
            // Single-item array (e.g., radio button) - return plain value without bullet
            if (count($value) === 1) {
                return self::clean_text_for_pdf(reset($value));
            }
            // Format multi-item array values (checkboxes) on separate lines with bullet points
            $values = array_map(function($v) {
                return '- ' . self::clean_text_for_pdf($v);
            }, $value);
            return implode("\n", $values);
        }

        $value = self::clean_text_for_pdf($value);

        // Check if this looks like a comma-separated list of checkbox values
        // (multiple items separated by ", " with no sentence-like structure)
        if (strpos($value, ', ') !== false) {
            $items = explode(', ', $value);
            // Only treat as checkbox list if there are multiple items
            // and items don't look like a sentence (no lowercase words after comma)
            if (count($items) >= 2) {
                $looks_like_list = true;
                foreach ($items as $item) {
                    $item = trim($item);
                    // If item starts with lowercase or is very long, it's probably a sentence
                    if (strlen($item) > 60 || (strlen($item) > 0 && ctype_lower($item[0]))) {
                        $looks_like_list = false;
                        break;
                    }
                }
                if ($looks_like_list) {
                    // Format as bulleted list on separate lines
                    $formatted_items = array_map(function($item) {
                        return '- ' . trim($item);
                    }, $items);
                    return implode("\n", $formatted_items);
                }
            }
        }

        return $value;
    }

    /**
     * Clean text for PDF output - handles encoding and special characters
     * Converts smart quotes and other special characters to PDF-safe equivalents
     *
     * @param string $text Input text
     * @return string Cleaned text safe for FPDF
     */
    private static function clean_text_for_pdf($text) {
        if (!is_string($text)) {
            $text = (string) $text;
        }

        // First, decode any HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Fix already-corrupted UTF-8 sequences (â€™ etc) from existing data
        // These appear when UTF-8 smart quotes were incorrectly stored/displayed as ISO-8859-1
        $corrupted_sequences = array(
            'â€™' => "'",   // Right single quote corrupted
            'â€˜' => "'",   // Left single quote corrupted
            'â€œ' => '"',   // Left double quote corrupted
            'â€' => '"',    // Right double quote corrupted (partial)
            'â€"' => '-',   // En dash corrupted
            'â€"' => '-',   // Em dash corrupted
            'â€¦' => '...', // Ellipsis corrupted
            'Â ' => ' ',    // Non-breaking space corrupted
            'â€²' => "'",   // Prime (feet) corrupted
            'â€³' => '"',   // Double prime (inches) corrupted
        );

        $text = str_replace(array_keys($corrupted_sequences), array_values($corrupted_sequences), $text);

        // Convert smart/curly quotes to straight quotes (these cause the â€™ issue)
        $search = array(
            "\xe2\x80\x98", // ' (left single quote) - UTF-8
            "\xe2\x80\x99", // ' (right single quote / apostrophe) - UTF-8
            "\xe2\x80\x9c", // " (left double quote) - UTF-8
            "\xe2\x80\x9d", // " (right double quote) - UTF-8
            "\xe2\x80\x93", // – (en dash) - UTF-8
            "\xe2\x80\x94", // — (em dash) - UTF-8
            "\xe2\x80\xa6", // … (ellipsis) - UTF-8
            "\xc2\xa0",     // non-breaking space - UTF-8
            "\xe2\x80\x9e", // „ (low double quote) - UTF-8
            "\xe2\x80\x9a", // ‚ (low single quote) - UTF-8
            "\xc2\xab",     // « (left guillemet) - UTF-8
            "\xc2\xbb",     // » (right guillemet) - UTF-8
            "\xe2\x80\xb2", // ′ (prime/feet) - UTF-8
            "\xe2\x80\xb3", // ″ (double prime/inches) - UTF-8
        );

        $replace = array(
            "'",    // left single quote -> straight single quote
            "'",    // right single quote -> straight single quote
            '"',    // left double quote -> straight double quote
            '"',    // right double quote -> straight double quote
            '-',    // en dash -> hyphen
            '-',    // em dash -> hyphen
            '...',  // ellipsis -> three dots
            ' ',    // non-breaking space -> regular space
            '"',    // low double quote -> straight double quote
            "'",    // low single quote -> straight single quote
            '"',    // left guillemet -> straight double quote
            '"',    // right guillemet -> straight double quote
            "'",    // prime (feet) -> straight single quote
            '"',    // double prime (inches) -> straight double quote
        );

        $text = str_replace($search, $replace, $text);

        // Also handle Windows-1252 encoded versions (common from MS Word)
        $win1252_search = array(
            chr(145), // ' left single quote
            chr(146), // ' right single quote
            chr(147), // " left double quote
            chr(148), // " right double quote
            chr(150), // – en dash
            chr(151), // — em dash
            chr(133), // … ellipsis
            chr(160), // non-breaking space
        );

        $win1252_replace = array(
            "'",
            "'",
            '"',
            '"',
            '-',
            '-',
            '...',
            ' ',
        );

        $text = str_replace($win1252_search, $win1252_replace, $text);

        // Convert remaining UTF-8 to ISO-8859-1 for FPDF compatibility
        // Use transliteration to handle characters that don't exist in ISO-8859-1
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        // Final cleanup - remove any remaining problematic characters
        $text = preg_replace('/[^\x20-\x7E\xA0-\xFF]/', '', $text);

        return trim($text);
    }

    /**
     * Format field label for display
     *
     * @param string $key Field key
     * @return string Formatted label
     */
    private static function format_field_label($key) {
        // Remove common prefixes
        $label = preg_replace('/^(your-|cf7-|wpcf7-|field-|input-)/', '', $key);
        // Replace separators with spaces
        $label = str_replace(array('-', '_'), ' ', $label);
        // Capitalize words
        return ucwords($label);
    }

    /**
     * Check if a field is a file/image upload field
     * These fields contain hash values or file paths and should be skipped
     * as images are displayed in the separate "Uploaded Images" section
     *
     * @param string $key   Field key/name
     * @param mixed  $value Field value
     * @return bool True if this is a file field
     */
    private static function is_file_field($key, $value) {
        // Common file/image field name patterns
        $file_field_patterns = array(
            'photo', 'image', 'picture', 'file', 'upload', 'attachment',
            'front', 'back', 'left', 'right', 'profile', 'oblique',
            'document', 'pdf', 'doc', 'img', 'pic', 'avatar', 'thumbnail',
        );

        $key_lower = strtolower($key);

        // Check if field name matches file patterns
        foreach ($file_field_patterns as $pattern) {
            if (strpos($key_lower, $pattern) !== false) {
                return true;
            }
        }

        // Check if value looks like a hash (64 character hex string - SHA256)
        if (is_string($value) && preg_match('/^[a-f0-9]{64}$/i', trim($value))) {
            return true;
        }

        // Check if value looks like a file path or URL
        if (is_string($value)) {
            $value_trimmed = trim($value);
            // File extension check
            if (preg_match('/\.(jpg|jpeg|png|gif|webp|bmp|pdf|doc|docx|xls|xlsx|zip|rar)$/i', $value_trimmed)) {
                return true;
            }
            // File path check (contains /uploads/ or \uploads\)
            if (strpos($value_trimmed, '/uploads/') !== false || strpos($value_trimmed, '\\uploads\\') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a section in the PDF
     *
     * @param FPDF   $pdf          PDF instance
     * @param array  $template     Template configuration
     * @param array  $rgb          Section color RGB
     * @param string $section_name Section name
     * @param array  $fields       Section fields
     */
    private static function generate_section($pdf, $template, $rgb, $section_name, $fields) {
        $design = isset(self::$settings['pdf_design']) ? self::$settings['pdf_design'] : 'modern';

        // Check if we need a new page
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
        }

        // Add spacing before section
        $pdf->Ln(3);

        // Section header based on design
        switch ($design) {
            case 'modern':
                // Section title with background color from settings (header_color)
                $pdf->SetFont($template['font_family'], 'B', 14);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
                $pdf->Cell(190, 12, strtoupper($section_name), 0, 1, 'C', true);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->Ln(4);
                break;

            case 'classic':
                $pdf->SetFont($template['font_family'], 'B', 12);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
                $pdf->Cell(190, 10, '  ' . $section_name, 1, 1, 'L', true);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->Ln(2);
                break;

            case 'minimal':
                $pdf->SetFont($template['font_family'], 'B', 11);
                $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
                $pdf->Cell(190, 8, $section_name, 0, 1, 'L');
                $pdf->SetDrawColor($rgb[0], $rgb[1], $rgb[2]);
                $pdf->SetLineWidth(0.5);
                $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
                $pdf->SetLineWidth(0.2);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Ln(4);
                break;
        }

        // For modern design, use custom layouts per section
        if ($design === 'modern') {
            if ($section_name === 'PROCEDURES OF INTEREST') {
                self::generate_modern_procedures($pdf, $template, $rgb, $fields);
            } elseif ($section_name === 'MEDICAL HISTORY') {
                self::generate_modern_medical_history($pdf, $template, $rgb, $fields);
            } else {
                self::generate_modern_table($pdf, $template, $rgb, $section_name, $fields);
            }
            return;
        }

        $pdf->SetFont($template['font_family'], '', 10);
        $row_index = 0;

        // Table column widths: 30% for label (57mm), 70% for value (133mm) = 190mm total
        $label_width = 57;  // 30% of 190mm
        $value_width = 133; // 70% of 190mm

        // Draw table border for section
        $pdf->SetDrawColor(200, 200, 200);

        foreach ($fields as $label => $value) {
            // Check if value contains newlines (multi-line like checkboxes/textarea)
            $has_newlines = strpos($value, "\n") !== false;
            $is_textarea = strlen($value) > 80 || $has_newlines;

            // Calculate space needed for this field
            $lines_needed = $is_textarea ? (substr_count($value, "\n") + 1) : 1;
            $space_needed = max($template['cell_padding'], $lines_needed * 5) + 2;

            // Check if we need a new page
            if ($pdf->GetY() + $space_needed > 275) {
                $pdf->AddPage();
                // Re-add section header on new page
                $pdf->SetFont($template['font_family'], 'B', 10);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->Cell(190, 6, $section_name . ' (continued)', 0, 1, 'L');
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Ln(2);
                $pdf->SetDrawColor(200, 200, 200);
            }

            // Alternating row colors for modern design
            if ($template['use_alternating_rows'] && $row_index % 2 === 0) {
                $pdf->SetFillColor(248, 248, 248);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }

            if ($is_textarea) {
                // For multi-line/textarea values, use full width with label on top
                $pdf->SetFont($template['font_family'], 'B', 10);
                $pdf->SetFillColor(248, 248, 248);
                $pdf->Cell(190, $template['cell_padding'], $label . ':', 1, 1, 'L', true);

                $pdf->SetFont($template['font_family'], '', 10);
                $pdf->SetFillColor(255, 255, 255);

                // Store current X position
                $start_x = $pdf->GetX();
                $start_y = $pdf->GetY();

                // Calculate proper line height for multi-line content
                $line_height = 6;
                $pdf->MultiCell(190, $line_height, $value, 1, 'L', false);
                $pdf->Ln(1);
            } else {
                // Table row: 30% label | 70% value with borders
                $cell_height = $template['cell_padding'];

                // Label cell (30%)
                $pdf->SetFont($template['font_family'], 'B', 10);
                $pdf->Cell($label_width, $cell_height, $label . ':', 1, 0, 'L', $template['use_alternating_rows']);

                // Value cell (70%)
                $pdf->SetFont($template['font_family'], '', 10);
                $pdf->Cell($value_width, $cell_height, $value, 1, 1, 'L', $template['use_alternating_rows']);
            }

            $row_index++;
        }

        $pdf->Ln(5);
    }

    /**
     * Generate modern table layout for a section
     * Clean label-value table with alternating rows and multi-line support
     *
     * @param FPDF   $pdf          PDF instance
     * @param array  $template     Template configuration
     * @param array  $rgb          Section color RGB
     * @param string $section_name Section name
     * @param array  $fields       Section fields (label => value)
     */
    private static function generate_modern_table($pdf, $template, $rgb, $section_name, $fields) {
        $label_width = 70;  // 37% of 190mm - enough for long labels like "State (Territory/Province)"
        $value_width = 120; // 63% of 190mm
        $cell_height = 10;  // Increased for better padding
        $line_height = 5.5;

        $pdf->SetDrawColor(220, 220, 220);
        $row_index = 0;

        foreach ($fields as $label => $value) {
            // Calculate how many lines the value needs
            $value_lines = self::calculate_lines($pdf, $value, $value_width - 6, $template['font_family'], '', 10);
            $label_lines = self::calculate_lines($pdf, $label, $label_width - 6, $template['font_family'], 'B', 11);
            $max_lines = max($value_lines, $label_lines, 1);
            $row_height = max($cell_height, $max_lines * $line_height + 4);

            // Check if we need a new page
            if ($pdf->GetY() + $row_height > 275) {
                $pdf->AddPage();
                $pdf->SetFont($template['font_family'], 'B', 10);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->Cell(190, 6, $section_name . ' (continued)', 0, 1, 'C');
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Ln(3);
                $pdf->SetDrawColor(220, 220, 220);
            }

            // Alternating row background
            if ($row_index % 2 === 0) {
                $pdf->SetFillColor(248, 249, 250);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            $fill = true;

            $start_x = $pdf->GetX();
            $start_y = $pdf->GetY();

            // Draw row background
            $pdf->Rect($start_x, $start_y, $label_width + $value_width, $row_height, 'F');

            // Draw cell borders
            $pdf->Rect($start_x, $start_y, $label_width, $row_height);
            $pdf->Rect($start_x + $label_width, $start_y, $value_width, $row_height);

            // Print label (bold, dark, left-aligned with padding)
            $pdf->SetFont($template['font_family'], 'B', 11);
            $pdf->SetTextColor(20, 20, 20);
            $pdf->SetXY($start_x + 3, $start_y + 2);
            $pdf->MultiCell($label_width - 6, $line_height, $label, 0, 'L', false);

            // Print value (normal, left-aligned with padding)
            $pdf->SetFont($template['font_family'], '', 10);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetXY($start_x + $label_width + 3, $start_y + 2);
            $pdf->MultiCell($value_width - 6, $line_height, $value, 0, 'L', false);

            // Move to next row
            $pdf->SetXY($start_x, $start_y + $row_height);

            $row_index++;
        }

        $pdf->Ln(8);
    }

    /**
     * Generate PROCEDURES OF INTEREST section for modern design
     * List-based layout: selected procedures as bullet list, other text, description textarea
     *
     * @param FPDF  $pdf      PDF instance
     * @param array $template Template configuration
     * @param array $rgb      Section color RGB
     * @param array $fields   Raw procedure field data (key => raw value)
     */
    private static function generate_modern_procedures($pdf, $template, $rgb, $fields) {
        $procedures_list = array();
        $other_procedure = '';
        $procedure_describe = '';

        // Sort fields into their roles
        foreach ($fields as $key => $value) {
            $key_lower = strtolower($key);

            if (strpos($key_lower, 'other-procedure') !== false || strpos($key_lower, 'other-procedure-text') !== false) {
                // "Other Procedure" textarea
                $other_procedure = is_array($value) ? implode(', ', $value) : self::clean_text_for_pdf(trim($value));
            } elseif (strpos($key_lower, 'procedure-describe') !== false || strpos($key_lower, 'procedure-description') !== false || strpos($key_lower, 'procedure-concerns') !== false) {
                // "Procedure describe" textarea
                $procedure_describe = is_array($value) ? implode("\n", $value) : self::clean_text_for_pdf(trim($value));
            } else {
                // Checkbox selections (procedures list)
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $cleaned = self::clean_text_for_pdf(trim($v));
                        if (!empty($cleaned)) {
                            $procedures_list[] = $cleaned;
                        }
                    }
                } else {
                    // Could be comma-separated string
                    $items = explode(',', $value);
                    foreach ($items as $item) {
                        $cleaned = self::clean_text_for_pdf(trim($item));
                        if (!empty($cleaned)) {
                            $procedures_list[] = $cleaned;
                        }
                    }
                }
            }
        }

        // If nothing to show, skip
        if (empty($procedures_list) && empty($other_procedure) && empty($procedure_describe)) {
            return;
        }

        $left_margin = $pdf->GetX();

        // --- Selected Procedures ---
        if (!empty($procedures_list)) {
            $pdf->SetFont($template['font_family'], 'B', 11);
            $pdf->SetTextColor(20, 20, 20);
            $pdf->Cell(190, 7, 'Selected Procedures:', 0, 1, 'L');
            $pdf->Ln(2);

            $pdf->SetFont($template['font_family'], '', 10);
            $pdf->SetTextColor(30, 30, 30);

            foreach ($procedures_list as $procedure) {
                // Check page break
                if ($pdf->GetY() + 7 > 275) {
                    $pdf->AddPage();
                }

                // Bullet point with procedure name
                $pdf->SetX($left_margin + 5);
                $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
                // Small colored bullet
                $bullet_y = $pdf->GetY() + 2.5;
                $pdf->Rect($left_margin + 5, $bullet_y, 2, 2, 'F');
                $pdf->SetX($left_margin + 10);
                $pdf->MultiCell(175, 6, $procedure, 0, 'L', false);
            }

            $pdf->Ln(3);
        }

        // --- Other Procedure (if filled) ---
        if (!empty($other_procedure)) {
            if ($pdf->GetY() + 15 > 275) {
                $pdf->AddPage();
            }

            $pdf->SetFont($template['font_family'], 'B', 11);
            $pdf->SetTextColor(20, 20, 20);
            $pdf->Cell(190, 7, 'Other Procedure:', 0, 1, 'L');

            $pdf->SetFont($template['font_family'], '', 10);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetX($left_margin + 5);
            $pdf->MultiCell(180, 6, $other_procedure, 0, 'L', false);
            $pdf->Ln(3);
        }

        // --- Procedure Description ---
        if (!empty($procedure_describe)) {
            if ($pdf->GetY() + 20 > 275) {
                $pdf->AddPage();
            }

            $pdf->SetFont($template['font_family'], 'B', 11);
            $pdf->SetTextColor(20, 20, 20);
            $pdf->MultiCell(190, 6, 'For each procedure you\'re interested in, please briefly describe what concerns you about that area and what change you\'re hoping to achieve. This will help Dr. Foulad assess whether each procedure is appropriate for your goals.*', 0, 'L', false);
            $pdf->Ln(2);

            // Description in a light gray box
            $pdf->SetFont($template['font_family'], '', 10);
            $pdf->SetTextColor(30, 30, 30);
            $box_x = $left_margin;
            $box_y = $pdf->GetY();

            // Calculate box height
            $desc_lines = self::calculate_lines($pdf, $procedure_describe, 182, $template['font_family'], '', 10);
            $box_height = max(15, $desc_lines * 6 + 6);

            // Draw background box
            $pdf->SetFillColor(248, 249, 250);
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->Rect($box_x, $box_y, 190, $box_height, 'DF');

            // Print text inside box
            $pdf->SetXY($box_x + 4, $box_y + 3);
            $pdf->MultiCell(182, 6, $procedure_describe, 0, 'L', false);

            $pdf->SetY($box_y + $box_height + 3);
        }

        $pdf->Ln(5);
    }

    /**
     * Generate MEDICAL HISTORY section for modern design
     * Mixed layout: table rows for short fields/radios, full-width blocks for textareas
     * Preserves exact field order from the form
     *
     * @param FPDF  $pdf      PDF instance
     * @param array $template Template configuration
     * @param array $rgb      Section color RGB
     * @param array $fields   Array of field arrays with 'label', 'value', 'key'
     */
    private static function generate_modern_medical_history($pdf, $template, $rgb, $fields) {
        $label_width = 70;
        $value_width = 120;
        $cell_height = 10;  // Increased for better padding
        $line_height = 5.5;
        $left_margin = $pdf->GetX();

        $pdf->SetDrawColor(220, 220, 220);
        $row_index = 0;
        $prev_was_long = false; // Track layout transitions for padding

        foreach ($fields as $field) {
            $label = $field['label'];
            $raw_value = $field['value'];
            $key = $field['key'];

            // Format the value
            if (is_array($raw_value)) {
                // Checkbox array - will be rendered as bullet list
                $is_checkbox = true;
                $items = array();
                foreach ($raw_value as $v) {
                    $cleaned = self::clean_text_for_pdf(trim($v));
                    if (!empty($cleaned)) {
                        $items[] = $cleaned;
                    }
                }
                $formatted_value = implode(', ', $items);
            } else {
                $is_checkbox = false;
                $formatted_value = self::clean_text_for_pdf(trim($raw_value));
            }

            if (empty($formatted_value) && empty($items)) {
                continue;
            }

            // Determine if this is a short field (table row) or long field (textarea block)
            // Long labels (question-style >55 chars) always render as full-width blocks
            $is_long = false;
            if (strlen($label) > 55) {
                $is_long = true;
            } elseif ($is_checkbox && count($items) > 3) {
                $is_long = true;
            } elseif (!$is_checkbox) {
                $has_newlines = strpos($formatted_value, "\n") !== false;
                $is_long = $has_newlines || strlen($formatted_value) > 60;
            }

            // Page break check
            $space_needed = $is_long ? 25 : $cell_height + 2;
            if ($pdf->GetY() + $space_needed > 275) {
                $pdf->AddPage();
                $pdf->SetFont($template['font_family'], 'B', 10);
                $pdf->SetTextColor(120, 120, 120);
                $pdf->Cell(190, 6, 'MEDICAL HISTORY (continued)', 0, 1, 'C');
                $pdf->SetTextColor(0, 0, 0);
                $pdf->Ln(3);
                $pdf->SetDrawColor(220, 220, 220);
            }

            if ($is_long) {
                // --- TEXTAREA / LONG FIELD BLOCK ---

                // Add spacing when transitioning from table rows to textarea block
                if (!$prev_was_long) {
                    $pdf->Ln(5);
                }

                // Bold label (full width, as a question)
                $pdf->SetFont($template['font_family'], 'B', 11);
                $pdf->SetTextColor(20, 20, 20);
                $pdf->MultiCell(190, 6, $label, 0, 'L', false);
                $pdf->Ln(2);

                if ($is_checkbox && !empty($items)) {
                    // Render as bullet list
                    $pdf->SetFont($template['font_family'], '', 10);
                    $pdf->SetTextColor(30, 30, 30);

                    foreach ($items as $item) {
                        if ($pdf->GetY() + 7 > 275) {
                            $pdf->AddPage();
                        }
                        $pdf->SetX($left_margin + 5);
                        $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
                        $bullet_y = $pdf->GetY() + 2.5;
                        $pdf->Rect($left_margin + 5, $bullet_y, 2, 2, 'F');
                        $pdf->SetX($left_margin + 10);
                        $pdf->MultiCell(175, 6, $item, 0, 'L', false);
                    }
                } else {
                    // Render text in a light gray box
                    $pdf->SetFont($template['font_family'], '', 10);
                    $pdf->SetTextColor(30, 30, 30);

                    $box_x = $left_margin;
                    $box_y = $pdf->GetY();

                    $desc_lines = self::calculate_lines($pdf, $formatted_value, 182, $template['font_family'], '', 10);
                    $box_height = max(10, $desc_lines * 6 + 4);

                    // Check if box fits on page
                    if ($box_y + $box_height > 275) {
                        $pdf->AddPage();
                        $box_y = $pdf->GetY();
                    }

                    $pdf->SetFillColor(248, 249, 250);
                    $pdf->SetDrawColor(220, 220, 220);
                    $pdf->Rect($box_x, $box_y, 190, $box_height, 'DF');

                    $pdf->SetXY($box_x + 4, $box_y + 2);
                    $pdf->MultiCell(182, 6, $formatted_value, 0, 'L', false);

                    $pdf->SetY($box_y + $box_height + 2);
                }

                $pdf->Ln(3);
                $row_index = 0; // Reset alternating after a textarea block
                $prev_was_long = true;

            } else {
                // --- TABLE ROW (short field / radio) ---

                // Add spacing when transitioning from textarea block to table rows
                if ($prev_was_long) {
                    $pdf->Ln(4);
                    $prev_was_long = false;
                }

                // Calculate row height
                $pdf->SetFont($template['font_family'], 'B', 11);
                $label_lines = self::calculate_lines($pdf, $label, $label_width - 6, $template['font_family'], 'B', 11);
                $pdf->SetFont($template['font_family'], '', 10);
                $value_lines = self::calculate_lines($pdf, $formatted_value, $value_width - 6, $template['font_family'], '', 10);
                $max_lines = max($label_lines, $value_lines, 1);
                $row_height = max($cell_height, $max_lines * $line_height + 4);

                // Alternating row background
                if ($row_index % 2 === 0) {
                    $pdf->SetFillColor(248, 249, 250);
                } else {
                    $pdf->SetFillColor(255, 255, 255);
                }

                $start_x = $pdf->GetX();
                $start_y = $pdf->GetY();

                // Draw row background
                $pdf->Rect($start_x, $start_y, $label_width + $value_width, $row_height, 'F');

                // Draw cell borders
                $pdf->SetDrawColor(220, 220, 220);
                $pdf->Rect($start_x, $start_y, $label_width, $row_height);
                $pdf->Rect($start_x + $label_width, $start_y, $value_width, $row_height);

                // Print label (bold, dark)
                $pdf->SetFont($template['font_family'], 'B', 11);
                $pdf->SetTextColor(20, 20, 20);
                $pdf->SetXY($start_x + 3, $start_y + 2);
                $pdf->MultiCell($label_width - 6, $line_height, $label, 0, 'L', false);

                // Print value
                $pdf->SetFont($template['font_family'], '', 10);
                $pdf->SetTextColor(30, 30, 30);
                $pdf->SetXY($start_x + $label_width + 3, $start_y + 2);
                $pdf->MultiCell($value_width - 6, $line_height, $formatted_value, 0, 'L', false);

                // Move to next row
                $pdf->SetXY($start_x, $start_y + $row_height);

                $row_index++;
            }
        }

        $pdf->Ln(8);
    }

    /**
     * Calculate number of lines a text will occupy in a given width
     *
     * @param FPDF   $pdf        PDF instance
     * @param string $text       The text
     * @param float  $width      Available width in mm
     * @param string $font_family Font family
     * @param string $font_style  Font style (B, I, etc.)
     * @param int    $font_size   Font size
     * @return int Number of lines
     */
    private static function calculate_lines($pdf, $text, $width, $font_family, $font_style, $font_size) {
        $pdf->SetFont($font_family, $font_style, $font_size);

        // Handle newlines in text
        $explicit_lines = explode("\n", $text);
        $total_lines = 0;

        foreach ($explicit_lines as $line) {
            if (empty($line)) {
                $total_lines++;
                continue;
            }
            // Calculate how many wrapped lines this single line produces
            $line_width = $pdf->GetStringWidth($line);
            $wrapped_lines = max(1, ceil($line_width / $width));
            $total_lines += $wrapped_lines;
        }

        return $total_lines;
    }

    /**
     * Generate images section
     *
     * @param FPDF  $pdf      PDF instance
     * @param array $template Template configuration
     * @param array $rgb      Section color RGB
     * @param array $files    Uploaded files
     */
    private static function generate_images_section($pdf, $template, $rgb, $files) {
        $design = isset(self::$settings['pdf_design']) ? self::$settings['pdf_design'] : 'modern';
        $has_images = false;

        // Check if there are any valid images
        foreach ($files as $field_name => $file_paths) {
            if (!is_array($file_paths)) {
                $file_paths = array($file_paths);
            }
            foreach ($file_paths as $path) {
                if (self::is_valid_image($path)) {
                    $has_images = true;
                    break 2;
                }
            }
        }

        if (!$has_images) {
            return;
        }

        $image_number = 1;
        foreach ($files as $field_name => $file_paths) {
            if (!is_array($file_paths)) {
                $file_paths = array($file_paths);
            }

            foreach ($file_paths as $index => $path) {
                if (!self::is_valid_image($path)) {
                    continue;
                }

                $label = self::format_field_label($field_name);
                if (count($file_paths) > 1) {
                    $label .= ' ' . ($index + 1);
                }

                // Each photo gets its own page
                $pdf->AddPage();

                // Photo label at top of page (matching section title style)
                if ($design === 'modern') {
                    $pdf->SetFont($template['font_family'], 'B', 12);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
                    $pdf->Cell(190, 10, $image_number . '. ' . $label, 0, 1, 'C', true);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->Ln(4);
                } else {
                    $pdf->SetFont($template['font_family'], 'B', 11);
                    $pdf->SetTextColor(50, 50, 50);
                    $pdf->Cell(190, 8, $image_number . '. ' . $label, 0, 1, 'L');
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Ln(3);
                }

                try {
                    // Optimize image (handles EXIF rotation)
                    $optimized_path = self::optimize_image_for_pdf($path);
                    $image_path = $optimized_path ?: $path;

                    // Get image dimensions
                    $image_info = @getimagesize($image_path);
                    if ($image_info === false) {
                        $pdf->SetTextColor(200, 0, 0);
                        $pdf->Cell(0, 6, 'Error: Invalid image format', 0, 1);
                        $pdf->SetTextColor(0, 0, 0);
                        continue;
                    }

                    $img_width = $image_info[0];
                    $img_height = $image_info[1];

                    // Available space on page (after label): 190mm wide, ~245mm tall
                    $max_width_mm = 190;
                    $available_height_mm = 277 - $pdf->GetY(); // page bottom minus current Y

                    // Calculate display size maintaining exact aspect ratio
                    $aspect_ratio = $img_width / $img_height;

                    if ($aspect_ratio >= 1) {
                        // Landscape or square: fit to width
                        $width_mm = $max_width_mm;
                        $height_mm = $width_mm / $aspect_ratio;

                        // If too tall, scale down to fit available height
                        if ($height_mm > $available_height_mm) {
                            $height_mm = $available_height_mm;
                            $width_mm = $height_mm * $aspect_ratio;
                        }
                    } else {
                        // Portrait: fit to available height
                        $height_mm = $available_height_mm;
                        $width_mm = $height_mm * $aspect_ratio;

                        // If too wide, scale down to fit width
                        if ($width_mm > $max_width_mm) {
                            $width_mm = $max_width_mm;
                            $height_mm = $width_mm / $aspect_ratio;
                        }
                    }

                    // Center the image horizontally
                    $x = 10 + ($max_width_mm - $width_mm) / 2;

                    // Draw light border around image
                    $pdf->SetDrawColor(220, 220, 220);
                    $pdf->Rect($x - 1, $pdf->GetY() - 1, $width_mm + 2, $height_mm + 2);

                    $pdf->Image($image_path, $x, $pdf->GetY(), $width_mm, $height_mm);

                } catch (Exception $e) {
                    $pdf->SetTextColor(200, 0, 0);
                    $pdf->Cell(0, 6, 'Error loading image: ' . $e->getMessage(), 0, 1);
                    $pdf->SetTextColor(0, 0, 0);
                    self::log_error('Image processing error: ' . $e->getMessage());
                }

                $image_number++;
            }
        }
    }

    /**
     * Generate PDF footer
     *
     * @param FPDF  $pdf      PDF instance
     * @param array $template Template configuration
     * @param int   $form_id  Form ID
     */
    private static function generate_footer($pdf, $template, $submission_id) {
        // Footer is handled by FPDF's auto page break
    }

    /**
     * Check if file is a valid image
     *
     * @param string $path File path
     * @return bool
     */
    private static function is_valid_image($path) {
        if (empty($path) || !file_exists($path) || !is_readable($path)) {
            return false;
        }

        // Check file size (max 20MB to prevent memory issues)
        $max_file_size = 20 * 1024 * 1024; // 20MB
        $file_size = filesize($path);
        if ($file_size === false || $file_size > $max_file_size) {
            self::log_error(sprintf('Image file too large: %s (%s)', basename($path), size_format($file_size)), 'warning');
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp');

        if (!in_array($extension, $allowed, true)) {
            self::log_error(sprintf('Invalid image extension: %s', $extension), 'warning');
            return false;
        }

        // Verify it's actually an image
        // Note: @ suppresses warnings for corrupted/unreadable files - return value is checked
        $image_info = @getimagesize($path);
        if ($image_info === false) {
            self::log_error(sprintf('Corrupt or invalid image file: %s', basename($path)), 'warning');
            return false;
        }

        // Check image dimensions (max 10000x10000 to prevent memory issues)
        if ($image_info[0] > 10000 || $image_info[1] > 10000) {
            self::log_error(sprintf('Image dimensions too large: %dx%d', $image_info[0], $image_info[1]), 'warning');
            return false;
        }

        return true;
    }

    /**
     * Optimize image for PDF processing
     *
     * @param string $image_path Original image path
     * @return string|false Optimized image path or false
     */
    private static function optimize_image_for_pdf($image_path) {
        // Check GD extension
        if (!extension_loaded('gd')) {
            return false;
        }

        if (!file_exists($image_path) || !is_readable($image_path)) {
            return false;
        }

        // Note: @ suppresses warnings for corrupted files - return value is checked
        $image_info = @getimagesize($image_path);
        if ($image_info === false) {
            return false;
        }

        $width = $image_info[0];
        $height = $image_info[1];
        $mime_type = $image_info['mime'];

        // Get settings
        $max_dimension = isset(self::$settings['max_image_size']) ? absint(self::$settings['max_image_size']) : 1024;
        $quality = isset(self::$settings['image_quality']) ? absint(self::$settings['image_quality']) : 85;

        // Check EXIF orientation for JPEG images
        $exif_orientation = 1; // Default: normal
        if ($mime_type === 'image/jpeg' && function_exists('exif_read_data')) {
            // Note: @ suppresses warnings for files without EXIF data
            $exif = @exif_read_data($image_path, 'IFD0');
            if ($exif !== false && isset($exif['Orientation'])) {
                $exif_orientation = intval($exif['Orientation']);
            }
        }

        // Skip optimization for small JPEG files that don't need rotation
        $needs_rotation = ($exif_orientation > 1);
        if ($mime_type === 'image/jpeg' && $width <= $max_dimension && $height <= $max_dimension && !$needs_rotation) {
            return false;
        }

        // Create optimized images directory
        $upload_dir = wp_upload_dir();
        $optimized_dir = $upload_dir['basedir'] . '/cf7-optimized-images/';
        if (!file_exists($optimized_dir)) {
            wp_mkdir_p($optimized_dir);
        }

        // Generate optimized filename
        $pathinfo = pathinfo($image_path);
        $optimized_filename = $pathinfo['filename'] . '_opt_' . md5($image_path . $max_dimension . $quality) . '.jpg';
        $optimized_path = $optimized_dir . $optimized_filename;

        // Check if optimized version exists and is newer
        if (file_exists($optimized_path) && filemtime($optimized_path) > filemtime($image_path)) {
            return $optimized_path;
        }

        try {
            // Load source image
            // Note: @ suppresses GD warnings for corrupted images - return value is checked
            $source = null;
            switch ($mime_type) {
                case 'image/jpeg':
                    $source = @imagecreatefromjpeg($image_path);
                    break;
                case 'image/png':
                    $source = @imagecreatefrompng($image_path);
                    break;
                case 'image/gif':
                    $source = @imagecreatefromgif($image_path);
                    break;
                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $source = @imagecreatefromwebp($image_path);
                    }
                    break;
                default:
                    return false;
            }

            if ($source === false) {
                return false;
            }

            // Apply EXIF orientation correction
            if ($exif_orientation > 1) {
                switch ($exif_orientation) {
                    case 2: // Horizontal flip
                        imageflip($source, IMG_FLIP_HORIZONTAL);
                        break;
                    case 3: // 180° rotation
                        $source = imagerotate($source, 180, 0);
                        break;
                    case 4: // Vertical flip
                        imageflip($source, IMG_FLIP_VERTICAL);
                        break;
                    case 5: // 90° CCW + horizontal flip
                        $source = imagerotate($source, 90, 0);
                        imageflip($source, IMG_FLIP_HORIZONTAL);
                        break;
                    case 6: // 90° CW (most common phone portrait)
                        $source = imagerotate($source, -90, 0);
                        break;
                    case 7: // 90° CW + horizontal flip
                        $source = imagerotate($source, -90, 0);
                        imageflip($source, IMG_FLIP_HORIZONTAL);
                        break;
                    case 8: // 90° CCW
                        $source = imagerotate($source, 90, 0);
                        break;
                }

                if ($source === false) {
                    return false;
                }

                // Update dimensions after rotation (90°/270° swaps width and height)
                if (in_array($exif_orientation, array(5, 6, 7, 8))) {
                    $temp = $width;
                    $width = $height;
                    $height = $temp;
                }
            }

            // Calculate new dimensions
            if ($width > $max_dimension || $height > $max_dimension) {
                if ($width > $height) {
                    $new_width = $max_dimension;
                    $new_height = intval($height * ($max_dimension / $width));
                } else {
                    $new_height = $max_dimension;
                    $new_width = intval($width * ($max_dimension / $height));
                }
            } else {
                $new_width = $width;
                $new_height = $height;
            }

            // Create new image with white background
            $optimized = imagecreatetruecolor($new_width, $new_height);
            if ($optimized === false) {
                imagedestroy($source);
                return false;
            }
            $white = imagecolorallocate($optimized, 255, 255, 255);
            if ($white === false) {
                $white = 0; // Fallback to black if allocation fails
            }
            imagefill($optimized, 0, 0, $white);

            // Resize
            imagecopyresampled($optimized, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

            // Save as JPEG
            $save_result = imagejpeg($optimized, $optimized_path, $quality);

            // Clean up
            imagedestroy($source);
            imagedestroy($optimized);

            return $save_result ? $optimized_path : false;

        } catch (Exception $e) {
            self::log_error('Image optimization error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Save PDF to file
     *
     * @param FPDF $pdf     PDF instance
     * @param int  $form_id Form ID
     * @return string|false Path to saved PDF or false
     */
    private static function save_pdf($pdf, $form_id) {
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/cf7-working-pdfs/';

        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);

            // Add .htaccess for security
            $htaccess = "Order deny,allow\nDeny from all\n<Files *.pdf>\nAllow from all\n</Files>";
            @file_put_contents($pdf_dir . '.htaccess', $htaccess);
        }

        if (!is_writable($pdf_dir)) {
            self::log_error('PDF directory is not writable: ' . $pdf_dir);
            return false;
        }

        $filename = sprintf('submission-%d-%s.pdf', $form_id, uniqid());
        $filepath = $pdf_dir . $filename;

        try {
            $pdf->Output('F', $filepath);

            if (file_exists($filepath) && filesize($filepath) > 0) {
                return $filepath;
            }

            self::log_error('PDF file was not created or is empty');
            return false;

        } catch (Exception $e) {
            self::log_error('PDF save error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log error message
     * Always logs critical errors, regardless of WP_DEBUG setting
     *
     * @param string $message Error message
     * @param string $level   Log level: 'error', 'warning', 'info'
     */
    private static function log_error($message, $level = 'error') {
        $log_message = '[CF7_PDF_Engine] ' . gmdate('Y-m-d H:i:s') . ' ' . strtoupper($level) . ': ' . $message;

        // Always log errors (they are critical)
        if ($level === 'error') {
            error_log($log_message);

            // Store last error for admin display
            update_option('cf7_working_pdf_last_error', array(
                'message' => $message,
                'time' => current_time('mysql'),
                'level' => $level,
            ));
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            // Only log warnings/info if debug enabled
            error_log($log_message);
        }
    }
}

/**
 * Global function to generate PDF
 *
 * @param array  $data     Form data
 * @param string $filename Output filename
 * @return string|false Path to PDF or false
 */
if (!function_exists('cf7_working_pdf_generate')) {
    function cf7_working_pdf_generate($data, $filename = 'document.pdf') {
        return CF7_Working_PDF_Engine::generate_pdf($data, $filename);
    }
}
