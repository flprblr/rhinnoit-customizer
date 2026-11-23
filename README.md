# Customizer

Private WordPress plugin by RHINNO IT that adjusts the WooCommerce cart,
checkout, orders and store pickup. Built from `wp-plugin-template`, which
carries the structure already solved (bootstrap, settings, assets, i18n,
linters), so only the functional part is written here.

Requires **PHP 8.3+**, WordPress 6.5+ and WooCommerce 8.0+. It activates on
any site, but everything it does needs WooCommerce: without a supported one it
draws its screen with the master switch alone and does nothing else.

It was split out of **RHINNO IT - WP Optimizer** 2.8.13, together with
**RHINNO IT - Optimizer**, which took the delivery and page cache half. See
[Migrating from WP Optimizer](#migrating-from-wp-optimizer). It also
replaces four older plugins of the owner's with features of its own: the
RUT check, the birth date field and two shipping rules by commune. See
[Replacing the old plugins](#replacing-the-old-plugins).

## Where the plugins live

Siblings, each its own repository:

```
~/gitlab/ce/
├── wp-plugin-template/           the template this came from
├── rhinnoit-customizer/          this repository
├── rhinnoit-optimizer/           the other half of WP Optimizer
└── rhinnoit-wholesale-pricing/
```

Not a `plugins/` directory inside this one. Nesting them would give every
plugin a shared git history, so a fix to one shows up in the log of all;
`composer.json` and `phpunit.xml.dist` would nest inside each other, which
neither tool handles cleanly; one CI run would cover unrelated plugins and go
red for a change that has nothing to do with them; and tagging `v1.2.0` would
be ambiguous about which plugin it refers to, which breaks any update checker
reading releases.

Keep the built zips in each plugin's own `dist/`, which `composer dist`
regenerates in a second. Committing zips means storing a binary that goes stale
the moment the source changes.

The first commit of this repository is the template at `634cb2b` as
`bin/new-plugin.sh` copied it, before any change of this plugin's own: the
fork point a resync from the template merges against. The `upstream` remote
points at the template, with push disabled.

## Layout

```
rhinnoit-customizer.php         Header, constants, bootstrap hooks
uninstall.php                   Option cleanup when the plugin is deleted
includes/
├── autoload.php                Namespace to file autoloader
├── class-plugin.php            Container: the screen always, every service where WooCommerce is supported
├── class-install.php           Default options on activation and update, the copy of WP Optimizer's, a shipping refresh on (de)activation
├── class-settings.php          Declaration and reading of the options
├── class-assets.php            build/ enqueueing (the admin script and style), and WooCommerce's selectWoo beside it
├── class-logger.php            wc_get_logger() wrapper, PHP's error log without it; throttled write
├── frontend/                   The WooCommerce half: every service
│   ├── class-widget-thumbnail.php      A srcset for Porto's product widget thumbnails
│   ├── class-widget-sale-price.php     The listing's sale color in Porto's product widgets
│   ├── class-birthdate-style.php       The checkout's birth date field as tall as Porto's others
│   ├── class-express-checkout.php      Skips the cart after an add
│   ├── class-checkout-focus.php        Hides the checkout's coupon and login prompts
│   ├── class-commune-select.php        Chile's communes as a dropdown on the checkout
│   ├── class-rut-validation.php        Formats and checks the RUT on the checkout
│   ├── class-cart-sale-price.php       Regular price struck through in the cart
│   ├── class-coupon-split.php          Splits a fixed cart coupon by each line's price
│   ├── class-shipping-block.php        Hides chosen shipping methods for chosen communes
│   ├── class-shipping-exclusion.php    Stops deliveries to chosen communes, store pickup kept
│   ├── class-inventory-links.php       Keeps WeLaunch's store out of links and cached pages
│   ├── class-quick-view-store.php      Sends the store picked in Porto's quick view
│   ├── class-size-stores.php           Each size's stores, in the page before WeLaunch answers
│   ├── class-store-choice.php          Leaves every location unticked until the shopper picks one
│   ├── class-pickup-stores.php         Hides the stores without the size picked
│   ├── class-store-stock-hold.php      Holds a store's units while an order is paid
│   ├── class-unpaid-orders.php         Checks for unpaid orders every 2 minutes
│   ├── class-fintoc-session-expiry.php Ends Fintoc's payment page with the stock hold
│   ├── class-cart-state.php            Shared "nothing to ship" check, not a service
│   └── class-shipping-communes.php     What the two shipping rules share, not a service
└── admin/
    ├── class-menu.php          Shared node, its place in the menu, the page
    ├── class-settings-page.php The settings form: drawn, sanitized and saved
    └── class-plugin-links.php  "Settings" link on the plugins list
src/                            JS/SCSS sources (one entry point, admin: the scss draws the screen)
build/                          @wordpress/scripts output (not committed)
languages/                      .pot, and the es_CL and es_ES catalogues
tests/                          PHPUnit suite with WordPress stubbed
└── stubs/
    ├── woocommerce.php         WooCommerce, loaded only where WC_VERSION is defined
    ├── woocommerce-core/       WooCommerce 11.2.2's WC_Discounts and NumberUtil, the real coupon split
    ├── html-api/               WordPress 7.1.2's HTML API, the real parser Widget_Thumbnail uses
    └── porto.php               The slice of Porto the plugin touches
bin/release.sh                  Every check, then the zip
bin/build-zip.sh                Packages dist/<slug>-<version>.zip
phpstan-bootstrap.php           Plugin constants and Porto's srcset trim, for the static analyser only
.github/workflows/ci.yml        Lint, static analysis and tests on every push
```

Every class is registered from `Plugin::__construct()`. To add functionality:
create the class in the matching folder with a `register()` method that only
hooks, and add one line to the constructor.

The constructor has two halves. The WordPress half registers on every site and
touches WooCommerce only behind a guard: here it is `Assets` and the admin
classes, which draw the screen, and nothing that acts on the shop. Every
service of this plugin needs WooCommerce, so each one lives in
`includes/frontend/` and its line goes in the
`if ( self::woocommerce_is_supported() )` branch; a new one goes there too. An
unguarded WooCommerce call registered anywhere else is a fatal, on a site
without WooCommerce, on every request that reaches it.
`BootWithoutWooCommerceTest` runs with no WooCommerce function defined at all,
but it only boots the plugin: it catches a call made while booting (a
constructor or a `register()`), not one inside a callback, which is why the
services stay inside that branch.

The order of the lines matters once. `Inventory_Links`, `Quick_View_Store` and
`Size_Stores` add their scripts to WeLaunch's own handle at priority 99, where
they print in the order they register, and `Store_Choice` adds its own at 100,
which ticks from the radios the `Size_Stores` script sets.
`BootWithWeLaunchTest` pins that order.

Each feature reads its own switch in `register()` through
`Settings::feature( 'key' )`, which answers no for every feature while the
**Enable** checkbox is off. A feature that hooks without asking keeps working
with the box unchecked - which turns the first question support asks ("uncheck
it and tell me if the checkout still breaks") into a false negative. `Assets` is
the exception: it registers either way, because the admin bundle has to load
with the plugin off.

Nothing here names a class, constant or function of RHINNO IT - Optimizer.
PHP evaluates every class constant on the first `new`, so one constant built
from another plugin's class is a "Class not found" fatal on every request of
every shop, admin included. What both plugins need is copied instead:
`Inventory_Links::CART_COOKIE` and `Inventory_Links::SESSION_COOKIE` hold
WooCommerce's own cookie names, and `Inventory_Links::CART_PAGES` the pages a
cache keeps only without a session (cart, checkout, my account), the same
three the Optimizer's `Shop_Prefetch::PAGES` lists. Keep the two lists in step
by hand.

## What it does

Three cards of the settings screen, one per domain - WooCommerce, Porto and
WeLaunch Multi Inventory - and **one switch per feature**, so the one that
misbehaves on a site can be turned off and the rest kept. A master switch
above them, **Enable**, turns everything off at once. Each feature decides
from the shop's own state at that moment - the cart's lines, the size picked,
each store's numbers, the theme that is active - and steps aside where what it
extends is missing.

The screen only offers what can act on the site. A card is drawn only where
the site has what it works on (a supported WooCommerce, Porto, WeLaunch Multi
Inventory), and a switch only where what it needs is installed: Fintoc for the
Fintoc switch, WooCommerce 8.8+ for the coupon split, Porto for the quick view
store. Read from what is installed, never from another plugin's settings. A
hidden switch keeps its stored value and the feature still reads it.

Every switch ships on except the four that change the shopper's journey: both
skip-the-cart switches and hiding the checkout's coupon and login prompts
(owner's decision, 2026-10-16: the plugin goes on every client with its
defaults). The master switch ships on too. The two shipping rules ship on with
their lists empty, so they act only once the owner picks what they act on.
Defaults apply to a fresh install and to a switch added by an update; a site
that already saved the screen keeps its choices, and a site that ran WP
Optimizer starts from the choices it made there.

The versions in the rows below are WP Optimizer's releases, where these
features were written and measured, unless a row names this plugin. This
plugin starts at 2.0.0, with the code of WP Optimizer 2.8.13. The RUT check, the birth date field and the two shipping rules
are its own, built on the ideas of older plugins (see *Replacing
the old plugins*).

**WooCommerce** - shown only when WooCommerce 8.0+ is active:

| Switch | Default | What it does |
| --- | --- | --- |
| Skip the cart for virtual products | **off** | Goes straight to checkout when, after the add, every item in the cart is virtual. Decided with the real cart, so a physical product added from a virtual product's page or quick view keeps the cart page. |
| Skip the cart for any product | **off** | Goes straight to checkout after any add, physical products included. |
| Show the regular price struck through | on, by the owner's decision | Listings and the product page print a sale as the regular price struck through and the sale price; the mini cart, the cart and the checkout printed the sale price alone. Porto prints those lines through `woocommerce_cart_item_price` (mini cart, cart price column) and `woocommerce_cart_item_subtotal` (cart subtotal column, checkout), so at priority 20 the regular price, through the cart's tax display and for the line's quantity, goes before WooCommerce's own price HTML with `wc_format_sale_price()`: the product page's markup, screen-reader text and styles. Nothing changes when the regular price is not higher, when a `<del>` is already there, or when Porto's catalog mode has emptied the price (priority 100). Porto styles a struck price only inside `.price` or the cart table's cells, so the markup is wrapped in `span.rwpo-sale-price` (WP Optimizer's class, kept: see *Keys that keep WP Optimizer's spelling*) and an inline style with no file (every page: the mini cart is in the header) gives `del` Porto's own `.price del` look (`#a7a7a7`, `0.8em`) and lets both amounts inherit their `del`/`ins` color past Porto's mini cart `!important` gray; the red is the shop's own `ins` rule. Measured on hoka's live CSS: mini cart, cart and checkout then match the listing. |
| Split cart coupons by price | on | WooCommerce 8.8+. WooCommerce splits a fixed cart coupon evenly per unit (`WC_Discounts::apply_coupon_fixed_cart()`), capping each line at its price, so a cheap line can reach the order at 0: 10,000 off 179,990 shoes and 4,990 socks took 5,010 and 4,990 on hoka. Last on `woocommerce_coupon_get_items_to_apply`, which WooCommerce passes the items it is about to discount and itself, the coupon is split by each line's price (what earlier coupons left of it with "Calculate coupon discounts sequentially" on), in whole cents: floors, then the cents left to the largest remainders, so the lines add up to the coupon; a line takes at most what is left of it and the rest goes to the others by price. Shoes 9,730, socks 270. The split goes into WooCommerce's own table, the protected `WC_Discounts::$discounts`, and the filter returns no items, so WooCommerce's split has nothing left to do; the cart, both checkouts and an order whose coupons are applied, removed or recalculated in the admin all total through it. Orders already placed keep their totals. Percent and fixed product coupons are left alone. In the cart each share is sent through WooCommerce's legacy `woocommerce_coupon_get_discount_amount`, as WooCommerce sends its own; if a plugin changes any, WooCommerce splits the coupon its own way and the log says so once a day. Hidden where WooCommerce is older than 8.8 or the table is gone. |
| Hide the coupon prompt | **off** | On a checkout where every item is virtual. Classic checkout only. |
| Hide the login prompt | **off** | Same condition. Classic checkout only. |
| Pick the commune from a list | on | Classic checkout only, for an address in Chile. WooCommerce draws the region as a dropdown and the commune (the city field) as free text, so one commune reached the orders spelt many ways. Once the country is Chile and every region the region dropdown offers is one of WooCommerce's 16 codes, a script printed after `wc-checkout` swaps the text box for a dropdown of that region's communes (`Commune_Select::COMMUNES`, 346 in all, the list both shipping rules pick their communes from), enhanced by selectWoo like the region. It keeps the field's id, name and autocomplete, so the label, the Checkout Field Editor's settings and the order are unchanged: the order gets the commune's name. A saved value is picked when it names a commune of the region, ignoring case and accents, otherwise the shopper chooses; changing the region rebuilds the list and another country gives the text box back. A region dropdown with codes of its own (a plugin redefining Chile's regions: hoka's shipping zones still name `CL_RM` and others) keeps the text box, which an empty list would have made impossible to fill. Nothing is validated on the server, so without the script the text box stays. |
| Check the RUT at checkout | on | Classic checkout only, and only where the checkout has a RUT field: `billing_rut`, with the Chilean/foreigner radio `billing_is_chilean` beside it, both added by another plugin; this one adds neither, and never ticks the radio for the shopper. What RHINNOIT - WooCommerce Validación RUT did, rebuilt (see *Replacing the old plugins*). A foreigner has no RUT, so the requirement stops being WooCommerce's: at `woocommerce_checkout_fields` 20, after the plugin that adds the field, its `required` flag and its `validate-required` class (which WooCommerce's script checks) go, everything else that plugin set stays, and what the flag said stays on the input as `data-rcus-required`. The radio is read as the old plugin read it, accents and case aside: chilean, chilena, chileno, 1, true, si, or anything containing "chile" is a Chilean; foreigner, extranjero, extranjera, 0, false, no, or anything containing "extra" a foreigner. On the submit, `woocommerce_checkout_posted_data` empties a foreigner's RUT, and `woocommerce_after_checkout_validation` refuses a missing RUT - always of a Chilean, never of a foreigner, and with no choice or no radio as the field's own flag said - and one that is not valid, each with its own message and the field's id, so WooCommerce links the notice to the field (11.2 also shows it under the field). Valid is `Rut_Validation::is_valid()`: stripped to digits and K (a lowercase k counts), 8 or 9 characters, a body of 7 or 8 digits that is neither all zeros nor eight repeated digits, and Chile's modulo 11 check digit (11 is 0, 10 is K). A script after `wc-checkout`, added at `wp_enqueue_scripts` 100 once WooCommerce has enqueued it, formats the field as 12.345.678-9 while it is typed, the caret kept in place; checks it when it loses focus and on `checkout_place_order`, which it stops, ahead of any later handler, with the message under the field in WooCommerce's own inline error markup; disables and empties the field for a foreigner and gives it back for anyone else; shows the required mark while the RUT is required, since WooCommerce now draws the field as optional; and runs again on `updated_checkout`. An inline style with no file marks the field in error, whichever side found it (WooCommerce's red where its stylesheet loads, plain red on Porto, which styles no invalid field), and the field turned off. Without the script the server refuses what the script would. The old plugin's server stripped a lowercase k before upper-casing it, read its own relaxed flag when no choice was made, so the RUT was never required then, and counted a K in the body as a 0; none of that is kept. In a jsdom harness running WordPress's jQuery 3.7.1 and WooCommerce 11.2.2's own checkout script: the formatting, the caret, both messages, the stopped submit (a gateway handler bound later did not let it through), the foreigner's field turned off and back, one submit check after two updates, a checkout without the field left alone, and a server refusal shown once under the field. |
| Hide shipping methods in some communes | on, inert until both lists hold something | For a same-day promise the courier keeps in a city's central communes only: what YNK Bloqueo Despacho Express por Comuna did with one instance id and 19 communes written in its code (see *Replacing the old plugins*). **Shipping methods to hide** lists every method of every zone as *zone: method* (the zone for the locations no other zone covers last, a disabled method marked), stored by instance id, so renaming a method changes nothing and the same kind of method in another zone stays; **Communes where they are hidden** offers Chile's 346 communes, grouped under WooCommerce's names for the regions. For a package whose commune matches (see below), the chosen instances' rates are taken off `woocommerce_package_rates` at `PHP_INT_MAX`, after the couriers' plugins add theirs: the cart and both checkouts lose them, and every other rate stays. While a package's commune matches and its zone has one of the methods enabled, **Message for those communes** (by default, that express delivery is not available for the commune and to choose another method) shows as its own row under the shipping options of the classic cart's totals and the classic checkout's order review (`woocommerce_cart_totals_after_shipping`, `woocommerce_review_order_after_shipping`), once, escaped, its line breaks kept; an empty message shows nothing, and the methods still go. A hidden method still in the rates WooCommerce stored before the rule changed, and chosen, is refused at the classic checkout's submit (`woocommerce_after_checkout_validation`) with the message, or a sentence of the plugin's when the message is empty. So is a hidden method the submit still names - a checkout drawn before the rule covered the commune, or sent before its review caught up with the address - once it is gone from the rates WooCommerce works out again for the submit: WooCommerce alone would move that package to its first remaining rate and place the order on it, without a word. That refusal also has the review drawn again (`refresh_totals`), without the method. A package left with no method at all is WooCommerce's to refuse, with its own "No shipping method has been selected": the rule adds no notice to it and only has the review drawn again, so it stops offering what is gone; however many packages lose the method, the order is refused once. Whether the rule took a posted method is read from its rate id, *method:instance* or *method:instance:suffix*, so it holds when an earlier request already worked the rates out: the instance has to be a chosen one and the method that instance's own, read from the instance's zone row in WooCommerce's data store (`WC_Shipping_Zone_Data_Store::get_method()`) only then, at the submit - never through `WC_Shipping_Zones::get_shipping_method()`, which builds the method's class and is a fatal when a shipping plugin registered a class it never defines. A method outside every zone builds ids of the same shape with a number of its own, as the block checkout's `pickup_location:N` does with a location; such an id, like any other, counts only when the rule took that very id in the same request. |
| Stop deliveries to some communes | on, inert until its list holds something | For when the courier stops delivering somewhere, a disaster zone for instance: what YNK - Exclusión de envío en comunas did with ten places and a message written in its code, without its removal of store pickup (see *Replacing the old plugins*). For a package whose commune is one of **Communes without delivery** (the same 346), every rate but WooCommerce's pickup ones (method ids `local_pickup` and `pickup_location`, `Shipping_Exclusion::PICKUP`) is taken off `woocommerce_package_rates` at `PHP_INT_MAX`, whatever zone or courier offers it, in the cart and both checkouts; a pickup method another plugin registers under an id of its own goes like any delivery. With pickup left, **Message for those communes** (by default, that the shop cannot deliver to the commune for now, and to check the address or get in touch) shows as its own row under the shipping options, as above; with nothing left, it replaces WooCommerce's own "no shipping options" text in that package (`woocommerce_no_shipping_available_html` on the classic checkout, `woocommerce_cart_no_shipping_available_html` in the classic cart) and no row is printed, so it shows once. An empty message prints nothing and leaves WooCommerce's text. A delivery still in stored rates, and chosen, is refused at the classic checkout's submit with the message, and so is a delivery the submit still names once it is gone from the rates while a pickup is left: WooCommerce alone would move the package to the first rate left, a store the shopper never picked, and place the order at no shipping cost. That refusal has the review drawn again, pickup only. With nothing left WooCommerce refuses the order itself ("No shipping method has been selected"): the rule adds no notice to it and only has the review drawn again, so it shows the message in place of WooCommerce's text; however many packages ship to the communes, the order is refused once. A posted rate is a delivery unless its method id, before the first colon of its rate id, is one of the pickup ones, matched whole, so a posted pickup goes through and another plugin's, `local_pickup_plus:3` say, does not. |
| Cancel unpaid orders on time | on | WooCommerce looks for unpaid orders once per Hold stock period N and cancels checkout orders pending since their last update + N, so one can wait almost 2N. With N of 15 minutes or more, `woocommerce_cancel_unpaid_orders_interval_minutes` returns 2, which all three places that schedule WooCommerce's job read (after each run, the 03:00 Action Scheduler repair, a Products > Inventory save). Below 15 it steps aside: Webpay's window is about 9-10 minutes, and a payment confirmed after the cancellation reopens the order. On the classic checkout stock and coupons do not change: a pending order holds neither, and the reservation expires at N anyway; the block checkout counts a coupon as used from pending until the cancellation. What comes sooner is the Cancelled status, the admin email and the end of the Pay button. Replaces a must-use file that queried Action Scheduler on every uncached request, ran the cancellation twice and left a 5-minute recurring job that outlives it; that job stops once WooCommerce's own check runs first (this switch on with N of 15 or more) or on a Products > Inventory save. Nothing is scheduled here, so turning it off needs no cleanup. |
| Avoid late Fintoc payments | on | Fintoc for WooCommerce 1.2.4 sends no `expires_at`, so each Checkout Session stays payable for 24 hours, and a late payment completes a cancelled or failed order through `payment_complete()` after its units were freed. `woocommerce_fintoc_checkout_session_payload` gets `expires_at` = the order's creation + Hold stock, or now + 11 minutes if later (Fintoc's minimum is 10); Fintoc then cancels the unpaid order itself on `checkout_session.expired`. Steps aside when the payload already has an expiry, stock is not managed or Hold stock is 0. Fintoc cancels on the expiry of any of the order's sessions, so a retry's session can stay payable up to 11 minutes after the first one cancelled the order. |

