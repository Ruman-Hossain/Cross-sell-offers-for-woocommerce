<?php
/**
 * Showing the offer on the basket and the checkout.
 *
 * Basket: a nonce'd link. No JavaScript at all.
 * Checkout: the same box, but accepted by AJAX — reloading the checkout would
 * throw away everything the customer has typed.
 */

defined( 'ABSPATH' ) || exit;

class CSO_Display {

	const AJAX_ACTION = 'cso_accept';

	public function __construct() {
		// Basket: below the items, above the totals.
		add_action( 'woocommerce_after_cart_table', array( $this, 'render_cart' ), 20 );

		// Checkout: above the form, deliberately outside the order review panel
		// that WooCommerce redraws by AJAX.
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout' ), 20 );

		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
	}

	/* ---------------------------------------------------------------------
	 * Placement
	 * ------------------------------------------------------------------ */

	private function offers_for( $placement ) {
		$out = array();
		foreach ( cso_live_offers() as $id => $offer ) {
			if ( ! in_array( $placement, (array) $offer['placements'], true ) ) {
				continue;
			}
			if ( ! CSO_Cart::should_show( $offer ) ) {
				continue;
			}
			$out[ $id ] = $offer;
		}
		return $out;
	}

	public function render_cart() {
		$offers = $this->offers_for( 'cart' );
		if ( ! $offers ) {
			return;
		}

		$this->styles();
		foreach ( $offers as $offer ) {
			$this->box( $offer, 'cart' );
		}
	}

	public function render_checkout() {
		$offers = $this->offers_for( 'checkout' );
		if ( ! $offers ) {
			return;
		}

		$this->styles();
		echo '<div id="cso-checkout-offers">';
		foreach ( $offers as $offer ) {
			$this->box( $offer, 'checkout' );
		}
		echo '</div>';
		$this->script();
	}

	/* ---------------------------------------------------------------------
	 * The box
	 * ------------------------------------------------------------------ */

	private function box( $offer, $placement ) {
		$missing = CSO_Cart::missing_addons( $offer );
		if ( ! $missing ) {
			return;
		}

		$qty = CSO_Cart::wanted_qty( $offer );

		echo '<div class="cso-offer" data-offer="' . esc_attr( $offer['id'] ) . '">';

		if ( $offer['heading'] ) {
			echo '<p class="cso-offer__heading">' . esc_html( $offer['heading'] ) . '</p>';
		}
		if ( $offer['text'] ) {
			echo '<p class="cso-offer__text">' . esc_html( $offer['text'] ) . '</p>';
		}

		// One row per product still missing. A single add-on therefore looks
		// like a simple offer; two or three read as a short menu rather than
		// a stack of separate boxes shouting at the customer.
		foreach ( $missing as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$this->row( $offer, $product, $qty, $placement );
		}

		echo '</div>';
	}

