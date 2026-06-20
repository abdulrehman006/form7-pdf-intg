   jQuery(document).ready(function($) {
         
	     let isProcessing = false;


    // Initialize CF7 Image Upload
    function initCF7ImageUpload() {
        $('.cf7-upload-wrapper').each(function () {
            const $wrapper = $(this);
            const inputId = $wrapper.data('for');
            const $input = $('#' + inputId);
            const $placeholder = $wrapper.find('.cf7-upload-placeholder');
            const $preview = $wrapper.find('.cf7-image-preview');
            const $removeBtn = $wrapper.find('.cf7-remove-image');
            const $progress = $wrapper.find('.cf7-upload-progress');
           
         

            // Clean up previous event handlers
            $wrapper.off('click.cf7upload');
            $input.off('change.cf7upload');
            $removeBtn.off('click.cf7upload');

            // Click handler for wrapper
            $wrapper.on('click.cf7upload', function (e) {
                if ($(e.target).closest('.cf7-remove-image').length === 0 &&
                    $(e.target).is('.cf7-upload-wrapper, .cf7-upload-placeholder')) {
                    if (!isProcessing) {
                        isProcessing = true;
                        $input.trigger('click');
                        setTimeout(() => { isProcessing = false; }, 100);
                    }
                }
            });

            // Change handler for file input
            $input.on('change.cf7upload', function () {
                if (isProcessing) return;
                isProcessing = true;
				 const file = this.files[0];

                if (!file.type.match('image.*')) {
                   
                    $(this).val('');                   
                    isProcessing = false;
                    return;
                }

                $progress.show();

                const reader = new FileReader();
                reader.onload = function (e) {
                    $preview.html('<img src="' + e.target.result + '">').show();
                    $placeholder.hide();
                    $removeBtn.show();
                    $progress.hide();
                    isProcessing = false;
                };
                reader.readAsDataURL(file);
            });

            // Remove button handler
            $removeBtn.on('click.cf7upload', function (e) {
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

    

    // Initialize image upload
    initCF7ImageUpload();

    // Other Procedure Checkbox Handling
    function handleOtherCheckbox() {
        const checkboxes = $('#procedures input[type="checkbox"]');
        const otherWrapper = $('#other-procedure-wrapper');
        const otherInput = $('#other-procedure');
        function toggleOtherField() {
            const otherChecked = checkboxes.filter('[value="Other"]').is(':checked');
            otherWrapper.css('display', otherChecked ? 'block' : 'none');
            if (!otherChecked) {
                otherInput.val(''); // Clear when unchecked
            }
        }

        // Attach change event to all checkboxes
        checkboxes.on('change', toggleOtherField);

        // Initial check on page load
        toggleOtherField();
    }

    // Initialize checkbox handling
    handleOtherCheckbox();
	   
	   //////////form submit///
	   document.addEventListener( 'wpcf7mailsent', function( event ) {
   // if ( '123' === event.detail.contactFormId ) { // Replace '123' with your form ID
        location.href = '/consultation-request/submitted/';
    //}
}, false );

        });