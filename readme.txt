=== Cross-Sell Offers for WooCommerce ===
Contributors: rumanhossain
Tags: woocommerce, cross-sell, upsell, order bump, cart
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-click cross-sells on the basket and checkout, with quantity that matches the booking. Every pairing is chosen by hand.

== Description ==

WooCommerce already has a "Cross-sells" field that displays linked products on
the cart page. This is different: the customer adds the product in one click,
without leaving the page, and the quantity matches what they are already buying.

You create "offers". An offer says: when these products are in the basket, show
a box inviting the customer to add that product too.

Nothing is automatic. No offer applies to a product you have not named, and the
plugin never guesses relationships from categories, tags or past orders.

Built for a course provider offering an on-site Manual Handling course alongside
a Safe Pass booking, where the number of people is the same in most cases and
different in some.

* Trigger on specific products, or on whole categories
* Offer one product, or several — several appear as rows in one box, not as
  separate boxes stacked up
* Show the offer on the basket, the checkout, or both
* Accepted in one place, it stops appearing in the other
* Add-on quantity matches the booking, and follows it if the booking changes
* The moment the customer edits the add-on quantity themselves, the plugin
  stops touching that line
* Optional cap at the number of places booked, and optional removal when the
  main course leaves the basket

== The quantity rule ==

Book three places and accept the offer, and three are added. Change the booking
to five and the add-on becomes five. Type your own number into the add-on and
the plugin steps back permanently — three places with two add-ons works, and so
does three with five, because someone who already holds the main qualification
may still want the add-on.

== Author ==

Built and maintained by **Md Ruman Hossain**, Rangpur, Bangladesh.

* Website: https://rumancsebrur.blogspot.com/
* GitHub: https://github.com/Ruman-Hossain
* LinkedIn: https://www.linkedin.com/in/ruman-hossain/
* WordPress.org: https://profiles.wordpress.org/rumanhossain/

Project: https://github.com/Ruman-Hossain/Cross-sell-offers-for-woocommerce

== Support ==

Please open an issue on the project's tracker:
https://github.com/Ruman-Hossain/Cross-sell-offers-for-woocommerce/issues

== Compatibility ==

* WooCommerce 6.0 and later, tested to 11.0.
* High-Performance Order Storage (HPOS): compatible, declared.
* Classic cart and checkout. The block-based cart and checkout are declared
  *not* supported, so WooCommerce warns you rather than the box vanishing.

== Changelog ==

= 1.0.0 =
* First release.
