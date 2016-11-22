<?php /*

**************************************************************************

Plugin Name:  WordPress.com Thumbnail Editor
Description:  Since thumbnails are generated on-demand on WordPress.com, thumbnail cropping location must be set via the URL. This plugin assists in doing this. Based on concepts by Imran Nathani of <a href="http://metronews.ca/">Metro News Canada</a>.
Author:       Automattic
Author URI:   http://vip.wordpress.com/

**************************************************************************/


class WPcom_Thumbnail_Editor {

	/**
	 * Post meta key name, for storing crop coordinates.
	 *
	 * @var string
	 */
	public $post_meta = 'wpcom_thumbnail_edit';

	/**
	 * Determine if we want to use a dimension map or not
	 */
	public $use_ratio_map = false;

	/**
	 * An array that maps specific aspect ratios to image size strings. Should be defined by user to be accurate.
	 */
	public $image_ratio_map = array();

	/**
	 * Default settings for allowing private blogs to use this plugin.
	 */
	public $allow_private_blogs = false;

	/**
	 * Initialize the class by registering various hooks.
	 */
	function __construct() {

		$args = apply_filters( 'wpcom_thumbnail_editor_args', array(
			'image_ratio_map' => false,
		) );

		// Allow for private blogs to use this plugin
		$this->allow_private_blogs = apply_filters( 'wpcom_thumbnail_editor_allow_private_blogs', $this->allow_private_blogs );

		// When a thumbnail is requested, intercept the request and return the custom thumbnail
		if ( ! function_exists( 'is_private_blog' ) || ( function_exists( 'is_private_blog' )
			&& ( ! is_private_blog() || true === $this->allow_private_blogs ) ) ) {
			add_filter( 'image_downsize', array( $this, 'get_thumbnail_url' ), 15, 3 );
		}

		// Admin-only hooks
		if ( is_admin() ) {

			// Add a new field to the edit attachment screen
			add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_fields_to_edit' ), 50, 2 );

			// Create a new screen for editing a thumbnail
			add_action( 'admin_action_wpcom_thumbnail_edit', array( $this, 'edit_thumbnail_screen' ) );

			// Handle the form submit of the edit thumbnail screen
			add_action( 'wp_ajax_wpcom_thumbnail_edit', array( $this, 'post_handler' ) );

			add_action( 'admin_notices', array( $this, 'jetpack_photon_url_message' ) );
		}

		// using a global for now, maybe these values could be set in constructor in future?
		if( is_array( $args['image_ratio_map'] ) ) {
			$this->use_ratio_map = true;

			// Validate image sizes
			global $_wp_additional_image_sizes;
			foreach ( $args['image_ratio_map'] as $ratio => $image_sizes ) {
				$ratio_map[ $ratio ] = array();

				foreach ( $image_sizes as $image_size ) {
					if ( array_key_exists( $image_size, $_wp_additional_image_sizes ) )
						$ratio_map[ $ratio ][] = $image_size;
				}

				if ( empty( $ratio_map[ $ratio ] ) )
					unset( $ratio_map[ $ratio ] );
			}
			$this->image_ratio_map = $ratio_map;
		}
	}

	/**
	 * Display a message if JetPack isn't enabled (specifically, jetpack_photon_url is not defined.)
	 */
	function jetpack_photon_url_message() {
		if( function_exists( 'jetpack_photon_url' ) )
			return;

		echo '<div class="error"><p>' . __( 'Jetpack is not enabled, which will disable some features of the WordPress.com Thumbnail Editor module. Please enable JetPack to make this module fully functional.', 'wpcom-thumbnail-editor' ) . '</p></div>';

		settings_errors( 'wpcom_thumbnail_edit' );
	}

	/**
	 * Adds a new field to the edit attachment screen that lists thumbnail sizes.
	 *
	 * @param $form_fields array Existing fields.
	 * @param $attachment object The attachment currently being edited.
	 * @return array Form fields, either unmodified on error or new field added on success.
	 */
	public function add_attachment_fields_to_edit( $form_fields, $attachment ) {
		if ( ! wp_attachment_is_image( $attachment->ID ) )
			return $form_fields;

		$form_fields['wpcom_thumbnails'] = array(
			'label' => 'Thumbnail Images',
			'input' => 'html',
			'html'  => $this->get_attachment_field_html( $attachment ),
		);

		return $form_fields;
	}