**Porto** - shown only with WooCommerce and the Porto theme (a child theme included):

| Switch | Default | What it does |
| --- | --- | --- |
| Show sharp thumbnails | on | Porto's `porto_widget_product_thumbnail()` (the products beside a product page, and any product widget) builds its `<img>` by hand from `wp_get_attachment_image_src( $id, 'widget-thumb-medium' )`: an 85-pixel square with no `srcset`, so a Retina screen or a phone stretches it and it looks blurred. The URL each `widget-thumb-medium` lookup returns is remembered with its attachment (`wp_get_attachment_image_src`, priority 999, after any URL rewriter, at most 200 per request), and where Porto passes the tag through `porto_lazy_load_images` (priority 5, before its own lazy load at 10) WordPress's own `wp_get_attachment_image_srcset()` and `sizes="85px"` are added. Porto trims every srcset on `wp_calculate_image_srcset` to copies from 320 px up to the box (`porto_image_srcset_filter_sizes`), which for an 85 px box leaves none, so that trim is taken off for this one call and put back at its own priority (2.7.11 added nothing because of it). Copies narrower than 1.5 times the box are left out: the 85-pixel one looked soft even on the owner's 1x monitor (the CDN serves it as a 664-byte WebP, shrunk to the 84 px box), so a 1x screen takes the 150 (1.4 KB in WebP) and shrinks it, and a Retina one the 400 (about 5 KB). Images not looked up here, or that already have a `srcset`, are left alone. Porto only, asked for when the tag is printed: plugins boot before the theme's `functions.php` (2.7.10 asked in `register()` and hooked nothing). |
| Match the sale price color | on, by the owner's decision | Porto's `.product_list_widget .product-details .amount` gives every amount in its product widgets the theme's price color, so the sale price under `ins` lost the shop's own `ins` color (hoka's custom CSS: `#DC3545`) that the listings show. An inline style with no file, `.product_list_widget .product-details ins .woocommerce-Price-amount.amount{color:inherit}`, lets that amount take its `ins`'s color, and `:where(.product_list_widget .product-details ins){color:var(--porto-color-price)}` gives the `ins` a fallback that weighs nothing, so any `ins` rule of the shop's wins and a shop without one keeps Porto's price color. The struck price stays gray, and the mini cart keeps its own `!important` gray. Porto only, asked for when the style is added (see above). |
| Match the birth date field | on | Porto only, classic checkout only, and only where the checkout has a birth date field: `billing_birthdate`, a date input another plugin adds; this one only styles it. Porto 7.10's checkout stylesheet (`woopage.css`) gives the checkout's text, email, phone and password inputs a 30px line height, 48px tall with Porto's padding and border, and leaves the date input out, so the birth date field came out shorter than the fields around it. What RHINNOIT - WooCommerce Fecha Nacimiento did, on Porto only (see *Replacing the old plugins*): an inline style with no file, added on `wp_enqueue_scripts` on the checkout only, makes `#billing_birthdate[type="date"]` 48px tall, with Porto's input border (`--porto-input-bc`), 12px 16px of padding, the inherited font and no native look, and puts its calendar icon (`::-webkit-calendar-picker-indicator`) at its right, centered, inside the field's wrapper, which becomes the icon's positioning box. Asked through `get_template()` when the plugin boots, the same answer the Porto card's mark gives: off Porto the border color has no value, and the field would lose its border. |

**WeLaunch Multi Inventory** - shown only with WooCommerce and WeLaunch Multi Inventory active:

| Switch | Default | What it does |
| --- | --- | --- |
| Keep the quick view's store choice | on | WeLaunch only, shown and run only on Porto. Porto's quick view adds to the cart over admin-ajax with only the product, quantity and attributes, so a pickup store ticked there was lost and the line went in under the online store. A listener on `adding_to_cart`, which hands over the object Porto is about to post, adds the store ticked in the same form (`:checked:enabled`). WooCommerce's listing buttons fire the same event outside any form and are left alone. |
| Remove the store from links | on | WeLaunch only. Every add to cart sets its store cookie for a year, and from then on its script appends `?inventory=<store>` to every link, which Kinsta never caches: every page a returning shopper opened was a render, and none could be prefetched. This sets WeLaunch's own `disableStateReplace` option before its script reads it, and on a request the cache may keep (a GET, no cart, not logged in, no `?inventory=` or `?add-to-cart=`, and the cart, checkout and account pages only without a WooCommerce session, since Kinsta caches those too) sets the cookie aside on `init`, before WeLaunch reads it, so the page is the default store's, as a first visit gets it, and never one shopper's store served to all. Add to cart, the cart, checkout and WeLaunch's AJAX still see the cookie. A script after WeLaunch's keeps the store the owner wants ticked after each of WeLaunch's stock answers: the shopper's own click while that store has stock, else - without a cart - the default (online) store, never a pickup store remembered from an old order; with a cart the cart's store stays, because WeLaunch (one store per cart) empties the cart of other stores' lines on the next add. When the ticked store is not the one WeLaunch last checked, WeLaunch's own change handler runs again, at most twice per size pick (2.7.3 instead re-validated the remembered pickup store, which left it ticked by default). That script is left out while the next switch is on, which decides the ticked store itself from WeLaunch's stock for the size: the two would each re-run WeLaunch's check for a store the other then unticks. For shops that let the shopper pick the store on the product page. |
| Let the shopper choose | on | WeLaunch Multi Inventory only (owner's decision, 2026-10-06: no store is ticked but by the shopper). WeLaunch ticks a location each time a size is picked - its cookie's store, or the online store - and with nothing ticked files the line under its cookie's store. After every AJAX request this unticks every location but the one the shopper clicked on the page (while still enabled), and ticks that click again when one of WeLaunch's answers ticked its own store in the same group (2.8.6 to 2.8.8 lost the click, and the shopper was asked to choose again); the next switch may then tick home delivery. WeLaunch enables the add button and writes the stock line for the location its `woocommerce_multi_inventory_get_stock` check asked about - the ticked one, else its cookie's, else none, answered with the store the server chose - so when the location left ticked is another, WeLaunch's own change handler runs again, at most twice per size pick. A capture-phase click/submit listener stops the add to cart (Porto's AJAX add and the quick view included) while the form has enabled locations and none ticked, with a line above them; an add from a form with a location the shopper can tick carries a `rwpo_store_choice` marker (Porto's AJAX add gets it, and the ticked store, which Porto leaves out), and `woocommerce_add_to_cart_validation` at 5 (with the product, quantity and size WooCommerce passes; its AJAX add and Porto's pass the size as the product) refuses an add with the marker and no location, before WeLaunch falls back to its cookie. It also refuses three adds WeLaunch's own check lets through: a posted store the site does not offer (a page cached before the store was hidden still draws it); a posted store with no units of the size - WeLaunch validates its default location instead and files the line under the posted store, which its checkout then refused (`%1$s: %3$d left at %2$s...`); with some units but fewer than asked WeLaunch refuses it itself, so nothing is said twice, unless its `inventoryAllowEmptyProducts` skips that check - and, with no store posted, a size no store the site offers has any of (WeLaunch's answer about a hidden store had enabled the button, and the line went in under delivery for the checkout to refuse; left to WeLaunch when its `inventoryRequired` refuses it). Read from the numbers of the product `get_stock_managed_by_id()` names, as WeLaunch's checkout reads them; backorders, bundles, a variable product without its size, products without store numbers and WeLaunch's `orderFlowAlwaysUse` (its checkout then picks every line's store) are left to WeLaunch. A store the site does not offer is one WeLaunch's term meta `woocommerce_multi_inventory_frontend` keeps off the product page (any row holding "on", in any case, as the page's query matches it), unless it is `defaultInventory` or Click & Collect's `deliveryInventory`, which WeLaunch files lines under by itself. It is never the visitor's store either: on `init` at 0, before WeLaunch reads them at 10, its cookie (a year long) or a `?inventory=` link naming one is set aside for the request, so the line goes where it would for a first visit and nothing sells from a store the owner hid (Parque Arauco, on hoka, since 2026-10-06); WeLaunch's next add writes its validated store over the cookie. Radios only: WeLaunch's Hidden display (a hidden field) and Select display are left alone. A size on backorder, whose locations WeLaunch disables, gets no marker, so its add goes through as WeLaunch allows. On WeLaunch's Label display a refused add unfolds the locations. Buttons without locations are left alone. In a jsdom harness running WordPress's jQuery 3.7.1, WooCommerce 11.2's variation script and WeLaunch's own, with the posts handed to this class and to a port of WeLaunch's add and checkout checks, 2.8.11 refused a returning Parque Arauco shopper's add of a size only that hidden store had (2.8.10 filed it there and the checkout sold it), a branch ticked on a page cached before it was hidden, and a branch posted with none of the size before WeLaunch's answer arrived (2.8.10 added it for the checkout to refuse). |
| Tick home delivery when in stock | on | WeLaunch Multi Inventory only, and only with the switch above (owner's decision, 2026-10-27). Once that one has unticked, home delivery - Click & Collect's `deliveryInventory`, the row WeLaunch draws above the pickup stores, or without Click & Collect its `defaultInventory` (280, the online store, on hoka, where both name it); read on the server and printed into the script as a store id, since WeLaunch's script options carry only the default and Click & Collect makes the default one more store to pick - is ticked in each add to cart form where the shopper clicked no other location, when the size's stock gives it some - first the stores the page carries for each size (`rcus_stores`, with the Show each size's stores instantly switch on), then WeLaunch's answer for the size (`woocommerce_multi_inventory_get_variation_stock`: every store's number), which corrects them; a store with no number counts as none, since WeLaunch then leaves its radio as it was - and the cart holds nothing for another store: with `woocommerce_items_in_cart` set, the `rwpo_cart_store` cookie must name delivery or say that no line has a store (`0`), because WeLaunch keeps one store per cart and empties it of other stores' lines on the next add. That cookie is sent on `woocommerce_set_cart_cookies`, beside WooCommerce's own, so only on requests that already carry a cart and no page cache keeps: the store every line is filed under, `0` when none has one (WeLaunch leaves those lines on any add), none when they differ or the cart cannot be read, and deleted with WooCommerce's. An add sets the cookies before WeLaunch empties the cart, so a cart it is about to empty counts as unknown until the next page. 2.8.6 to 2.8.10 read WeLaunch's cookie instead, the store it validated on the last add: a cart kept from another device, or a branch with none of the size, which WeLaunch validates against its default location and writes into its cookie whether or not the add goes through, left that cookie on delivery over a pickup cart, and the next add emptied it. A simple product has no per-size answer, so its radio counts as printed - WeLaunch disables a store with none and leaves out one with no number, but prints Click & Collect's delivery radio enabled whatever its stock, so there only WeLaunch's `woocommerce-multi-inventory-inventories-stock-on-stock` class on its row counts - until WeLaunch's own check of delivery says otherwise; Porto's quick view runs no such check, and 2.8.6 to 2.8.10 ticked delivery there for a simple product it did not have. A shopper who clicked a store keeps it and is never moved to delivery; one who clicked delivery gets it back on the next size that has it. Without stock for delivery nothing is ticked, and the switch above asks for a choice. Measured on hoka on 2026-10-27 with the script run in the live product page and quick view: delivery ticked on sizes the online store had; nothing ticked and the add button enabled on a size only Parque Arauco had (WeLaunch had checked delivery and disabled it); nothing ticked with a branch's cart; a returning shopper whose cookie named a branch without the size got delivery and an enabled button. In the jsdom harness described above, 2.8.11 ticked nothing over a pickup cart whose WeLaunch cookie said delivery (2.8.10 ticked delivery, and the add would have emptied the cart), ticked delivery for a cart of lines without a store, ticked nothing for a simple product without delivery stock in Porto's quick view (2.8.10 ticked it), and with Click & Collect's delivery store apart from a default location that is a branch ticked delivery (2.8.10 ticked the branch). |
| Hide stores without the size | on | WeLaunch Multi Inventory only (owner's decision, 2026-10-27). WeLaunch disables the radio of each store without stock for the size picked, from its live answer, and leaves the row on the page; hoka's custom CSS hides WeLaunch's In stock and Out of stock text (`.woocommerce-multi-inventory-inventories-stock{display:none!important}`), so a faint grey radio was the only sign. An inline style after WeLaunch's stylesheet (`woocommerce-multi-inventory-public`) hides every row whose radio is disabled, except home delivery - anything inside Click & Collect's delivery block, and the row of the store the switch above ticks as home delivery (`deliveryInventory` with Click & Collect, else `defaultInventory`, checked as a store id), so with Click & Collect a default location among the pickup stores hides like any other - and hides the pickup block (`.woocommerce-multi-inventory-inventories-click-collect-container`), title included, when no radio in it is enabled. CSS only, so the list follows every redraw as WeLaunch enables and disables the radios; `!important`, because hoka's custom CSS forces those rows to `display:inline!important`. A browser without `:has()` drops the rules and shows the whole list. A store WeLaunch leaves enabled because the size has no number for it stays in sight. Measured on hoka on 2026-10-27 with the rules in the live product page: 9 US showed delivery, sold out, and Parque Arauco alone under "Retiro en Tienda"; 8 US delivery, Parque Arauco and Marina. |
| Show each size's stores instantly | on | WeLaunch Multi Inventory only. Each time a size is picked WeLaunch asks the server which stores have it, in two admin-ajax POSTs nothing can cache (`get_stock` and `get_variation_stock`, about 470 ms each on hoka on 2026-10-27, and 23% of its PHP time during the 2026-10-02 peak); the list showed about 800 ms after the pick, the previous size's stores and tick stayed in sight meanwhile, and when the requests failed the list never showed and the add button stayed disabled. `woocommerce_available_variation` gives every size `rcus_stores`: the stores whose number in `woocommerce_multi_inventory_inventories_stock` is above zero, read from the product `get_stock_managed_by_id()` names, as WeLaunch reads its parent's numbers; a store with no number is left out, and a size with no numbers at all gets nothing, leaving WeLaunch to decide. A script printed after WeLaunch's (at 99, before Store_Choice's) acts on `found_variation`, which WooCommerce 11 fires as the size is picked, about 300 ms before the `show_variation` WeLaunch waits for: it sets each radio from the list, unticking one that goes off, shows the list, removes the `disabled` WeLaunch's check of the parent at page load set on the button when the size is in stock somewhere, and does so again when a check answering for another size disables it; Store_Choice then ticks from the same list, so WeLaunch's own check asks about that store and needs no second request. The button counts only stores on the screen: a store hidden from the frontend has no radio. With nothing ticked WeLaunch checks the store in its cookie, which a returning shopper carries for a year, and disables the button with its out-of-stock line when that store lacks the size; after that check, while no store is ticked and one on the screen has the size, the button is lifted and the line says In stock, and the add asks for a choice. The size's own `get_variation_stock` answer replaces the page's list for the rest of the visit, and a late answer for another size, which WeLaunch draws on whatever size is shown, is followed by the current size's stores again. After `show_variation` it hides WeLaunch's spinner and, on WeLaunch's In stock / Out of stock display, writes WeLaunch's In stock text over WooCommerce's line (which follows the default store) when the ticked store, or with nothing ticked and no WeLaunch cookie any store, has the size. WeLaunch's requests still run and correct what changed since the page was cached; RHINNO IT - Optimizer's page cache switches purge a product when one of its stores runs out or comes back. Measured on hoka on 2026-10-27 with the script in the live product page: list and tick right 15-22 ms after the pick, the stock line and a usable button when WooCommerce showed the size about 300 ms later, still before WeLaunch answered; with WeLaunch's requests aborted the list still showed and the button stayed usable; a store wrongly in the page was taken off by WeLaunch's answer within 1.2 s. In a jsdom harness running WordPress's jQuery 3.7.1, WooCommerce 11.2's variation script and WeLaunch's own, 2.8.9 kept a branch clicked before WeLaunch answered (2.8.8 lost it), left the button usable for a returning shopper on a size only a branch has (2.8.8 left it disabled under WeLaunch's out-of-stock line), and drew the right stores when an earlier size's answer arrived late (2.8.8 drew that size's). WeLaunch's answer for a size no visible store has names the hidden store its server picked and enables the button; with Let the shopper choose on, the add is refused on the server instead of being filed under a store without units, and a hidden store in the shopper's cookie is set aside. |
| Hold each store's stock | on | WeLaunch Multi Inventory only. WooCommerce reserves a pending order's units in `wc_reserved_stock` against `_stock`, which WeLaunch keeps as the sum of every store; WeLaunch's checkout check compares only the picked store's own stock with the quantity and takes the units off on payment, so two shoppers could pay for a store's last unit (three branch oversells on hoka on 2026-10-05). Each line with a store picked subtracts the units other shoppers' pending orders hold of that product at that store: unexpired `wc_reserved_stock` rows (read directly, as WooCommerce's own `ReserveStock` does: there is no API for the rows), from orders still pending or failed (a rejected Webpay card leaves the order failed while the shopper's retry resumes it, and Mercado Pago and Fintoc complete a failed order on a later approval; WooCommerce's own sum leaves failed out), whose line carries the store in `_woocommerce_multi_inventory_inventory`. The shopper's own orders are left out: the one the session is paying and any other with the same account or billing email. Checked three times: at `woocommerce_after_checkout_validation` priority 20 (a checkout error naming the store and the units left); at `woocommerce_checkout_order_created` priority 11, after WooCommerce's reservation, counting only older orders, so of two submits in the same second the newer is refused (thrown, which WooCommerce catches and releases); and at `woocommerce_before_pay_action`, since paying an existing order from its page (My account's Pay, Fintoc's return after a cancel or failure) runs no checkout validation: an error notice stops the payment, a store already short of the units refuses too unless the order's units were already taken, and an order that passes gets its own reservation renewed through `wc_reserve_stock_for_order()`, which WooCommerce does not do on that page. Counter sales at a store are not seen: they reach the web when the ERP writes the store's stock. Neither is a payment made on the gateway's page with no request to the shop (a Mercado Pago preference or Fintoc session still open after the hold). On `woocommerce_order_status_failed` priority 5, before WooCommerce 11 clears `_order_stock_reduced` at 10, an order whose stock was reduced (on-hold first: Fintoc validating, Mercado Pago reviewing) gets `_rwpo_store_units_taken`: WeLaunch never gives a store its units back on failed, so that order's retry is not refused for them and its row is not counted against other shoppers. The renewal on order-pay forces `woocommerce_product_is_in_stock` to true for the call: the store was just checked, and WeLaunch answers stock status from the visitor's store cookie. The session's own order is left out at every check, and a product on backorder is never refused for a short store. |

Both skip-the-cart switches decide after the add. A navigating add goes
through `woocommerce_add_to_cart_redirect` (the product-less call WooCommerce
makes to print its "View cart" link is left alone); an AJAX add - WooCommerce's
listing buttons, Porto's product page, quick view and variation forms - is
answered from `woocommerce_ajax_added_to_cart` with `{error: true,
product_url: <checkout>}`, which both scripts already follow. Nothing depends
on the cart in the printed page, and no button is changed.

Both shipping rules match a package by the city it ships to
(`$package['destination']['city']`) against their communes, once both are
normalized: accents removed, lowercase, trimmed, inner whitespace collapsed.
With **Pick the commune from a list** on the city is the commune's official
name; with it off it is what the shopper typed, so "nunoa" matches Ñuñoa and
"Santiago Centro" matches nothing. The region is not compared. A commune in
both rules' lists loses its deliveries and can show both messages. A method
deleted from its zone stays in the stored list and matches no rate; the screen
no longer offers it, so the next save drops it. WooCommerce
keeps each package's rates in the shopper's session under a hash that
includes its `shipping` transient version, and recalculates them only when the
package changes, so every save of a settings screen of the group
(`rhinnoit_settings_saved`) bumps that version with
`WC_Cache_Helper::get_transient_version( 'shipping', true )`, as WooCommerce
does when its own zones are saved, whatever the switches say: what turns a
rule off has to free the rates it hid. So does every plugin's activation and
deactivation (core's `activated_plugin` and `deactivated_plugin`), which
WooCommerce bumps nothing for: a plugin that filtered rates - the old shipping
plugins among them - would otherwise leave what it did in every session that
stored it. This plugin's own activation and deactivation bump it too
(`Install::activate()`, `Install::deactivate()`): deactivated, its rules stop,
and the rates they took away come back at each shopper's next cart or
checkout, but for a cart rated in the very instant of the change (see
*Failure modes*). An option changed outside the screen (WP-CLI, code) waits
for each shopper's cart or address to change; save the screen afterwards. A
message never saved follows the shop's language; saving the screen stores the
text its box shows, in the admin's. Core's `sanitize_textarea_field()` drops a
`%` followed by two hexadecimal characters, so "15%de descuento" is stored and
shown as "15 descuento": write "15 % de descuento", with spaces.

## Cached pages and the Optimizer

This plugin clears no page cache (the shipping rules only bump WooCommerce's
own shipping version, which keys the rates stored in each shopper's session:
see *What it does*). What it prints goes into cached pages - the
struck-through price's style on every page (the mini cart is in the header),
the markup and style of Porto's product widgets, the WeLaunch scripts, and
each size's stores in a product page - so on a shop with a page cache it stays
fresh only through **RHINNO IT - Optimizer**, which clears that cache:

- **A save of this screen** fires the group hook `rhinnoit_settings_saved`
  (`Settings_Page::SAVED_HOOK`), which the Optimizer takes like a save of its
  own screen: it clears whether or not a value changed, at once on cPanel,
  and on Kinsta with a forced purge and its 5-minute check. **An update**
  fires `rhinnoit_page_cache_dirty` (`Install::DIRTY_HOOK`) with `'site'`,
  which it clears like any other change to every page. Activating or
  deactivating this plugin reaches it through core's `activated_plugin` and
  `deactivated_plugin`.
- **A WeLaunch store running out or coming back** makes the stores
  `Size_Stores` printed into that product's cached page wrong. The
  Optimizer's `Shop_Cache` watches WeLaunch's stock numbers itself and has the
  page cleared - on Kinsta that product and its archives, on cPanel the whole
  cache at most every 15 minutes - with no code link between the two plugins.
  Meanwhile the list is wrong only until WeLaunch's own answer for the size
  corrects it on the page.
- **An option changed outside this screen** (WP-CLI, code) clears nothing:
  the Optimizer watches only its own options. Save this screen afterwards.

All of it needs the Optimizer's page cache switches on. Without the
Optimizer, or with those switches off, nothing clears on these, and cached
pages turn over on the host's own schedule.

A WeLaunch shop runs both plugins. **Remove the store from links** is what
keeps a returning shopper's links free of `?inventory=` and builds the
cacheable pages for the default store, so for those shoppers the Optimizer's
page cache and hover prefetching only pay off with it on.

## Keys that keep WP Optimizer's spelling

Everything of WP Optimizer's that this plugin took was renamed from `rwpo` to
`rcus`, except four keys that pages and orders of WP Optimizer's time still
carry:

| Key | What it is | Why it keeps the name |
| --- | --- | --- |
| `_rwpo_store_units_taken` | Order meta, `Store_Stock_Hold::UNITS_TAKEN` | Written on a failed order whose store units WeLaunch kept, by WP Optimizer 2.8.x and by this plugin alike, and read by every stock check. Renamed, the orders WP Optimizer marked would count against other shoppers again, and their own retries would be refused. It is order data, so `uninstall.php` never deletes it. |
| `rwpo_cart_store` | Browser-session cookie, `Store_Choice::CART_STORE_COOKIE` | The server writes it on `woocommerce_set_cart_cookies`, and the script in pages cached or still open from before the switch reads it. |
| `rwpo-sale-price` | Class, `Cart_Sale_Price::WRAPPER` | WooCommerce puts back the mini cart a tab stored before the switch on every page until the cart changes (at most 24 hours), and a page cached or still open from before it gets the new markup over AJAX. With one class, the old pages' rules and this plugin's style both. |
| `rwpo_store_choice` | Posted marker field, `Store_Choice::MARKER` | Pages cached or still open from before the switch post it, and the server refuses a marked add that names no store. The option with the same name did rename, to `rcus_store_choice`, so a blind rename pass gets this one wrong. |

Two more strings keep the old spelling because they name WP Optimizer itself:
`Install::LEGACY_PREFIX` (`rwpo_`), where the settings copy reads from, and
`Plugin::LEGACY_CONSTANT` (`RWPO_VERSION`), what the guard asks. Renamed to
this plugin's own, the copy would read its own rows back and the guard would
hold on every shop. `KeptKeysTest` pins all six with the old prefix built in
two pieces, so a rename pass cannot rewrite the expectation along with the
code.

`rcus_stores`, the stores `Size_Stores` puts in each size's data, did rename:
it is never stored, and both of its readers are here. A page cached or still
open from before the switch only loses the instant store list, until WeLaunch
answers, for sizes it loads afterwards: on a product with more than 30
variations (whose sizes load through `wc-ajax=get_variation`) and in Porto's
quick view (rendered through `admin-ajax.php`).

## Stripped from the template

The template carries scaffolding so that adding is cheap. This plugin ran two
of its strip recipes - the storefront lists, and a plugin with admin JS but no
storefront JS - and dropped the WordPress card, so these pieces of the
template are not here, on purpose, and a resync from the template leaves them
out:

- The two storefront lists, `rcus_scope_wordpress` and
  `rcus_scope_woocommerce`, with `Settings::wordpress_scope_choices()`,
  `woocommerce_scope_choices()` and `frontend_contexts()`, and the
  `multicheck` type that drew them (`Settings_Page::render_multicheck()` and
  `.rcus-group__desc`). `chosen()` went with them and came back adapted, as
  the reader of the `multiselect` type (see *Admin interface*).
- The storefront bundle: `src/frontend.js`, `src/frontend.scss`,
  `Assets::enqueue_frontend()`, `scope_matches()` and `is_plain_page()`, the
  `$extra_deps` parameter of `Assets::enqueue()`, and the
  `rcus_broken_build_frontend` transient.
- The WordPress card: no service of this plugin works without WooCommerce, so
  the WordPress half is the screen alone.

What each case of those pieces became is in the commit that removed them,
`e4a4b67`.

## Admin interface

The plugin hangs off a shared node, right below Dashboard:

```
Dashboard
RHINNO IT                ← shared node
├── Customizer           ← this plugin
├── Optimizer            ← another plugin of the group
└── ...
────────────────
Posts
```

The node is created by the first plugin of the group to load, and the rest
detect it through the trace `add_menu_page()` leaves in `$admin_page_hooks`.
There is no shared library and no stored state: deactivate the one that created
it and the next one creates it. The three names live in constants at the top of
`rhinnoit-customizer.php`:

| Constant                 | What it is                | Shared |
| ------------------------ | ------------------------- | ------ |
| `RCUS_MENU_PARENT_SLUG`  | Parent node slug          | Yes    |
| `RCUS_MENU_PARENT_TITLE` | Parent node label         | Yes    |
| `RCUS_MENU_TITLE`        | Label of this plugin      | No     |

The two shared ones must hold **the same value in every plugin of the group**,
together with `Menu::PARENT_ICON`, `Menu::MENU_POSITION` and
`Menu::CAPABILITY`: whichever wins the race defines how the node looks, and the
order depends on the folder name. Their values deliberately carry no plugin
prefix, so creating a plugin from the template leaves them alone.

**Staying below Dashboard** is not something `MENU_POSITION` can promise. When
the slot is already taken, `add_menu_page()` resolves the tie by adding a
fraction derived from `md5( slug . title )`, so between two plugins asking for 3
the winner is effectively random - on a shop with Elementor and a theme addon
the node ended up fourth. `Menu::move_below_dashboard()` filters `menu_order`
instead, which runs once every menu is registered and is the only place the
answer is deterministic. It runs at `PHP_INT_MAX`: Site Kit splices its own
node right after Dashboard from a `menu_order` filter it adds on `init`, after
this plugin's, so at the same priority Site Kit ran last and took the slot on
the shops that have it. It needs `custom_menu_order` to return true, which is
what `Menu::enable_menu_order()` is for. Every plugin of the group moves the
same slug to the same place, so it does not matter how many of them do it.

The icon is `Menu::PARENT_ICON`, a [Dashicons](https://developer.wordpress.org/resource/dashicons/)
class. Change it in every plugin of the group at once: the one that creates the
node is the one whose icon shows, and which one that is depends on load order.

The node renders nothing itself, so clicking it opens the first plugin listed
under it — WordPress takes the href of a top-level item from its first child,
and which plugin that is depends on load order. That is also why
`Menu::register_pages()` ends with a `remove_submenu_page()`: core inserts a
back-link to the parent as the first child of a node that had none, and with a
callback-less parent both the node and that back-link resolve to
`/wp-admin/rhinnoit-tools`, a hard 404. Do not drop that line when rewriting
the method.

With two plugins of the group installed, whichever loads first creates the node
and gives it its href; the second finds the submenu already declared, so core
inserts no back-link for it and its own `remove_submenu_page()` is a harmless
no-op. Mixed versions are safe in both directions: a plugin built on this
template repairs a node that an older sibling left dead, and an older sibling
registering afterwards cannot break it again, because core only inserts that
back-link for a node with no children at all. Three cases in `AdminTest` hold
that down.

The node has one screen per plugin, and this plugin's is its settings form and
nothing else: the versions a support request asks for are already in Tools >
Site Health > Info and on the plugins list, and reports beside the switches
make them harder to find.

The form draws its own fields, because `WC_Admin_Settings` does not exist on a
site without WooCommerce. It uses the card-and-row pattern of
`@wordpress/components` (the look of Site Health and the newer WooCommerce
screens) in plain PHP markup, styled by `src/admin.scss`: a card per section,
small group labels inside it, and a row per setting with its title and one line
of description on the left and an on/off switch on the right; a list or a text
box goes below its text and fills the row. The switches are real checkboxes
with `role="switch"`, so the form posts the same thing with or without the
stylesheet. It knows five types: `title`, `sectionend`, `checkbox`,
`multiselect` and `textarea` (the template's `multicheck` went with the
storefront lists). A new one goes into `Settings_Page::render_field()` and
`Settings_Page::sanitize_submission()` together. The save reads only the ids
the field list declares, each through its own type's sanitizer, so a crafted
post cannot create an option - and only the fields the form says it drew, so a
section that appeared while the form was open (WooCommerce activated in another
tab) is not saved as all unchecked.

A `multiselect` is a native multiple select with WooCommerce's
`wc-enhanced-select` class, so WooCommerce's selectWoo, which `Assets` loads on
this screen where WooCommerce is supported, makes it the box of WooCommerce's
"Sell to specific countries" setting: searchable, every choice kept as a chip
with an x. Without that script it stays a native multiple select that posts
the same list, and nothing selected posts nothing, which stores the empty list.
Its choices are fixed `'options'`, optionally grouped into `<optgroup>`s that a
`'group_labels'` callback names when the screen is drawn, or come from an
`'options_callback'` for choices that live in the database, such as shipping
methods: the form calls it only when the screen is drawn or saved, and the save
keeps only the ids it offers then. The row is a list of string ids, read back
through `Settings::chosen()`, which keeps only the strings its field can
produce - one of its `'options'`, or for a callback's list one matching the
field's `'id_format'` (`Settings::DIGITS`), since a storefront request never
asks the callback. A `textarea` stores one text through
`sanitize_textarea_field()`, line breaks kept, and is read back through
`Settings::text()` the first time it is printed. Core's sanitizer stores a
lone `<`, and the rest of the text after it, as entities; the shop prints the
row through `esc_html()`, which leaves an entity as it is, so the shopper reads
what was typed. The box draws the row through
`esc_textarea( wp_specialchars_decode( ... ) )`, so it shows what the shop
shows, and saving it untouched stores the same row: through `esc_textarea()`
alone it showed the entities, and kept them on every save. Its declared default
is a translated sentence, so it is never seeded: `Settings::defaults()`, which
activation writes, is first built on `plugins_loaded`, before the translations
load on `init`, and would have stored it in English. Until the screen is saved
the row stays empty and the default follows the shop's language; the box
shows it, and saving stores it as shown. Either reader takes a row of the
wrong type as its field's declared default. A `multiselect`'s title names what
is picked and a `textarea`'s what is written, each with one sentence of what
the owner or the shopper notices.

The form posts to `admin-post.php` with a nonce and a check of
`Menu::capability()`: `manage_woocommerce` where WooCommerce is active, shared
by the group, and `manage_options` where it is not - WooCommerce registers
`manage_woocommerce` itself, so on a plain WordPress site nobody would hold it.
It redirects after saving.

Two hooks are the group's, not the plugin's, and carry no prefix for the same
reason as the menu constants: `rhinnoit_settings_saved`
(`Settings_Page::SAVED_HOOK`), which the form fires after every save, and
`rhinnoit_page_cache_dirty` (`Install::DIRTY_HOOK`), which
`Install::check_version()` fires with `'site'` when an update changes the
version. A switch or an update can change what every page prints, and every
plugin of the group fires the same two, so the plugin that clears the page
cache - RHINNO IT - Optimizer - hears all of them. Without it they do nothing
(see *Cached pages and the Optimizer*).

Each domain is a card of the one form, not a tab: WooCommerce, Porto and
WeLaunch Multi Inventory, under the master switch's untitled card. There is no
WordPress card: nothing this plugin does works without WooCommerce, so a site
without it sees the master switch alone. One screen needs no tab bar, and a
second tab meant a click on every visit to reach the thing anyone came for.

The screen offers only what can act on the site. A card is drawn only where
its section mark holds - `Settings::REQUIRES_WOOCOMMERCE`, `REQUIRES_PORTO`
(Porto on a supported WooCommerce) or `REQUIRES_MULTI_INVENTORY` (WeLaunch's
`welaunch_woocommerce_multi_inventory_run()` on one) - and inside any card an
option that carries `'needs'` is drawn only where the site has every mark it
names. `Settings::conditions()` answers each mark from what is installed - a
plugin's constant, the theme, WooCommerce's version, the class a feature
writes into - never from another plugin's settings, and with the same
condition the feature itself stops at, since hiding an option turns nothing
off. A mark it does not answer hides its option on every site, which is what
`SettingsNeedsTest` catches. A hidden option keeps its row, so the day what it
needs is installed it comes back with the value it had. This plugin's needs
are `NEEDS_FINTOC` (Fintoc's `FINTOC_WC_VERSION`), `NEEDS_COUPON_ITEMS`
(`Coupon_Split::can_split()`: WooCommerce 8.8+, whose `WC_Discounts` hands
itself to `woocommerce_coupon_get_items_to_apply`) and `REQUIRES_PORTO` for
the quick view switch; a new one comes with the option that needs it. A card
left with no option to draw is not drawn at all.

The page slug is the plugin slug, so the URL is
`admin.php?page=rhinnoit-customizer` and says which plugin the screen belongs to
— the way core's own `page=wc-settings` does. It lives in `Menu::PAGE_SLUG`,
and the renamer rewrites it through the **slug**, not the function prefix, so
anything comparing against it uses the constant: a literal `'rcus'` somewhere
else would be rewritten through the prefix and the two would drift apart in
every copy.

## Conventions

- **Files**: `class-{hyphenated-name}.php`, one class per file.
- **Namespace**: the subfolder of `includes/` is the namespace segment
  (`includes/admin/class-settings-page.php` →
  `RhinnoIT\Customizer\Admin\Settings_Page`; a class of the WooCommerce
  half goes in `includes/frontend/` and lands under `...\Frontend`). The
  autoloader relies on this.
- **Types**: every signature carries parameter and return types. No
  `declare( strict_types=1 )`: WordPress passes loosely typed values through the
  hooks and turning it on raises `TypeError` in production.
- **Options**: always prefixed with `rcus_` and declared in
  `Settings::fields()`, which is where the activation defaults come from. Add
  them to `uninstall.php` too.
- **Kept keys**: four keys keep WP Optimizer's `rwpo` spelling, and never
  follow a rename (see *Keys that keep WP Optimizer's spelling*).
- **Escaping**: sanitize on input, escape on output (`esc_html`, `esc_attr`,
  `esc_url`, `wp_kses_post`).
- **Strings**: everything visible goes through `__()` / `esc_html__()` with the
  `rhinnoit-customizer` text domain.

## Commands

```bash
composer install     # PHP linters and static analysis
composer lint        # phpcs (WooCommerce-Core)
composer lint:fix    # phpcbf
composer analyse     # phpstan level 8, with WordPress and WooCommerce stubs
composer test        # phpunit
composer dist        # rebuilds build/, then packages dist/<slug>-<version>.zip
composer release     # all of the above, then the zip; stops at the first failure
npm install
npm run build        # compiles src/ into build/
npm start            # build in watch mode
npm run format       # prettier over src/
npm run lint:js
npm run lint:css
```

## Private plugin

- The header carries `Update URI: false`, which stops wordpress.org from serving
  updates should the slug collide with a public plugin.
- There is no `Plugin URI`: it is the public website of the plugin and WordPress
  renders it as a link on the plugins list. `Author URI` points to
  `https://rhinnoit.cloud/`, so the author name links to RHINNO IT's site.
- No `readme.txt` is needed: only the wordpress.org directory uses it.
- `load_plugin_textdomain()` **is** needed: WordPress only resolves the
  translations on its own for plugins hosted on wordpress.org.
- To update the plugin across several shops without copying it by hand, the
  usual route is `yahnis-elsts/plugin-update-checker`, which reads releases
  straight from a GitHub repository and supports private ones through a token.

## Versioning

Bump the `Version:` plugin header and the `RCUS_VERSION` constant together.
WordPress and `composer dist` read the header; `Install::check_version()` reads
the constant, and a mismatch means the upgrade path silently
stops re-seeding new options. `tests/PackagingTest.php` fails when they drift.

Bump both on every release of this plugin. It started at 2.0.0 (owner's
decision, 2026-11-23; the template gives a new plugin 1.0.0), with the
features of WP Optimizer 2.8.13 and the four that replace older plugins of the
owner's (see *Replacing the old plugins*). A version this README names is WP
Optimizer's unless it names this plugin.

## PHP version

The floor is PHP 8.3, declared in `Requires PHP` (plugin header), `testVersion`
(`phpcs.xml.dist`) and `"php"` (`composer.json`). WordPress blocks activation
when the server does not reach the minimum, so keep it at the lowest version any
of the shops actually runs.

## Translations

The plugin is written in English and ships Spanish for Spain (`es_ES`) and for
Chile (`es_CL`). WordPress does not fall back from one Spanish locale to
another, so each needs its own files; they differ only where the two countries
use different words ("añadir" / "agregar"). Any other locale sees English. The
catalogues carry WP Optimizer's translations of every string that came from
it.

After changing a visible string, build first, then regenerate the template,
update both catalogues and compile them (WP-CLI and gettext):

```bash
npm run build
wp i18n make-pot . languages/rhinnoit-customizer.pot --domain=rhinnoit-customizer --exclude=tests,vendor,node_modules,src
wp i18n update-po languages/rhinnoit-customizer.pot languages/
msgfmt -o languages/rhinnoit-customizer-es_ES.mo languages/rhinnoit-customizer-es_ES.po
msgfmt -o languages/rhinnoit-customizer-es_CL.mo languages/rhinnoit-customizer-es_CL.po
wp i18n make-json languages --no-purge   # strings coming from the .js files
```

Without WP-CLI, write the entries into the `.pot` and both `.po` files in the
format they already use - a `#:` line naming the file, without line numbers -
and run the two `msgfmt` lines.

A string from a bundle is read from `build/`, not `src/`:
`wp_set_script_translations()` finds the JSON make-json writes by a hash of the
built file's path, so the `.po` has to name that file. `build/` is not in git,
which is why the build comes first: a stale one gives a stale template.

`tests/TranslationsTest.php` fails when a string in the code - the PHP or a
bundle source in `src/` - is missing from the template, when either locale
leaves one untranslated or changes its placeholders, and when a `.mo` no longer
matches its `.po`. It cannot read plurals or contexts yet, so it also fails on
any `_n()` or `_x()`: teach it those before using one.

## Tests

`composer test` runs PHPUnit against the plugin classes with the slice of
WordPress they touch stubbed in `tests/bootstrap.php`. No database and no
containers, so it runs in CI in seconds.

`RCUS_PATH` points at a temporary sandbox whose `includes/` is a symlink to the
real source, which lets the tests create and corrupt `build/` files without
touching the repository.

Some cases run in their own process, because they depend on a constant and a
constant cannot be changed once the suite has defined it. The WooCommerce
support gate reads `WC_VERSION`, so `tests/bootstrap.php` takes it from
`RCUS_TESTS_WC`: unset is a supported version, `7.9` one below the minimum,
`8.7.1` a supported one that does not yet hand `WC_Discounts` to
`woocommerce_coupon_get_items_to_apply` (`CouponSplitBeforeItemsFilterTest`),
`none` no WooCommerce at all - and then `tests/stubs/woocommerce.php`, the
WooCommerce functions, is not loaded either, so the WordPress half is tested on
exactly what a plain WordPress site has. The child process inherits whatever
the test class put in the environment before it was spawned. Anything else
gated on a constant needs the same treatment:

- `RCUS_TESTS_WELAUNCH=1` defines WeLaunch Multi Inventory's
  `welaunch_woocommerce_multi_inventory_run()`, for `InventoryLinksTest`,
  `QuickViewStoreTest`, `SizeStoresTest`, `StoreChoiceTest`,
  `PickupStoresTest`, `StoreStockHoldTest`, `BootWithWeLaunchTest`,
  `BootWithOldWooCommerceTest`, `BootWithWpOptimizerTest`,
  `SettingsWithoutPortoTest`, `QuickViewStoreWithoutPortoTest` and
  `HookArityTest`. The `*WithoutWeLaunchTest` cases run in the shared process,
  where it is absent.
- `tests/stubs/porto.php` is the slice of Porto the plugin touches;
  `RCUS_TESTS_PORTO=none` boots without it, which is how
  `WidgetThumbnailWithoutPortoTest`, `WidgetSalePriceWithoutPortoTest`,
  `QuickViewStoreWithoutPortoTest`, `BirthdateStyleWithoutPortoTest` and
  `SettingsWithoutPortoTest` check a theme that is not Porto.
- `RCUS_TESTS_WP_OPTIMIZER=1` defines WP Optimizer's `RWPO_VERSION`, for
  `BootWithWpOptimizerTest`: the guard and the copy while WP Optimizer is
  still active.
- `SettingsWithFintocTest` defines Fintoc's `FINTOC_WC_VERSION` in its own
  process.

`HookArityTest` registers every service and checks each hooked method against
the arguments its hook passes; a method that needs more is an
`ArgumentCountError` on every request that fires the hook, which no other case
would see because the stubs never call anything. A new service goes into its
list. It runs with WeLaunch active, so the WeLaunch services hook too.

`Widget_Thumbnail` adds its srcset through WordPress's HTML API, so the
bootstrap loads a copy of the classes its Tag Processor needs from
`tests/stubs/html-api/` (WordPress 7.1.2, unchanged) whenever
`WP_HTML_Tag_Processor` is not already defined, and its cases run the real
parser. Refresh the copy from a newer core by replacing the seven files.

`Coupon_Split` writes into WooCommerce's own split, so the WooCommerce stubs
load `WC_Discounts` and the `NumberUtil` it rounds with from
`tests/stubs/woocommerce-core/` (WooCommerce 11.2.2, unchanged), plus
WooCommerce's own precision helpers, and its cases apply real coupons the way
`WC_Cart_Totals` does: the case with the switch off is WooCommerce's split as it
runs on the shop. `WP_Stub::$live_filters` runs a callback where such a class
calls one through a filter. Refresh the copy by replacing the two files.

The shipping rules' cases build packages and rates in the shape WooCommerce
hands them to `woocommerce_package_rates`, and their zones in
`WP_Stub::$zones`, which the stubs of `WC_Shipping_Zones` and
`WC_Shipping_Zone` read; `WP_Stub::$zone_listings` counts the reads of every
zone, which only the settings screen may make, and `WP_Stub::$method_lookups`
the reads of one instance's zone row (the `WC_Shipping_Zone_Data_Store` stub), which only the
submit may make; the stub of `WC_Shipping_Zones::get_shipping_method()` throws,
since WooCommerce builds the method's class there. `WC_Cache_Helper` is
WooCommerce 11.2's own. The submit cases hand the posted data as
`WC_Checkout::get_posted_data()` builds it, the rate ids by package index,
with the session already holding what WooCommerce chose in place of a rate
that is gone, and the package that matches is not always the first. `__()`
answers from `WP_Stub::$translations`, empty until a case fills it the way the
plugin's translations load on `init`, after the services register: that is
how the cases pin that a message is read when it is printed, not in
`register()`.

The RUT check's cases hand it the checkout fields, the posted data and the
errors in the shapes WooCommerce 11.2's `WC_Checkout` passes them to
`woocommerce_checkout_fields`, `woocommerce_checkout_posted_data` and
`woocommerce_after_checkout_validation`. The bootstrap's `WP_Error` keeps the
data an error carries, as core's does, because WooCommerce hands it to each
notice: that is how the field's id on an error is checked. The script itself
is a string the cases check only in part; its behavior was checked in a
jsdom harness (see *What it does*).

The suite is not decoration. Most of it covers a defect that reached a fatal
error or a dead admin page before it was fixed; the rest pins a decision that
is cheap to undo by accident — that the node stays under Dashboard, that the
page prints no tab bar, that the header carries the group prefix. Either
way every case was checked by breaking what it covers and watching it go red.
Do that too when adding one: a case that cannot fail is worse than no case.

`tests/` is excluded from phpcs (PHPUnit's `*Test.php` naming conflicts with the
WordPress file convention) and from PHPStan (the bootstrap redeclares core
functions the WordPress stubs already define).

## Before every push

`.githooks/pre-push` runs `bin/release.sh`, so a push checks everything and
writes `dist/<slug>-<version>.zip` before anything leaves the machine. A red
tree aborts the push instead of reaching CI, and the installable is waiting in
`dist/` afterwards, of exactly the commit that went out.

Git does not version hooks, only this directory, so each clone turns it on once:

```bash
git config core.hooksPath .githooks
```

`git push --no-verify` skips it, for a push that cannot break anything. A push
that only deletes a remote branch skips the checks on its own. The hook needs
`composer install` and `npm install` to have run; without them `bin/release.sh`
says so and stops.

## Continuous integration

`.github/workflows/ci.yml` runs two jobs on every push: `composer lint`,
`composer analyse` and `composer test` on PHP 8.3, and `npm run lint:js`,
`npm run lint:css`, `npm run build` and `bin/build-zip.sh` on Node 22. That last
step uploads the zip as a run artifact, so the installable can be downloaded
from the run page with no clone, no Node and no build - kept 14 days, since it
is rebuilt on every push and artifacts bill against private storage.

It only triggers on `push` (plus manual dispatch) and cancels superseded runs of
the same branch, because Actions minutes are metered on private repositories.
Add `pull_request:` to the `on:` block if the repository starts taking PRs.

`shivammathur/setup-php` is the only third-party action; the rest are official.

## Failure modes

Every service runs on the shop's own requests - its pages, the cart, the
checkout, WooCommerce's AJAX - and the plugin activates on sites with and
without WooCommerce, so those paths are hardened:

| Situation                                   | Behavior                                  |
| ------------------------------------------- | ----------------------------------------- |
| `build/` missing (deployed without a build)  | No admin script or style, one log entry per hour |
| `build/*.asset.php` truncated mid-upload     | `ParseError` caught, no script, logged     |
| Manifest returns a non-array or lacks keys   | No script, logged                          |
| `wp_json_encode()` fails                     | Inline script skipped, no broken JS emitted |
| `?page[]=x` (array in the query string)      | The plugin page simply does not match      |
| Switch holds a non-string value              | Reads as off; no cast, no `TypeError`      |
| A list or a text holds another type          | Reads as its declared default; no cast, no `TypeError` |
| WooCommerce's selectWoo not registered       | A multiselect stays a native multiple select that saves the same |
| No WooCommerce (plain WordPress)             | The screen with the master switch alone; the other options kept, not read |
| WooCommerce below the minimum               | The same, and a warning to admins          |
| WP Optimizer still active                    | Nothing but a notice to admins; activation still copies its settings |
| Porto, WeLaunch or Fintoc missing            | What is built on them steps aside, and its switch is not offered |
| A plugin changes a coupon's share, or `WC_Discounts` starts no row for it | WooCommerce splits that coupon its own way, one log entry per day |
| A shipping package names no city as text      | No commune matches; every rate stays       |
| A shipping list changed outside the screen   | Stored rates keep the old rule until the shopper's cart or address changes; a save of the screen refreshes them |
| A plugin that filters rates is deactivated, this one included | WooCommerce's shipping version is bumped, so each session works its rates out again at its next cart or checkout. Core fires the hooks a moment before it records the change, so a cart rated by a request already under way in that instant keeps the old rates until it or its address changes, and a plugin that goes silently - during an update, or with its folder removed - fires no hook at all |
| A stale checkout posts a method a rule took away | Refused with the rule's message and the review drawn again while another method is left, where WooCommerce alone would move the order to it; with none left, WooCommerce's own refusal is the only one |
| The checkout has no RUT field                | The RUT check does nothing                 |
| The RUT script blocked or broken             | The submit is left to the server, which refuses a missing or invalid RUT |
| A crafted post sends the RUT or the radio as an array | Read as no RUT and no choice; no cast, no `TypeError` |
| No WooCommerce to log to                     | Written to PHP's error log instead         |

None of them raise a fatal error. The rule for anything reachable from a
storefront hook: fail soft, log once, never take the shop down. "Log once" is
`Logger::error_once( $key, $message )` — a key per failure, since one shared
key means the first failure silences the second for its whole window.

## Assets

The plugin ships **no storefront bundle**. Its one entry point is
`src/admin.js` with `src/admin.scss`, which draws the settings screen and loads
on that screen only (`Menu::is_current_page()`). `Assets::enqueue()` reads
`build/admin.asset.php` for the dependencies and the version, and validates it:
a half-uploaded build degrades to "no script" and logs once, never to a fatal
error.

Before the bundle, where WooCommerce is supported, the screen also gets
WooCommerce's own `wc-enhanced-select` script and `woocommerce_admin_styles`
stylesheet, the selectWoo its multiselects need, each only if WooCommerce
registered it. Both go in at priority 20 of `admin_enqueue_scripts`:
WooCommerce registers its admin stylesheet from a callback at 10 that it adds
on `init`, after this plugin's, so at 10 there was nothing to enqueue yet.

What the features print on the storefront is inline, on a handle that is
already on the page, so it costs no request and loads only where what it
extends does:

| Feature | Prints | On |
| --- | --- | --- |
| Show the regular price struck through | a style | its own file-less handle, `rcus-cart-sale-price`, on every page |
| Match the sale price color | a style | its own file-less handle, `rcus-widget-sale-price`, on every Porto page |
| Match the birth date field | a style | its own file-less handle, `rcus-birthdate-style`, on the checkout, on Porto |
| Pick the commune from a list | a script | WooCommerce's `wc-checkout`, on the checkout |
| Check the RUT at checkout | a script, and a style | WooCommerce's `wc-checkout`, and its own file-less handle, `rcus-rut-validation`, on the checkout |
| Remove the store from links, Keep the quick view's store choice, Show each size's stores instantly, Let the shopper choose | scripts | WeLaunch's `woocommerce-multi-inventory-public`, at 99 and then 100 |
| Hide stores without the size | a style | WeLaunch's `woocommerce-multi-inventory-public` stylesheet |

## WooCommerce notes

- There is no `Requires Plugins: woocommerce` header, on purpose: the plugin
  activates on any site. `Plugin::woocommerce_is_supported()` decides whether
  the services register, and `Plugin::MIN_WC_VERSION` is its minimum. A site
  running an older WooCommerce gets a notice saying the plugin needs
  WooCommerce 8.0 and does nothing until then.
- `Plugin::declare_woocommerce_compatibility()` declares HPOS
  (`custom_order_tables`) and cart/checkout blocks. Set `cart_checkout_blocks`
  to `false` only for a feature that would break the block checkout; one that
  just does nothing there stays compatible, like the checkout prompts, the
  commune list, the RUT check, the birth date field and the store stock hold,
  which act on the classic checkout only. The two shipping rules take their
  rates away on both checkouts, and print their message and check the submit
  on the classic one only.
- Errors go to **WooCommerce → Status → Logs** through `Logger`, under the
  source `rhinnoit-customizer`, or to PHP's error log on a site without
  WooCommerce.

## Migrating from WP Optimizer

This plugin and **RHINNO IT - Optimizer** replace **RHINNO IT - WP Optimizer**
(`rhinnoit-wp-optimizer`, prefix `rwpo_`): this one took its cart, checkout,
order and store features, the Optimizer its delivery and page cache ones. Both
slugs are new, so WordPress treats them as new plugins. On a shop that runs WP
Optimizer, in this order:

1. **Activate** the Optimizer and the Customizer while WP Optimizer is still
   active, each installed from its zip (**Plugins > Add New > Upload**) into
   its own folder. Activation copies the `rwpo_*` row of each of this plugin's
   settings, the master switch included, into its `rcus_*` equivalent - only
   where this plugin has no value yet, so nothing set here is ever
   overwritten. Then both wait: while WP Optimizer is active they boot nothing
   but a notice ("Customizer waits until WP Optimizer is deactivated, and does
   nothing until then"), so the shop never gets a feature twice.
2. **Deactivate** WP Optimizer straight away. From the next request the two
   take over. WP Optimizer's own clear on deactivation may not happen: on
   cPanel, within 30 seconds of its last clear, it is deferred to a scheduled
   event that nothing runs once WP Optimizer is inactive, and on Kinsta it is
   not forced.
3. **Save the Optimizer's screen** once, on any host, with **Clear the cache
   after changes** on: a save clears at once, past the spacing, and on Kinsta
   forces a purge and runs the 5-minute check. A save of this screen does the
   same, through the same group hook. With that switch off, nothing clears:
   purge the host's cache by hand.
4. **Delete** WP Optimizer later, from the admin. Its uninstaller removes its
   own `rwpo_*` options; the copies here carry another prefix, and this
   plugin's uninstaller never touches WP Optimizer's rows.

Never delete WP Optimizer first: its `uninstall.php` deletes every `rwpo_`
row, so nothing would be left to copy and this plugin would start from its
defaults. Settings changed in WP Optimizer after step 1 are not carried over:
the copy runs on activation and on each version change, and never overwrites
a row that already exists here. Pages cached or still open from before the
switch keep working: the four keys they post and read kept their spelling.
What such a page loads over AJAX afterwards comes from this plugin, so the
size data in Porto's quick view and on products with more than 30 variations
carries `rcus_stores`, which their old script does not read: the stores show
when WeLaunch answers. Step 3's save leaves only tabs still open.

What changes around the shop:

- **No public filter** moved here: WP Optimizer's belonged to its delivery
  half, and this plugin adds none.
- **Handles, classes and codes** renamed from `rwpo` to `rcus`: the style
  handles `rcus-cart-sale-price` and `rcus-widget-sale-price` (and so the ids
  WordPress prints on their inline styles, `rcus-cart-sale-price-inline-css`
  and `rcus-widget-sale-price-inline-css`), the classes `rcus-commune-select`
  and `rcus-store-choice`, the error code
  `rcus-store-held`, and `rcus_stores` in each size's data. Custom CSS, or a
  JS-delay or cache exclusion, keyed on the `rwpo-` names must follow.
- **Log entries** go under the source `rhinnoit-customizer` from now on; the
  older ones stay under `rhinnoit-wp-optimizer`.

The guard, its notice and the copy go once no shop runs WP Optimizer. The
four kept keys stay: they are this plugin's own names now, and renaming one
later would break the same things.

## Replacing the old plugins

This plugin also does what four older plugins of the owner's did, rebuilt under
this plugin's rules: each behind its own switch, and what they wrote in their
code - a method's instance id, the communes, a message - picked and written on
this screen instead.

| Old plugin, as the plugins list names it | Folder | Replaced by |
| --- | --- | --- |
| YNK Bloqueo Despacho Express por Comuna | `ynk-bloqueo-despacho-express` | **Hide shipping methods in some communes** |
| YNK - Exclusión de envío en comunas | `regiones-excluidas-envio` | **Stop deliveries to some communes** |
| RHINNOIT - WooCommerce Validación RUT | `rhinnoit-validacion-rut-cl` | **Check the RUT at checkout** |
| RHINNOIT - WooCommerce Fecha Nacimiento | `rhinnoit-correccion-fecha-nacimiento` | **Match the birth date field** |

On a shop that runs any of them, in one visit:

1. **Install** the Customizer, as *Migrating from WP Optimizer* says when the
   shop runs it. The RUT check and the birth date field
   act from the next request: both ship on, and a switch never saved reads as
   its default. The two shipping rules ship on too, and do nothing while their
   lists are empty.
2. **Enter on this screen what the old shipping plugins kept in their code**,
   and save. Nothing is copied: they had no settings to copy.
   - The express rule: the method in **Shipping methods to hide**, picked
     under its zone, and its communes in **Communes where they are hidden**.
     The old plugin's notice on **WooCommerce > Settings > Shipping** names
     both on the live shop: the method by its instance id (the number in the
     method's edit link) and the communes (19, all of the Metropolitan
     Region, in the copy at hand). **Message for those communes** has a
     default to keep or change.
   - The exclusion, while the courier still does not deliver there:
     **Communes without delivery** and its **Message for those communes**,
     whose old values are in the plugin's `exclusion-enviame.php`.

   The save makes the rates each shopper's session stored follow the new
   lists, beside what the old plugins, still active, take away.
3. **Deactivate** the four old plugins right after that save - after it, not
   before: until the lists are saved nothing here stops a delivery to those
   communes, so deactivating first would leave minutes in which express and
   home delivery can be ordered there. The deactivation itself bumps
   WooCommerce's shipping version (core's `deactivated_plugin`, which this
   plugin listens to), so the rates stored while the old plugins still ran -
   no rate at all, pickup included, for the old exclusion's places - are
   worked out again without them at each shopper's next cart or checkout.
   Core fires that hook a moment before it records the plugins as inactive,
   so a cart rated by a request already under way in that instant keeps the
   old rates until it or its address changes; a save of this screen
   afterwards renews those too. Deactivate them from the plugins list, never
   by removing their folders: a plugin that goes without being deactivated
   fires no hook at all, and bumps nothing. While they stay active:
   - the RUT is checked twice: two scripts work on the field, one wrong RUT
     gets two notices, and with no choice made neither requires a RUT, since
     the old plugin takes the field's required flag away before this one
     reads it;
   - the old express plugin keeps hiding its hardcoded method in its own
     communes, whatever this screen says;
   - the old exclusion plugin keeps taking every rate, store pickup included,
     from its own places, and puts its message about the fires in place of
     WooCommerce's "no shipping options" text for any address;
   - the birth date rules print twice, and on any theme.
4. **Delete** them whenever convenient: they store nothing.

What changes from them:

- The screen offers communes only: Chile's 346, under their official names.
  The old exclusion list also held five places that are not communes -
  Lirquén, Palomares, Dichato, Liruy and Cerro Negro: pick the commune each
  lies in - and Trehuaco, which the list spells Treguaco. Where **Pick the
  commune from a list** shows its dropdown, a shopper can only pick a commune
  anyway.
- Store pickup stays where deliveries stop (owner's decision, 2026-11-21).
- The old RUT script ticked "Chilean" while nothing was ticked; this one ticks
  nothing, and with no choice made the field's own required flag decides.
- The old express plugin's filters (`ynk_bloqueo_comunas`,
  `ynk_bloqueo_is_comuna_bloqueada`, `ynk_bloqueo_envio_method_matches`) go
  with it: a snippet built on them stops acting.
