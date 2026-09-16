<?php
/**
 * The basket side: finding triggers, adding and removing add-ons, and the
 * quantity rule.
 *
 * SHAPES THIS SUPPORTS
 * --------------------
 * One offer is "these triggers → these add-ons", so every shape works:
 *   A → B            one offer, one trigger, one add-on
 *   A → B and C      one offer, one trigger, two add-ons (one box, two rows)
 *   A and C → B      one offer, two triggers, one add-on
 *   A → B, C → D     two separate offers
 *
 * THE ONE RULE WORTH UNDERSTANDING
 * --------------------------------
 * A line this plugin adds is marked "automatic". While it is automatic, its
 * quantity is kept equal to the quantity of the trigger lines. The first time
 * the customer edits that quantity themselves, the mark is removed and the
 * plugin never touches the line again.
 */

defined( 'ABSPATH' ) || exit;

class CSO_Cart {

	/**
	 * True while this class is changing quantities itself.
	 *
	 * Without it, our own set_quantity() call would fire the "customer edited
	 * it" hook and immediately cancel the syncing we were in the middle of.
	 */
	private $syncing = false;

	public function __construct() {
		add_action( 'woocommerce_cart_updated', array( $this, 'sync' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'note_manual_edit' ), 10, 4 );
		add_action( 'wp_loaded', array( $this, 'handle_post' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'line_note' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Reading the basket
	 * ------------------------------------------------------------------ */

	/**
	 * Total quantity of the lines that trigger this offer.
	 *
	 * Summed, not maxed: three places on Dublin plus two on Mullingar is five
	 * people who could take the add-on.
	 */
	public static function trigger_qty( $offer ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		$qty = 0;
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_addon_line( $item ) ) {
				continue; // An add-on never triggers an offer.
			}
			if ( cso_product_triggers( $offer, isset( $item['product_id'] ) ? $item['product_id'] : 0 ) ) {
				$qty += max( 0, (int) $item['quantity'] );
			}
		}

		return $qty;
	}

	public static function is_addon_line( $item ) {
		return is_array( $item ) && ! empty( $item[ CSO_ITEM_OFFER ] );
	}

	/**
	 * Is this particular product already in the basket — by any route?
	 *
	 * Checked by product, not just by our own marker, so a customer who added
	 * it themselves is not pestered to add it again.
	 */
	public static function product_in_cart( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$pid = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
			$vid = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
			if ( $product_id === $pid || $product_id === $vid ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * The add-on products of this offer that are NOT yet in the basket.
	 */
	public static function missing_addons( $offer ) {
		$missing = array();
		foreach ( cso_offer_addons( $offer ) as $product_id ) {
			if ( '' === self::product_in_cart( $product_id ) ) {
				$missing[] = $product_id;
			}
		}
		return $missing;
	}

	/**
	 * Should this offer be shown at all? Trigger present, and at least one of
	 * its add-ons still missing.
	 */
	public static function should_show( $offer ) {
		return self::trigger_qty( $offer ) > 0 && (bool) self::missing_addons( $offer );
	}

	/**
	 * How many of an add-on to add.
	 */
	public static function wanted_qty( $offer ) {
		$trigger = self::trigger_qty( $offer );

		if ( 'one' === $offer['qty_mode'] ) {
			return $trigger > 0 ? 1 : 0;
		}

		return $trigger;
	}

	/* ---------------------------------------------------------------------
	 * Changing the basket
	 * ------------------------------------------------------------------ */

	/**
	 * Add one of an offer's products.
	 *
	 * @param array $offer
	 * @param int   $product_id Which of the offer's add-ons. 0 = the first one.
	 */
	public static function accept( $offer, $product_id = 0 ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		$addons     = cso_offer_addons( $offer );
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			$product_id = reset( $addons );
		}

		// Never add something this offer does not actually list.
		if ( ! in_array( $product_id, $addons, true ) ) {
			return false;
		}
		if ( '' !== self::product_in_cart( $product_id ) ) {
			return false; // Already there.
		}

		$qty = self::wanted_qty( $offer );
		if ( $qty < 1 ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return false;
		}

		// The custom data both marks the line as ours and keeps it on its own
		// row rather than merging into the same product added another way.
		$added = WC()->cart->add_to_cart(
			$product_id,
			$qty,
			0,
			array(),
			array(
				CSO_ITEM_OFFER => $offer['id'],
				CSO_ITEM_AUTO  => 'match' === $offer['qty_mode'],
			)
		);

		return (bool) $added;
	}

	public static function decline( $offer, $product_id = 0 ) {
		$key = self::product_in_cart( $product_id ? $product_id : reset( cso_offer_addons( $offer ) ) );
		if ( '' === $key || ! WC()->cart ) {
			return false;
		}
		return WC()->cart->remove_cart_item( $key );
	}

	/* ---------------------------------------------------------------------
	 * The quantity rule
	 * ------------------------------------------------------------------ */

	public function sync() {
		if ( $this->syncing || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		if ( ! cso_live_offers() ) {
			return;
		}

		$this->syncing = true;
		$changed       = false;

		foreach ( WC()->cart->get_cart() as $key => $item ) {

			if ( ! self::is_addon_line( $item ) ) {
				continue;
			}

			$offer = cso_get_offer( $item[ CSO_ITEM_OFFER ] );
			if ( ! $offer ) {
				continue; // Offer deleted since; leave the line alone.
			}

			$trigger = self::trigger_qty( $offer );

			// The trigger has left the basket entirely.
			if ( 0 === $trigger ) {
				if ( 'yes' === $offer['remove_with_trigger'] ) {
					WC()->cart->remove_cart_item( $key );
					$changed = true;
				}
				continue;
			}

			// The customer has taken control of this line.
			if ( empty( $item[ CSO_ITEM_AUTO ] ) ) {
				if ( 'yes' === $offer['cap_to_trigger'] && (int) $item['quantity'] > $trigger ) {
					WC()->cart->set_quantity( $key, $trigger, false );
					$changed = true;
				}
				continue;
			}

			$wanted = self::wanted_qty( $offer );
			if ( $wanted > 0 && (int) $item['quantity'] !== $wanted ) {
				WC()->cart->set_quantity( $key, $wanted, false );
				$changed = true;
			}
		}

		if ( $changed ) {
			WC()->cart->calculate_totals();
		}

		$this->syncing = false;
	}

	/**
	 * The customer changed a quantity. If it was one of ours, hand the line
	 * over to them for good.
	 */
	public function note_manual_edit( $cart_item_key, $quantity, $old_quantity, $cart = null ) {
		if ( $this->syncing || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return; // That was us, not them.
		}

		$contents = WC()->cart->cart_contents;
		if ( empty( $contents[ $cart_item_key ] ) || ! self::is_addon_line( $contents[ $cart_item_key ] ) ) {
			return;
		}

		WC()->cart->cart_contents[ $cart_item_key ][ CSO_ITEM_AUTO ] = false;
		WC()->cart->set_session();
	}

	/* ---------------------------------------------------------------------
	 * Basket link
	 * ------------------------------------------------------------------ */

	/**
	 * The basket box is a nonce'd link, not a form: it renders inside
	 * WooCommerce's own cart form, and HTML forbids nested forms.
	 */
	public function handle_post() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$src = ! empty( $_GET['cso_offer'] ) ? $_GET : $_POST;

		if ( empty( $src['cso_offer'] ) || empty( $src['cso_action'] ) ) {
			return;
		}

		$id      = sanitize_text_field( wp_unslash( $src['cso_offer'] ) );
		$action  = sanitize_text_field( wp_unslash( $src['cso_action'] ) );
		$product = isset( $src['cso_product'] ) ? absint( $src['cso_product'] ) : 0;
		$nonce   = isset( $src['cso_nonce'] ) ? sanitize_key( wp_unslash( $src['cso_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'cso_offer_' . $id . '_' . $product ) ) {
			return;
		}

		$live = cso_live_offers();
		if ( ! isset( $live[ $id ] ) ) {
			return;
		}
		$offer = $live[ $id ];

		if ( 'add' === $action ) {
			if ( self::accept( $offer, $product ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: product name */
						__( '%s was added to your booking.', 'cross-sell-offers-for-woocommerce' ),
						get_the_title( $product )
					)
				);
			}
		} elseif ( 'remove' === $action ) {
			self::decline( $offer, $product );
		}

		// Always redirect, so the action cannot be repeated by refreshing and
		// the nonce does not sit in the address bar.
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	/**
	 * A small note under the add-on's name in the basket, so nobody wonders
	 * where it came from.
	 */
	public function line_note( $data, $item ) {
		if ( ! self::is_addon_line( $item ) ) {
			return $data;
		}

		$offer = cso_get_offer( $item[ CSO_ITEM_OFFER ] );
		if ( ! $offer ) {
			return $data;
		}

		$data[] = array(
			'key'     => __( 'Added with', 'cross-sell-offers-for-woocommerce' ),
			'value'   => $offer['label'] ? $offer['label'] : __( 'your course booking', 'cross-sell-offers-for-woocommerce' ),
			'display' => '',
		);

		return $data;
	}
}