	/**
	 * One offered product: name, what it will cost, and the button.
	 */
	private function row( $offer, $product, $qty, $placement ) {
		$button = $offer['button'] ? $offer['button'] : __( 'Add to my booking', 'cross-sell-offers-for-woocommerce' );
		$unit   = wc_get_price_to_display( $product );
		$nonce  = wp_create_nonce( 'cso_offer_' . $offer['id'] . '_' . $product->get_id() );

		echo '<div class="cso-offer__row">';

		echo '<div class="cso-offer__body">';
		echo '<p class="cso-offer__name">' . esc_html( $product->get_name() ) . '</p>';

		// Say plainly what will be added and what it costs. Guessing games at
		// this point cost more trust than the add-on is worth.
		if ( $qty > 1 ) {
			echo '<p class="cso-offer__price">'
				. sprintf(
					/* translators: 1: quantity, 2: unit price, 3: line total */
					esc_html__( '%1$s places × %2$s = %3$s', 'cross-sell-offers-for-woocommerce' ),
					esc_html( $qty ),
					wp_kses_post( wc_price( $unit ) ),
					wp_kses_post( wc_price( $unit * $qty ) )
				)
				. '</p>';
		} else {
			echo '<p class="cso-offer__price">' . wp_kses_post( wc_price( $unit ) ) . '</p>';
		}

		// Say that the number can be changed.
		//
		// Without this, a customer booking five places sees a €250 add-on and
		// assumes it is all-or-nothing. Most will simply decline rather than
		// hunt for a way to take two. There is no quantity control on the
		// checkout, so the basket is named explicitly.
		if ( $qty > 1 && 'match' === $offer['qty_mode'] ) {
			echo '<p class="cso-offer__hint">'
				. sprintf(
					/* translators: %s: number of places booked */
					esc_html__( 'Set to match the %s places you are booking — you can change the number in your basket afterwards.', 'cross-sell-offers-for-woocommerce' ),
					esc_html( $qty )
				)
				. '</p>';
		}

		echo '</div>';

		echo '<div class="cso-offer__action">';

		if ( 'checkout' === $placement ) {
			echo '<button type="button" class="button cso-offer__btn"'
				. ' data-offer="' . esc_attr( $offer['id'] ) . '"'
				. ' data-product="' . esc_attr( $product->get_id() ) . '"'
				. ' data-nonce="' . esc_attr( $nonce ) . '">'
				. esc_html( $button ) . '</button>';
		} else {
			// A nonce'd link, NOT a form. The basket box renders inside
			// WooCommerce's own cart <form>, and HTML forbids nested forms —
			// the browser throws the inner tag away and hands the button to
			// WooCommerce's form, firing a cart update as well as this.
			$url = add_query_arg(
				array(
					'cso_offer'   => rawurlencode( $offer['id'] ),
					'cso_action'  => 'add',
					'cso_product' => $product->get_id(),
					'cso_nonce'   => $nonce,
				),
				wc_get_cart_url()
			);

			echo '<a href="' . esc_url( $url ) . '" class="button cso-offer__btn">' . esc_html( $button ) . '</a>';
		}

		echo '</div>';
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Accepting from the checkout, without losing what has been typed
	 * ------------------------------------------------------------------ */

	public function ajax() {
		$id      = isset( $_POST['offer'] ) ? sanitize_text_field( wp_unslash( $_POST['offer'] ) ) : '';
		$product = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		$nonce   = isset( $_POST['nonce'] ) ? sanitize_key( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $id || ! wp_verify_nonce( $nonce, 'cso_offer_' . $id . '_' . $product ) ) {
			wp_send_json_error( array( 'message' => __( 'That offer has expired. Please reload the page.', 'cross-sell-offers-for-woocommerce' ) ), 400 );
		}

		$live = cso_live_offers();
		if ( ! isset( $live[ $id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That offer is no longer available.', 'cross-sell-offers-for-woocommerce' ) ), 400 );
		}

		if ( ! CSO_Cart::accept( $live[ $id ], $product ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, that could not be added. It may be out of stock.', 'cross-sell-offers-for-woocommerce' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: product name */
					__( '%s added.', 'cross-sell-offers-for-woocommerce' ),
					get_the_title( $product )
				),
			)
		);
	}

	private function script() {
		?>
<script id="cso-js">
(function(){
	var wrap = document.getElementById('cso-checkout-offers');
	if(!wrap) return;

	wrap.addEventListener('click', function(e){
		var btn = e.target.closest ? e.target.closest('.cso-offer__btn') : null;
		if(!btn || !btn.dataset.offer) return;
		e.preventDefault();

		btn.disabled = true;
		var original = btn.textContent;
		btn.textContent = '…';

		var body = new URLSearchParams();
		body.set('action', '<?php echo esc_js( self::AJAX_ACTION ); ?>');
		body.set('offer', btn.dataset.offer);
		body.set('product', btn.dataset.product);
		body.set('nonce', btn.dataset.nonce);

		fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type':'application/x-www-form-urlencoded'},
			body: body.toString()
		})
		.then(function(r){ return r.json(); })
		.then(function(res){
			var row = btn.closest('.cso-offer__row');
			var box = btn.closest('.cso-offer');
			if(res && res.success){
				// Take away only the row that was accepted; any other product
				// in the same offer stays on offer.
				if(row) row.parentNode.removeChild(row);
				if(box && !box.querySelector('.cso-offer__row')) box.parentNode.removeChild(box);
				// Ask WooCommerce to recalculate. Nothing typed is lost:
				// only the order review panel is redrawn.
				if(window.jQuery) jQuery(document.body).trigger('update_checkout');
			} else {
				btn.disabled = false;
				btn.textContent = original;
				if(row){
					var p = document.createElement('p');
					p.className = 'cso-offer__error';
					p.textContent = (res && res.data && res.data.message) ? res.data.message : 'Sorry, that could not be added.';
					row.appendChild(p);
				}
			}
		})
		.catch(function(){
			btn.disabled = false;
			btn.textContent = original;
		});
	});
})();
</script>
		<?php
	}

	/**
	 * Inline, and colour-free apart from one accent border: the box should
	 * inherit the theme rather than fight it.
	 */
	private function styles() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		echo '<style id="cso-css">'
			. '.cso-offer{border:1px solid rgba(128,128,128,.35);border-left-width:4px;border-radius:6px;padding:16px 18px;margin:0 0 18px}'
			. '.cso-offer__row{display:flex;flex-wrap:wrap;gap:12px 16px;align-items:center;justify-content:space-between}'
			. '.cso-offer__row + .cso-offer__row{margin-top:12px;padding-top:12px;border-top:1px solid rgba(128,128,128,.25)}'
			. '.cso-offer__body{flex:1 1 240px;min-width:0}'
			. '.cso-offer__name{margin:0 0 2px;font-weight:600}'
			. '.cso-offer__heading{margin:0 0 4px;font-weight:700;font-size:1.02em}'
			. '.cso-offer__text{margin:0 0 12px;font-size:.94em;opacity:.85;line-height:1.45}'
			. '.cso-offer__price{margin:0;font-size:.94em;font-weight:600}'
			. '.cso-offer__hint{margin:4px 0 0;font-size:.86em;opacity:.75;line-height:1.4}'
			. '.cso-offer__action{flex:0 0 auto}'
			. ''
			. '.cso-offer__error{flex:1 1 100%;margin:8px 0 0;font-size:.9em;color:#b32d2e}'
			. '</style>';
	}
}
