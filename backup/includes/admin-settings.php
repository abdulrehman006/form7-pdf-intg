<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'cf7-working-pdf'));
}

$settings = get_option('cf7_working_pdf_settings', array(
    'enable_pdf' => true,
    'pdf_design' => 'modern',
    'header_color' => '#2271b1',
    'store_submissions' => true
));
?>
<div class="wrap">
    <h1><?php _e('Contact Form 7 PDF Generator Settings', 'cf7-working-pdf'); ?></h1>
    <form method="post" action="">
        <?php wp_nonce_field('cf7_working_pdf_settings'); ?>
        <table class="form-table">
            <tr>
                <th><label for="enable_pdf"><?php _e('Enable PDF Generation', 'cf7-working-pdf'); ?></label></th>
                <td><input type="checkbox" name="enable_pdf" id="enable_pdf" <?php checked($settings['enable_pdf'], 1); ?>></td>
            </tr>
            <tr>
                <th><label for="pdf_design"><?php _e('PDF Design', 'cf7-working-pdf'); ?></label></th>
                <td>
                    <select name="pdf_design" id="pdf_design">
                        <option value="modern" <?php selected($settings['pdf_design'], 'modern'); ?>><?php _e('Modern', 'cf7-working-pdf'); ?></option>
                        <option value="classic" <?php selected($settings['pdf_design'], 'classic'); ?>><?php _e('Classic', 'cf7-working-pdf'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="header_color"><?php _e('Header Color', 'cf7-working-pdf'); ?></label></th>
                <td><input type="text" name="header_color" id="header_color" value="<?php echo esc_attr($settings['header_color']); ?>" class="color-picker"></td>
            </tr>
            <tr>
                <th><label for="store_submissions"><?php _e('Store Submissions', 'cf7-working-pdf'); ?></label></th>
                <td><input type="checkbox" name="store_submissions" id="store_submissions" <?php checked($settings['store_submissions'], 1); ?>></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>
    <script>
        jQuery(document).ready(function($) {
            $('.color-picker').wpColorPicker();
        });
    </script>
</div>
<?php
?>