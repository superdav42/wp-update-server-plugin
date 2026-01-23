<?php
/**
 * New Release Email
 *
 * Email notification sent to customers when a new downloadable file is added to a product they purchased.
 *
 * @package WP_Update_Server_Plugin
 */

namespace WP_Update_Server_Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

/**
 * New Release Email Class.
 *
 * @extends \WC_Email
 */
class New_Release_Email extends \WC_Email {

	/**
	 * The product object.
	 *
	 * @var \WC_Product|null
	 */
	public $product = null;

	/**
	 * The version string.
	 *
	 * @var string
	 */
	public $version = '';

	/**
	 * The changelog text.
	 *
	 * @var string
	 */
	public $changelog = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'wu_new_release';
		$this->customer_email = true;
		$this->title          = __( 'New Product Release', 'wp-update-server-plugin' );
		$this->description    = __( 'New release emails are sent to customers when a new downloadable file is added to a product they have purchased.', 'wp-update-server-plugin' );
		$this->template_html  = 'emails/new-release.php';
		$this->template_plain = 'emails/plain/new-release.php';
		$this->placeholders   = [
			'{product_name}' => '',
			'{version}'      => '',
			'{site_title}'   => '',
		];

		// Call parent constructor.
		parent::__construct();

		// Set default template path to our plugin.
		$this->template_base = WP_UPDATE_SERVER_PLUGIN_PATH . 'templates/';
	}

	/**
	 * Get email subject.
	 *
	 * @return string
	 */
	public function get_default_subject(): string {
		return __( '[{site_title}] New release: {product_name} {version}', 'wp-update-server-plugin' );
	}

	/**
	 * Get email heading.
	 *
	 * @return string
	 */
	public function get_default_heading(): string {
		return __( '{product_name} {version} is now available!', 'wp-update-server-plugin' );
	}

	/**
	 * Trigger the email.
	 *
	 * @param int         $user_id    The user ID to send to.
	 * @param \WC_Product $product    The product object.
	 * @param string      $version    The version string.
	 * @param string      $changelog  The changelog text.
	 * @return bool Whether the email was sent.
	 */
	public function trigger( int $user_id, \WC_Product $product, string $version, string $changelog = '' ): bool {
		$this->setup_locale();

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			$this->restore_locale();
			return false;
		}

		$this->recipient = $user->user_email;
		$this->product   = $product;
		$this->version   = $version;
		$this->changelog = $changelog;
		$this->object    = $product;

		// Set placeholders.
		$this->placeholders['{product_name}'] = $product->get_name();
		$this->placeholders['{version}']      = $version;
		$this->placeholders['{site_title}']   = $this->get_blogname();

		$result = false;

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$result = $this->send(
				$this->get_recipient(),
				$this->get_subject(),
				$this->get_content(),
				$this->get_headers(),
				$this->get_attachments()
			);
		}

		$this->restore_locale();

		return $result;
	}

	/**
	 * Get content HTML.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			[
				'product'            => $this->product,
				'version'            => $this->version,
				'changelog'          => $this->changelog,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			],
			'',
			$this->template_base
		);
	}

	/**
	 * Get content plain.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			[
				'product'            => $this->product,
				'version'            => $this->version,
				'changelog'          => $this->changelog,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			],
			'',
			$this->template_base
		);
	}

	/**
	 * Default content to show below main email content.
	 *
	 * @return string
	 */
	public function get_default_additional_content(): string {
		return __( 'Thank you for being a customer!', 'wp-update-server-plugin' );
	}

	/**
	 * Initialize settings form fields.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		/* translators: %s: list of placeholders */
		$placeholder_text = sprintf(
			__( 'Available placeholders: %s', 'wp-update-server-plugin' ),
			'<code>' . implode( '</code>, <code>', array_keys( $this->placeholders ) ) . '</code>'
		);

		$this->form_fields = [
			'enabled'            => [
				'title'   => __( 'Enable/Disable', 'wp-update-server-plugin' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'wp-update-server-plugin' ),
				'default' => 'yes',
			],
			'subject'            => [
				'title'       => __( 'Subject', 'wp-update-server-plugin' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			],
			'heading'            => [
				'title'       => __( 'Email heading', 'wp-update-server-plugin' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $placeholder_text,
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			],
			'additional_content' => [
				'title'       => __( 'Additional content', 'wp-update-server-plugin' ),
				'description' => __( 'Text to appear below the main email content.', 'wp-update-server-plugin' ) . ' ' . $placeholder_text,
				'css'         => 'width:400px; height: 75px;',
				'placeholder' => __( 'N/A', 'wp-update-server-plugin' ),
				'type'        => 'textarea',
				'default'     => $this->get_default_additional_content(),
				'desc_tip'    => true,
			],
			'email_type'         => [
				'title'       => __( 'Email type', 'wp-update-server-plugin' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'wp-update-server-plugin' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			],
		];
	}
}
