jQuery(document).ready(function($) {
    // Check if cf7_working_pdf_ajax is defined
    if (typeof cf7_working_pdf_ajax === 'undefined') {
        console.error('cf7_working_pdf_ajax is not defined. Check wp_localize_script in PHP.');
        alert('Plugin error: AJAX configuration is missing.');
        return;
    }

    console.log('cf7_working_pdf_admin initialized. AJAX URL:', cf7_working_pdf_ajax.ajax_url);

    // Initialize color picker for settings page
    if ($('.color-picker').length) {
        $('.color-picker').wpColorPicker();
        console.log('Color picker initialized.');
    }

    // View submission
    $(document).on('click', '.view-submission', function(e) {
        e.preventDefault();
        var $this = $(this);
        var submissionId = $this.data('submission-id');

        if (!submissionId) {
            console.error('Missing submission ID for view action.');
            alert('Error: Submission ID is missing.');
            return;
        }

        console.log('Requesting submission ID:', submissionId);

        $.ajax({
            url: cf7_working_pdf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cf7_working_pdf_get_submission',
                nonce: cf7_working_pdf_ajax.nonce,
                submission_id: submissionId
            },
            beforeSend: function() {
                $this.text('Loading...').prop('disabled', true);
            },
            success: function(response) {
                console.log('View submission response:', response);
                if (response.success && response.data && response.data.html) {
                    $('#submission-content').html(response.data.html);
                    $('#submission-modal').css('display', 'flex');
                } else {
                    alert('Error: ' + (response.data || 'Failed to load submission.'));
                    console.error('View submission failed:', response);
                }
            },
            error: function(xhr, status, error) {
                alert('Error loading submission: ' + error);
                console.error('AJAX error for view submission:', xhr.status, xhr.responseText);
            },
            complete: function() {
                $this.text('View').prop('disabled', false);
            }
        });
    });

    // Close modal
    $(document).on('click', '.close-modal', function(e) {
        e.preventDefault();
        $('#submission-modal').hide();
        $('#submission-content').empty();
        console.log('Modal closed.');
    });

    // Delete submission
    $(document).on('click', '.delete-submission', function(e) {
        e.preventDefault();
        if (!confirm(cf7_working_pdf_ajax.confirm_delete)) {
            return;
        }

        var $this = $(this);
        var submissionId = $this.data('submission-id');
        var $row = $this.closest('tr');

        if (!submissionId) {
            console.error('Missing submission ID for delete action.');
            alert('Error: Submission ID is missing.');
            return;
        }

        console.log('Deleting submission ID:', submissionId);

        $.ajax({
            url: cf7_working_pdf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cf7_working_pdf_delete_submission',
                nonce: cf7_working_pdf_ajax.nonce,
                submission_id: submissionId
            },
            beforeSend: function() {
                $this.text('Deleting...').prop('disabled', true);
            },
            success: function(response) {
                console.log('Delete submission response:', response);
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                    alert(response.data.message || 'Submission deleted successfully.');
                } else {
                    alert('Error: ' + (response.data.message || 'Failed to delete submission.'));
                    console.error('Delete submission failed:', response);
                }
            },
            error: function(xhr, status, error) {
                alert('Error deleting submission: ' + error);
                console.error('AJAX error for delete submission:', xhr.status, xhr.responseText);
            },
            complete: function() {
                $this.text('Delete').prop('disabled', false);
            }
        });
    });

    // Delete image
    $(document).on('click', '.delete-image', function(e) {
        e.preventDefault();
        if (!confirm(cf7_working_pdf_ajax.confirm_image_delete)) {
            return;
        }

        var $this = $(this);
        var imageId = $this.data('image-id');
        var $imageRow = $this.closest('div');

        if (!imageId) {
            console.error('Missing image ID for delete action.');
            alert('Error: Image ID is missing.');
            return;
        }

        console.log('Deleting image ID:', imageId);

        $.ajax({
            url: cf7_working_pdf_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'cf7_working_pdf_delete_image',
                nonce: cf7_working_pdf_ajax.nonce,
                image_id: imageId
            },
            beforeSend: function() {
                $this.text('Deleting...').prop('disabled', true);
            },
            success: function(response) {
                console.log('Delete image response:', response);
                if (response.success) {
                    $imageRow.fadeOut(300, function() {
                        $(this).remove();
                    });
                    alert(response.data || 'Image deleted successfully.');
                } else {
                    alert('Error: ' + (response.data || 'Failed to delete image.'));
                    console.error('Delete image failed:', response);
                }
            },
            error: function(xhr, status, error) {
                alert('Error deleting image: ' + error);
                console.error('AJAX error for delete image:', xhr.status, xhr.responseText);
            },
            complete: function() {
                $this.text('Delete').prop('disabled', false);
            }
        });
    });

    // Download PDF
    $(document).on('click', '.download-pdf', function(e) {
        e.preventDefault();
        var $this = $(this);
        var submissionId = $this.data('submission-id');

        if (!submissionId) {
            console.error('Missing submission ID for download action.');
            alert('Error: Submission ID is missing.');
            return;
        }

        console.log('Initiating PDF download for submission ID:', submissionId);

        // Use direct URL with query parameters for download
        var downloadUrl = cf7_working_pdf_ajax.ajax_url + '?action=cf7_working_pdf_download_pdf&nonce=' + encodeURIComponent(cf7_working_pdf_ajax.nonce) + '&submission_id=' + encodeURIComponent(submissionId);
        window.open(downloadUrl, '_blank');
        console.log('Download URL opened:', downloadUrl);
    });
});