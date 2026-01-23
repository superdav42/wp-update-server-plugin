<?php
/**
 * Composer Modal Template
 *
 * Modal dialog for Composer installation instructions and token management.
 *
 * @package WP_Update_Server_Plugin
 */

defined('ABSPATH') || exit;

$repository_url = \WP_Update_Server_Plugin\Composer_Repository::get_repository_url();
?>

<div id="wu-composer-modal" class="wu-modal" style="display: none;">
	<div class="wu-modal-overlay"></div>
	<div class="wu-modal-container">
		<div class="wu-modal-header">
			<h2><?php esc_html_e('Install with Composer', 'wp-update-server-plugin'); ?></h2>
			<button type="button" class="wu-modal-close" aria-label="<?php esc_attr_e('Close', 'wp-update-server-plugin'); ?>">
				<span aria-hidden="true">&times;</span>
			</button>
		</div>

		<div class="wu-modal-body">
			<div class="wu-modal-tabs">
				<button type="button" class="wu-modal-tab active" data-tab="quick-start">
					<?php esc_html_e('Quick Start', 'wp-update-server-plugin'); ?>
				</button>
				<button type="button" class="wu-modal-tab" data-tab="tokens">
					<?php esc_html_e('Manage Tokens', 'wp-update-server-plugin'); ?>
				</button>
			</div>

			<div class="wu-modal-tab-content active" data-tab="quick-start">
				<div class="wu-composer-section">
					<h3><?php esc_html_e('1. Add Repository to composer.json', 'wp-update-server-plugin'); ?></h3>
					<p class="wu-section-description">
						<?php esc_html_e('Add this repository configuration to your composer.json file:', 'wp-update-server-plugin'); ?>
					</p>
					<div class="wu-code-block">
						<pre><code id="wu-composer-json-config">{
    "repositories": [
        {
            "type": "composer",
            "url": "<span class="wu-token-placeholder"><?php echo esc_url($repository_url); ?>?token=YOUR_TOKEN</span>"
        }
    ]
}</code></pre>
						<button type="button" class="wu-copy-button" data-target="wu-composer-json-config">
							<span class="dashicons dashicons-admin-page"></span>
							<?php esc_html_e('Copy', 'wp-update-server-plugin'); ?>
						</button>
					</div>
				</div>

				<div class="wu-composer-section">
					<h3><?php esc_html_e('2. Install Package', 'wp-update-server-plugin'); ?></h3>
					<p class="wu-section-description">
						<?php esc_html_e('Then run the following command:', 'wp-update-server-plugin'); ?>
					</p>
					<div class="wu-code-block">
						<pre><code id="wu-composer-require">composer require <span class="wu-package-name">ultimate-multisite/package-name</span></code></pre>
						<button type="button" class="wu-copy-button" data-target="wu-composer-require">
							<span class="dashicons dashicons-admin-page"></span>
							<?php esc_html_e('Copy', 'wp-update-server-plugin'); ?>
						</button>
					</div>
				</div>

				<div class="wu-composer-section wu-composer-section--alternative">
					<h3><?php esc_html_e('Alternative: Use auth.json (Recommended for Teams)', 'wp-update-server-plugin'); ?></h3>
					<p class="wu-section-description">
						<?php esc_html_e('For better security, store your token in auth.json instead of composer.json:', 'wp-update-server-plugin'); ?>
					</p>
					<div class="wu-code-block">
						<pre><code id="wu-auth-json-config">{
    "http-basic": {
        "<?php echo esc_html(wp_parse_url(home_url(), PHP_URL_HOST)); ?>": {
            "username": "token",
            "password": "<span class="wu-token-placeholder">YOUR_TOKEN</span>"
        }
    }
}</code></pre>
						<button type="button" class="wu-copy-button" data-target="wu-auth-json-config">
							<span class="dashicons dashicons-admin-page"></span>
							<?php esc_html_e('Copy', 'wp-update-server-plugin'); ?>
						</button>
					</div>
					<p class="wu-section-note">
						<?php esc_html_e('When using auth.json, use this repository URL without the token parameter:', 'wp-update-server-plugin'); ?>
					</p>
					<div class="wu-code-block wu-code-block--inline">
						<code id="wu-repo-url-plain"><?php echo esc_url($repository_url); ?></code>
						<button type="button" class="wu-copy-button wu-copy-button--small" data-target="wu-repo-url-plain">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
					</div>
				</div>

				<div class="wu-composer-section wu-composer-section--token">
					<h3><?php esc_html_e('Your Token', 'wp-update-server-plugin'); ?></h3>
					<div class="wu-token-display">
						<div class="wu-token-status wu-token-status--loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e('Loading...', 'wp-update-server-plugin'); ?>
						</div>
						<div class="wu-token-status wu-token-status--no-token" style="display: none;">
							<p><?php esc_html_e('You don\'t have any tokens yet. Generate one to use Composer.', 'wp-update-server-plugin'); ?></p>
							<button type="button" class="wu-generate-token-button button button-primary">
								<?php esc_html_e('Generate Token', 'wp-update-server-plugin'); ?>
							</button>
						</div>
						<div class="wu-token-status wu-token-status--has-token" style="display: none;">
							<div class="wu-current-token">
								<label><?php esc_html_e('Active Token:', 'wp-update-server-plugin'); ?></label>
								<div class="wu-code-block wu-code-block--inline">
									<code class="wu-token-value"></code>
									<button type="button" class="wu-copy-button wu-copy-button--small" data-copy-value>
										<span class="dashicons dashicons-admin-page"></span>
									</button>
								</div>
							</div>
							<p class="wu-token-note">
								<?php esc_html_e('Manage your tokens in the "Manage Tokens" tab.', 'wp-update-server-plugin'); ?>
							</p>
						</div>
						<div class="wu-token-status wu-token-status--new-token" style="display: none;">
							<div class="wu-alert wu-alert--success">
								<strong><?php esc_html_e('Token generated!', 'wp-update-server-plugin'); ?></strong>
								<?php esc_html_e('Copy it now - this is the only time you\'ll see the full token.', 'wp-update-server-plugin'); ?>
							</div>
							<div class="wu-code-block wu-code-block--highlight">
								<code class="wu-new-token-value"></code>
								<button type="button" class="wu-copy-button" data-copy-value>
									<span class="dashicons dashicons-admin-page"></span>
									<?php esc_html_e('Copy', 'wp-update-server-plugin'); ?>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="wu-modal-tab-content" data-tab="tokens">
				<div class="wu-tokens-section">
					<div class="wu-tokens-header">
						<h3><?php esc_html_e('Your Composer Tokens', 'wp-update-server-plugin'); ?></h3>
						<button type="button" class="wu-generate-token-button button button-primary">
							<span class="dashicons dashicons-plus-alt2"></span>
							<?php esc_html_e('New Token', 'wp-update-server-plugin'); ?>
						</button>
					</div>

					<div class="wu-new-token-form" style="display: none;">
						<label for="wu-new-token-name"><?php esc_html_e('Token Name (optional):', 'wp-update-server-plugin'); ?></label>
						<div class="wu-token-form-row">
							<input type="text" id="wu-new-token-name" placeholder="<?php esc_attr_e('e.g., My Laptop, CI Server', 'wp-update-server-plugin'); ?>" />
							<button type="button" class="wu-create-token-button button button-primary">
								<?php esc_html_e('Create', 'wp-update-server-plugin'); ?>
							</button>
							<button type="button" class="wu-cancel-token-button button">
								<?php esc_html_e('Cancel', 'wp-update-server-plugin'); ?>
							</button>
						</div>
					</div>

					<div class="wu-tokens-list-wrapper">
						<div class="wu-tokens-loading">
							<span class="spinner is-active"></span>
							<?php esc_html_e('Loading tokens...', 'wp-update-server-plugin'); ?>
						</div>
						<table class="wu-tokens-table" style="display: none;">
							<thead>
								<tr>
									<th><?php esc_html_e('Name', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Token', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Created', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Last Used', 'wp-update-server-plugin'); ?></th>
									<th><?php esc_html_e('Actions', 'wp-update-server-plugin'); ?></th>
								</tr>
							</thead>
							<tbody class="wu-tokens-list">
							</tbody>
						</table>
						<div class="wu-no-tokens" style="display: none;">
							<p><?php esc_html_e('You don\'t have any tokens yet.', 'wp-update-server-plugin'); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="wu-modal-footer">
			<a href="https://getcomposer.org/doc/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e('Composer Documentation', 'wp-update-server-plugin'); ?>
				<span class="dashicons dashicons-external"></span>
			</a>
		</div>
	</div>
</div>