	/**
	 * Generates the HTML for the edit attachment field.
	 *
	 * @param $attachment object The attachment currently being edited.
	 * @return string The HTML for the form field.
	 */
	public function get_attachment_field_html( $attachment ) {

		$sizes = $this->use_ratio_map ? $this->get_image_sizes_by_ratio() : $this->get_intermediate_image_sizes();

		$sizes = apply_filters( 'wpcom_thumbnail_editor_image_size_names_choose', $sizes );

		if ( empty( $sizes ) ) {
			return '<p>' . __( 'No thumbnail sizes could be found that are cropped. For now this functionality only supports cropped thumbnails.', 'wpcom-thumbnail-editor' ) . '</p>';
		}

		// Photon has to be able to access the source images
		if ( function_exists( 'is_private_blog' ) && is_private_blog() && true !== $this->allow_private_blogs ) {
			return '<p>' . sprintf( __( "The WordPress.com VIP custom thumbnail cropping functionality doesn't work on sites <a href='%s'>marked as private</a>.", 'wpcom-thumbnail-editor' ), admin_url( 'options-reading.php' ) ) . '</p>';
		} elseif ( 'localhost' == $_SERVER['HTTP_HOST'] ) {
			return '<p>' . __( "The WordPress.com VIP custom thumbnail cropping functionality needs the images be publicly accessible in order to work, which isn't possible when you're developing locally.", 'wpcom-thumbnail-editor' ) . '</p>';
		}

		$html = '<p class="hide-if-js">' . __( 'You need to enable Javascript to use this functionality.', 'wpcom-thumbnail-editor' ) . '</p>';

		$html .= sprintf(
			'<a class="hide-if-no-js button" href="%1$s" target="_blank">%2$s</a>',
			esc_url( admin_url( 'admin.php?action=wpcom_thumbnail_edit&id=' . intval( $attachment->ID ) ) ),
			esc_html__( 'Edit Thumbnails', 'wpcom-thumbnail-editor' )
		);

		return $html;
	}

