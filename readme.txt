=== Contact Form 7 Working PDF Generator ===
Contributors: cf7pdfteam
Donate link: https://example.com/donate
Tags: contact form 7, pdf, form submissions, pdf generator, cf7
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 4.1.4
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-purpose PDF generator for Contact Form 7 with image handling, email tracking, submission storage, and automatic cleanup.

== Description ==

**Contact Form 7 Working PDF Generator** transforms your Contact Form 7 submissions into professional PDF documents. Whether you need to archive form data, send PDF attachments via email, or manage uploaded images, this plugin provides a complete solution.

= Key Features =

* **Automatic PDF Generation** - Creates PDF documents from any CF7 form submission
* **Multiple Design Templates** - Choose from Modern, Classic, or Minimal PDF designs
* **Customizable Styling** - Set header colors, company name, and PDF title
* **Image Handling** - Processes and optimizes uploaded images for PDF inclusion
* **Email Attachments** - Automatically attaches generated PDFs to CF7 emails
* **Submission Storage** - Stores form submissions in the database for later viewing
* **Admin Dashboard** - View, search, filter, and manage all submissions
* **Bulk Actions** - Delete multiple submissions at once
* **Date Range Deletion** - Delete submissions within a specific date range
* **Auto-Cleanup** - Automatically delete old submissions and images to save server space
* **Email Tracking** - Track email delivery status for each submission
* **Image Management** - View and delete individual uploaded images
* **Storage Statistics** - Monitor disk usage and file counts

= PDF Design Templates =

* **Modern** - Full-width colored header, alternating row colors, clean layout
* **Classic** - Traditional bordered design with decorative lines
* **Minimal** - Simple, understated design with maximum readability

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher (tested up to PHP 8.3)
* Contact Form 7 plugin installed and activated
* GD library for image processing (recommended)

== Installation ==

1. Upload the `cf7-working-pdf-generator` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure Contact Form 7 is installed and activated
4. Go to Contact > PDF Settings to configure the plugin
5. Start receiving PDF submissions!

== Frequently Asked Questions ==

= Does this work with any Contact Form 7 form? =

Yes! The plugin is designed to work with any CF7 form. It automatically processes all form fields and creates organized PDF documents.

= Where are the PDF files stored? =

PDF files are temporarily generated for email attachments and then automatically deleted. Uploaded images are stored in `/wp-content/uploads/cf7-working-pdfs/images/`.

= Can I customize the PDF appearance? =

Yes! You can choose from three design templates (Modern, Classic, Minimal), set a custom header color, add your company name, and customize the PDF title.

= How do I automatically delete old submissions? =

Go to Contact > PDF Settings and enable "Auto-Delete". You can set how many days to keep submissions and choose whether to delete only images or entire submissions.

= Is this plugin GDPR compliant? =

The plugin stores form submissions in your database. You should inform users about data collection in your privacy policy and use the auto-delete feature to automatically remove old data.

= Does it work with multisite? =

The plugin is designed for single-site installations. Multisite support is not currently tested.

== Screenshots ==

1. PDF Settings page with all configuration options
2. Submissions list with search and filtering
3. Submission detail modal with images
4. Example PDF output with Modern template
5. Storage overview and cleanup options

== Changelog ==

= 4.1.4 =
* Improved: PDF format and layout for better UI/UX
* Improved: Two-column table layout for short fields (City, State, Address, Country, etc.)
* Improved: Better spacing between sections in PDF
* Improved: Multi-line field values (checkboxes) now display with proper indentation
* Improved: Section headers now consistent across all design templates
* Improved: Images section with numbered labels and light border around images
* Improved: Auto page break handling with "continued" labels for long content
* Improved: Email template now shows clear visual separation for uploaded images section
* Improved: Image download URLs in email now numbered for better readability

= 4.1.3 =
* Improved: Implemented WordPress Settings API for settings page
* Improved: Checkbox/multi-select values now display on separate lines in PDF with bullet points
* Fixed: Image/file upload field hash values no longer appear in PDF form data section
* Fixed: PDF now shows submission/entry ID instead of form ID in header
* Added: Proper register_setting() with sanitize_callback
* Added: Settings sections and fields using add_settings_section() and add_settings_field()
* Added: admin_init hook for settings registration
* Improved: Settings now use options.php for native WordPress handling
* Improved: Automatic settings validation and sanitization via Settings API

= 4.1.2 =
* Fixed: Character encoding issue causing smart quotes to display as garbled text (â€™ instead of ')
* Fixed: Height field and other text showing weird characters from mobile/Word input
* Fixed: PDF download button not working in admin submissions page
* Added: Smart quote normalization for iOS/Android keyboards and MS Word copy-paste
* Added: Corrupted UTF-8 sequence repair for existing database records
* Improved: Form processing now wrapped in comprehensive error handling
* Improved: Plugin will never block form submission even if PDF generation fails
* Improved: PDF download now uses hidden iframe method (avoids popup blocker issues)
* Improved: Better error handling for PHP 7+ Error types during PDF generation
* Added: Public normalize_quotes() method for consistent text handling throughout plugin

= 4.1.1 =
* Fixed: Replaced deprecated mysql2date() with wp_date() for WordPress 6.0+ compatibility
* Fixed: Added proper GD image null/false checks for PHP 8.0+ compatibility
* Fixed: Removed error suppression operators and added proper error handling
* Fixed: Added headers_sent() check before PDF download
* Fixed: Added function_exists check for cascade delete function
* Improved: Moved inline CSS to external stylesheet
* Improved: Added WordPress coding standards compliance
* Added: readme.txt for Plugin Directory
* Added: LICENSE.txt file
* Updated: Tested up to WordPress 6.5
* Updated: Tested up to PHP 8.3

= 4.1.0 =
* New: Multi-purpose design - works with any CF7 form
* New: Three PDF design templates (Modern, Classic, Minimal)
* New: Auto-delete feature for old submissions and images
* New: Delete by date range functionality
* New: Configurable PDF title and company name
* New: Image optimization settings (max size, quality)
* New: Success redirect URL configuration
* New: Storage usage statistics
* New: Run Cleanup Now button
* Fixed: All security issues from QA audit
* Fixed: SQL injection vulnerabilities
* Fixed: Cascade delete for all related data
* Improved: Complete rewrite of PDF generator engine
* Improved: Admin interface with modern design
* Improved: JavaScript handlers consolidated

= 4.0.4 =
* Initial public release

== Upgrade Notice ==

= 4.1.4 =
PDF format and email template improvements for better UI/UX. Better spacing, cleaner layout, and improved visual organization.

= 4.1.3 =
Settings page now uses WordPress Settings API for better integration and security. No functionality changes, just improved code standards compliance.

= 4.1.2 =
Fixes character encoding issues where smart quotes display as garbled text, and fixes PDF download button not working. Also improves error handling to ensure form submissions always succeed.

= 4.1.1 =
This update includes important compatibility fixes for WordPress 6.0+ and PHP 8.0+. Recommended for all users.

= 4.1.0 =
Major update with new features, security fixes, and complete rewrite. Please backup before upgrading.

== Privacy Policy ==

This plugin stores form submission data including:
* Form field values submitted by users
* Uploaded images
* Email delivery status
* Submission timestamps

Data is stored in your WordPress database and in the uploads directory. Use the auto-delete feature to automatically remove old data. No data is sent to external servers.

== Credits ==

* Uses FPDF library for PDF generation (http://www.fpdf.org/)
* Requires Contact Form 7 by Takayuki Miyoshi
