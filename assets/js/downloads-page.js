/**
 * Downloads Page JavaScript
 *
 * Handles version dropdown, composer modal, and token management.
 */

(function($) {
	'use strict';

	var WUDownloads = {
		/**
		 * Current package name for the modal.
		 */
		currentPackage: '',

		/**
		 * Cached tokens data.
		 */
		tokens: [],

		/**
		 * New token value (shown once).
		 */
		newToken: null,

		/**
		 * Initialize the downloads page functionality.
		 */
		init: function() {
			this.bindEvents();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function() {
			var self = this;

			// Version dropdown change
			$(document).on('change', '.wu-version-select', function() {
				self.handleVersionChange($(this));
			});

			// Composer button click
			$(document).on('click', '.wu-composer-button', function() {
				self.openComposerModal($(this).data('product-sku'));
			});

			// Modal close
			$(document).on('click', '.wu-modal-close, .wu-modal-overlay', function() {
				self.closeComposerModal();
			});

			// Tab switching
			$(document).on('click', '.wu-modal-tab', function() {
				self.switchTab($(this).data('tab'));
			});

			// Copy button
			$(document).on('click', '.wu-copy-button', function(e) {
				e.preventDefault();
				self.handleCopy($(this));
			});

			// Generate token button (both in quick-start and tokens tab)
			$(document).on('click', '.wu-generate-token-button', function() {
				self.showNewTokenForm();
			});

			// Create token button
			$(document).on('click', '.wu-create-token-button', function() {
				self.generateToken();
			});

			// Cancel token button
			$(document).on('click', '.wu-cancel-token-button', function() {
				self.hideNewTokenForm();
			});

			// Revoke token button
			$(document).on('click', '.wu-revoke-token-button', function() {
				self.revokeToken($(this).data('token-id'));
			});

			// Close modal on Escape key
			$(document).on('keydown', function(e) {
				if (e.key === 'Escape' && $('#wu-composer-modal').is(':visible')) {
					self.closeComposerModal();
				}
			});
		},

		/**
		 * Handle version dropdown change.
		 *
		 * @param {jQuery} $select The select element.
		 */
		handleVersionChange: function($select) {
			var downloadUrl = $select.val();
			var $row = $select.closest('tr');
			var $downloadButton = $row.find('.wu-download-button');

			$downloadButton.attr('href', downloadUrl);
		},

		/**
		 * Open the composer modal.
		 *
		 * @param {string} sku The product SKU.
		 */
		openComposerModal: function(sku) {
			var self = this;

			this.currentPackage = 'ultimate-multisite/' + sku.toLowerCase().replace(/_/g, '-');
			this.newToken = null;

			// Update package name in modal
			$('.wu-package-name').text(this.currentPackage);

			// Show modal
			$('#wu-composer-modal').fadeIn(200);
			$('body').css('overflow', 'hidden');

			// Reset to quick-start tab
			this.switchTab('quick-start');

			// Load token data
			this.loadTokenData();
		},

		/**
		 * Close the composer modal.
		 */
		closeComposerModal: function() {
			$('#wu-composer-modal').fadeOut(200);
			$('body').css('overflow', '');
			this.newToken = null;
		},

		/**
		 * Switch modal tabs.
		 *
		 * @param {string} tab The tab name.
		 */
		switchTab: function(tab) {
			$('.wu-modal-tab').removeClass('active');
			$('.wu-modal-tab[data-tab="' + tab + '"]').addClass('active');

			$('.wu-modal-tab-content').removeClass('active');
			$('.wu-modal-tab-content[data-tab="' + tab + '"]').addClass('active');

			// Load tokens if switching to tokens tab
			if (tab === 'tokens') {
				this.renderTokensList();
			}
		},

		/**
		 * Load token data from server.
		 */
		loadTokenData: function() {
			var self = this;

			// Show loading state
			$('.wu-token-status').hide();
			$('.wu-token-status--loading').show();
			$('.wu-tokens-loading').show();
			$('.wu-tokens-table, .wu-no-tokens').hide();

			$.ajax({
				url: wuDownloads.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wu_get_composer_data',
					nonce: wuDownloads.nonce
				},
				success: function(response) {
					if (response.success) {
						self.tokens = response.data.tokens || [];
						self.updateTokenDisplay();
						self.renderTokensList();
					} else {
						self.showError(response.data.message || wuDownloads.i18n.error);
					}
				},
				error: function() {
					self.showError(wuDownloads.i18n.error);
				}
			});
		},

		/**
		 * Update the token display in quick-start tab.
		 */
		updateTokenDisplay: function() {
			$('.wu-token-status').hide();

			if (this.newToken) {
				// Show newly generated token
				$('.wu-token-status--new-token').show();
				$('.wu-new-token-value').text(this.newToken);
				this.updateCodeBlocksWithToken(this.newToken);
			} else if (this.tokens.length > 0) {
				// Show existing token prefix
				$('.wu-token-status--has-token').show();
				$('.wu-token-value').text(this.tokens[0].token_prefix + '...');
				this.updateCodeBlocksWithToken(this.tokens[0].token_prefix + '...');
			} else {
				// No tokens
				$('.wu-token-status--no-token').show();
				this.updateCodeBlocksWithToken('YOUR_TOKEN');
			}
		},

		/**
		 * Update code blocks with the token value.
		 *
		 * @param {string} token The token value.
		 */
		updateCodeBlocksWithToken: function(token) {
			$('.wu-token-placeholder').text(token === 'YOUR_TOKEN' ? 'YOUR_TOKEN' :
				wuDownloads.repositoryUrl + '?token=' + token);
		},

		/**
		 * Render the tokens list in the tokens tab.
		 */
		renderTokensList: function() {
			var self = this;
			var $list = $('.wu-tokens-list');

			$('.wu-tokens-loading').hide();

			if (this.tokens.length === 0) {
				$('.wu-tokens-table').hide();
				$('.wu-no-tokens').show();
				return;
			}

			$('.wu-no-tokens').hide();
			$('.wu-tokens-table').show();

			$list.empty();

			this.tokens.forEach(function(token) {
				var lastUsed = token.last_used_at ?
					self.formatDate(token.last_used_at) :
					wuDownloads.i18n ? 'Never' : 'Never';

				var $row = $('<tr>').html(
					'<td data-title="Name">' + self.escapeHtml(token.name) + '</td>' +
					'<td data-title="Token"><span class="wu-token-prefix">' + self.escapeHtml(token.token_prefix) + '...</span></td>' +
					'<td data-title="Created">' + self.formatDate(token.created_at) + '</td>' +
					'<td data-title="Last Used">' + lastUsed + '</td>' +
					'<td data-title="Actions">' +
					'<button type="button" class="wu-revoke-token-button button button-small" data-token-id="' + token.id + '">Revoke</button>' +
					'</td>'
				);

				$list.append($row);
			});
		},

		/**
		 * Show the new token form.
		 */
		showNewTokenForm: function() {
			$('.wu-new-token-form').slideDown(200);
			$('#wu-new-token-name').val('').focus();
		},

		/**
		 * Hide the new token form.
		 */
		hideNewTokenForm: function() {
			$('.wu-new-token-form').slideUp(200);
		},

		/**
		 * Generate a new token.
		 */
		generateToken: function() {
			var self = this;
			var name = $('#wu-new-token-name').val() || 'Default';
			var $button = $('.wu-create-token-button');

			$button.prop('disabled', true).text(wuDownloads.i18n.generating);

			$.ajax({
				url: wuDownloads.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wu_generate_token',
					nonce: wuDownloads.nonce,
					name: name
				},
				success: function(response) {
					$button.prop('disabled', false).text('Create');

					if (response.success) {
						self.newToken = response.data.token;
						self.tokens = response.data.tokens || [];
						self.hideNewTokenForm();
						self.updateTokenDisplay();
						self.renderTokensList();

						// Switch to quick-start tab to show the new token
						self.switchTab('quick-start');
					} else {
						alert(response.data.message || wuDownloads.i18n.error);
					}
				},
				error: function() {
					$button.prop('disabled', false).text('Create');
					alert(wuDownloads.i18n.error);
				}
			});
		},

		/**
		 * Revoke a token.
		 *
		 * @param {number} tokenId The token ID.
		 */
		revokeToken: function(tokenId) {
			var self = this;

			if (!confirm(wuDownloads.i18n.confirmRevoke)) {
				return;
			}

			var $button = $('.wu-revoke-token-button[data-token-id="' + tokenId + '"]');
			$button.prop('disabled', true);

			$.ajax({
				url: wuDownloads.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wu_revoke_token',
					nonce: wuDownloads.nonce,
					token_id: tokenId
				},
				success: function(response) {
					if (response.success) {
						self.tokens = response.data.tokens || [];
						self.newToken = null;
						self.updateTokenDisplay();
						self.renderTokensList();
					} else {
						alert(response.data.message || wuDownloads.i18n.error);
						$button.prop('disabled', false);
					}
				},
				error: function() {
					alert(wuDownloads.i18n.error);
					$button.prop('disabled', false);
				}
			});
		},

		/**
		 * Handle copy button click.
		 *
		 * @param {jQuery} $button The copy button.
		 */
		handleCopy: function($button) {
			var self = this;
			var textToCopy = '';

			// Check for data-copy-value attribute (for dynamic token values)
			if ($button.attr('data-copy-value') !== undefined) {
				var $codeElement = $button.siblings('code').length ?
					$button.siblings('code') :
					$button.closest('.wu-code-block').find('code');
				textToCopy = $codeElement.text();
			} else {
				// Get text from target element
				var targetId = $button.data('target');
				var $target = $('#' + targetId);

				if ($target.length) {
					textToCopy = $target.text();
				}
			}

			if (!textToCopy) {
				return;
			}

			// Try using the Clipboard API
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(textToCopy).then(function() {
					self.showCopied($button);
				}).catch(function() {
					self.fallbackCopy(textToCopy, $button);
				});
			} else {
				this.fallbackCopy(textToCopy, $button);
			}
		},

		/**
		 * Fallback copy method using textarea.
		 *
		 * @param {string} text The text to copy.
		 * @param {jQuery} $button The copy button.
		 */
		fallbackCopy: function(text, $button) {
			var self = this;
			var $temp = $('<textarea>');
			$temp.css({
				position: 'fixed',
				left: '-9999px',
				top: '0'
			});
			$temp.val(text);
			$('body').append($temp);
			$temp.select();

			try {
				document.execCommand('copy');
				this.showCopied($button);
			} catch (e) {
				alert(wuDownloads.i18n.copyFailed);
			}

			$temp.remove();
		},

		/**
		 * Show copied feedback on button.
		 *
		 * @param {jQuery} $button The copy button.
		 */
		showCopied: function($button) {
			var originalHtml = $button.html();

			$button.addClass('copied').html('<span class="dashicons dashicons-yes"></span>');

			setTimeout(function() {
				$button.removeClass('copied').html(originalHtml);
			}, 2000);
		},

		/**
		 * Show error message.
		 *
		 * @param {string} message The error message.
		 */
		showError: function(message) {
			$('.wu-token-status').hide();
			$('.wu-tokens-loading').hide();

			// Show a simple error in the token display area
			$('.wu-token-status--loading')
				.removeClass('wu-token-status--loading')
				.addClass('wu-token-status--error')
				.html('<p style="color: #dc2626;">' + this.escapeHtml(message) + '</p>')
				.show();
		},

		/**
		 * Format a date string.
		 *
		 * @param {string} dateStr The date string.
		 * @return {string} The formatted date.
		 */
		formatDate: function(dateStr) {
			if (!dateStr) {
				return '-';
			}

			var date = new Date(dateStr.replace(' ', 'T'));

			if (isNaN(date.getTime())) {
				return dateStr;
			}

			return date.toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric'
			});
		},

		/**
		 * Escape HTML entities.
		 *
		 * @param {string} str The string to escape.
		 * @return {string} The escaped string.
		 */
		escapeHtml: function(str) {
			if (!str) {
				return '';
			}
			var div = document.createElement('div');
			div.textContent = str;
			return div.innerHTML;
		}
	};

	// Initialize when document is ready
	$(document).ready(function() {
		WUDownloads.init();
	});

})(jQuery);
