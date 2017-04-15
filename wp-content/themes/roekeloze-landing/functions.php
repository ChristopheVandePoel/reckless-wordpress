<?php 
function add_cors_http_header(){

    // ENABLE CORS FOR GIVEN ADDRESSES

$http_origin = $_SERVER['HTTP_ORIGIN'];

if ($http_origin == "http://localhost:4200" || $http_origin == "http://roekeloos.be")
    {  
        header("Access-Control-Allow-Origin: $http_origin");
    }
}
add_action('init','add_cors_http_header');

function xxxx_add_edit_form_multipart_encoding() {

    echo ' enctype="multipart/form-data"';

}
add_action('post_edit_form_tag', 'xxxx_add_edit_form_multipart_encoding');

    // POSTS CAN HAVE AN IMAGE UPLOADED AS BANNER

function xxxx_render_image_attachment_box($post) {

    // See if there's an existing image. (We're associating images with posts by saving the image's 'attachment id' as a post meta value)
    // Incidentally, this is also how you'd find any uploaded files for display on the frontend.
    $existing_image_id = get_post_meta($post->ID,'_xxxx_attached_image', true);
    if(is_numeric($existing_image_id)) {

        echo '<div class="post-uploaded_image">';
            $arr_existing_image = wp_get_attachment_image_src($existing_image_id, 'large');
            $existing_image_url = $arr_existing_image[0];
            echo '<img src="' . $existing_image_url . '" />';
        echo '</div>';

    }

    // If there is an existing image, show it
    if($existing_image_id) {

        echo '<div>Attached Image ID: ' . $existing_image_id . '</div>';

    } 

    echo 'Upload an image: <input type="file" name="xxxx_image" id="xxxx_image" />';

    // See if there's a status message to display (we're using this to show errors during the upload process, though we should probably be using the WP_error class)
    $status_message = get_post_meta($post->ID,'_xxxx_attached_image_upload_feedback', true);

    // Show an error message if there is one
    if($status_message) {

        echo '<div class="upload_status_message">';
            echo $status_message;
        echo '</div>';

    }

    // Put in a hidden flag. This helps differentiate between manual saves and auto-saves (in auto-saves, the file wouldn't be passed).
    echo '<input type="hidden" name="xxxx_manual_save_flag" value="true" />';

}

function xxxx_setup_meta_boxes() {

    // Add the box to a particular custom content type page
    add_meta_box('xxxx_image_box', 'Upload Image', 'xxxx_render_image_attachment_box', 'post', 'normal', 'high');

}
add_action('admin_init','xxxx_setup_meta_boxes');

function xxxx_update_post($post_id, $post) {

    // Get the post type. Since this function will run for ALL post saves (no matter what post type), we need to know this.
    // It's also important to note that the save_post action can runs multiple times on every post save, so you need to check and make sure the
    // post type in the passed object isn't "revision"
    $post_type = $post->post_type;

    // Make sure our flag is in there, otherwise it's an autosave and we should bail.
    if($post_id && isset($_POST['xxxx_manual_save_flag'])) { 

        // Logic to handle specific post types
        switch($post_type) {

            // If this is a post. You can change this case to reflect your custom post slug
            case 'post':

                // HANDLE THE FILE UPLOAD

                // If the upload field has a file in it
                if(isset($_FILES['xxxx_image']) && ($_FILES['xxxx_image']['size'] > 0)) {

                    // Get the type of the uploaded file. This is returned as "type/extension"
                    $arr_file_type = wp_check_filetype(basename($_FILES['xxxx_image']['name']));
                    $uploaded_file_type = $arr_file_type['type'];

                    // Set an array containing a list of acceptable formats
                    $allowed_file_types = array('image/jpg','image/jpeg','image/gif','image/png');

                    // If the uploaded file is the right format
                    if(in_array($uploaded_file_type, $allowed_file_types)) {

                        // Options array for the wp_handle_upload function. 'test_upload' => false
                        $upload_overrides = array( 'test_form' => false ); 

                        // Handle the upload using WP's wp_handle_upload function. Takes the posted file and an options array
                        $uploaded_file = wp_handle_upload($_FILES['xxxx_image'], $upload_overrides);

                        // If the wp_handle_upload call returned a local path for the image
                        if(isset($uploaded_file['file'])) {

                            // The wp_insert_attachment function needs the literal system path, which was passed back from wp_handle_upload
                            $file_name_and_location = $uploaded_file['file'];

                            // Generate a title for the image that'll be used in the media library
                            $file_title_for_media_library = 'your title here';

                            // Set up options array to add this file as an attachment
                            $attachment = array(
                                'post_mime_type' => $uploaded_file_type,
                                'post_title' => 'Uploaded image ' . addslashes($file_title_for_media_library),
                                'post_content' => '',
                                'post_status' => 'inherit'
                            );

                            // Run the wp_insert_attachment function. This adds the file to the media library and generates the thumbnails. If you wanted to attch this image to a post, you could pass the post id as a third param and it'd magically happen.
                            $attach_id = wp_insert_attachment( $attachment, $file_name_and_location );
                            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
                            $attach_data = wp_generate_attachment_metadata( $attach_id, $file_name_and_location );
                            wp_update_attachment_metadata($attach_id,  $attach_data);

                            // Before we update the post meta, trash any previously uploaded image for this post.
                            // You might not want this behavior, depending on how you're using the uploaded images.
                            $existing_uploaded_image = (int) get_post_meta($post_id,'_xxxx_attached_image', true);
                            if(is_numeric($existing_uploaded_image)) {
                                wp_delete_attachment($existing_uploaded_image);
                            }

                            // Now, update the post meta to associate the new image with the post
                            update_post_meta($post_id,'_xxxx_attached_image',$attach_id);

                            // Set the feedback flag to false, since the upload was successful
                            $upload_feedback = false;


                        } else { // wp_handle_upload returned some kind of error. the return does contain error details, so you can use it here if you want.

                            $upload_feedback = 'There was a problem with your upload.';
                            update_post_meta($post_id,'_xxxx_attached_image',$attach_id);

                        }

                    } else { // wrong file type

                        $upload_feedback = 'Please upload only image files (jpg, gif or png).';
                        update_post_meta($post_id,'_xxxx_attached_image',$attach_id);

                    }

                } else { // No file was passed

                    $upload_feedback = false;

                }

                // Update the post meta with any feedback
                update_post_meta($post_id,'_xxxx_attached_image_upload_feedback',$upload_feedback);

                break;

                default:

            } // End switch

        return;

        } // End if manual save flag

    return;

}
add_action('save_post','xxxx_update_post',1,2);


function filter_post_json( $data, $post, $context ) {
  $source = get_post_meta( $post->ID, '_xxxx_attached_image', true ); // get the value from the meta field
	if( $source ) { // include it in the response if not empty
		$data->data['custom_meta'] = array( 'imageID' => $source );
	}
	
	return $data;
}
add_filter( 'rest_prepare_post', 'filter_post_json', 10, 3 );


function my_custom_css() {
  echo '<style>
    .post-uploaded_image {
        width: 100%;
        height: auto;
    }
    .post-uploaded_image img {
        max-width:700px;
        height:auto;
    }
  </style>';
}

add_action('admin_head', 'my_custom_css');