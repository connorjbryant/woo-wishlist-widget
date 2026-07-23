<?php
/**
 * Public Wishlist Template
 * 
 * This template displays a user's public wishlist
 * 
 * To override this template in your theme, create:
 * /your-theme/woocommerce-wishlist-widget/public-wishlist.php
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get the user ID from the query var
$user_id = get_query_var( 'wishlist_user_id', 0 );

if ( ! $user_id ) {
    wp_die( 'Invalid wishlist.', 'Wishlist Error', array( 'response' => 404 ) );
}

$user = get_userdata( $user_id );
$wishlist = get_user_meta( $user_id, '_wishlist_products', true );
$wishlist = is_array( $wishlist ) ? $wishlist : [];

// Use theme's header
get_header();
?>

<div class="wishlist-public-wrapper">
    <div class="wishlist-public-container">
        <div class="wishlist-public-header">
            <h1>
                <?php 
                printf(
                    esc_html__( '%s\'s Wishlist', 'woocommerce-wishlist-widget' ),
                    esc_html( $user->display_name )
                );
                ?>
            </h1>
        </div>
        
        <?php if ( empty( $wishlist ) ) : ?>
            <div class="wishlist-empty">
                <p><?php esc_html_e( 'This wishlist is empty.', 'woocommerce-wishlist-widget' ); ?></p>
            </div>
        <?php else : ?>
            <div class="wishlist-products">
                <?php
                foreach ( $wishlist as $product_id ) {
                    $product = wc_get_product( $product_id );
                    
                    if ( ! $product || ! $product->is_visible() ) {
                        continue;
                    }
                    
                    $product_url = $product->get_permalink();
                    ?>
                    <div class="wishlist-product">
                        <a class="wishlist-product-image" href="<?php echo esc_url( $product_url ); ?>">
                            <?php echo wp_kses_post( $product->get_image() ); ?>
                        </a>
                        
                        <div class="wishlist-product-information">
                            <a href="<?php echo esc_url( $product_url ); ?>">
                                <strong><?php echo esc_html( $product->get_name() ); ?></strong>
                            </a>
                            
                            <?php if ( $product->get_sku() ) : ?>
                                <span class="wishlist-product-sku">
                                    <?php
                                    printf(
                                        esc_html__( 'SKU: %s', 'woocommerce-wishlist-widget' ),
                                        esc_html( $product->get_sku() )
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="wishlist-product-price">
                            <?php echo wp_kses_post( $product->get_price_html() ); ?>
                        </div>
                        
                        <div class="wishlist-product-cart">
                            <?php
                            if ( $product->is_purchasable() && $product->is_in_stock() ) :
                                ?>
                                <a
                                    href="<?php echo esc_url( $product->add_to_cart_url() ); ?>"
                                    class="button add_to_cart_button ajax_add_to_cart"
                                    data-product_id="<?php echo esc_attr( $product_id ); ?>"
                                    data-product_sku="<?php echo esc_attr( $product->get_sku() ); ?>"
                                    data-quantity="1"
                                    aria-label="<?php echo esc_attr( $product->add_to_cart_description() ); ?>"
                                    rel="nofollow"
                                >
                                    <?php echo esc_html( $product->add_to_cart_text() ); ?>
                                </a>
                            <?php else : ?>
                                <span class="wishlist-product-unavailable">
                                    <?php esc_html_e( 'Currently unavailable', 'woocommerce-wishlist-widget' ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Use theme's footer
get_footer();