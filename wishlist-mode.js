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
});