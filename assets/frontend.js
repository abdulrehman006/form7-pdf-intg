/**
 * CF7 Working PDF Generator - Frontend JavaScript
 *
 * Handles image upload previews, form interactions, and success redirects.
 *
 * @package CF7_Working_PDF_Generator
 * @since 4.1.0
 */

(function($) {
    'use strict';

    // Prevent double processing
    var isProcessing = false;

    /**
     * Initialize CF7 Image Upload functionality
     */
    function initCF7ImageUpload() {
        $('.cf7-upload-wrapper').each(function() {
            var $wrapper = $(this);
            var inputId = $wrapper.data('for');
            var $input = $('#' + inputId);
            var $placeholder = $wrapper.find('.cf7-upload-placeholder');
            var $preview = $wrapper.find('.cf7-image-preview');
            var $removeBtn = $wrapper.find('.cf7-remove-image');
            var $progress = $wrapper.find('.cf7-upload-progress');

            // Skip if already initialized
            if ($wrapper.data('cf7-upload-initialized')) {
                return;
            }
            $wrapper.data('cf7-upload-initialized', true);

            // Click handler for wrapper - opens file dialog
            $wrapper.on('click.cf7upload', function(e) {
                // Don't trigger if clicking remove button
                if ($(e.target).closest('.cf7-remove-image').length > 0) {
                    return;
                }
                // Only trigger on placeholder or wrapper itself
                if ($(e.target).is('.cf7-upload-wrapper, .cf7-upload-placeholder')) {
                    if (!isProcessing) {
                        isProcessing = true;
                        $input.trigger('click');
                        setTimeout(function() {
                            isProcessing = false;
                        }, 100);
                    }
                }
            });

            // File input change handler
            $input.on('change.cf7upload', function() {
                if (isProcessing) {
                    return;
                }
                isProcessing = true;

                var file = this.files[0];
                if (!file) {
                    isProcessing = false;
                    return;
                }

                // Validate file type
                if (!file.type.match('image.*')) {
                    alert('Please select an image file (JPG, PNG, GIF, etc.)');
                    $(this).val('');
                    isProcessing = false;
                    return;
                }

                // Validate file size (max 10MB)
                var maxSize = 10 * 1024 * 1024; // 10MB
                if (file.size > maxSize) {
                    alert('Image file is too large. Maximum size is 10MB.');
                    $(this).val('');
                    isProcessing = false;
                    return;
                }

                // Show progress
                $progress.show();

                // Create preview using FileReader
                var reader = new FileReader();
                reader.onload = function(e) {
                    $preview.html('<img src="' + e.target.result + '" alt="Preview">').show();
                    $placeholder.hide();
                    $removeBtn.show();
                    $progress.hide();
                    isProcessing = false;
                };
                reader.onerror = function() {
                    alert('Error reading file. Please try again.');
                    $progress.hide();
                    isProcessing = false;
                };
                reader.readAsDataURL(file);
            });

            // Remove button handler
            $removeBtn.on('click.cf7upload', function(e) {
                e.stopPropagation();
                e.preventDefault();

                $input.val('');
                $preview.empty().hide();
                $placeholder.show();
                $(this).hide();
                $progress.hide();
            });
        });
    }

    /**
     * Handle "Other" procedure checkbox
     */
    function handleOtherCheckbox() {
        var $checkboxes = $('#procedures input[type="checkbox"]');
        var $otherWrapper = $('#other-procedure-wrapper');
        var $otherInput = $('#other-procedure');

        if ($checkboxes.length === 0 || $otherWrapper.length === 0) {
            return;
        }

        function toggleOtherField() {
            var otherChecked = $checkboxes.filter('[value="Other"]').is(':checked');
            $otherWrapper.css('display', otherChecked ? 'block' : 'none');
            if (!otherChecked) {
                $otherInput.val('');
            }
        }

        // Attach change event to all checkboxes
        $checkboxes.on('change', toggleOtherField);

        // Initial check on page load
        toggleOtherField();
    }

    /**
     * Handle form submission success redirect
     */
    function initSuccessRedirect() {
        // Check if we have a redirect URL configured
        if (typeof cf7_working_pdf_frontend === 'undefined' || !cf7_working_pdf_frontend.redirect_url) {
            return;
        }

        var redirectUrl = cf7_working_pdf_frontend.redirect_url;

        // Listen for CF7 mail sent event
        document.addEventListener('wpcf7mailsent', function(event) {
            if (redirectUrl) {
                // Small delay to ensure CF7 processing is complete
                setTimeout(function() {
                    window.location.href = redirectUrl;
                }, 100);
            }
        }, false);
    }

    /**
     * Show validation errors in a centered modal when required fields are missing
     */
    function initValidationAlert() {
        document.addEventListener('wpcf7invalid', function(event) {
            var detail = event.detail;
            var message = 'Please fill in all required fields.';

            if (detail && detail.apiResponse && detail.apiResponse.message) {
                message = detail.apiResponse.message;
            }

            showCenteredModal(message);
        }, false);
    }

    /**
     * Display a centered modal overlay with a message
     * Uses CSS classes from frontend.css for styling, responsiveness, and cross-browser support
     */
    function showCenteredModal(message) {
        // Remove any existing modal
        var existing = document.getElementById('cf7-validation-modal');
        if (existing) {
            existing.parentNode.removeChild(existing);
        }

        // Create overlay
        var overlay = document.createElement('div');
        overlay.id = 'cf7-validation-modal';
        overlay.className = 'cf7-validation-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Validation message');

        // Create modal box
        var box = document.createElement('div');
        box.className = 'cf7-validation-modal';

        // Message text
        var msg = document.createElement('p');
        msg.textContent = message;

        // OK button
        var btn = document.createElement('button');
        btn.textContent = 'OK';
        btn.setAttribute('type', 'button');

        // Close handler
        function closeModal() {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        }

        btn.onclick = closeModal;
        overlay.onclick = function(e) {
            if (e.target === overlay) closeModal();
        };

        // Close on Escape key
        function handleEscape(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                closeModal();
                document.removeEventListener('keydown', handleEscape);
            }
        }
        document.addEventListener('keydown', handleEscape);

        box.appendChild(msg);
        box.appendChild(btn);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        // Focus the OK button for keyboard accessibility
        btn.focus();
    }

    /**
     * Initialize all functionality when DOM is ready
     */
    $(document).ready(function() {
        initCF7ImageUpload();
        handleOtherCheckbox();
        initSuccessRedirect();
        initValidationAlert();
    });

    // Re-initialize on CF7 form reset (after submission)
    $(document).on('wpcf7reset', function() {
        // Reset all image previews
        $('.cf7-upload-wrapper').each(function() {
            var $wrapper = $(this);
            $wrapper.find('.cf7-image-preview').empty().hide();
            $wrapper.find('.cf7-upload-placeholder').show();
            $wrapper.find('.cf7-remove-image').hide();
            $wrapper.find('.cf7-upload-progress').hide();
        });
    });

})(jQuery);
