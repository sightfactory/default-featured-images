<?php
/**
 * Plugin Name: Default Featured Images with CPT & Taxonomy Overrides
 * Plugin URI: https://sightfactory.com
 * Description: Automatically sets fallback featured images from the Media Library. Supports general post-type defaults (post, page, portfolio, client) and custom category/tag/taxonomy overrides.
 * Version: 1.2.0
 * Author: Antigravity AI
 * Author URI: https://sightfactory.com
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Hook to dynamically filter post metadata for '_thumbnail_id'
 */
add_filter( 'get_post_metadata', 'dfi_override_thumbnail_id', 10, 4 );
function dfi_override_thumbnail_id( $value, $object_id, $meta_key, $single ) {
	// Only intercept featured image lookup
	if ( '_thumbnail_id' !== $meta_key ) {
		return $value;
	}

	// Bypass override on block editor edit screens to let users see and select featured images normally
	if ( is_admin() ) {
		if ( function_exists( 'get_current_screen' ) && get_current_screen() ) {
			$screen = get_current_screen();
			if ( 'post' === $screen->base || 'edit' === $screen->base ) {
				if ( 'post' === $screen->base ) {
					return $value;
				}
			}
		}
	}

	// Temporarily remove filter to check the actual database value
	remove_filter( 'get_post_metadata', 'dfi_override_thumbnail_id', 10 );
	$db_thumbnail_id = get_post_meta( $object_id, '_thumbnail_id', true );
	add_filter( 'get_post_metadata', 'dfi_override_thumbnail_id', 10, 4 );

	// If there's already a featured image selected, use it!
	if ( ! empty( $db_thumbnail_id ) ) {
		return $value;
	}

	$post = get_post( $object_id );
	if ( ! $post ) {
		return $value;
	}
	
	$post_type = $post->post_type;

	// Verify post type is public (exclude attachment)
	$public_types = get_post_types( array( 'public' => true ) );
	if ( ! in_array( $post_type, $public_types ) || 'attachment' === $post_type ) {
		return $value;
	}

	// Get settings
	$settings = get_option( 'dfi_settings', array() );
	$fallback_id = 0;

	// 1. Check term-specific overrides (highest priority)
	if ( ! empty( $settings['overrides'] ) && is_array( $settings['overrides'] ) ) {
		foreach ( $settings['overrides'] as $override ) {
			if ( ! empty( $override['post_type'] ) && $override['post_type'] === $post_type ) {
				if ( ! empty( $override['taxonomy'] ) && ! empty( $override['term_id'] ) ) {
					if ( has_term( (int) $override['term_id'], $override['taxonomy'], $object_id ) ) {
						if ( ! empty( $override['image_id'] ) ) {
							$fallback_id = (int) $override['image_id'];
							break; // Found matching override, exit loop
						}
					}
				}
			}
		}
	}

	// 2. Check general default image for this specific post type
	if ( ! $fallback_id && ! empty( $settings['post_type_defaults'][$post_type] ) ) {
		$fallback_id = (int) $settings['post_type_defaults'][$post_type];
	}

	if ( $fallback_id ) {
		return $single ? $fallback_id : array( $fallback_id );
	}

	return $value;
}

/**
 * Register Admin Settings Page
 */
add_action( 'admin_menu', 'dfi_register_admin_menu' );
function dfi_register_admin_menu() {
	add_options_page(
		esc_html__( 'Default Featured Images', 'default-featured-images' ),
		esc_html__( 'Default Featured Images', 'default-featured-images' ),
		'manage_options',
		'default-featured-images',
		'dfi_render_admin_page'
	);
}

/**
 * Enqueue WordPress Media Library and custom scripts for admin settings
 */
add_action( 'admin_enqueue_scripts', 'dfi_enqueue_admin_assets' );
function dfi_enqueue_admin_assets( $hook ) {
	if ( 'settings_page_default-featured-images' !== $hook ) {
		return;
	}
	
	wp_enqueue_media();
	wp_enqueue_script( 'jquery' );
}

/**
 * Save settings from POST request
 */
