jQuery(function ($) {
	'use strict';

	const CARD_SELECTOR = [
		'li.product',
		'.product.type-product',
		'.ht-product',
		'.woolentor-product',
		'.woolentor-grid-item',
		'.wl-grid-product',
		'.wc-block-grid__product',
		'.wc-block-product',
		'.product-item[data-product-id]',
		'.product-card[data-product-id]'
	].join(', ');

	const pendingRequests = new Map();
	const resolvedProductUrls = new Map();
	let observerTimer = null;

	function getSavedProductIds() {
		return (wishlistData.wishlist || [])
			.map(Number)
			.filter(Number.isInteger);
	}

	function getProductId(card) {
		const possibleValues = [
			card.attr('data-product-id'),
			card.attr('data-product_id'),
			card.attr('data-id'),
			card.data('product-id'),
			card.data('product_id'),
			card.data('id')
		];

		for (const value of possibleValues) {
			const productId = parseInt(value, 10);

			if (productId > 0) {
				return productId;
			}
		}

		/*
		* WooCommerce and WooLentor commonly put the product ID on:
		*
		* - Add-to-cart buttons
		* - Quick-view buttons
		* - Wishlist/action buttons
		* - Inner product wrappers
		*/
		const productIdElement = card.find([
			'[data-product_id]',
			'[data-product-id]',
			'.add_to_cart_button',
			'.ajax_add_to_cart',
			'.woolentorquickview',
			'.woolentor-quickview',
			'.ht-product-action a'
		].join(', ')).filter(function () {
			return !$(this).hasClass('wishlist-heart-btn');
		}).first();

		if (productIdElement.length) {
			const productId = parseInt(
				productIdElement.attr('data-product_id') ||
				productIdElement.attr('data-product-id') ||
				productIdElement.attr('data-id'),
				10
			);

			if (productId > 0) {
				return productId;
			}
		}

		/*
		* Try an add-to-cart URL.
		*/
		const addToCartLink = card.find('a[href*="add-to-cart="]').first();

		if (addToCartLink.length) {
			const href = addToCartLink.attr('href') || '';
			const match = href.match(/[?&]add-to-cart=(\d+)/);

			if (match) {
				return parseInt(match[1], 10);
			}
		}

		/*
		* Try common WordPress product classes.
		*/
		const classNames = card.attr('class') || '';

		const classPatterns = [
			/(?:^|\s)post-(\d+)(?:\s|$)/,
			/(?:^|\s)product-(\d+)(?:\s|$)/,
			/(?:^|\s)product_id_(\d+)(?:\s|$)/,
			/(?:^|\s)product-id-(\d+)(?:\s|$)/,
			/(?:^|\s)ht-product-(\d+)(?:\s|$)/
		];

		for (const pattern of classPatterns) {
			const match = classNames.match(pattern);

			if (match) {
				return parseInt(match[1], 10);
			}
		}

		return 0;
	}


	function getProductUrl(card) {
		const links = card.find('a[href]').filter(function () {
			const href = $(this).attr('href') || '';

			if (
				!href ||
				href.startsWith('#') ||
				href.startsWith('javascript:') ||
				href.includes('add-to-cart=') ||
				$(this).hasClass('wishlist-heart-btn')
			) {
				return false;
			}

			try {
				const url = new URL(href, window.location.href);

				return (
					url.origin === window.location.origin &&
					url.pathname !== window.location.pathname
				);
			} catch (error) {
				return false;
			}
		});

		if (!links.length) {
			return '';
		}

		try {
			const url = new URL(links.first().attr('href'), window.location.href);
			url.hash = '';
			return url.href;
		} catch (error) {
			return '';
		}
	}

	function resolveProductIdFromUrl(productUrl) {
		if (!productUrl) {
			return $.Deferred().reject().promise();
		}

		if (resolvedProductUrls.has(productUrl)) {
			return $.Deferred()
				.resolve(resolvedProductUrls.get(productUrl))
				.promise();
		}

		return $.ajax({
			url: wishlistData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'wishlist_resolve_product_url',
				nonce: wishlistData.nonce,
				url: productUrl
			}
		}).then(function (response) {
			if (!response.success || !response.data.product_id) {
				return $.Deferred().reject().promise();
			}

			const productId = parseInt(response.data.product_id, 10);

			if (!productId) {
				return $.Deferred().reject().promise();
			}

			resolvedProductUrls.set(productUrl, productId);
			return productId;
		});
	}

	function createHeart(productId, isFavorited) {
		return $(`
			<div class="wishlist-heart-wrapper" data-wishlist-generated="true">
				<button
					type="button"
					class="wishlist-heart-btn ${isFavorited ? 'favorited' : ''}"
					data-product-id="${productId}"
					data-favorited="${isFavorited ? 'true' : 'false'}"
					aria-pressed="${isFavorited ? 'true' : 'false'}"
					aria-label="${isFavorited ? 'Remove from wishlist' : 'Add to wishlist'}"
				>
					<span class="wishlist-heart-icon wishlist-heart-empty">
						<i class="fa-regular fa-heart" aria-hidden="true"></i>
					</span>
					<span class="wishlist-heart-icon wishlist-heart-filled">
						<i class="fa-solid fa-heart" aria-hidden="true"></i>
					</span>
					<span class="wishlist-heart-label">Favorites</span>
				</button>
			</div>
		`);
	}

	function renderHeartOnCard(card, productId) {
		if (!productId || card.find('.wishlist-heart-wrapper').length) {
			return;
		}

		const isFavorited = getSavedProductIds().includes(productId);
		const heart = createHeart(productId, isFavorited);

		card.addClass('wishlist-card-has-heart');

		const imageArea = card.find([
			'.ht-product-image-wrap',
			'.ht-product-image',
			'.ht-product-img',
			'.woolentor-product-thumb',
			'.woolentor-product-image',
			'.product-image',
			'.wl-product-thumb',
			'.product-thumbnail',
			'.wc-block-grid__product-image'
		].join(', ')).first();

		if (imageArea.length) {
			imageArea.css('position', 'relative');
			imageArea.prepend(heart);
			return;
		}

		const productLink = card.find(
			'a.woocommerce-LoopProduct-link, a.woocommerce-loop-product__link'
		).first();

		if (productLink.length) {
			productLink.before(heart);
			return;
		}

		card.css('position', 'relative');
		card.prepend(heart);
	}

	function addWishlistHeartToCard(card) {
		if (
			!card.length ||
			card.find('.wishlist-heart-wrapper').length ||
			card.attr('data-wishlist-resolving') === 'true'
		) {
			return;
		}

		const productId = getProductId(card);

		if (productId) {
			renderHeartOnCard(card, productId);
			return;
		}

		const productUrl = getProductUrl(card);

		if (!productUrl) {
			return;
		}

		card.attr('data-wishlist-resolving', 'true');

		resolveProductIdFromUrl(productUrl)
			.done(function (resolvedProductId) {
				renderHeartOnCard(card, resolvedProductId);
			})
			.always(function () {
				card.removeAttr('data-wishlist-resolving');
			});
	}

	function addWishlistHearts(root) {
		const scope = root ? $(root) : $(document);
		const cards = scope.is(CARD_SELECTOR)
			? scope.add(scope.find(CARD_SELECTOR))
			: scope.find(CARD_SELECTOR);

		cards.each(function () {
			addWishlistHeartToCard($(this));
		});
	}

	function scheduleHeartScan(root) {
		window.clearTimeout(observerTimer);
		observerTimer = window.setTimeout(function () {
			addWishlistHearts(root || document);
		}, 40);
	}

	function updateWishlistData(productId, isFavorited) {
		const ids = getSavedProductIds().filter(function (id) {
			return id !== productId;
		});

		if (isFavorited) {
			ids.push(productId);
		}

		wishlistData.wishlist = ids;
	}

	function updateProductState(productId, isFavorited, count) {
		const allControls = $(
			'.wishlist-heart-btn[data-product-id="' + productId + '"], ' +
			'.js-wishlist-product-button[data-product-id="' + productId + '"]'
		);

		allControls
			.toggleClass('favorited', isFavorited)
			.attr('data-favorited', isFavorited ? 'true' : 'false')
			.attr('aria-pressed', isFavorited ? 'true' : 'false')
			.attr(
				'aria-label',
				isFavorited ? 'Remove from wishlist' : 'Add to wishlist'
			);

		$('.js-wishlist-product-button[data-product-id="' + productId + '"]')
			.text(isFavorited ? 'Remove from Wishlist' : 'Add to Wishlist');

		$('.wishlist-count')
			.text(count)
			.toggleClass('is-empty', count === 0);

		updateWishlistData(productId, isFavorited);

		if (!isFavorited) {
			$('.wishlist-remove-button[data-product-id="' + productId + '"]')
				.closest('.wishlist-product')
				.fadeOut(200, function () {
					$(this).remove();

					if ($('.wishlist-product').length === 0) {
						window.location.reload();
					}
				});
		}
	}

	function toggleWishlist(productId) {
		if (pendingRequests.has(productId)) {
			return pendingRequests.get(productId);
		}

		const controls = $(
			'.wishlist-heart-btn[data-product-id="' + productId + '"], ' +
			'.js-wishlist-product-button[data-product-id="' + productId + '"]'
		);

		controls.addClass('loading').prop('disabled', true);

		const request = $.ajax({
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
				alert(response.data || 'Something went wrong.');
				return;
			}

			if (Array.isArray(response.data.wishlist)) {
				wishlistData.wishlist = response.data.wishlist.map(Number);
			}

			updateProductState(
				productId,
				Boolean(response.data.is_favorited),
				parseInt(response.data.count, 10) || 0
			);
		})
		.fail(function (xhr) {
			const message =
				xhr.responseJSON && xhr.responseJSON.data
					? xhr.responseJSON.data
					: 'The wishlist could not be updated.';

			alert(message);
		})
		.always(function () {
			pendingRequests.delete(productId);
			controls.removeClass('loading').prop('disabled', false);
		});

		pendingRequests.set(productId, request);
		return request;
	}

	addWishlistHearts(document);

	const observer = new MutationObserver(function (mutations) {
		let shouldScan = false;

		mutations.forEach(function (mutation) {
			if (mutation.addedNodes && mutation.addedNodes.length) {
				shouldScan = true;
			}
		});

		if (shouldScan) {
			scheduleHeartScan(document);
		}
	});

	observer.observe(document.body, {
		childList: true,
		subtree: true
	});

	$(document).on(
		'found_variation wc_fragments_refreshed updated_wc_div ' +
		'woocommerce_variation_has_changed',
		function () {
			scheduleHeartScan(document);
		}
	);

	$(document).on(
		'click',
		'.wishlist-heart-btn, .js-wishlist-product-button',
		function (event) {
			event.preventDefault();
			event.stopPropagation();
			event.stopImmediatePropagation();

			if (!wishlistData.isLoggedIn) {
				window.location.assign(wishlistData.wishlistUrl);
				return;
			}

			const productId = parseInt($(this).attr('data-product-id'), 10);
			if (!productId) {
				return;
			}

			toggleWishlist(productId);
		}
	);

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

	$(document).on('click', '.wishlist-sticky-share', function (event) {
		event.preventDefault();
		event.stopPropagation();

		const shareUrl = $(this).attr('data-share-url');

		if (!shareUrl) {
			alert('A public wishlist link could not be found.');
			return;
		}

		window.wishlistShareWishlist(shareUrl);
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
		
		$(document)
			.off('click.wishlistSharePopup')
			.on('click.wishlistSharePopup', function (event) {
				if (
					!$(event.target).closest('.wishlist-share-popup').length &&
					!$(event.target).closest('.wishlist-sticky-share').length
				) {
					$('.wishlist-share-popup').remove();
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
});