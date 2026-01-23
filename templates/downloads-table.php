<?php
/**
 * Downloads Table Template
 *
 * Enhanced WooCommerce account downloads page with Freemius-like interface.
 * Overrides: myaccount/downloads.php
 *
 * @package WP_Update_Server_Plugin
 */

defined('ABSPATH') || exit;

$downloads = \WP_Update_Server_Plugin\Downloads_Page::get_downloads_data();
?>

<div class="wu-downloads-page">
	<div class="wu-downloads-instructions">
		<p>
			<?php esc_html_e('For the easiest installation, use the Ultimate Multisite Addons panel in your WordPress Network Admin. These downloads are for manual installation or Composer.', 'wp-update-server-plugin'); ?>
		</p>
	</div>

	<?php if (empty($downloads)) : ?>
		<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
			<?php esc_html_e('No downloads available yet.', 'wp-update-server-plugin'); ?>
		</div>
	<?php else : ?>
		<table class="wu-downloads-table woocommerce-orders-table woocommerce-MyAccount-downloads shop_table shop_table_responsive">
			<thead>
				<tr>
					<th class="wu-downloads-table__header wu-downloads-table__header--product">
						<?php esc_html_e('Product', 'wp-update-server-plugin'); ?>
					</th>
					<th class="wu-downloads-table__header wu-downloads-table__header--download">
						<?php esc_html_e('Download', 'wp-update-server-plugin'); ?>
					</th>
					<th class="wu-downloads-table__header wu-downloads-table__header--type">
						<?php esc_html_e('Type', 'wp-update-server-plugin'); ?>
					</th>
					<th class="wu-downloads-table__header wu-downloads-table__header--requires">
						<?php esc_html_e('Required', 'wp-update-server-plugin'); ?>
					</th>
					<th class="wu-downloads-table__header wu-downloads-table__header--tested">
						<?php esc_html_e('Tested Up To', 'wp-update-server-plugin'); ?>
					</th>
					<th class="wu-downloads-table__header wu-downloads-table__header--composer">
						<?php esc_html_e('Composer', 'wp-update-server-plugin'); ?>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($downloads as $product) : ?>
					<tr class="wu-downloads-table__row" data-product-sku="<?php echo esc_attr($product['sku']); ?>">
						<td class="wu-downloads-table__cell wu-downloads-table__cell--product" data-title="<?php esc_attr_e('Product', 'wp-update-server-plugin'); ?>">
							<div class="wu-product-info">
								<?php if ( ! empty($product['icon'])) : ?>
									<img src="<?php echo esc_url($product['icon']); ?>" alt="" class="wu-product-icon" />
								<?php else : ?>
									<div class="wu-product-icon wu-product-icon--placeholder"></div>
								<?php endif; ?>
								<span class="wu-product-name"><?php echo esc_html($product['name']); ?></span>
							</div>
						</td>
						<td class="wu-downloads-table__cell wu-downloads-table__cell--download" data-title="<?php esc_attr_e('Download', 'wp-update-server-plugin'); ?>">
							<?php if ( ! empty($product['versions'])) : ?>
								<div class="wu-download-controls">
									<select class="wu-version-select" data-product-id="<?php echo esc_attr($product['product_id']); ?>">
										<?php foreach ($product['versions'] as $index => $version) : ?>
											<option
												value="<?php echo esc_attr($version['download_url']); ?>"
												<?php selected($index, 0); ?>
											>
												<?php
												echo esc_html($version['version']);
												if ($index === 0) {
													echo ' (' . esc_html__('Latest', 'wp-update-server-plugin') . ')';
												}
												?>
											</option>
										<?php endforeach; ?>
									</select>
									<a href="<?php echo esc_url($product['versions'][0]['download_url']); ?>" class="wu-download-button button">
										<?php esc_html_e('Download', 'wp-update-server-plugin'); ?>
									</a>
								</div>
							<?php else : ?>
								<span class="wu-no-downloads"><?php esc_html_e('No files available', 'wp-update-server-plugin'); ?></span>
							<?php endif; ?>
						</td>
						<td class="wu-downloads-table__cell wu-downloads-table__cell--type" data-title="<?php esc_attr_e('Type', 'wp-update-server-plugin'); ?>">
							<span class="wu-product-type wu-product-type--<?php echo esc_attr($product['type']); ?>">
								<?php echo esc_html(ucfirst($product['type'])); ?>
							</span>
						</td>
						<td class="wu-downloads-table__cell wu-downloads-table__cell--requires" data-title="<?php esc_attr_e('Required', 'wp-update-server-plugin'); ?>">
							<?php if ( ! empty($product['requires'])) : ?>
								<span class="wu-version-badge">WP <?php echo esc_html($product['requires']); ?>+</span>
							<?php else : ?>
								<span class="wu-version-badge wu-version-badge--unknown">-</span>
							<?php endif; ?>
						</td>
						<td class="wu-downloads-table__cell wu-downloads-table__cell--tested" data-title="<?php esc_attr_e('Tested Up To', 'wp-update-server-plugin'); ?>">
							<?php if ( ! empty($product['tested_up_to'])) : ?>
								<span class="wu-version-badge">WP <?php echo esc_html($product['tested_up_to']); ?></span>
							<?php else : ?>
								<span class="wu-version-badge wu-version-badge--unknown">-</span>
							<?php endif; ?>
						</td>
						<td class="wu-downloads-table__cell wu-downloads-table__cell--composer" data-title="<?php esc_attr_e('Composer', 'wp-update-server-plugin'); ?>">
							<button type="button" class="wu-composer-button button" data-product-sku="<?php echo esc_attr($product['sku']); ?>">
								<span class="dashicons dashicons-editor-code"></span>
								<?php esc_html_e('Install', 'wp-update-server-plugin'); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<?php
// Include the composer modal template
include dirname(__FILE__) . '/composer-modal.php';
?>
