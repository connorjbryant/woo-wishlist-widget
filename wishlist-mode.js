jQuery(function ($) {
	$(document).on('click', '.wishlist-heart-btn', function (event) {
		event.preventDefault();
		event.stopImmediatePropagation();

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
			.done(function (response) {
				if (!response.success) {
					alert(response.data || 'Something went wrong.');
					return;
				}

				const isFavorited = response.data.is_favorited;
				const count = parseInt(response.data.count, 10) || 0;

				/*
				 * Update every button for this product.
				 */
				const matchingButtons = $(
					'.wishlist-heart-btn[data-product-id="' +
						productId +
					'"]'
				);

				matchingButtons
					.toggleClass('favorited', isFavorited)
					.attr(
						'aria-pressed',
						isFavorited ? 'true' : 'false'
					)
					.attr(
						'aria-label',
						isFavorited
							? 'Remove from wishlist'
							: 'Add to wishlist'
					);

				/*
				 * Update the sticky wishlist count.
				 */
				$('.wishlist-count')
					.text(count)
					.toggleClass('is-empty', count === 0);

				/*
				 * Remove the product row from the Wishlist page.
				 */
				if (
					!isFavorited &&
					button.hasClass('wishlist-remove-button')
				) {
					button
						.closest('.wishlist-product')
						.fadeOut(200, function () {
							$(this).remove();

							if ($('.wishlist-product').length === 0) {
								window.location.reload();
							}
						});
				}
			})
			.fail(function () {
				alert('The wishlist could not be updated.');
			})
			.always(function () {
				button.removeClass('loading');
			});
	});
});