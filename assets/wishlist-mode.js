jQuery(function ($) {
	/**
	 * Add wishlist hearts to product cards that did not receive
	 * one through the normal WooCommerce PHP hook.
	 */
	function addWishlistHearts() {
		$('.product, .ht-product, li.product').each(function () {
			const card = $(this);

			// Do not add a duplicate heart.
			if (card.find('.wishlist-heart-wrapper').length) {
				return;
			}

			let productId = 0;

			/*
			 * Most WooCommerce Add to Cart buttons contain
			 * data-product_id.
			 */
			const addToCartButton = card.find(
				'[data-product_id]'
			).first();

			if (addToCartButton.length) {
				productId = parseInt(
					addToCartButton.attr('data-product_id'),
					10
				);
			}

			/*
			 * Fallback: WooCommerce often adds a post ID class,
			 * such as post-7368.
			 */
			if (!productId) {
				const classList = card.attr('class') || '';
				const match = classList.match(/post-(\d+)/);

				if (match) {
					productId = parseInt(match[1], 10);
				}
			}

			if (!productId) {
				return;
			}

			const heart = `
				<div class="wishlist-heart-wrapper">
					<button
						type="button"
						class="wishlist-heart-btn"
						data-product-id="${productId}"
						aria-pressed="false"
						aria-label="Add to wishlist"
					>
						<span class="wishlist-heart-icon wishlist-heart-empty">
							<i class="fa-regular fa-heart" aria-hidden="true"></i>
						</span>

						<span class="wishlist-heart-icon wishlist-heart-filled">
							<i class="fa-solid fa-heart" aria-hidden="true"></i>
						</span>

						<span class="wishlist-heart-label">
							Favorites
						</span>
					</button>
				</div>
			`;

			card.prepend(heart);
            const savedProductIds = (
                wishlistData.wishlist || []
            ).map(Number);

            if (savedProductIds.includes(productId)) {
                card
                    .find(
                        '.wishlist-heart-btn[data-product-id="' +
                            productId +
                        '"]'
                    )
                    .addClass('favorited')
                    .attr('aria-pressed', 'true')
                    .attr('aria-label', 'Remove from wishlist');
            }
		});
	}

	/*
	 * Run when the page initially loads.
	 */
	addWishlistHearts();

	/*
	 * Run again when Elementor, filtering, pagination, or AJAX
	 * dynamically inserts new product cards.
	 */
	const observer = new MutationObserver(function () {
		addWishlistHearts();
	});

	observer.observe(document.body, {
		childList: true,
		subtree: true
	});

	/*
	 * Existing wishlist toggle code.
	 */
	$(document).on('click', '.wishlist-heart-btn', function (event) {
		event.preventDefault();
		event.stopImmediatePropagation();
		
		if (!wishlistData.isLoggedIn) {
    		window.location.href = wishlistData.wishlistUrl;
    		return;
    	}

		const button = $(this);
		const productId = parseInt(
			button.data('product-id'),
			10
		);

		if (!productId || button.hasClass('loading')) {
			return;
		}

		button.addClass('loading');

		$.ajax({
			url: wishlistData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'wishlist_toggle',
				nonce: wishlistData.nonce,
				product_id: productId
			}
		})
			.done(function (response) {
				if (!response.success) {
					alert(
						response.data ||
						'Something went wrong.'
					);

					return;
				}

				const isFavorited =
					response.data.is_favorited;

				const count =
					parseInt(response.data.count, 10) || 0;

				const matchingButtons = $(
					'.wishlist-heart-btn[data-product-id="' +
						productId +
					'"]'
				);

				matchingButtons
					.toggleClass(
						'favorited',
						isFavorited
					)
					.attr(
						'aria-pressed',
						isFavorited
							? 'true'
							: 'false'
					)
					.attr(
						'aria-label',
						isFavorited
							? 'Remove from wishlist'
							: 'Add to wishlist'
					);

				$('.wishlist-count')
					.text(count)
					.toggleClass(
						'is-empty',
						count === 0
					);

				if (
					!isFavorited &&
					button.hasClass(
						'wishlist-remove-button'
					)
				) {
					button
						.closest('.wishlist-product')
						.fadeOut(200, function () {
							$(this).remove();

							if (
								$('.wishlist-product')
									.length === 0
							) {
								window.location.reload();
							}
						});
				}
			})
			.fail(function () {
				alert(
					'The wishlist could not be updated.'
				);
			})
			.always(function () {
				button.removeClass('loading');
			});
	});

	// Share functionality
	$(document).on('change', '#wishlist_public_toggle', function() {
		const isPublic = $(this).is(':checked') ? 'yes' : 'no';
		const userId = $(this).data('user-id');
		
		$.ajax({
			url: wishlistData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'wishlist_toggle_public',
				nonce: wishlistData.nonce,
				is_public: isPublic
			}
		})
		.done(function(response) {
			if (response.success) {
				$('.wishlist-share-url-wrapper').toggle(isPublic === 'yes');
				$('.wishlist-share-status').text(
					isPublic === 'yes' ? 'Public' : 'Private'
				);
				
				if (isPublic === 'yes') {
					$('#wishlist_share_url').val(response.data.share_url);
				}
			}
		})
		.fail(function() {
			alert('Could not update wishlist privacy settings.');
		});
	});

	// Copy URL functionality
	$(document).on('click', '.wishlist-copy-url', function() {
		const input = $('#wishlist_share_url');
		input.select();
		
		try {
			document.execCommand('copy');
			alert('Link copied to clipboard!');
		} catch (err) {
			alert('Please copy the URL manually.');
		}
	});

	// Share wishlist function with Pinterest image support
	window.wishlistShareWishlist = function(shareUrl) {
		// Get the first product image from the wishlist for Pinterest
		var firstImage = '';
		var productImages = $('.wishlist-product-image img, .wishlist-public-container .wishlist-product-image img');
		
		if (productImages.length > 0) {
			// Get the actual image URL
			var imgSrc = productImages.first().attr('src');
			if (imgSrc) {
				// Make sure it's a full URL
				if (imgSrc.startsWith('//')) {
					imgSrc = 'https:' + imgSrc;
				} else if (imgSrc.startsWith('/')) {
					imgSrc = window.location.origin + imgSrc;
				}
				firstImage = encodeURIComponent(imgSrc);
			}
		}
		
		// If no product image found, try to get from product cards on the page
		if (!firstImage) {
			var productCardImages = $('.product img, .ht-product img, li.product img').first();
			if (productCardImages.length > 0) {
				var imgSrc = productCardImages.attr('src');
				if (imgSrc) {
					if (imgSrc.startsWith('//')) {
						imgSrc = 'https:' + imgSrc;
					} else if (imgSrc.startsWith('/')) {
						imgSrc = window.location.origin + imgSrc;
					}
					firstImage = encodeURIComponent(imgSrc);
				}
			}
		}
		
		// Fallback to site icon or logo
		if (!firstImage) {
			var siteIcon = $('link[rel="icon"]').attr('href') || 
						  $('link[rel="apple-touch-icon"]').attr('href') ||
						  $('link[rel="shortcut icon"]').attr('href') ||
						  '';
			if (siteIcon) {
				if (siteIcon.startsWith('//')) {
					siteIcon = 'https:' + siteIcon;
				} else if (siteIcon.startsWith('/')) {
					siteIcon = window.location.origin + siteIcon;
				}
				firstImage = encodeURIComponent(siteIcon);
			}
		}
		
		// Ultimate fallback - use a default icon
		if (!firstImage) {
			const svg = `
			<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 300 300">
				<rect width="100%" height="100%" fill="#ffffff"/>
				<path fill="#ff1f1f"
					d="M150 255
					L45 150
					C15 120 15 75 45 45
					C75 15 120 15 150 45
					C180 15 225 15 255 45
					C285 75 285 120 255 150
					Z"/>
			</svg>`;

			firstImage = encodeURIComponent(
				'data:image/svg+xml;charset=utf-8,' + svg
			);
		}
		
		// Use native share API if available (mobile)
		if (navigator.share) {
			navigator.share({
				title: 'My Wishlist',
				text: 'Check out my wishlist!',
				url: shareUrl
			}).catch(function(error) {
				if (error.name !== 'AbortError') {
					console.log('Share cancelled:', error);
				}
			});
			return;
		}
		
		// Desktop: Show popup near the share button
		var existingPopup = $('.wishlist-share-popup');
		if (existingPopup.length) {
			existingPopup.remove();
			return;
		}
		
		var sharePopup = $('<div class="wishlist-share-popup"></div>');
		sharePopup.html(`
			<div class="wishlist-share-popup-content">
				<div class="wishlist-share-popup-header">
					<span>Share Wishlist</span>
					<button class="wishlist-share-popup-close">&times;</button>
				</div>
				<div class="wishlist-share-popup-body">
					<div class="wishlist-share-popup-url">
						<input type="text" value="${shareUrl}" readonly onclick="this.select();">
						<button class="button wishlist-copy-url-small">Copy</button>
					</div>
					<div class="wishlist-share-popup-social">
						<a href="mailto:?subject=${encodeURIComponent('Check out my wishlist!')}&body=${encodeURIComponent('Here\'s my wishlist: ' + shareUrl)}" target="_blank" class="wishlist-share-email" title="Email">
							<i class="fa-solid fa-envelope"></i>
						</a>
						<a href="https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareUrl)}" target="_blank" class="wishlist-share-facebook" title="Facebook">
							<i class="fa-brands fa-facebook"></i>
						</a>
						<a href="https://twitter.com/intent/tweet?text=${encodeURIComponent('Check out my wishlist!')}&url=${encodeURIComponent(shareUrl)}" target="_blank" class="wishlist-share-twitter" title="Twitter">
							<i class="fa-brands fa-x-twitter"></i>
						</a>
						<a href="https://pinterest.com/pin/create/button/?url=${encodeURIComponent(shareUrl)}&media=${firstImage}&description=${encodeURIComponent('My Wishlist - Check out my favorite products!')}" target="_blank" class="wishlist-share-pinterest" title="Pinterest">
							<i class="fa-brands fa-pinterest"></i>
						</a>
					</div>
				</div>
			</div>
		`);
		
		$('body').append(sharePopup);
		
		// Calculate position dynamically based on share button
		var shareButton = $('.wishlist-sticky-share');
		var buttonOffset = shareButton.offset();
		var popupWidth = 280; // Approximate width of popup
		
		// Position popup to the left of the share button, vertically centered
		var popupTop = buttonOffset.top - (sharePopup.outerHeight() / 2) + (shareButton.outerHeight() / 2);
		var popupLeft = buttonOffset.left - popupWidth - 20;
		
		// Ensure popup stays within viewport
		if (popupLeft < 20) {
			popupLeft = 20;
		}
		
		if (popupTop < 20) {
			popupTop = 20;
		}
		
		if (popupTop + sharePopup.outerHeight() > $(window).height() - 20) {
			popupTop = $(window).height() - sharePopup.outerHeight() - 20;
		}
		
		sharePopup.css({
			display: 'block',
			top: popupTop + 'px',
			left: popupLeft + 'px'
		});
		
		// Close handlers
		sharePopup.find('.wishlist-share-popup-close').on('click', function() {
			sharePopup.remove();
		});
		
		$(document).on('click', function(e) {
			if (!$(e.target).closest('.wishlist-share-popup').length && 
				!$(e.target).closest('.wishlist-sticky-share').length) {
				sharePopup.remove();
			}
		});
		
		// Copy URL functionality
		sharePopup.find('.wishlist-copy-url-small').on('click', function() {
			var input = sharePopup.find('input');
			input.select();
			
			try {
				document.execCommand('copy');
				$(this).text('Copied!');
				setTimeout(function() {
					$(this).text('Copy');
				}.bind(this), 2000);
			} catch (err) {
				alert('Please copy the URL manually.');
			}
		});
	};

	// Single product page wishlist button toggle
	$(document).on('click', '.wishlist-product-button', function(event) {
		event.preventDefault();
		
		if (!wishlistData.isLoggedIn) {
			window.location.href = wishlistData.wishlistUrl;
			return;
		}
		
		const button = $(this);
		const productId = parseInt(button.data('product-id'), 10);
		
		if (!productId || button.hasClass('loading')) {
			return;
		}
		
		button.addClass('loading');
		
		$.ajax({
			url: wishlistData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'wishlist_toggle',
				nonce: wishlistData.nonce,
				product_id: productId
			}
		})
		.done(function(response) {
			if (!response.success) {
				alert(response.data || 'Something went wrong.');
				return;
			}
			
			const isFavorited = response.data.is_favorited;
			const count = parseInt(response.data.count, 10) || 0;
			
			// Update button text
			button.text(isFavorited ? 'Remove from Wishlist' : 'Add to Wishlist');
			button.toggleClass('favorited', isFavorited);
			
			// Update heart icon on product card if it exists
			const matchingButtons = $('.wishlist-heart-btn[data-product-id="' + productId + '"]');
			matchingButtons
				.toggleClass('favorited', isFavorited)
				.attr('aria-pressed', isFavorited ? 'true' : 'false')
				.attr('aria-label', isFavorited ? 'Remove from wishlist' : 'Add to wishlist');
			
			// Update wishlist count
			$('.wishlist-count')
				.text(count)
				.toggleClass('is-empty', count === 0);
		})
		.fail(function() {
			alert('The wishlist could not be updated.');
		})
		.always(function() {
			button.removeClass('loading');
		});
	});
});