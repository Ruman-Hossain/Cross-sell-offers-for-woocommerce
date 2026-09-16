<?php
/**
 * Admin: WooCommerce → Cross-Sell Offers.
 *
 * Every offer is edited on this one screen and saved together, so there is no
 * separate add/edit/delete routing to get lost in. A blank block at the bottom
 * is how you create the next one; clearing an offer's add-on product is how you
 * delete it.
 */

defined( 'ABSPATH' ) || exit;

class CSO_Settings {

	const SLUG  = 'cso-offers';
	const NONCE = 'cso_save';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_cso_save', array( $this, 'save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CSO_FILE ), array( $this, 'action_links' ) );
	}

	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Settings</a>',
			'<a href="' . esc_url( CSO_SUPPORT_URL ) . '" target="_blank" rel="noopener noreferrer">Support</a>'
		);
		return $links;
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			'Cross-Sell Offers',
			'Cross-Sell Offers',
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'page' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	public function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to change these settings.' );
		}
		check_admin_referer( self::NONCE );

		update_option( CSO_ENABLED, empty( $_POST['cso_enabled'] ) ? 'no' : 'yes' );

		$in     = isset( $_POST['cso'] ) && is_array( $_POST['cso'] ) ? wp_unslash( $_POST['cso'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$offers = array();

		foreach ( $in as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$addons = isset( $row['addon_products'] ) && is_array( $row['addon_products'] )
				? array_values( array_unique( array_filter( array_map( 'absint', $row['addon_products'] ) ) ) )
				: array();

			if ( ! $addons ) {
				continue; // Nothing to offer = not an offer. This is also how you delete one.
			}

			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( ! $id ) {
				$id = 'offer_' . wp_generate_password( 8, false, false );
			}

			$placements = array();
			foreach ( array( 'cart', 'checkout' ) as $where ) {
				if ( ! empty( $row['placements'][ $where ] ) ) {
					$placements[] = $where;
				}
			}

			$offers[] = array(
				'id'                  => $id,
				'enabled'             => empty( $row['enabled'] ) ? 'no' : 'yes',
				'label'               => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
				'trigger_products'    => isset( $row['trigger_products'] ) && is_array( $row['trigger_products'] )
					? array_values( array_filter( array_map( 'absint', $row['trigger_products'] ) ) ) : array(),
				'trigger_categories'  => isset( $row['trigger_categories'] ) && is_array( $row['trigger_categories'] )
					? array_values( array_filter( array_map( 'absint', $row['trigger_categories'] ) ) ) : array(),
				'addon_products'      => $addons,
				'placements'          => $placements,
				'qty_mode'            => ( isset( $row['qty_mode'] ) && 'one' === $row['qty_mode'] ) ? 'one' : 'match',
				'cap_to_trigger'      => empty( $row['cap_to_trigger'] ) ? 'no' : 'yes',
				'remove_with_trigger' => empty( $row['remove_with_trigger'] ) ? 'no' : 'yes',
				'heading'             => isset( $row['heading'] ) ? sanitize_text_field( $row['heading'] ) : '',
				'text'                => isset( $row['text'] ) ? sanitize_textarea_field( $row['text'] ) : '',
				'button'              => isset( $row['button'] ) ? sanitize_text_field( $row['button'] ) : '',
			);
		}

		update_option( CSO_OPTION, $offers );

		wp_safe_redirect( add_query_arg( 'cso_saved', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------------ */

	public function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'You do not have permission to view these settings.' );
		}

		$offers = array_values( cso_get_offers() );

		// The blank row at the bottom. It starts "Live" ticked, and so does every
		// row the "Add another offer" button clones — the two must agree, or two
		// identical-looking new rows on the same screen save differently. Nothing
		// goes live regardless until the master switch is on AND the offer has
		// both a trigger and something to offer.
		$blank            = cso_default_offer();
		$blank['enabled'] = 'yes';
		$offers[]         = $blank;

		$products = wc_get_products(
			array(
				'limit'   => 300,
				'status'  => array( 'publish', 'private' ),
				'orderby' => 'title',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);

		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		if ( is_wp_error( $cats ) ) {
			$cats = array();
		}

		// Look-ups, so a summary row can name things without a query each time.
		$product_names = array();
		foreach ( $products as $p ) {
			$product_names[ $p->get_id() ] = $p->get_name();
		}
		$cat_names = array();
		foreach ( $cats as $c ) {
			$cat_names[ (int) $c->term_id ] = $c->name;
		}

		$enabled = cso_is_enabled();
		$live    = count( cso_live_offers() );
		?>
		<div class="wrap">
			<h1>Cross-Sell Offers</h1>

			<?php if ( isset( $_GET['cso_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>Offers saved.</p></div>
			<?php endif; ?>

			<div class="notice <?php echo $enabled ? 'notice-warning' : 'notice-info'; ?>">
				<p>
					<strong>Status:</strong>
					<?php if ( $enabled && $live ) : ?>
						Running — <?php echo esc_html( $live ); ?> offer<?php echo 1 === $live ? '' : 's'; ?> live on your shop.
					<?php elseif ( $enabled ) : ?>
						Switched on, but no offer is complete yet. An offer needs something to offer and at least one trigger.
					<?php else : ?>
						Doing nothing. Your basket and checkout are completely unchanged.
					<?php endif; ?>
				</p>
			</div>

			<style>
				.cso-table td { vertical-align: middle; }
				.cso-table .cso-sum-name { font-weight: 600; }
				.cso-table .cso-muted { color: #646970; }
				.cso-edit-row > td { background: #fbfbfc; border-top: 0 !important; padding: 4px 16px 12px !important; }
				.cso-edit-row .form-table th { width: 190px; }
				.cso-pill { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; font-weight:600; }
				.cso-pill--on { background:#e6f4ea; color:#1f7a3d; }
				.cso-pill--off { background:#f0f0f1; color:#646970; }
				.cso-row--removed { opacity:.45; }
				.cso-row--removed .cso-sum-name { text-decoration: line-through; }
			</style>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cso_save" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<p>
					<label>
						<input type="checkbox" name="cso_enabled" value="1" <?php checked( $enabled ); ?> />
						<strong>Enable cross-sell offers</strong>
					</label><br />
					<span class="description">While unticked this plugin registers nothing on the front end.</span>
				</p>

				<table class="widefat striped cso-table">
					<thead>
						<tr>
							<th style="width:60px">Live</th>
							<th>Offer</th>
							<th>When the basket has</th>
							<th>Offer this</th>
							<th style="width:120px">Shown on</th>
							<th style="width:150px">&nbsp;</th>
						</tr>
					</thead>
					<tbody id="cso-rows">

					<?php foreach ( $offers as $i => $offer ) :
						$new     = empty( $offer['id'] );
						$addons  = cso_offer_addons( $offer );
						$t_prods = array_map( 'absint', (array) $offer['trigger_products'] );
						$t_cats  = array_map( 'absint', (array) $offer['trigger_categories'] );

						$trigger_summary = array();
						foreach ( $t_cats as $id ) {
							$trigger_summary[] = isset( $cat_names[ $id ] ) ? $cat_names[ $id ] . ' (category)' : '#' . $id;
						}
						foreach ( $t_prods as $id ) {
							$trigger_summary[] = isset( $product_names[ $id ] ) ? $product_names[ $id ] : '#' . $id;
						}

						$addon_summary = array();
						foreach ( $addons as $id ) {
							$addon_summary[] = isset( $product_names[ $id ] ) ? $product_names[ $id ] : '#' . $id;
						}
						?>

						<tr class="cso-summary-row cso-offer-block" data-index="<?php echo (int) $i; ?>">
							<td>
								<input type="checkbox" name="cso[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( 'yes', $offer['enabled'] ); ?> />
								<input type="hidden" name="cso[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $offer['id'] ); ?>" />
							</td>
							<td>
								<span class="cso-sum-name">
									<?php echo $new ? '<em class="cso-muted">New offer</em>' : esc_html( $offer['label'] ? $offer['label'] : 'Untitled offer' ); ?>
								</span>
							</td>
							<td class="<?php echo $trigger_summary ? '' : 'cso-muted'; ?>">
								<?php echo $trigger_summary ? esc_html( implode( ', ', $trigger_summary ) ) : '—'; ?>
							</td>
							<td class="<?php echo $addon_summary ? '' : 'cso-muted'; ?>">
								<?php echo $addon_summary ? esc_html( implode( ', ', $addon_summary ) ) : '—'; ?>
							</td>
							<td>
								<?php
								$places = array();
								if ( in_array( 'cart', (array) $offer['placements'], true ) ) {
									$places[] = 'Basket';
								}
								if ( in_array( 'checkout', (array) $offer['placements'], true ) ) {
									$places[] = 'Checkout';
								}
								echo $places
									? '<span class="cso-pill cso-pill--on">' . esc_html( implode( ' + ', $places ) ) . '</span>'
									: '<span class="cso-pill cso-pill--off">Nowhere</span>';
								?>
							</td>
							<td>
								<button type="button" class="button button-small cso-edit">Edit</button>
								<button type="button" class="button button-small button-link-delete cso-remove" style="color:#b32d2e">Remove</button>
							</td>
						</tr>

						<tr class="cso-edit-row" hidden>
							<td colspan="6">
								<table class="form-table" role="presentation">

									<tr>
										<th scope="row">Name</th>
										<td>
											<input type="text" name="cso[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $offer['label'] ); ?>" class="regular-text" placeholder="e.g. Manual Handling with Safe Pass" />
											<p class="description">For your own reference only. Customers never see it.</p>
										</td>
									</tr>

									<tr>
										<th scope="row">When the basket contains…</th>
										<td>
											<p><strong>These products</strong><br />
											<select name="cso[<?php echo (int) $i; ?>][trigger_products][]" multiple size="6" style="min-width:420px">
												<?php foreach ( $products as $p ) : ?>
													<option value="<?php echo esc_attr( $p->get_id() ); ?>" <?php selected( in_array( $p->get_id(), $t_prods, true ) ); ?>>
														<?php echo esc_html( $p->get_name() . ' (#' . $p->get_id() . ')' ); ?>
													</option>
												<?php endforeach; ?>
											</select></p>

											<p><strong>…or anything in these categories</strong><br />
											<select name="cso[<?php echo (int) $i; ?>][trigger_categories][]" multiple size="5" style="min-width:420px">
												<?php foreach ( $cats as $c ) : ?>
													<option value="<?php echo esc_attr( $c->term_id ); ?>" <?php selected( in_array( (int) $c->term_id, $t_cats, true ) ); ?>>
														<?php echo esc_html( $c->name . ' (' . $c->count . ')' ); ?>
													</option>
												<?php endforeach; ?>
											</select></p>
											<p class="description">A category is easier to keep up with — picking Safe Pass covers every venue, including ones you add later.</p>
										</td>
									</tr>

									<tr>
										<th scope="row">…offer these</th>
										<td>
											<select name="cso[<?php echo (int) $i; ?>][addon_products][]" multiple size="8" style="min-width:420px">
												<?php foreach ( $products as $p ) : ?>
													<option value="<?php echo esc_attr( $p->get_id() ); ?>" <?php selected( in_array( $p->get_id(), $addons, true ) ); ?>>
														<?php echo esc_html( $p->get_name() . ' — ' . wp_strip_all_tags( wc_price( $p->get_price() ) ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
											<p class="description">Hold Ctrl (or Cmd) to offer more than one. Several products appear as rows in a single box, each with its own button.</p>
										</td>
									</tr>

									<tr>
										<th scope="row">Show it on</th>
										<td>
											<label><input type="checkbox" name="cso[<?php echo (int) $i; ?>][placements][cart]" value="1" <?php checked( in_array( 'cart', (array) $offer['placements'], true ) ); ?> /> Basket</label><br />
											<label><input type="checkbox" name="cso[<?php echo (int) $i; ?>][placements][checkout]" value="1" <?php checked( in_array( 'checkout', (array) $offer['placements'], true ) ); ?> /> Checkout</label>
											<p class="description">Tick both and it follows them: shown in the basket, and again at checkout if they passed on it. The moment they accept, it stops appearing.</p>
										</td>
									</tr>

									<tr>
										<th scope="row">How many</th>
										<td>
											<label><input type="radio" name="cso[<?php echo (int) $i; ?>][qty_mode]" value="match" <?php checked( 'match', $offer['qty_mode'] ); ?> /> Match the booking — 3 places booked, 3 added</label><br />
											<label><input type="radio" name="cso[<?php echo (int) $i; ?>][qty_mode]" value="one" <?php checked( 'one', $offer['qty_mode'] ); ?> /> Always one</label>
											<p class="description">
												With <em>match</em>, the add-on follows the booking if they change it later — until the customer edits the add-on quantity themselves.
												After that their number wins and the plugin leaves that line alone.
											</p>
										</td>
									</tr>

									<tr>
										<th scope="row">Limits</th>
										<td>
											<label><input type="checkbox" name="cso[<?php echo (int) $i; ?>][cap_to_trigger]" value="1" <?php checked( 'yes', $offer['cap_to_trigger'] ); ?> /> Never allow more than the number of places booked</label>
											<p class="description">Leave off if someone who already holds the main qualification might join just for this.</p>

											<label><input type="checkbox" name="cso[<?php echo (int) $i; ?>][remove_with_trigger]" value="1" <?php checked( 'yes', $offer['remove_with_trigger'] ); ?> /> Remove it if they empty the course out of the basket</label>
										</td>
									</tr>

									<tr>
										<th scope="row">Heading</th>
										<td><input type="text" name="cso[<?php echo (int) $i; ?>][heading]" value="<?php echo esc_attr( $offer['heading'] ); ?>" class="large-text" placeholder="Add Manual Handling on the day — €50" /></td>
									</tr>

									<tr>
										<th scope="row">Wording</th>
										<td>
											<textarea name="cso[<?php echo (int) $i; ?>][text]" rows="2" class="large-text" placeholder="Our trainer can run it at the same venue, right after your Safe Pass. No extra day, no second trip."><?php echo esc_textarea( $offer['text'] ); ?></textarea>
											<p class="description">Say what they get and why it saves them something. One honest sentence beats three excited ones.</p>
										</td>
									</tr>

									<tr>
										<th scope="row">Button</th>
										<td><input type="text" name="cso[<?php echo (int) $i; ?>][button]" value="<?php echo esc_attr( $offer['button'] ); ?>" class="regular-text" placeholder="Add to my booking" /></td>
									</tr>

								</table>
							</td>
						</tr>

					<?php endforeach; ?>

					</tbody>
				</table>

				<p style="margin:14px 0 0">
					<button type="button" class="button button-secondary" id="cso-add-offer">➕ Add another offer</button>
					<span class="description" style="margin-left:8px">There is no limit. Add as many as you like before saving.</span>
				</p>

				<?php submit_button( 'Save offers' ); ?>
				<p class="description">Removing an offer takes effect when you save. Nothing is deleted until then.</p>
			</form>

			<script>
			(function(){
				var tbody = document.getElementById('cso-rows');
				if(!tbody) return;

				// Expand or collapse one offer.
				tbody.addEventListener('click', function(e){
					var edit = e.target.closest('.cso-edit');
					if(edit){
						var row = edit.closest('.cso-summary-row');
						var pane = row.nextElementSibling;
						if(pane && pane.classList.contains('cso-edit-row')){
							pane.hidden = !pane.hidden;
							edit.textContent = pane.hidden ? 'Edit' : 'Close';
						}
						return;
					}

					// Remove. The row is taken out of the form, so when the form is
					// saved the offer simply is not in what was posted, and the
					// stored list is rebuilt without it. Nothing is destroyed until
					// Save, so a mis-click is undone by reloading the page.
					var rm = e.target.closest('.cso-remove');
					if(rm){
						var srow = rm.closest('.cso-summary-row');
						var epane = srow.nextElementSibling;
						var name = srow.querySelector('.cso-sum-name');
						var label = name ? name.textContent.trim() : 'this offer';
						if(!window.confirm('Remove ' + label + '? It will be deleted when you save.')) return;
						if(epane && epane.classList.contains('cso-edit-row')) epane.parentNode.removeChild(epane);
						srow.parentNode.removeChild(srow);
					}
				});

				document.getElementById('cso-add-offer').addEventListener('click', function(){
					var rows = tbody.querySelectorAll('.cso-summary-row');
					var last = rows[rows.length - 1];
					if(!last) return;
					var lastPane = last.nextElementSibling;

					var next = 0;
					rows.forEach(function(r){ next = Math.max(next, parseInt(r.dataset.index, 10) + 1); });

					// Clone summary row and its editing pane together, then renumber
					// every field. Cloning rather than building from scratch means
					// this keeps working when fields are added later.
					var newRow  = last.cloneNode(true);
					var newPane = lastPane ? lastPane.cloneNode(true) : null;
					newRow.dataset.index = next;

					[newRow, newPane].forEach(function(el){
						if(!el) return;
						el.querySelectorAll('[name]').forEach(function(f){
							f.name = f.name.replace(/^cso\[\d+\]/, 'cso[' + next + ']');
						});
						el.querySelectorAll('input[type=text], textarea').forEach(function(f){ f.value = ''; });
						el.querySelectorAll('input[type=hidden]').forEach(function(f){ f.value = ''; });
						el.querySelectorAll('select').forEach(function(sel){
							Array.prototype.forEach.call(sel.options, function(o){ o.selected = false; });
						});
						el.querySelectorAll('input[type=checkbox]').forEach(function(c){
							c.checked = /\[(enabled|remove_with_trigger)\]|\[placements\]\[cart\]/.test(c.name);
						});
						el.querySelectorAll('input[type=radio]').forEach(function(r){
							r.checked = /\[qty_mode\]$/.test(r.name) && r.value === 'match';
						});
					});

					if(newPane) newPane.hidden = false;   // open it, ready to fill in
					var editBtn = newRow.querySelector('.cso-edit');
					if(editBtn) editBtn.textContent = 'Close';

					var nameCell = newRow.querySelector('.cso-sum-name');
					if(nameCell) nameCell.innerHTML = '<em class="cso-muted">New offer</em>';
					newRow.querySelectorAll('td').forEach(function(td, idx){
						if(idx === 2 || idx === 3){ td.textContent = '—'; td.classList.add('cso-muted'); }
					});

					tbody.appendChild(newRow);
					if(newPane) tbody.appendChild(newPane);
					newRow.scrollIntoView({block:'center', behavior:'smooth'});
				});
			})();
			</script>

			<hr />
			<h2>About</h2>
			<table class="widefat striped" style="max-width:720px"><tbody>
				<tr><td style="width:150px"><strong>Version</strong></td><td><?php echo esc_html( CSO_VERSION ); ?></td></tr>
				<tr><td><strong>Author</strong></td><td><a href="<?php echo esc_url( CSO_AUTHOR_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( CSO_AUTHOR ); ?></a></td></tr>
				<tr><td><strong>Project</strong></td><td><a href="<?php echo esc_url( CSO_PROJECT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( CSO_PROJECT_URL ); ?></a></td></tr>
				<tr><td><strong>Licence</strong></td><td>GPL-2.0-or-later</td></tr>
			</tbody></table>
		</div>
		<?php
	}
}