	/**
	 * Outputs the HTML for the thumbnail crop selection screen.
	 */
	public function edit_thumbnail_screen() {
		global $parent_file, $submenu_file, $title;

		// Set the activate menu items.
		$parent_file = 'upload.php';
		$submenu_file = 'upload.php';

		// Validate "id" and "size" query string values and check user capabilities. Dies on error.
		$attachment = $this->validate_parameters();

		// Make sure the image fits on the screen
		if ( ! $image = image_downsize( $attachment->ID, array( 1024, 1024 ) ) ) {
			wp_die( esc_html__( 'Failed to downsize the original image to fit on your screen. How odd. Please contact support.', 'wpcom-thumbnail-editor' ) );
		}

		// Enqueue all the static assets.
		$assets_dir = trailingslashit( plugins_url( '', __FILE__ ) );
		wp_enqueue_style( 'wpcom-thumbnail-editor-css', $assets_dir . 'wpcom-thumbnail-editor.css', array( 'imgareaselect' ), '1.0.0' );
		wp_enqueue_script( 'wpcom-thumbnail-editor-js', $assets_dir . 'wpcom-thumbnail-editor.js', array( 'jquery', 'imgareaselect' ), '1.0.0' );
		wp_localize_script( 'wpcom-thumbnail-editor-js', 'wpcomThumbnailEditor', array(
			'imgWidth' => $image[1],
			'imgHeight' => $image[2],
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );

		// Get all the image sizes.
		$sizes = $this->use_ratio_map ? $this->get_image_sizes_by_ratio() : $this->get_intermediate_image_sizes();
		$sizes = apply_filters( 'wpcom_thumbnail_editor_image_size_names_choose', $sizes );

		// Build an array of data for each size.
		$sizes_data = array();
		foreach ( $sizes as $key => $size ) {
			// Get a name for this image, either the map name or the image size.
			$image_name = $this->use_ratio_map ? $key : $size;
			$image_name = apply_filters( 'wpcom_thumbnail_editor_image_name', $image_name, $key, $size, $this->use_ratio_map );

			$sizes_data[ $image_name ] = $this->get_cropping_data_for_image( $attachment->ID, $size, $image );
		}

		require( ABSPATH . '/wp-admin/admin-header.php' );
?>

<div class="wrap">
	<h2><?php echo esc_html( sprintf( __( 'Editing Thumbnails for %s', 'wpcom-thumbnail-editor' ), get_the_title( $attachment ) ) ); ?></h2>

	<div id="wpcom-thumbnail-columns">
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" id="wpcom-thumbnail-image-col">

			<noscript><p><strong style="color:red;font-size:20px;"><?php esc_html_e( 'Please enable Javascript to use this page.', 'wpcom-thumbnail-editor' ); ?></strong></p></noscript>

			<p><?php esc_html_e( 'The original image is shown in full below, although it may have been shrunk to fit on your screen.', 'wpcom-thumbnail-editor' ); ?></p>

			<p><img src="<?php echo esc_url( $image[0] ); ?>" width="<?php echo (int) $image[1]; ?>" height="<?php echo (int) $image[2]; ?>" id="wpcom-thumbnail-edit" alt="<?php esc_attr_e( 'Image cropping region', 'wpcom-thumbnail-editor' ); ?>" /></p>

			<?php do_action( 'wpcom_thumbnail_editor_edit_thumbnail_screen', $attachment->ID, $size ) ?>

			<p id="wpcom-thumbnail-actions">
				<?php submit_button( null, 'primary wpcom-thumbnail-save', 'submit', false ); ?>
				<?php submit_button( __( 'Reset Thumbnail', 'wpcom-thumbnail-editor' ), 'secondary wpcom-thumbnail-save', 'wpcom_thumbnail_edit_reset', false ); ?>
				<a href="#" class="button button-secondary" id="wpcom-thumbnail-cancel"><?php esc_html_e( 'Cancel Changes', 'wpcom-thumbnail-editor' ); ?></a>
				<i class="spinner"></i>
				<span id="wpcom-thumbnail-feedback"></span>
			</p>

			<input type="hidden" name="action" value="wpcom_thumbnail_edit" />
			<input type="hidden" name="id" value="<?php echo (int) $attachment->ID; ?>" />
			<input type="hidden" name="size" value="" id="wpcom-thumbnail-size" />
			<?php wp_nonce_field( 'wpcom_thumbnail_edit_' . $attachment->ID ); ?>

			<?php
			/**
			 * Since the fullsize image is possibly scaled down, we need to record
			 * at what size it was displayed at so the we can scale up the new
			 * selection dimensions to the fullsize image.
			 */
			?>
			<input type="hidden" name="wpcom_thumbnail_edit_display_width"  value="<?php echo intval( $image[1] ); ?>" />
			<input type="hidden" name="wpcom_thumbnail_edit_display_height" value="<?php echo intval( $image[2] ); ?>" />

			<?php
			/**
			 * These are manipulated via Javascript to submit the selected values.
			 */
			?>
			<input type="hidden" id="wpcom_thumbnail_edit_x1" name="wpcom_thumbnail_edit_x1" value="" /> <?php // was: <?php echo (int) $initial_selection[0]; ?>
			<input type="hidden" id="wpcom_thumbnail_edit_y1" name="wpcom_thumbnail_edit_y1" value="" /> <?php // was: <?php echo (int) $initial_selection[1]; ?>
			<input type="hidden" id="wpcom_thumbnail_edit_x2" name="wpcom_thumbnail_edit_x2" value="" /> <?php // was: <?php echo (int) $initial_selection[2]; ?>
			<input type="hidden" id="wpcom_thumbnail_edit_y2" name="wpcom_thumbnail_edit_y2" value="" /> <?php // was: <?php echo (int) $initial_selection[3]; ?>
		</form>

		<aside id="wpcom-thumbnail-sizes-col">
			<div>
				<p><?php esc_html_e( 'Click on a thumbnail image to modify it. Each thumbnail has likely been scaled down in order to fit nicely into a grid.', 'wpcom-thumbnail-editor' ) ?></p>
				<p><?php printf( esc_html__( '%1$sOnly thumbnails that are cropped are shown.%2$s Other sizes are hidden because they will be scaled to fit.', 'wpcom-thumbnail-editor' ), '<strong>', '</strong>' ) ?></p>

				<ul>
				<?php foreach ( $sizes_data as $image_name => $image_data ) : ?>
					<li>
						<a href="#wpcom-thumbnail-edit"
							class="wpcom-thumbnail-size wpcom-thumbnail-crop-activate"
							data-selection="<?php echo esc_attr( implode( ',', $image_data['selection'] ) ) ?>"
							data-ratio="<?php echo esc_attr( $image_data['aspect_ratio_string'] ); ?>"
							data-size="<?php echo esc_attr( $image_data['size'] ); ?>"
							id="wpcom-thumbnail-size-<?php echo esc_attr( $image_data['size'] ); ?>"
						>
							<strong><?php echo esc_html( $image_name ) ?></strong>
							<img src="<?php echo esc_url( $image_data['nav_thumbnail_url'] ); ?>" alt="<?php echo esc_attr( $image_name ) ?>" />
						</a>
					</li>
				<?php endforeach ?>
				</ul>
			</div>
		</aside>
	</div>

	<div id="wpcom-thumbnail-edit-preview-container">
		<h3><?php esc_html_e( 'Fullsize Thumbnail Preview', 'wpcom-thumbnail-editor' ); ?></h3>

		<div id="wpcom-thumbnail-edit-preview-mask">
			<img id="wpcom-thumbnail-edit-preview" class="hidden" src="<?php echo esc_url( wp_get_attachment_url( $attachment->ID ) ); ?>" />
		</div>
	</div>
</div>

<?php

		require( ABSPATH . '/wp-admin/admin-footer.php' );
	}

	/**
	 * Processes the submission of the thumbnail crop selection screen and saves the results to post meta.
	 */
	public function post_handler() {
		// Filter the wp_die() ajax handler so we can call wp_send_json_error()
		// in validate_parameters().
		// @todo Make this unnecessary.
		add_filter( 'wp_die_ajax_handler', array( $this, 'wp_die_ajax_handler' ) );

		// Validate "id" and "size" POST values and check user capabilities. Dies on error.
		$attachment = $this->validate_parameters();

		// Remove the filter, let wp_die() work as normal now.
		remove_filter( 'wp_die_ajax_handler', array( $this, 'wp_die_ajax_handler' ) );

		$size = $_REQUEST['size']; // Validated in this::validate_parameters()

		check_admin_referer( 'wpcom_thumbnail_edit_' . $attachment->ID );

		// Reset to default?
		if ( ! empty( $_POST['wpcom_thumbnail_edit_reset'] ) ) {
			$this->delete_coordinates( $attachment->ID, $size );

			// Get original cropping data for this size.
			$cropping_data = $this->get_cropping_data_for_image( $attachment->ID, $size, image_downsize( $attachment->ID, array( 1024, 1024 ) ) );
			if ( $cropping_data ) {
				wp_send_json_success( array(
					'message' => __( 'Thumbnail position successfully reset', 'wpcom-thumbnail-editor' ),
					'thumbnail' => $cropping_data['nav_thumbnail_url'],
					'selection' => implode( ',', $cropping_data['selection'] ),
					'size' => $size,
				) );
			} else {
				wp_send_json_error( array(
					'message' => __( 'There was an error retrieving data about the reset thumbnail, please refresh your screen and try again.', 'wpcom-thumbnail-editor' ),
				) );
			}
		}

		$required_fields = array(
			'wpcom_thumbnail_edit_display_width'  => 'display_width',
			'wpcom_thumbnail_edit_display_height' => 'display_height',
			'wpcom_thumbnail_edit_x1'             => 'selection_x1',
			'wpcom_thumbnail_edit_y1'             => 'selection_y1',
			'wpcom_thumbnail_edit_x2'             => 'selection_x2',
			'wpcom_thumbnail_edit_y2'             => 'selection_y2',
		);

		foreach ( $required_fields as $required_field => $variable_name ) {
			if ( empty ( $_POST[ $required_field ] ) && 0 != $_POST[ $required_field ] ) {
				wp_send_json_error( array(
					'message' => sprintf( __( 'Invalid parameter: %s', 'wpcom-thumbnail-editor' ), $required_field ),
				) );
			}

			$$variable_name = intval( $_POST[ $required_field ] );
		}

		$attachment_metadata = wp_get_attachment_metadata( $attachment->ID );

		$selection_coordinates = array( 'selection_x1', 'selection_y1', 'selection_x2', 'selection_y2' );

		// If the image was scaled down on the selection screen,
		// then we need to scale up the selection to fit the fullsize image
		if ( $attachment_metadata['width'] > $display_width || $attachment_metadata['height'] > $display_height ) {
			$scale_ratio = $attachment_metadata['width'] / $display_width;

			foreach ( $selection_coordinates as $selection_coordinate ) {
				${'fullsize_' . $selection_coordinate} = round( $$selection_coordinate * $scale_ratio );
			}
		} else {
			// Remap
			foreach ( $selection_coordinates as $selection_coordinate ) {
				${'fullsize_' . $selection_coordinate} = $$selection_coordinate;
			}
		}

		// Save the coordinates
		$this->save_coordinates( $attachment->ID, $size, array( $fullsize_selection_x1, $fullsize_selection_y1, $fullsize_selection_x2, $fullsize_selection_y2 ) );

		// Allow for saving custom fields
		do_action( 'wpcom_thumbnail_editor_post_handler', $attachment->ID, $size );

		wp_send_json_success( array(
			'message' => __( 'Thumbnail position successfully updated', 'wpcom-thumbnail-editor' ),
			'thumbnail' => $this->get_nav_thumbnail_url( $attachment->ID, $size ),
			'selection' => implode( ',', call_user_func_array( 'compact', $selection_coordinates ) ),
			'size' => $size,
		) );
	}

	public function wp_die_ajax_handler( $function ) {
		return array( $this, 'wp_die_json_error' );
	}

	public function wp_die_json_error( $message, $title, $args ) {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'wp_die_ajax_handler' ) );
		wp_send_json_error( compact( 'message' ) );
	}

	/**
	 * Makes sure that the "id" (attachment ID) and "size" (thumbnail size) query string parameters are valid
	 * and dies if they are not. Returns attachment object with matching ID on success.
	 *
	 * @return null|object Dies on error, returns attachment object on success.
	 */
	public function validate_parameters() {
		if ( empty( $_REQUEST['id'] ) || ! $attachment = get_post( intval( $_REQUEST['id'] ) ) )
			wp_die( sprintf( __( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>id</code>' ) );

		if ( 'attachment' != $attachment->post_type  || ! wp_attachment_is_image( $attachment->ID ) )
			wp_die( sprintf( __( 'That is not a valid image attachment.', 'wpcom-thumbnail-editor' ), '<code>id</code>' ) );

		if ( ! current_user_can( get_post_type_object( $attachment->post_type )->cap->edit_post, $attachment->ID ) )
			wp_die( __( 'You are not allowed to edit this attachment.', 'wpcom-thumbnail-editor' ) );

		// Validate `size` if present.
		if ( ! empty( $_REQUEST['size'] ) ) {
			if ( $this->use_ratio_map ) {
				if ( ! in_array( $_REQUEST['size'], $this->get_image_sizes_by_ratio() ) )
					wp_die( sprintf( __( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>size</code>' ) );
			} else {
				if ( ! in_array( $_REQUEST['size'], $this->get_intermediate_image_sizes() ) )
					wp_die( sprintf( __( 'Invalid %s parameter.', 'wpcom-thumbnail-editor' ), '<code>size</code>' ) );
			}
		}

		return $attachment;
	}

	/**
	 * Returns all thumbnail size names. get_intermediate_image_sizes() is filtered to return an
	 * empty array on WordPress.com so this function removes that filter, calls the function,
	 * and then re-adds the filter back onto the function.
	 *
	 * @return array An array of image size strings.
	 */
	public function get_intermediate_image_sizes( $cropped_only = true ) {
		global $_wp_additional_image_sizes;

		# /wp-content/mu-plugins/wpcom-media.php
		$had_filter = remove_filter( 'intermediate_image_sizes', 'wpcom_intermediate_sizes' );

		$sizes = get_intermediate_image_sizes();

		if ( $had_filter ) {
			add_filter( 'intermediate_image_sizes', 'wpcom_intermediate_sizes' );
		}

		if ( apply_filters( 'wpcom_thumbnail_editor_cropped_only', $cropped_only ) ) {
			$filtered_sizes = array();

			foreach ( $sizes as $size ) {
				switch ( $size ) {
					case 'thumbnail':
						if ( get_option( 'thumbnail_crop' ) )
							$filtered_sizes[] = $size;
						break;

					case 'medium':
					case 'large':
						break;

					default:
						if ( ! empty( $_wp_additional_image_sizes[$size] ) && $_wp_additional_image_sizes[$size]['crop'] )
							$filtered_sizes[] = $size;
				}
			}

			$sizes = $filtered_sizes;
		}

		return apply_filters( 'wpcom_thumbnail_editor_get_intermediate_image_sizes', $sizes, $cropped_only );
	}

	/**
	 * Gets the first size defined for each dimension. All images are assumed to be cropped
	 *
	 * @todo Add validation that image sizes are of the cropped variety?
	 * @return array Array of image size strings.
	 */
	public function get_image_sizes_by_ratio() {

		$ratios = array_keys( $this->image_ratio_map );

		foreach( $ratios as $ratio ) {
			if( isset( $this->image_ratio_map[$ratio][0] ) )
				$sizes[$ratio] = $this->image_ratio_map[$ratio][0];
		}

		return $sizes;
	}

	/**
	 * Returns the width and height of a given thumbnail size.
	 *
	 * @param $size string Thumbnail size name.
	 * @return array|false Associative array of width and height in pixels. False on invalid size.
	 */
	public function get_thumbnail_dimensions( $size ) {
		global $_wp_additional_image_sizes;

		switch ( $size ) {
			case 'thumbnail':
			case 'medium':
			case 'large':
				$width  = get_option( $size . '_size_w' );
				$height = get_option( $size . '_size_h' );
				break;

			default:
				if ( empty( $_wp_additional_image_sizes[$size] ) )
					return false;

				$width  = $_wp_additional_image_sizes[$size]['width'];
				$height = $_wp_additional_image_sizes[$size]['height'];
		}

		// Just to be safe
		$width  = (int) $width;
		$height = (int) $height;

		return array( 'width' => $width, 'height' => $height );
	}

	/**
	 * Fetches the coordinates for a custom crop for a given attachment ID and thumbnail size.
	 *
	 * @param $attachment_id int Attachment ID.
	 * @param $size string Thumbnail size name.
	 * @return array|false Array of crop coordinates or false if no custom selection set.
	 */
	public function get_coordinates( $attachment_id, $size ) {
		$sizes = (array) get_post_meta( $attachment_id, $this->post_meta, true );

		$coordinates = false;

		if ( empty( $sizes[ $size ] ) ) {
			// Coordinates not explictly set for this size, but is it in a size group? If so, we can use the coordinates
			// from other sizes in the same group, as they are always the same. Happens if a size is added to a group later and hasn't
			// been backfilled in all post meta. Not sure why coords are saved for every size, rather than group, but hey.
			if ( $this->use_ratio_map ) {
				foreach( $this->image_ratio_map as $ratio => $ratio_sizes ) {
					foreach( $ratio_sizes as $ratio_size ) {
						if ( $size === $ratio_size ) {
							// Determine if there are any saved coordinates that match the desired $size in the matched ratio group
							$intersect = array_intersect_key( $ratio_sizes, $sizes );

							if ( is_array( $intersect ) && ! empty( $intersect ) ) {
								foreach( $intersect as $matching_size ) {
									if ( isset( $sizes[ $matching_size ] ) ) {
										$coordinates 	= $sizes[ $matching_size ];

										break;
									}
								}
							}
						}
					}
				}
			}
		} else {
			$coordinates = $sizes[ $size ];
		}

		return $coordinates;
	}

	/**
	 * Saves the coordinates for a custom crop for a given attachment ID and thumbnail size.
	 *
	 * @param $attachment_id int Attachment ID.
	 * @param $size string Thumbnail size name.
	 * @param $coordinates array Array of coordinates in the format array( x1, y1, x2, y2 )
	 */
	public function save_coordinates( $attachment_id, $size, $coordinates ) {
		$sizes = (array) get_post_meta( $attachment_id, $this->post_meta, true );

		$sizes[$size] = $coordinates;

		// save meta for all the related sizes to if were using a ratio map
		if( $this->use_ratio_map ) {

			$related_sizes = $this->get_related_sizes( $size );

			// add the same meta value to the related sizes
			if( count( $related_sizes ) ) {
				foreach( $related_sizes as $related_size ){
					$sizes[$related_size] = $coordinates;
				}
			}
		}

		update_post_meta( $attachment_id, $this->post_meta, $sizes );
	}

	/**
	 * Find the siblings of the passed size so we can apply the coordinates to them too.
	 *
	 * @return array Array of related image size strings
	 */
	public function get_related_sizes( $size ) {

		$related_sizes = array();

		// find out which ratio map the size belongs to
		foreach( $this->image_ratio_map as $ratio => $ratio_sizes ) {
			foreach( $ratio_sizes as $ratio_size ) {
				if( $ratio_size == $size ){
					$related_sizes = $this->image_ratio_map[$ratio];
					break 2;
				}
			}
		}

		return $related_sizes;
	}

	/**
	 * Deletes the coordinates for a custom crop for a given attachment ID and thumbnail size.
	 *
	 * @param $attachment_id int Attachment ID.
	 * @param $size string Thumbnail size name.
	 * @return bool False on failure (probably no such custom crop), true on success.
	 */
	public function delete_coordinates( $attachment_id, $size ) {
		if ( ! $sizes = get_post_meta( $attachment_id, $this->post_meta, true ) ) {
			return false;
		}

		if ( empty( $sizes[$size] ) ) {
			return false;
		}

		unset( $sizes[$size] );

		// also unset related sizes
		if( $this->use_ratio_map ) {
			$related_sizes = $this->get_related_sizes( $size );
			if( count( $related_sizes ) ) {
				foreach( $related_sizes as $related_size ){
					unset( $sizes[$related_size] );
				}
			}
		}

		return update_post_meta( $attachment_id, $this->post_meta, $sizes );
	}

	/**
	 * Returns the attributes for a given attachment thumbnail. Meant for hooking into image_downsize().
	 *
	 * @param $existing_resize array|false Any existing data. Returned on no action.
	 * @param $attachment_id int Attachment ID.
	 * @param $size string Thumbnail size name.
	 * @return mixed Array of thumbnail details (URL, width, height, is_intermedite) or the previous data.
	 */
	public function get_thumbnail_url( $existing_resize, $attachment_id, $size ) {

		//On dev sites, Jetpack is often active but Photon will not work because the content files are not accessible to the public internet.
		//Right now, a broken image is displayed when this plugin is active and a thumbnail has been edited. This will allow the unmodified image to be displayed.
		if( !function_exists( 'jetpack_photon_url' ) ||  defined('JETPACK_DEV_DEBUG') )
			return $existing_resize;

		// Named sizes only
		if ( is_array( $size ) ) {
			return $existing_resize;
		}

		$coordinates = $this->get_coordinates( $attachment_id, $size );

		if ( ! $coordinates || ! is_array( $coordinates ) || 4 != count( $coordinates ) ) {
			return $existing_resize;
		}

		if ( ! $thumbnail_size = $this->get_thumbnail_dimensions( $size ) ) {
			return $existing_resize;
		}

		list( $selection_x1, $selection_y1, $selection_x2, $selection_y2 ) = $coordinates;

		if ( function_exists( 'jetpack_photon_url' ) ) {
			$url = jetpack_photon_url(
				wp_get_attachment_url( $attachment_id ),
				apply_filters( 'wpcom_thumbnail_editor_thumbnail_args', array(
					'crop' => array(
						$selection_x1 . 'px',
						$selection_y1 . 'px',
						( $selection_x2 - $selection_x1 ) . 'px',
						( $selection_y2 - $selection_y1 ) . 'px',
					),
					'resize' => array(
						$thumbnail_size['width'],
						$thumbnail_size['height'],
					),
				), $attachment_id, $size, $thumbnail_size )
			);
		} else {
			$url = wp_get_attachment_url( $attachment_id );
		}

		return array( $url, $thumbnail_size['width'], $thumbnail_size['height'], true );
	}

	protected function get_nav_thumbnail_url( $attachment_id, $size ) {
		// We need to get the fullsize thumbnail so that the cropping is
		// properly done.
		$nav_thumbnail = image_downsize( $attachment_id, $size );

		// Resize the thumbnail to fit into a small box so it's displayed at
		// a reasonable size.
		if ( function_exists( 'jetpack_photon_url' ) ) {
			return jetpack_photon_url(
				$nav_thumbnail[0],
				apply_filters( 'wpcom_thumbnail_editor_preview_args', array( 'fit' => array( 250, 250 ) ), $attachment_id, $size )
			);
		} else {
			return $nav_thumbnail[0];
		}
	}

	protected function get_cropping_data_for_image( $attachment_id, $size, $image ) {
		// How big is the final thumbnail image? Check this early so we can
		// abort if the size isn't valid.
		if ( ! $thumbnail_dimensions = $this->get_thumbnail_dimensions( $size ) ) {
			return false;
		}

		$original_aspect_ratio = $image[1] / $image[2];

		// Get the thumbnail URL for the crops nav.
		$nav_thumbnail_url = $this->get_nav_thumbnail_url( $attachment_id, $size );

		// Build the selection coordinates.
		$aspect_ratio = $thumbnail_dimensions['width'] / $thumbnail_dimensions['height'];
		$aspect_ratio_string = $thumbnail_dimensions['width'] . ':' . $thumbnail_dimensions['height'];

		// If there's already a custom selection, use that.
		if ( $coordinates = $this->get_coordinates( $attachment_id, $size ) ) {
			$attachment_metadata = wp_get_attachment_metadata( $attachment_id );

			// If original is bigger than display, scale down the
			// coordinates to match the scaled down original.
			if ( $attachment_metadata['width'] > $image[1] || $attachment_metadata['height'] > $image[2] ) {

				// At what percentage is the image being displayed at?
				$scale = $image[1] / $attachment_metadata['width'];

				$selection = array();
				foreach ( $coordinates as $coordinate ) {
					$selection[] = round( $coordinate * $scale );
				}
			}

			// Or the image was not downscaled, so the coordinates are
			// correct.
			else {
				$selection = $coordinates;
			}
		}
		// If original and thumb are the same aspect ratio, then select the
		// whole image.
		elseif ( $aspect_ratio == $original_aspect_ratio ) {
			$selection = array( 0, 0, $image[1], $image[2] );
		}
		// If the thumbnail is wider than the original, we want the full
		// width.
		elseif ( $aspect_ratio > $original_aspect_ratio ) {
			// Take the width and divide by the thumbnail's aspect ratio.
			$selected_height = round( $image[1] / ( $thumbnail_dimensions['width'] / $thumbnail_dimensions['height'] ) );

			$selection = array(
				0,                                                     // Far left edge (due to aspect ratio comparison)
				round( ( $image[2] / 2 ) - ( $selected_height / 2 ) ), // Mid-point + half of height of selection
				$image[1],                                             // Far right edge (due to aspect ratio comparison)
				round( ( $image[2] / 2 ) + ( $selected_height / 2 ) ), // Mid-point - half of height of selection
			);
		}
		// The thumbnail must be narrower than the original, so we want the full height
		else {
			// Take the width and divide by the thumbnail's aspect ratio
			$selected_width = round( $image[2] / ( $thumbnail_dimensions['height'] / $thumbnail_dimensions['width'] ) );

			$selection = array(
				round( ( $image[1] / 2 ) - ( $selected_width / 2 ) ), // Mid-point + half of height of selection
				0,                                                    // Top edge (due to aspect ratio comparison)
				round( ( $image[1] / 2 ) + ( $selected_width / 2 ) ), // Mid-point - half of height of selection
				$image[2],                                            // Bottom edge (due to aspect ratio comparison)
			);
		}

		return compact( 'size', 'nav_thumbnail_url', 'aspect_ratio_string', 'selection' );
	}
}

// initializing the class on init so we can filter the args
add_action( 'init', function() {
	$GLOBALS['WPcom_Thumbnail_Editor'] = new WPcom_Thumbnail_Editor;
} );