function dfi_save_posted_settings() {
	if ( ! isset( $_POST['dfi_nonce'] ) || ! wp_verify_nonce( $_POST['dfi_nonce'], 'dfi_save_settings' ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings = array(
		'post_type_defaults' => array(),
		'overrides'          => array()
	);

	if ( ! empty( $_POST['post_type_defaults'] ) && is_array( $_POST['post_type_defaults'] ) ) {
		foreach ( $_POST['post_type_defaults'] as $pt_name => $image_id ) {
			$settings['post_type_defaults'][sanitize_key( $pt_name )] = (int) $image_id;
		}
	}

	if ( ! empty( $_POST['overrides'] ) && is_array( $_POST['overrides'] ) ) {
		foreach ( $_POST['overrides'] as $override ) {
			if ( ! empty( $override['post_type'] ) && ! empty( $override['taxonomy'] ) && ! empty( $override['term_id'] ) && ! empty( $override['image_id'] ) ) {
				$settings['overrides'][] = array(
					'post_type' => sanitize_key( $override['post_type'] ),
					'taxonomy'  => sanitize_key( $override['taxonomy'] ),
					'term_id'   => (int) $override['term_id'],
					'image_id'  => (int) $override['image_id']
				);
			}
		}
	}

	update_option( 'dfi_settings', $settings );
	add_settings_error( 'dfi_messages', 'dfi_message', esc_html__( 'Settings Saved.', 'default-featured-images' ), 'updated' );
}

/**
 * Render Admin Settings Page HTML
 */
function dfi_render_admin_page() {
	if ( isset( $_POST['dfi_submit'] ) ) {
		dfi_save_posted_settings();
	}

	$settings = get_option( 'dfi_settings', array() );
	$post_type_defaults = isset( $settings['post_type_defaults'] ) ? $settings['post_type_defaults'] : array();
	$overrides = isset( $settings['overrides'] ) ? $settings['overrides'] : array();

	// Fetch all public post types
	$post_types = get_post_types( array( 'public' => true ), 'objects' );
	unset( $post_types['attachment'] );

	// Build a dynamic map of CPT -> Taxonomies -> Terms for Javascript dropdown filtering
	$cpt_taxonomy_term_map = array();
	foreach ( $post_types as $pt_name => $pt_object ) {
		$cpt_taxonomy_term_map[ $pt_name ] = array(
			'label' => $pt_object->label,
			'taxonomies' => array()
		);

		$taxonomies = get_object_taxonomies( $pt_name, 'objects' );
		foreach ( $taxonomies as $tax_name => $tax_object ) {
			if ( ! $tax_object->public ) {
				continue;
			}
			$cpt_taxonomy_term_map[ $pt_name ]['taxonomies'][ $tax_name ] = array(
				'label' => $tax_object->label,
				'terms' => array()
			);

			$terms = get_terms( array(
				'taxonomy'   => $tax_name,
				'hide_empty' => false,
			) );

			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$cpt_taxonomy_term_map[ $pt_name ]['taxonomies'][ $tax_name ]['terms'][] = array(
						'id' => $term->term_id,
						'name' => $term->name
					);
				}
			}
		}
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Default Featured Images with CPT & Taxonomy Overrides', 'default-featured-images' ); ?></h1>
		
		<?php settings_errors( 'dfi_messages' ); ?>

		<form method="post" action="">
			<?php wp_nonce_field( 'dfi_save_settings', 'dfi_nonce' ); ?>

			<!-- Post Type Defaults Section -->
			<h2><?php esc_html_e( 'Post Type General Defaults', 'default-featured-images' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Set general fallback featured images for each public post type. These apply when no specific term override matches.', 'default-featured-images' ); ?></p>
			
			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( $post_types as $pt_name => $pt_object ) : 
						$pt_image_id = isset( $post_type_defaults[$pt_name] ) ? (int) $post_type_defaults[$pt_name] : 0;
						$pt_image_url = $pt_image_id ? wp_get_attachment_image_url( $pt_image_id, 'thumbnail' ) : '';
						?>
						<tr>
							<th scope="row">
								<label><?php echo esc_html( $pt_object->label ); ?> (<?php echo esc_html( $pt_name ); ?>)</label>
							</th>
							<td>
								<div class="dfi-media-picker">
									<input type="hidden" name="post_type_defaults[<?php echo esc_attr( $pt_name ); ?>]" class="dfi-image-id" value="<?php echo esc_attr( $pt_image_id ); ?>">
									<img src="<?php echo esc_url( $pt_image_url ); ?>" class="dfi-image-preview" style="max-width: 120px; max-height: 120px; display: <?php echo $pt_image_url ? 'block' : 'none'; ?>; border: 1px solid #ccc; margin-bottom: 5px; border-radius: 4px;">
									<button type="button" class="button button-small dfi-upload-button"><?php esc_html_e( 'Select Image', 'default-featured-images' ); ?></button>
									<button type="button" class="button button-small dfi-remove-button" style="display: <?php echo $pt_image_url ? 'inline-block' : 'none'; ?>; color: #b32d2e; border-color: #b32d2e;"><?php esc_html_e( 'Remove', 'default-featured-images' ); ?></button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<hr style="margin-top: 30px;">

			<!-- Taxonomy Term Overrides -->
			<h2><?php esc_html_e( 'Taxonomy & Term Overrides', 'default-featured-images' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configure overrides targeting specific categories, tags, or custom taxonomy terms for any post type (e.g. Portfolio Category or Client Type).', 'default-featured-images' ); ?></p>

			<table id="dfi-overrides-table" class="wp-list-table widefat fixed striped" style="margin-top: 15px; max-width: 950px;">
				<thead>
					<tr>
						<th style="width: 23%;"><?php esc_html_e( 'Post Type', 'default-featured-images' ); ?></th>
						<th style="width: 23%;"><?php esc_html_e( 'Taxonomy', 'default-featured-images' ); ?></th>
						<th style="width: 23%;"><?php esc_html_e( 'Term', 'default-featured-images' ); ?></th>
						<th style="width: 21%;"><?php esc_html_e( 'Fallback Featured Image', 'default-featured-images' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Action', 'default-featured-images' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$index = 0;
					foreach ( $overrides as $override ) :
						$saved_pt = isset( $override['post_type'] ) ? $override['post_type'] : '';
						$saved_tax = isset( $override['taxonomy'] ) ? $override['taxonomy'] : '';
						$saved_term_id = isset( $override['term_id'] ) ? (int) $override['term_id'] : 0;
						$img_id = isset( $override['image_id'] ) ? (int) $override['image_id'] : 0;
						$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
						
						// Get taxonomies for saved post type
						$saved_taxonomies = array();
						if ( $saved_pt ) {
							$taxonomies_objs = get_object_taxonomies( $saved_pt, 'objects' );
							foreach ( $taxonomies_objs as $tax_name => $tax_obj ) {
								if ( $tax_obj->public ) {
									$saved_taxonomies[ $tax_name ] = $tax_obj;
								}
							}
						}
						
						// Get terms for saved taxonomy
						$saved_terms = array();
						if ( $saved_tax ) {
							$terms_objs = get_terms( array(
								'taxonomy'   => $saved_tax,
								'hide_empty' => false,
							) );
							if ( ! is_wp_error( $terms_objs ) ) {
								$saved_terms = $terms_objs;
							}
						}
						?>
						<tr class="dfi-override-row">
							<td>
								<select name="overrides[<?php echo $index; ?>][post_type]" class="dfi-post-type" style="width: 90%;">
									<option value=""><?php esc_html_e( '-- Select Post Type --', 'default-featured-images' ); ?></option>
									<?php foreach ( $post_types as $pt_name => $pt_object ) : ?>
										<option value="<?php echo esc_attr( $pt_name ); ?>" <?php selected( $saved_pt, $pt_name ); ?>>
											<?php echo esc_html( $pt_object->label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="overrides[<?php echo $index; ?>][taxonomy]" class="dfi-taxonomy" style="width: 90%;">
									<option value=""><?php esc_html_e( '-- Select Taxonomy --', 'default-featured-images' ); ?></option>
									<?php foreach ( $saved_taxonomies as $tax_name => $tax_obj ) : ?>
										<option value="<?php echo esc_attr( $tax_name ); ?>" <?php selected( $saved_tax, $tax_name ); ?>>
											<?php echo esc_html( $tax_obj->label ); ?> (<?php echo esc_html( $tax_name ); ?>)
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="overrides[<?php echo $index; ?>][term_id]" class="dfi-term" style="width: 90%;">
									<option value=""><?php esc_html_e( '-- Select Term --', 'default-featured-images' ); ?></option>
									<?php foreach ( $saved_terms as $term ) : ?>
										<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $saved_term_id, $term->term_id ); ?>>
											<?php echo esc_html( $term->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<div class="dfi-media-picker">
									<input type="hidden" name="overrides[<?php echo $index; ?>][image_id]" class="dfi-image-id" value="<?php echo esc_attr( $img_id ); ?>">
									<img src="<?php echo esc_url( $img_url ); ?>" class="dfi-image-preview" style="max-width: 80px; max-height: 80px; display: <?php echo $img_url ? 'block' : 'none'; ?>; border: 1px solid #ccc; margin-bottom: 5px; border-radius: 4px;">
									<button type="button" class="button button-small dfi-upload-button"><?php esc_html_e( 'Select Image', 'default-featured-images' ); ?></button>
									<button type="button" class="button button-small dfi-remove-button" style="display: <?php echo $img_url ? 'inline-block' : 'none'; ?>; color: #b32d2e; border-color: #b32d2e;"><?php esc_html_e( 'Remove', 'default-featured-images' ); ?></button>
								</div>
							</td>
							<td>
								<button type="button" class="button button-small dfi-remove-row" style="color: #b32d2e; border-color: #b32d2e;"><?php esc_html_e( 'Delete Row', 'default-featured-images' ); ?></button>
							</td>
						</tr>
						<?php
						$index++;
					endforeach;
					?>
				</tbody>
			</table>

			<button type="button" id="dfi-add-override" class="button button-secondary" style="margin-top: 15px;"><?php esc_html_e( 'Add Override Row', 'default-featured-images' ); ?></button>

			<p class="submit" style="margin-top: 40px;">
				<input type="submit" name="dfi_submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'default-featured-images' ); ?>">
			</p>
		</form>
	</div>

	<!-- Row Template for Javascript -->
	<script type="text/html" id="dfi-row-template">
		<tr class="dfi-override-row">
			<td>
				<select name="overrides[{index}][post_type]" class="dfi-post-type" style="width: 90%;">
					<option value=""><?php esc_html_e( '-- Select Post Type --', 'default-featured-images' ); ?></option>
					<?php foreach ( $post_types as $pt_name => $pt_object ) : ?>
						<option value="<?php echo esc_attr( $pt_name ); ?>">
							<?php echo esc_html( $pt_object->label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="overrides[{index}][taxonomy]" class="dfi-taxonomy" style="width: 90%;">
					<option value=""><?php esc_html_e( '-- Select Taxonomy --', 'default-featured-images' ); ?></option>
				</select>
			</td>
			<td>
				<select name="overrides[{index}][term_id]" class="dfi-term" style="width: 90%;">
					<option value=""><?php esc_html_e( '-- Select Term --', 'default-featured-images' ); ?></option>
				</select>
			</td>
			<td>
				<div class="dfi-media-picker">
					<input type="hidden" name="overrides[{index}][image_id]" class="dfi-image-id" value="">
					<img src="" class="dfi-image-preview" style="max-width: 80px; max-height: 80px; display: none; border: 1px solid #ccc; margin-bottom: 5px; border-radius: 4px;">
					<button type="button" class="button button-small dfi-upload-button"><?php esc_html_e( 'Select Image', 'default-featured-images' ); ?></button>
					<button type="button" class="button button-small dfi-remove-button" style="display: none; color: #b32d2e; border-color: #b32d2e;"><?php esc_html_e( 'Remove', 'default-featured-images' ); ?></button>
				</div>
			</td>
			<td>
				<button type="button" class="button button-small dfi-remove-row" style="color: #b32d2e; border-color: #b32d2e;"><?php esc_html_e( 'Delete Row', 'default-featured-images' ); ?></button>
			</td>
		</tr>
	</script>

	<!-- Javascript Handler -->
	<script type="text/javascript">
		jQuery(document).ready(function($){
			var dfiMap = <?php echo json_encode( $cpt_taxonomy_term_map ); ?>;

			// Helper to populate taxonomy dropdown
			function updateTaxonomies(row) {
				var postType = row.find('.dfi-post-type').val();
				var taxSelect = row.find('.dfi-taxonomy');
				var termSelect = row.find('.dfi-term');

				taxSelect.empty().append('<option value=""><?php esc_js( esc_html_e( '-- Select Taxonomy --', 'default-featured-images' ) ); ?></option>');
				termSelect.empty().append('<option value=""><?php esc_js( esc_html_e( '-- Select Term --', 'default-featured-images' ) ); ?></option>');

				if (postType && dfiMap[postType] && dfiMap[postType].taxonomies) {
					var taxs = dfiMap[postType].taxonomies;
					for (var taxName in taxs) {
						taxSelect.append($('<option>', {
							value: taxName,
							text: taxs[taxName].label + ' (' + taxName + ')'
						}));
					}
				}
			}

			// Helper to populate term dropdown
			function updateTerms(row) {
				var postType = row.find('.dfi-post-type').val();
				var taxName = row.find('.dfi-taxonomy').val();
				var termSelect = row.find('.dfi-term');

				termSelect.empty().append('<option value=""><?php esc_js( esc_html_e( '-- Select Term --', 'default-featured-images' ) ); ?></option>');

				if (postType && taxName && dfiMap[postType] && dfiMap[postType].taxonomies[taxName]) {
					var terms = dfiMap[postType].taxonomies[taxName].terms;
					for (var i = 0; i < terms.length; i++) {
						termSelect.append($('<option>', {
							value: terms[i].id,
							text: terms[i].name
						}));
					}
				}
			}

			// Post Type select change event
			$(document).on('change', '.dfi-post-type', function() {
				var row = $(this).closest('tr');
				updateTaxonomies(row);
			});

			// Taxonomy select change event
			$(document).on('change', '.dfi-taxonomy', function() {
				var row = $(this).closest('tr');
				updateTerms(row);
			});

			// Media selector uploader popup
			$(document).on('click', '.dfi-upload-button', function(e) {
				e.preventDefault();
				var button = $(this);
				var idInput = button.siblings('.dfi-image-id');
				var preview = button.siblings('.dfi-image-preview');
				var removeBtn = button.siblings('.dfi-remove-button');

				var uploader = wp.media({
					title: '<?php esc_attr_e( 'Select Fallback Featured Image', 'default-featured-images' ); ?>',
					button: {
						text: '<?php esc_attr_e( 'Use this image', 'default-featured-images' ); ?>'
					},
					multiple: false
				})
				.on('select', function() {
					var attachment = uploader.state().get('selection').first().toJSON();
					idInput.val(attachment.id);
					preview.attr('src', attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url).show();
					removeBtn.show();
				})
				.open();
			});

			// Media clear removal
			$(document).on('click', '.dfi-remove-button', function(e) {
				e.preventDefault();
				var button = $(this);
				button.siblings('.dfi-image-id').val('');
				button.siblings('.dfi-image-preview').attr('src', '').hide();
				button.hide();
			});

			// Add dynamic row
			$('#dfi-add-override').click(function(e) {
				e.preventDefault();
				var index = $('#dfi-overrides-table tbody .dfi-override-row').length;
				var template = $('#dfi-row-template').html();
				template = template.replace(/\{index\}/g, index);
				$('#dfi-overrides-table tbody').append(template);
			});

			// Remove dynamic row
			$(document).on('click', '.dfi-remove-row', function(e) {
				e.preventDefault();
				$(this).closest('tr').remove();
			});
		});
	</script>
	<?php
}
