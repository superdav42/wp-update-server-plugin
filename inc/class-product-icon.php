<?php

namespace WP_Update_Server_Plugin;

class Product_Icon {

	/**
	 * Initialize the product icon functionality.
	 */
	public function __construct() {
		add_action('add_meta_boxes', array($this, 'add_product_icon_meta_box'));
		add_action('save_post_product', array($this, 'save_product_icon'));
		add_action('wp_ajax_upload_product_icon', array($this, 'handle_icon_upload'));
		add_action('wp_ajax_remove_product_icon', array($this, 'handle_icon_removal'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
	}

	/**
	 * Add the Product Icon meta box to the product edit page.
	 */
	public function add_product_icon_meta_box() {
		add_meta_box(
			'product-icon',
			__('Product Icon', 'wp-update-server-plugin'),
			array($this, 'render_product_icon_meta_box'),
			'product',
			'side',
			'low'
		);
	}

	/**
	 * Render the Product Icon meta box content.
	 *
	 * @param \WP_Post $post The current post object.
	 */
	public function render_product_icon_meta_box($post) {
		wp_nonce_field('save_product_icon', 'product_icon_nonce');

		$icon_id = get_post_meta($post->ID, '_product_icon_id', true);
		$icon_url = '';
		
		if ($icon_id) {
			$icon_url = wp_get_attachment_image_url($icon_id, 'thumbnail');
		}

		?>
		<div id="product-icon-container">
			<div id="product-icon-preview" <?php echo $icon_url ? '' : 'style="display: none;"'; ?>>
				<img id="product-icon-image" src="<?php echo esc_url($icon_url); ?>" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px;" />
				<p>
					<button type="button" id="remove-product-icon" class="button" <?php echo !$icon_url ? 'style="display: none;"' : ''; ?>>
						<?php esc_html_e('Remove Icon', 'wp-update-server-plugin'); ?>
					</button>
				</p>
			</div>
			
			<div id="product-icon-upload" <?php echo $icon_url ? 'style="display: none;"' : ''; ?>>
				<p>
					<button type="button" id="upload-product-icon" class="button">
						<?php esc_html_e('Upload Product Icon', 'wp-update-server-plugin'); ?>
					</button>
				</p>
				<p class="description">
					<?php esc_html_e('Upload a square image that will be used as the product icon. Recommended size: 128x128 pixels.', 'wp-update-server-plugin'); ?>
				</p>
			</div>
		</div>

		<input type="hidden" id="product-icon-id" name="product_icon_id" value="<?php echo esc_attr($icon_id); ?>" />
		<?php
	}

	/**
	 * Save the product icon when the product is saved.
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_product_icon($post_id) {
		// Verify nonce
		if (!isset($_POST['product_icon_nonce']) || !wp_verify_nonce($_POST['product_icon_nonce'], 'save_product_icon')) {
			return;
		}

		// Check user permissions
		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		// Save the icon ID
		if (isset($_POST['product_icon_id'])) {
			$icon_id = absint($_POST['product_icon_id']);
			if ($icon_id) {
				update_post_meta($post_id, '_product_icon_id', $icon_id);
			} else {
				delete_post_meta($post_id, '_product_icon_id');
			}
		}
	}

	/**
	 * Handle AJAX icon upload.
	 */
	public function handle_icon_upload() {
		check_ajax_referer('product_icon_upload', 'nonce');

		if (!current_user_can('upload_files')) {
			wp_die(__('You do not have permission to upload files.', 'wp-update-server-plugin'));
		}

		$attachment_id = media_handle_upload('product_icon', 0);

		if (is_wp_error($attachment_id)) {
			wp_send_json_error($attachment_id->get_error_message());
		}

		$image_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');

		wp_send_json_success(array(
			'attachment_id' => $attachment_id,
			'image_url' => $image_url,
		));
	}

	/**
	 * Handle AJAX icon removal.
	 */
	public function handle_icon_removal() {
		check_ajax_referer('product_icon_remove', 'nonce');

		wp_send_json_success();
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts($hook) {
		global $post;

		// Only load on product edit pages
		if ($hook !== 'post.php' && $hook !== 'post-new.php') {
			return;
		}

		if (!$post || $post->post_type !== 'product') {
			return;
		}

		wp_enqueue_media();

		$script = "
		jQuery(document).ready(function($) {
			var mediaUploader;
			
			$('#upload-product-icon').click(function(e) {
				e.preventDefault();
				
				if (mediaUploader) {
					mediaUploader.open();
					return;
				}
				
				mediaUploader = wp.media({
					title: '" . esc_js(__('Choose Product Icon', 'wp-update-server-plugin')) . "',
					button: {
						text: '" . esc_js(__('Use as Product Icon', 'wp-update-server-plugin')) . "'
					},
					multiple: false,
					library: {
						type: 'image'
					}
				});
				
				mediaUploader.on('select', function() {
					var attachment = mediaUploader.state().get('selection').first().toJSON();
					
					$('#product-icon-id').val(attachment.id);
					$('#product-icon-image').attr('src', attachment.url);
					$('#product-icon-preview').show();
					$('#product-icon-upload').hide();
				});
				
				mediaUploader.open();
			});
			
			$('#remove-product-icon').click(function(e) {
				e.preventDefault();
				
				$('#product-icon-id').val('');
				$('#product-icon-image').attr('src', '');
				$('#product-icon-preview').hide();
				$('#product-icon-upload').show();
			});
		});
		";

		wp_add_inline_script('jquery', $script);
	}

	/**
	 * Get the product icon URL for a given product ID.
	 *
	 * @param int $product_id The product ID.
	 * @param string $size The image size to retrieve.
	 * @return string|false The icon URL or false if not found.
	 */
	public static function get_product_icon($product_id, $size = 'thumbnail') {
		$icon_id = get_post_meta($product_id, '_product_icon_id', true);
		
		if (!$icon_id) {
			return false;
		}

		return wp_get_attachment_image_url($icon_id, $size);
	}

	/**
	 * Get the product icon ID for a given product ID.
	 *
	 * @param int $product_id The product ID.
	 * @return int|false The icon attachment ID or false if not found.
	 */
	public static function get_product_icon_id($product_id) {
		return get_post_meta($product_id, '_product_icon_id', true);
	}
}