<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Our main class
 *
 */
final class Woo_Product_Percentage_Coupon {

	/* Internal variables */
	private $discount_type            = 'percent_per_product';
	private $mode                     = 'default';
	private $products_magic_not_valid = array();

	/* Single instance */
	protected static $_instance = null;

	/* Constructor */
	public function __construct() {
		$this->version = PCPW_VERSION;
		$this->init_hooks();
	}

	/* Ensures only one instance of our plugin is loaded or can be loaded */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/* Hooks */
	private function init_hooks() {
		//Init stuff
		add_action( 'init', array( $this, 'init' ) );
		//Add coupon type to WooCommerce
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'woocommerce_coupon_discount_types' ) );
		add_filter( 'woocommerce_product_coupon_types', array( $this, 'woocommerce_product_coupon_types' ) );
		add_action( 'woocommerce_coupon_options', array( $this, 'woocommerce_coupon_options' ), 10, 2 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'woocommerce_coupon_get_discount_amount' ), 10, 5 );
		//Custom product fields
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'woocommerce_product_data_tabs' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'woocommerce_product_data_panels' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'woocommerce_process_product_meta' ) );
		//Custom product variation fields
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'woocommerce_product_after_variable_attributes' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'woocommerce_save_product_variation' ), 10, 2 );
		//Add coupon type to InvoiceXpress
		add_filter( 'invoicexpress_woocommerce_allowed_coupon_types', array( $this, 'invoicexpress_woocommerce_allowed_coupon_types' ) );
		//Magic coupon valid for product
		add_filter( 'magic_coupon_coupon_is_valid_for_product', array( $this, 'magic_coupon_coupon_is_valid_for_product' ), 10, 3 );
	}

	/* Init stuff */
	public function init() {
		//Maybe not needed because we should have a PRO version with a specific taxonomy and percentages within the coupon settings
		if ( apply_filters( 'product_percentage_coupon_woo_single_percentage_mode', false ) ) $this->mode = 'single';
	}

	/* Add to WooCommerce */
	public function woocommerce_coupon_discount_types( $discount_types ) {
		$discount_types[ $this->discount_type ] = __( 'Percentage discount (per product)', 'product-percentage-coupon-woo' );
		return $discount_types;
	}
	public function woocommerce_product_coupon_types( $coupon_types ) {
		$coupon_types[] = $this->discount_type;
		return $coupon_types;
	}

	/* Instructions */
	public function woocommerce_coupon_options( $coupon_id, $coupon ) {
		if ( is_a( $coupon, 'WC_Coupon' ) ) {
			if ( $coupon->get_discount_type() == $this->discount_type ) {
				?>
				<p>
					<strong>
						<?php _e( 'A “Percentage discount (per product)” coupon works just like a regular “Percentage discount” coupon, setting the default discount at the percentage set on this page, but applies a custom discount if you set it on each product.', 'product-percentage-coupon-woo' ); ?>
					</strong>
				</p>
				<?php
			}
		}
	}

	/* Get all coupons */
	public function get_all_coupons() {
		//There are no CRUD methods for coupons at this time
		$coupons = get_posts( array(
			'post_type'      => 'shop_coupon',
			'status'         => 'publish',
			'posts_per_page' => -1, //I know, nasty...
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_key'       => 'discount_type',
			'meta_value'     => $this->discount_type,
		) );
		foreach ( $coupons as $key => $post ) {
			$coupons[$key] = new WC_Coupon( $post->post_title );
		}
		return $coupons;
	}

	/* Calculations */
	public function woocommerce_coupon_get_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		if ( $coupon->get_discount_type() == $this->discount_type ) {
			if ( $product = wc_get_product( $cart_item['product_id'] ) ) {
				$variation = isset( $cart_item['variation_id'] ) && intval( $cart_item['variation_id'] ) > 0 ? wc_get_product( $cart_item['variation_id'] ) : null;
				$discount = $this->get_discount_amount( $discounting_amount, $coupon, $product, $variation );
			}
		}
		return $discount;
	}
	//We can use this function outside the plugin for Magic Coupon, for example
	public function get_discount_amount( $discounting_amount, $coupon, $product, $variation = null ) {
		//Coupon amount
		$amount = floatval( $coupon->get_amount() );
		//Variation? - Get meta from correct parent product
		if ( $product->get_type() == 'variation' ) {
			//Just in case, for integrations that don't pass the $product and $variation to the function
			$meta_product   = wc_get_product( $product->get_parent_id() );
			$meta_variation = $product;
		} else {
			//On this plugin we should always be here
			$meta_product   = $product;
			$meta_variation = $variation;
		}
		//Product amount?
		switch( $this->mode ) {

			case 'single':
				//Single (BF) mode
				if ( $meta_variation && trim( $meta_variation->get_meta( '_product_percentage_coupon_woo_active_global' ) ) == 'yes' ) {
					//Variation
					$temp_amount = floatval( $meta_variation->get_meta( '_product_percentage_coupon_woo_amount_global' ) );
					if ( $temp_amount > 0 ) $amount = $temp_amount; //Only replace coupon value if > 0
				} elseif ( trim( $meta_product->get_meta( '_product_percentage_coupon_woo_active_global' ) ) != 'no' ) {
					$temp_amount = floatval( $meta_product->get_meta( '_product_percentage_coupon_woo_amount_global' ) );
					if ( $temp_amount > 0 ) $amount = $temp_amount;
				} else {
					$amount = 0;
				}
				break;

			default:
				//Default - list all coupons
				if ( $meta_variation && trim( $meta_variation->get_meta( '_product_percentage_coupon_woo_active_'.$coupon->get_id() ) ) == 'yes' ) {
					//Variation
					$temp_amount = floatval( $meta_variation->get_meta( '_product_percentage_coupon_woo_amount_'.$coupon->get_id() ) );
					if ( $temp_amount > 0 ) $amount = $temp_amount; //Only replace coupon value if > 0
				} elseif ( trim( $meta_product->get_meta( '_product_percentage_coupon_woo_active_'.$coupon->get_id() ) ) != 'no' ) {
					//Product
					$temp_amount = floatval( $meta_product->get_meta( '_product_percentage_coupon_woo_amount_'.$coupon->get_id() ) );
					if ( $temp_amount > 0 ) $amount = $temp_amount; //Only replace coupon value if > 0
				} else {
					//None
					$amount = 0;
				}
				break;

		}
		//Set Magic Coupon "is on sale" to false
		if ( $amount == 0 ) {
			$add_filter = true;
			$filter_product = $meta_variation ? $meta_variation : $meta_product;
			//Is the product on sale because of "sale price"?
			if ( '' !== (string) $filter_product->get_sale_price() && $filter_product->get_regular_price() > $filter_product->get_sale_price() ) {
				$add_filter = false;
				if ( $filter_product->get_date_on_sale_from() && $filter_product->get_date_on_sale_from()->getTimestamp() > time() ) {
					$add_filter = true;
				}
				if ( $filter_product->get_date_on_sale_to() && $filter_product->get_date_on_sale_to()->getTimestamp() < time() ) {
					$add_filter = true;
				}
			}
			//if ( $add_filter ) {
			//	add_filter( 'magic_coupon_product_'.$filter_product->get_id().'_is_on_sale', '__return_false' );
			//}
			$add_to_array = false;
			if ( $filter_product->get_type() == 'variable' ) {
				//We start by setting it to true
				$add_to_array = true;
				//Get childs just to be sure
				$temp_variations = $product->get_available_variations();
				$temp_variations = wp_list_pluck( $temp_variations, 'variation_id' );
				foreach ( $temp_variations as $temp_variation_id ) {
					$temp_variation = wc_get_product( $temp_variation_id );
					switch( $this->mode ) {
						case 'single':
							$temp_meta_active = '_product_percentage_coupon_woo_active_global';
							$temp_meta_amount = '_product_percentage_coupon_woo_amount_global';
							break;
						default:
							$temp_meta_active = '_product_percentage_coupon_woo_active_'.$coupon->get_id();
							$temp_meta_amount = '_product_percentage_coupon_woo_amount_'.$coupon->get_id();
							break;
					}
					if ( trim( $temp_variation->get_meta( $temp_meta_active ) ) == 'yes' ) {
						if ( floatval( $temp_variation->get_meta( $temp_meta_amount ) ) > 0 ) {
							//This variation has discount - $add_to_array = false; and break because we don't need to go further
							$add_to_array = false;
							break;
						}
					}
				}
			} else {
				//Amount is 0, and not variable, add to array
				$add_to_array = true;
			}
			if ( $add_to_array ) {
				if ( ! ( isset( $this->products_magic_not_valid[ $coupon->get_id() ] ) && is_array( $this->products_magic_not_valid[ $coupon->get_id() ] ) ) ) $this->products_magic_not_valid[ $coupon->get_id() ] = array();
				$this->products_magic_not_valid[ $coupon->get_id() ] = array_unique( array_merge( $this->products_magic_not_valid[ $coupon->get_id() ], array( $filter_product->get_id() ) ) );
				if ( $add_filter ) {
					add_filter( 'magic_coupon_product_'.$filter_product->get_id().'_is_on_sale', '__return_false' );
				}
			}
		}
		//Just like in class-wc-coupon.php get_discount_amount()
		$discount = (float) $amount * ( $discounting_amount / 100 );
		//Allow the PRO add-on to recaulculate it
		return apply_filters( 'product_percentage_coupon_woo_discount', $discount, $discounting_amount, $coupon, $meta_product, $meta_variation );
	}

	/* Magic coupon - return false to non-active products for this coupon */
	public function magic_coupon_coupon_is_valid_for_product( $valid, $coupon, $product_id ) {
		//Product ID is on not_valid for magic coupon
		if ( isset( $this->products_magic_not_valid[ $coupon->get_id() ] ) && is_array( $this->products_magic_not_valid[ $coupon->get_id() ] ) && in_array( $product_id, $this->products_magic_not_valid[ $coupon->get_id() ] ) ) {
			return false;
		}
		return $valid;
	}

	/* Admin - Add our own product fields */
	public function woocommerce_product_data_tabs( $tabs ) {
		$tabs['product_percentage_coupon'] = array(
			'label'  => __( 'Percentage discount coupons', 'product-percentage-coupon-woo' ),
			'target' => 'product_percentage_coupon',
			'class'  => array(),
			'priority' => 9999,
		);
		return $tabs;
	}
	public function woocommerce_product_data_panels() {
		?>
		<div id="product_percentage_coupon" class="panel woocommerce_options_panel">
			<div class="options_group">
				<p>
					<strong><?php _e( 'The fields below are used to set custom percentage discounts for “Percentage discount (per product)” coupons <span class="show_if_variable">and might be overridden at the variations settings</span>', 'product-percentage-coupon-woo' ); ?></strong>
				</p>
				<?php
				switch ( $this->mode ) {

					case 'single':
						//Single (BF) mode
						woocommerce_wp_select(
							array(
								'id'      => '_product_percentage_coupon_woo_active_global',
								'label'   => __( 'Activate coupon discount (%)', 'product-percentage-coupon-woo' ),
								'options' => array(
									'yes' => __( 'Yes', 'product-percentage-coupon-woo' ),
									'no'  => __( 'No', 'product-percentage-coupon-woo' ),
								),
								'class'   => 'product_percentage_coupon_woo_active select short',
							)
						);
						woocommerce_wp_text_input( array(
							'id'                => '_product_percentage_coupon_woo_amount_global',
							'label'             => __( 'Coupon discount (%)', 'product-percentage-coupon-woo' ),
							'placeholder'       => __( 'Coupon default', 'product-percentage-coupon-woo' ),
							'data_type'         => 'decimal',
							'custom_attributes' => array(
								'size' => 5
							),
						) );
						break;
					
					default:
						//Default - list all coupons
						if ( $coupons = $this->get_all_coupons() ) {
							foreach ( $coupons as $coupon ) {
								woocommerce_wp_select(
									array(
										'id'      => '_product_percentage_coupon_woo_active_'.$coupon->get_id(),
										'label'   => sprintf(
											__( 'Activate “%s” discount (%%)', 'product-percentage-coupon-woo' ),
											$coupon->get_code()
										),
										'options' => array(
											'yes' => __( 'Yes', 'product-percentage-coupon-woo' ),
											'no'  => __( 'No', 'product-percentage-coupon-woo' ),
										),
										'class'   => 'product_percentage_coupon_woo_active select short',
									)
								);
								woocommerce_wp_text_input( array(
									'id'                => '_product_percentage_coupon_woo_amount_'.$coupon->get_id(),
									'label'             => sprintf(
										__( '“%s” discount (%%)', 'product-percentage-coupon-woo' ),
										$coupon->get_code()
									),
									'placeholder'       => $coupon->get_amount(),
									'data_type'         => 'decimal',
									'custom_attributes' => array(
										'size' => 5
									),
								) );
							}
						} else {
							?>
							<p>
								<?php _e( 'There are no “Percentage discount (per product)” coupons yet.', 'product-percentage-coupon-woo' ); ?>
							</p>
							<?php
						}
						break;

				}
				?>
				<script type="text/javascript">
				jQuery( function( $ ) {
					function product_percentage_coupon_hide_fields() {
						$( '.product_percentage_coupon_woo_active' ).each( function() {
							var id = $( this ).attr( 'id' );
							var perc_id = id.replace( '_active_', '_amount_' );
							if ( $( this ).val() == 'yes' ) {
								$( '.'+perc_id+'_field' ).show();
							} else {
								$( '.'+perc_id+'_field' ).hide();
							}
						} );
					}
					product_percentage_coupon_hide_fields();
					$( document ).on( 'change', '.product_percentage_coupon_woo_active', function() {
						product_percentage_coupon_hide_fields();
					} );
					$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded', function() {
						product_percentage_coupon_hide_fields();
					} );
				} );
				</script>
				<style type="text/css">
					#woocommerce-product-data ul.wc-tabs li.product_percentage_coupon_options a::before,
					.product_percentage_coupon_plugin_title::before {
						font-family: Dashicons;
						speak: none;
						font-weight: 400;
						font-variant: normal;
						text-transform: none;
						line-height: 1;
						-webkit-font-smoothing: antialiased;
						content: "\f323";
						text-decoration: none;
					}
					.product_percentage_coupon_variation_fields .form-field input {
						width: auto !important;
					}
					.product_percentage_coupon_woo_amount_variable_field {
						display: none;
					}
				</style>
				<?php
				//Action for integration
				do_action( 'product_percentage_coupon_product_data_panel_end' );
				?>
			</div>
		</div>
		<?php
	}

	/* Admin - Save product fields */
	public function woocommerce_process_product_meta( $post_id ) {
		$meta = array();
		switch ( $this->mode ) {

			case 'single':
				//Single (BF) mode
				$meta['_product_percentage_coupon_woo_active_global'] =  ! empty( $_POST['_product_percentage_coupon_woo_active_global'] ) ? wc_clean( $_POST['_product_percentage_coupon_woo_active_global'] ) : '';
				$meta['_product_percentage_coupon_woo_amount_global'] =  ! empty( $_POST['_product_percentage_coupon_woo_amount_global'] ) ? wc_clean( $_POST['_product_percentage_coupon_woo_amount_global'] ) : '';
				break;
			
			default:
				//Default - list all coupons
				if ( $coupons = $this->get_all_coupons() ) {
					foreach ( $coupons as $coupon ) {
						$meta['_product_percentage_coupon_woo_active_'.$coupon->get_id()] = ! empty( $_POST['_product_percentage_coupon_woo_active_'.$coupon->get_id()] ) ? wc_clean( $_POST['_product_percentage_coupon_woo_active_'.$coupon->get_id()] ) : 'yes';
						$meta['_product_percentage_coupon_woo_amount_'.$coupon->get_id()] = ! empty( $_POST['_product_percentage_coupon_woo_amount_'.$coupon->get_id()] ) ? wc_clean( $_POST['_product_percentage_coupon_woo_amount_'.$coupon->get_id()] ) : '';
					}
				}
				break;

		}
		//Update meta - CRUD
		$product = wc_get_product( $post_id );
		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->save();
	}

	/* Admin - Add our own variation fields */
	public function woocommerce_product_after_variable_attributes( $loop, $variation_data, $variation ) {
		global $thepostid;
		if ( ! isset( $thepostid ) ) {
			$thepostid = $variation->post_parent;
		}
		$product = wc_get_product( $thepostid );
		$variation = wc_get_product( $variation->ID );
		?>
		<div class="clear"></div>
		<div id="product_percentage_coupon_<?php echo $loop; ?>" class="product_percentage_coupon_variation_fields">
			<br/>
			<hr/>
			<p class="form-field form-row form-row-full product_percentage_coupon_plugin_title">
				<strong><?php _e( 'Percentage discount coupons', 'product-percentage-coupon-woo' ); ?></strong>
			</p>
			<p>
				<?php _e( 'The fields below are used to set custom percentage discounts for “Percentage discount (per product)” coupons and will override the product level settings', 'product-percentage-coupon-woo' ); ?>
			</p>
			<?php
			switch ( $this->mode ) {

				case 'single':
					//Single (BF) mode
					woocommerce_wp_select(
						array(
							'id'      => "_product_percentage_coupon_woo_active_global_variable{$loop}",
							'name'    => "_product_percentage_coupon_woo_active_global_variable[{$loop}]",
							'label'   => __( 'Activate coupon discount (%)', 'product-percentage-coupon-woo' ),
							'options' => array(
								'no'  => __( 'No (inherit from product)', 'product-percentage-coupon-woo' ),
								'yes' => __( 'Yes', 'product-percentage-coupon-woo' ),
							),
							'value'   => $variation->get_meta( '_product_percentage_coupon_woo_active_global' ),
							'class'   => 'product_percentage_coupon_woo_active select short',
						)
					);
					woocommerce_wp_text_input( array(
						'id'                => "_product_percentage_coupon_woo_amount_global_variable{$loop}",
						'name'              => "_product_percentage_coupon_woo_amount_global_variable[{$loop}]",
						'label'             => __( 'Coupon discount (%)', 'product-percentage-coupon-woo' ),
						'placeholder'       => __( 'Coupon default', 'product-percentage-coupon-woo' ),
						'data_type'         => 'decimal',
						'value'             => $variation->get_meta( '_product_percentage_coupon_woo_amount_global' ),
						'custom_attributes' => array(
							'size' => 5
						),
						'wrapper_class'     => 'product_percentage_coupon_woo_amount_variable_field',
					) );
					break;
				
				default:
					//Default - list all coupons
					if ( $coupons = $this->get_all_coupons() ) {
						foreach ( $coupons as $coupon ) {
							woocommerce_wp_select(
								array(
									'id'      => "_product_percentage_coupon_woo_active_".$coupon->get_id()."_variable{$loop}",
									'name'    => "_product_percentage_coupon_woo_active_".$coupon->get_id()."_variable[{$loop}]",
									'label'   => sprintf(
										__( 'Activate “%s” discount (%%)', 'product-percentage-coupon-woo' ),
										$coupon->get_code()
									),
									'options' => array(
										'no'  => __( 'No (inherit from product)', 'product-percentage-coupon-woo' ),
										'yes' => __( 'Yes', 'product-percentage-coupon-woo' ),
									),
									'value'   => $variation->get_meta( '_product_percentage_coupon_woo_active_'.$coupon->get_id() ),
									'class'   => 'product_percentage_coupon_woo_active select short',
								)
							);
							woocommerce_wp_text_input( array(
								'id'                => "_product_percentage_coupon_woo_amount_".$coupon->get_id()."_variable{$loop}",
								'name'              => "_product_percentage_coupon_woo_amount_".$coupon->get_id()."_variable[{$loop}]",
								'label'             => sprintf(
									__( '“%s” discount (%%)', 'product-percentage-coupon-woo' ),
									$coupon->get_code()
								),
								'placeholder'       => $coupon->get_amount(),
								'data_type'         => 'decimal',
								'value'   => $variation->get_meta( '_product_percentage_coupon_woo_amount_'.$coupon->get_id() ),
								'custom_attributes' => array(
									'size' => 5
								),
								'wrapper_class'     => 'product_percentage_coupon_woo_amount_variable_field',
							) );
						}
					} else {
						?>
						<p>
							<?php _e( 'There are no “Percentage discount (per product)” coupons yet.', 'product-percentage-coupon-woo' ); ?>
						</p>
						<?php
					}
					break;

			}
			//Action for integration
			do_action( 'product_percentage_coupon_product_variation_data_panel_end' );
			?>
			<div class="clear"></div>
		</div>
		<?php
	}

	/* Admin - Save variation fields */
	public function woocommerce_save_product_variation( $variation_id, $index ) {
		$variation = wc_get_product( $variation_id );

		$meta = array();
		switch ( $this->mode ) {

			case 'single':
				//Single (BF) mode
				$meta['_product_percentage_coupon_woo_active_global'] =  ! empty( $_POST['_product_percentage_coupon_woo_active_global_variable'][$index] ) ? wc_clean( $_POST['_product_percentage_coupon_woo_active_global_variable'][$index] ) : '';
				$meta['_product_percentage_coupon_woo_amount_global'] =  ! empty( $_POST['_product_percentage_coupon_woo_amount_global_variable'][$index] ) ? wc_clean( $_POST['_product_percentage_coupon_woo_amount_global_variable'][$index] ) : '';
				break;
			
			default:
				//Default - list all coupons
				if ( $coupons = $this->get_all_coupons() ) {
					foreach ( $coupons as $coupon ) {
						$meta['_product_percentage_coupon_woo_active_'.$coupon->get_id()] = ! empty( $_POST['_product_percentage_coupon_woo_active_'.$coupon->get_id().'_variable'][$index] ) ? wc_clean( $_POST['_product_percentage_coupon_woo_active_'.$coupon->get_id().'_variable'][$index] ) : 'yes';
						$meta['_product_percentage_coupon_woo_amount_'.$coupon->get_id()] = ! empty( $_POST['_product_percentage_coupon_woo_amount_'.$coupon->get_id().'_variable'][$index] ) ? wc_clean( $_POST['_product_percentage_coupon_woo_amount_'.$coupon->get_id().'_variable'][$index] ) : '';
					}
				}
				break;

		}
		//Update meta - CRUD
		foreach ( $meta as $key => $value ) {
			$variation->update_meta_data( $key, $value );
		}

		$variation->save();
	}

	/* Add coupon type to the InvoiceXpress allowed ones */
	public function invoicexpress_woocommerce_allowed_coupon_types( $coupon_types ) {
		$coupon_types[] = $this->discount_type;
		return $coupon_types;
	}

}