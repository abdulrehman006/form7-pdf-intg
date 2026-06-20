/**
 * CF7 Working PDF Generator - Admin JavaScript
 *
 * Handles all admin interactions including submission viewing, deletion,
 * PDF downloads, image management, and modal dialogs.
 *
 * @package CF7_Working_PDF_Generator
 * @since 4.1.0
 */

(function($) {
    'use strict';

    /**
     * CF7 Working PDF Admin Handler
     */
    var CF7PDFAdmin = {

        /**
         * Initialize all admin functionality
         */
        init: function() {
            // Check if cf7_working_pdf_ajax is defined
            if (typeof cf7_working_pdf_ajax === 'undefined') {
                console.warn('CF7 PDF: AJAX configuration not available on this page.');
                return;
            }

            this.bindEvents();
            this.initColorPicker();
        },

        /**
         * Bind all event handlers using event delegation
         */
        bindEvents: function() {
            var self = this;

            // Select all checkboxes - both top and bottom
            $(document).on('change', '#cb-select-all-1, #cb-select-all-2', function() {
                var isChecked = $(this).prop('checked');
                $('input[name="submissions[]"]').prop('checked', isChecked);
                // Sync both select-all checkboxes
                $('#cb-select-all-1, #cb-select-all-2').prop('checked', isChecked);
            });

            // Individual checkbox change - update select-all state
            $(document).on('change', 'input[name="submissions[]"]', function() {
                var totalCheckboxes = $('input[name="submissions[]"]').length;
                var checkedCheckboxes = $('input[name="submissions[]"]:checked').length;
                var allChecked = totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes;
                $('#cb-select-all-1, #cb-select-all-2').prop('checked', allChecked);
            });

            // Bulk action confirmation
            $(document).on('click', '#doaction, #doaction2', function(e) {
                var $select = $(this).prev('select');
                var action = $select.val();

                if (action === 'bulk_delete') {
                    var checkedCount = $('input[name="submissions[]"]:checked').length;

                    if (checkedCount === 0) {
                        e.preventDefault();
                        alert('Please select at least one submission to delete.');
                        return false;
                    }

                    if (!confirm(cf7_working_pdf_ajax.confirm_bulk_delete || 'Are you sure you want to delete the selected submissions?')) {
                        e.preventDefault();
                        return false;
                    }
                }
            });

            // View submission - handles both button types
            $(document).on('click', '.view-submission, .view-full-submission', function(e) {
                e.preventDefault();
                var submissionId = $(this).data('submission-id');
                if (submissionId) {
                    self.viewSubmission(submissionId);
                }
            });

            // Delete submission
            $(document).on('click', '.delete-submission', function(e) {
                e.preventDefault();
                var submissionId = $(this).data('submission-id');
                var $row = $(this).closest('tr');
                if (submissionId) {
                    self.deleteSubmission(submissionId, $row);
                }
            });

            // Download PDF from table row
            $(document).on('click', '.download-pdf', function(e) {
                e.preventDefault();
                var submissionId = $(this).data('submission-id');
                if (submissionId) {
                    self.downloadPdf(submissionId);
                }
            });

            // Download PDF from modal
            $(document).on('click', '#modal-download-pdf', function(e) {
                e.preventDefault();
                var submissionId = $('#submission-modal').data('submission-id');
                if (submissionId) {
                    self.downloadPdf(submissionId);
                }
            });

            // Delete image from modal
            $(document).on('click', '.delete-image', function(e) {
                e.preventDefault();
                var imageId = $(this).data('image-id');
                var $item = $(this).closest('.modal-image-item');
                if (imageId) {
                    self.deleteImage(imageId, $item);
                }
            });

            // Close modal - various triggers
            $(document).on('click', '.cf7-modal-close, .close-modal', function(e) {
                e.preventDefault();
                self.closeModal();
            });

            // Close modal on background click
            $(document).on('click', '#submission-modal', function(e) {
                if (e.target === this) {
                    self.closeModal();
                }
            });

            // Close modal on ESC key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('#submission-modal').is(':visible')) {
                    self.closeModal();
                }
            });

            // Date filter toggle for custom date range
            $(document).on('change', '#date-filter', function() {
                var value = $(this).val();
                var $customRange = $('.custom-date-range');
                if (value === 'custom') {
                    $customRange.css('display', 'inline-flex');
                } else {
                    $customRange.hide();
                }
            });
        },

        /**
         * Initialize WordPress color picker on settings page
         */
        initColorPicker: function() {
            if ($.fn.wpColorPicker && $('.color-picker').length) {
                $('.color-picker').wpColorPicker();
            }
        },

        /**
         * View submission details in modal
         *
         * @param {number} submissionId The submission ID
         */
        viewSubmission: function(submissionId) {
            var self = this;
            var $modal = $('#submission-modal');
            var $details = $('#submission-details, #submission-content');

            // Store submission ID for PDF download button
            $modal.data('submission-id', submissionId);

            // Show loading state
            var loadingHtml = '<div class="cf7-loading"><span class="spinner is-active"></span> ' +
                              (cf7_working_pdf_ajax.loading || 'Loading...') + '</div>';
            $details.html(loadingHtml);

            // Show modal
            $modal.css('display', 'flex').show();

            // Fetch submission details via AJAX
            $.ajax({
                url: cf7_working_pdf_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cf7_working_pdf_get_submission',
                    submission_id: submissionId,
                    nonce: cf7_working_pdf_ajax.nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.html) {
                        $details.html(response.data.html);
                    } else {
                        var errorMsg = response.data || cf7_working_pdf_ajax.error || 'Failed to load submission.';
                        $details.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = cf7_working_pdf_ajax.error || 'An error occurred. Please try again.';
                    $details.html('<div class="notice notice-error"><p>' + errorMsg + '</p></div>');
                }
            });
        },

        /**
         * Delete a submission with cascade delete of images
         *
         * @param {number} submissionId The submission ID
         * @param {jQuery} $row         The table row element
         */
        deleteSubmission: function(submissionId, $row) {
            var confirmMsg = cf7_working_pdf_ajax.confirm_delete ||
                            'Are you sure you want to delete this submission?';

            if (!confirm(confirmMsg)) {
                return;
            }

            var $deleteBtn = $row.find('.delete-submission');
            var originalText = $deleteBtn.text();

            $.ajax({
                url: cf7_working_pdf_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cf7_working_pdf_delete_submission',
                    submission_id: submissionId,
                    nonce: cf7_working_pdf_ajax.nonce
                },
                beforeSend: function() {
                    $deleteBtn.text('Deleting...').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        // Fade out and remove row
                        $row.fadeOut(300, function() {
                            $(this).remove();

                            // Update count in header if present
                            var $count = $('.wp-heading-inline .count');
                            if ($count.length) {
                                var currentCount = parseInt($count.text().replace(/[()]/g, ''), 10) || 0;
                                $count.text('(' + Math.max(0, currentCount - 1) + ')');
                            }

                            // Check if table is now empty
                            if ($('table.submissions tbody tr').length === 0) {
                                location.reload();
                            }
                        });
                    } else {
                        var errorMsg = (response.data && response.data.message) || response.data ||
                                      cf7_working_pdf_ajax.error || 'Failed to delete submission.';
                        alert(errorMsg);
                        $deleteBtn.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert(cf7_working_pdf_ajax.error || 'An error occurred. Please try again.');
                    $deleteBtn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Download PDF for a submission
         *
         * @param {number} submissionId The submission ID
         */
        downloadPdf: function(submissionId) {
            // Build download URL with query parameters
            var downloadUrl = cf7_working_pdf_ajax.ajax_url +
                             '?action=cf7_working_pdf_download_pdf' +
                             '&nonce=' + encodeURIComponent(cf7_working_pdf_ajax.nonce) +
                             '&submission_id=' + encodeURIComponent(submissionId);

            // Use hidden iframe to trigger download without navigating away or popup issues
            var iframe = document.getElementById('cf7-pdf-download-frame');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'cf7-pdf-download-frame';
                iframe.style.display = 'none';
                document.body.appendChild(iframe);
            }
            iframe.src = downloadUrl;
        },

        /**
         * Delete an image from a submission
         *
         * @param {number} imageId The image ID
         * @param {jQuery} $item   The image item element
         */
        deleteImage: function(imageId, $item) {
            var confirmMsg = cf7_working_pdf_ajax.confirm_image_delete ||
                            'Are you sure you want to delete this image?';

            if (!confirm(confirmMsg)) {
                return;
            }

            var $deleteBtn = $item.find('.delete-image');
            var originalText = $deleteBtn.text();

            $.ajax({
                url: cf7_working_pdf_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'cf7_working_pdf_delete_image',
                    image_id: imageId,
                    nonce: cf7_working_pdf_ajax.nonce
                },
                beforeSend: function() {
                    $deleteBtn.text('Deleting...').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        $item.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        var errorMsg = response.data || cf7_working_pdf_ajax.error || 'Failed to delete image.';
                        alert(errorMsg);
                        $deleteBtn.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert(cf7_working_pdf_ajax.error || 'An error occurred. Please try again.');
                    $deleteBtn.text(originalText).prop('disabled', false);
                }
            });
        },

        /**
         * Close the submission modal
         */
        closeModal: function() {
            var $modal = $('#submission-modal');
            $modal.hide();
            $('#submission-details, #submission-content').empty();
            $modal.removeData('submission-id');
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        CF7PDFAdmin.init();
    });

})(jQuery);
