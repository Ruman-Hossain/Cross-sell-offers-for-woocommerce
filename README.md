# Cross-Sell Offers for WooCommerce

One-click cross-sells on the WooCommerce basket and checkout, with add-on
quantity that matches the booking. Every pairing is chosen by hand.

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-6.0%2B-96588a.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](LICENSE.txt)

## What it does

WooCommerce already has a "Cross-sells" field that displays linked products on
the cart page. This is different: the customer adds the product in one click
without leaving the page, and the quantity matches what they are already buying.

You create **offers**. An offer says: when these products are in the basket,
show a box inviting the customer to add that product too.

Nothing is automatic. No offer applies to a product you have not named, and the
plugin never guesses relationships from categories, tags or past orders.

It was built for a course provider offering an on-site Manual Handling course
alongside a Safe Pass booking, where the number of people is the same in most
cases and different in some.

## Features

- **Triggers** on specific products, or on whole product categories
- **One or several add-ons** per offer — several appear as rows in one box,
  not as separate boxes stacked up
- **Placement** on the basket, the checkout, or both; accepted in one place, it
  stops appearing in the other
- **Quantity that follows the booking** — or a flat quantity of one, per offer
- **Hands off after a manual edit** — the moment the customer changes the add-on
  quantity themselves, the plugin stops managing that line
- **Optional cap** at the number of places booked
- **Optional removal** of the add-on when the trigger product leaves the basket

## The quantity rule

Book three places and accept the offer, and three are added. Change the booking
to five and the add-on becomes five. Type your own number into the add-on and
the plugin steps back permanently — three places with two add-ons works, and so
does three with five, because someone who already holds the main qualification
may still want the add-on.

Set an offer's quantity mode to `one` instead and it adds a single unit however
many places are booked, then leaves that line alone — the customer is free to
change it. The optional cap and the optional removal with the trigger still apply.

## Requirements

| | |
|---|---|
| WordPress | 5.8 or later |
| PHP | 7.4 or later |
| WooCommerce | 6.0 or later (tested to 11.0) |
| Cart & checkout | Classic shortcode-based |

## Installation

1. Download this repository as a ZIP, or clone it into `wp-content/plugins/`.
2. Activate **Cross-Sell Offers for WooCommerce** in **Plugins**.
3. Go to **WooCommerce → Cross-Sell Offers**.
4. Build an offer, then turn on both its switch and the master switch at the top.

The plugin does nothing at all until the master switch is on, and an offer does
nothing until its own switch is on.

## Usage

All offers live on one screen and are saved together, so there is no separate
add/edit/delete flow. A blank block at the bottom is how you create the next
one; clearing an offer's add-on product is how you delete it.

Each offer has:

| Setting | What it does |
|---|---|
| Label | Admin-only name, so a long list stays readable |
| Trigger products / categories | What must be in the basket for the box to show |
| Add-on products | What the box offers — one or several |
| Placement | `cart`, `checkout`, or both |
| Quantity mode | `match` follows the booking, `one` always adds a single unit |
| Cap to trigger | Never let the add-on exceed the places booked |
| Remove with trigger | Drop the add-on when the trigger product leaves |
| Heading / text / button | The wording customers see |

An offer is ignored unless it has at least one add-on product **and** at least
one trigger. Incomplete offers are skipped rather than guessed at.

Access requires the `manage_woocommerce` capability.

## Design notes

- **Off by default.** A master switch plus a per-offer switch, both off.
- **No database tables.** Offers live in a single option row (`cso_offers`),
  with the master switch in `cso_enabled`.
- **It only touches its own lines.** Cart lines the plugin added are marked, and
  it never modifies a line it did not add.
- **It stops when the customer takes over.** A manual quantity edit on an add-on
  line ends the plugin's management of that line for the rest of the session.
- **Stored offers are merged over defaults**, so a key missing after an upgrade
  cannot cause a fatal error.
- **Clean uninstall.** Deleting the plugin removes its two option rows and
  nothing else — orders and products are untouched.

## Compatibility

- **HPOS (High-Performance Order Storage):** compatible, declared.
- **Cart & checkout blocks:** declared *not* supported, deliberately, so
  WooCommerce warns you rather than the box silently vanishing. Use the classic
  cart and checkout.

## Project structure

```
cross-sell-offers-for-woocommerce.php   Bootstrap, constants, shared helpers
includes/class-cso-settings.php         Admin screen (WooCommerce → Cross-Sell Offers)
includes/class-cso-cart.php             Cart syncing, quantity rules, add handling
includes/class-cso-display.php          Offer boxes, AJAX add, styles
uninstall.php                           Removes the plugin's two options
```

## Support

Please open an issue on the
[project's tracker](https://github.com/Ruman-Hossain/Cross-sell-offers-for-woocommerce/issues).

## Author

Built and maintained by **Md Ruman Hossain**, Rangpur, Bangladesh.

- Website: <https://rumancsebrur.blogspot.com/>
- GitHub: [@Ruman-Hossain](https://github.com/Ruman-Hossain)
- LinkedIn: [ruman-hossain](https://www.linkedin.com/in/ruman-hossain/)
- WordPress.org: [rumanhossain](https://profiles.wordpress.org/rumanhossain/)

## License

GPL-2.0-or-later. See [LICENSE.txt](LICENSE.txt).
