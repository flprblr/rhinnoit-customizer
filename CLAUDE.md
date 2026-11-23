# Plugin conventions

Private WordPress plugin by RHINNO IT, based on `wp-plugin-template`: its first
commit is the template at `634cb2b`, the fork point of every resync. It adjusts
the WooCommerce cart, checkout, orders and store pickup, and does nothing
without WooCommerce. It is never published on wordpress.org. Read this before
writing code.

Its features come from RHINNO IT - WP Optimizer 2.8.13
(`rhinnoit-wp-optimizer`, prefixes `RWPO_` / `rwpo_`, namespace
`RhinnoIT\WpOptimizer`), which was split in two: this plugin took the cart,
checkout, order and store features, and RHINNO IT - Optimizer the delivery and
page cache ones. Each of those feature files is WP Optimizer's, byte for byte,
with the identity renamed and two kinds of hand edit: the copies of the
WooCommerce names the Optimizer also holds (see Layout), and the keys that keep
WP Optimizer's spelling on purpose (see Prefixes). The exception is
`Commune_Select`, which 1.1.0 gave `region_names()`, for the screen's commune
lists.

1.1.0 added four features of this plugin's own, built on the ideas of four
older plugins of the owner's, which it replaces: `Rut_Validation`,
`Birthdate_Style`, and the two shipping rules, `Shipping_Block` and
`Shipping_Exclusion`, with the helper they share, `Shipping_Communes`. Only
the ideas were taken, never the PHP or JavaScript; the birth date field's CSS
rules, and the RUT field's error and disabled colours, are the old plugins'.
See Communes, shipping rules and checkout fields below, and *Replacing the old
plugins* in the README.

Everything in this repository is written in English: code, comments, docblocks,
UI strings and documentation.

## Environment

- PHP 8.3 or higher (`Requires PHP` in the header, `testVersion` in
  `phpcs.xml.dist`, `"php"` in `composer.json`).
- WordPress 6.5+ and WooCommerce 8.0+ (`Plugin::MIN_WC_VERSION`). There is no
  `Requires Plugins` header, on purpose: the plugin activates on any site, and
  without a supported WooCommerce it registers no service.
- The plugin goes on every client, on any host and theme, with its defaults
  (owner's decision, 2026-10-16). The measured shops run Porto on Kinsta or on
  cPanel, and those are the cases the features are tuned for; anything else
  must fail soft. There are no per-host profiles: one set of defaults serves
  every site. What really depends on the theme or another plugin is decided by
  the plugin from what it can read - Porto from `get_template()`, WeLaunch
  Multi Inventory from its bootstrap function, Fintoc from its constant - and
  a switch that cannot act there is not offered, never by asking the admin to
  pick a profile.

## Layout

- One class per file, under `includes/`, named `class-{name}.php` (hyphens
  instead of underscores, all lowercase).
- The subfolder inside `includes/` is the namespace segment:
  `includes/admin/class-settings-page.php` →
  `RhinnoIT\Customizer\Admin\Settings_Page`. The autoloader
  (`includes/autoload.php`) relies on that mapping; do not use Composer to
  autoload the plugin code.
- The two halves are two places. The WordPress half lives at the top of
  `includes/` and runs on every site; here it is the shell alone (`Assets`,
  `Install`, `Logger`, `Settings` and the admin classes), and it touches
  WooCommerce only behind a guard: `Plugin::woocommerce_is_active()`,
  `function_exists()` / `class_exists()`, or ids that only exist where
  WooCommerce is supported. The shell's own cases are `Logger::log()`,
  `Plugin::declare_woocommerce_compatibility()`, `Settings::conditions()`,
  which asks `Coupon_Split` only behind the WooCommerce answer, and
  `Assets::enqueue_admin()`, which enqueues WooCommerce's selectWoo only
  behind the same answer and only once WooCommerce registered it. Every service
  of this plugin needs WooCommerce, so all of them live in `includes/frontend/`
  under `...\Frontend` (`Widget_Thumbnail`, `Widget_Sale_Price`,
  `Birthdate_Style`, `Express_Checkout`, `Checkout_Focus`, `Commune_Select`,
  `Rut_Validation`, `Cart_Sale_Price`, `Coupon_Split`, `Shipping_Block`,
  `Shipping_Exclusion`, `Inventory_Links`, `Quick_View_Store`, `Size_Stores`,
  `Store_Choice`, `Pickup_Stores`, `Store_Stock_Hold`, `Unpaid_Orders`,
  `Fintoc_Session_Expiry`, plus two helpers that are never registered:
  `Cart_State`, which two of them ask, and `Shipping_Communes`, what the two
  shipping rules share), and `Plugin::__construct()` registers them only when
  `Plugin::woocommerce_is_supported()` is true. A new one goes there too,
  whatever else it does.
- They register in that order. The WeLaunch scripts go on WeLaunch's own
  handle (`Inventory_Links::HANDLE`): `Inventory_Links`', `Quick_View_Store`'s
  and `Size_Stores`' at priority 99 print in the order they register, and
  `Store_Choice`'s at 100 ticks from the radios the `Size_Stores` script sets.
  `BootWithWeLaunchTest` pins it.
- Service classes are `final`, expose `register()` and hook **only** there. The
  logic goes in separate methods.
- Every new service is instantiated in `Plugin::__construct()`. Do not add
  containers or service providers.
- Every feature reads its own switch through `Settings::feature()`, which
  answers no for all of them while the master switch (**Enable**) is off, so
  unchecking it stops the plugin's work everywhere. A switch is read once, in
  `register()`, never per callback. `Assets` is the exception: it registers
  either way, because the admin bundle has to load with the switch off.
- `Settings` is not a service: it is a static helper (field definition and
  option reading). The UI lives in `Admin\Settings_Page`.
- Never name a class, constant or function of another plugin of the group,
  RHINNO IT - Optimizer above all. PHP evaluates every class constant on the
  first `new`, so one constant built from another plugin's class is a "Class
  not found" fatal on every request of every shop, admin included. Copy the
  value instead: `Inventory_Links::CART_COOKIE` and `SESSION_COOKIE` are
  WooCommerce's names, which the Optimizer holds too, and
  `Inventory_Links::CART_PAGES` is kept in step with the Optimizer's
  `Shop_Prefetch::PAGES` by hand. What has to cross between the two goes
  through the group hooks (see Interface), never through code.

## Naming

- Visible name: `RHINNO IT - <what the plugin does>`, in English. The
  `RHINNO IT - ` half is literal in the `Plugin Name:` header, so it survives
  the rename; only the part after it is a placeholder.
- Directory and slug: `rhinnoit-<what-it-does>`, English, lowercase, hyphens.
  The slug is also the text domain and the log source. The template's
  `bin/new-plugin.sh` forced the prefix when this plugin was created; nothing
  here re-checks it.
- `RCUS_MENU_TITLE` carries the bare name, with no prefix: the shared node it
  hangs from already says RHINNO IT.
- `tests/PackagingTest.php` checks the two halves it can see from inside the
  plugin: the header must carry the prefix, the menu entry must not. The
  directory rule has no test.

## Prefixes

- Global constants: `RCUS_`
- Options, transient keys, own hooks: `rcus_`. CSS classes and script and
  style handles: `rcus-` (BEM inside the scope: `.rcus-settings
  .rcus-card__title`, handle `rcus-cart-sale-price`). JS globals: `rcus` +
  PascalCase, though there is none today: every inline script keeps to its
  own closure. The two group hooks are the exception: see Interface.
- Four keys keep WP Optimizer's `rwpo` spelling, because pages cached or
  still open from before the switch, and orders WP Optimizer marked, carry
  them: the order meta `_rwpo_store_units_taken`
  (`Store_Stock_Hold::UNITS_TAKEN`), the cookie `rwpo_cart_store`
  (`Store_Choice::CART_STORE_COOKIE`), the posted marker field
  `rwpo_store_choice` (`Store_Choice::MARKER`) and the class
  `rwpo-sale-price` (`Cart_Sale_Price::WRAPPER`), which the mini cart a tab
  stored before the switch carries. The option `rcus_store_choice`
  is that same string renamed, so a blind rename pass gets the marker wrong.
  `Install::LEGACY_PREFIX` (`rwpo_`) and `Plugin::LEGACY_CONSTANT`
  (`RWPO_VERSION`) keep it too, because they name WP Optimizer itself. Never
  rename any of the six, and never give a new key the `rwpo` prefix.
  `KeptKeysTest` pins them with the old prefix built in two pieces, so a
  rename pass cannot rewrite the expectation along with the code. Everything
  else of WP Optimizer's was renamed, `rcus_stores` (`Size_Stores::KEY`)
  included: it is never stored, and both of its readers are here.
- The admin page slug is the **plugin slug**, not the prefix: `?page=` says
  which plugin the screen is. `Menu::PAGE_SLUG` holds it, and comparisons use
  the constant - a literal would be renamed through the prefix instead and
  drift.
- PHP namespace: `RhinnoIT\Customizer`
- Text domain and log source: `rhinnoit-customizer`

## PHP

- Types on every parameter and return value, hook callbacks included.
- Do **not** use `declare( strict_types=1 )`: WordPress passes loosely typed
  values through the hooks and it raises `TypeError` in production.
- Register hooks with `array( $this, 'method' )`, not with the first-class
  callable syntax (`$this->method(...)`): that one creates a Closure and makes
  the hook impossible to remove with `remove_action()`.
- Use what 8.3 already offers (`str_starts_with`, `match`, typed class
  constants, typed properties, constructor promotion) instead of the old forms.

## WordPress rules

- Every PHP file starts with `defined( 'ABSPATH' ) || exit;`.
- Sanitize on input (`sanitize_text_field`, `absint`; `wc_clean` only in the
  WooCommerce half), escape on output (`esc_html`, `esc_attr`, `esc_url`,
  `wp_kses_post`). Every dynamic value the admin screen prints has a case fed a
  value with markup in it: today that is the stored option values
  (`SettingsPageTest`, and `SettingsFieldTypesTest` for lists and texts), a
  multiselect's choices and group names from a callback
  (`SettingsFieldTypesTest`), and the shipping zones' names and their
  methods' titles (`ShippingBlockTest`). Print a new one and it needs its own.
- Nonce + `current_user_can()` on anything that writes.
- Every visible string inside `__()` / `esc_html__()` with the plugin text
  domain.
- Yoda conditions and docblocks on classes, methods and properties: `composer
  lint` runs the `WooCommerce-Core` ruleset and fails without them.
- `composer analyse` runs PHPStan at level 8 and must stay clean. Add a new
  runtime constant to `phpstan-bootstrap.php` as well, or the analyser will not
  find it, and so with a theme function a feature names as a callback:
  `porto_image_srcset_filter_sizes()` is there for `Widget_Thumbnail`.
- `composer test` runs PHPUnit with WordPress stubbed in `tests/bootstrap.php`.
  A fix for anything that could fatal on a storefront request gets a test that
  fails without the fix. Add new stubs to the bootstrap (WooCommerce ones to
  `tests/stubs/woocommerce.php`, below), not to the test files.
  A test class per file: PHPUnit only discovers the class whose name matches the
  filename, so a second one in the same file is silently never run.
- Behaviour gated on a constant needs a case in its own process, since the suite
  has already defined it. `WC_VERSION` comes from `RCUS_TESTS_WC` for that
  reason (`7.9` below the minimum, `none` no WooCommerce, `8.7.1` a supported
  one older than the coupon split's 8.8), and the child inherits the
  environment the class sets.
- `RCUS_TESTS_WELAUNCH=1` makes the bootstrap define
  `welaunch_woocommerce_multi_inventory_run()`, for the cases that run with
  WeLaunch Multi Inventory active (`InventoryLinksTest`, `QuickViewStoreTest`,
  `SizeStoresTest`, `StoreChoiceTest`, `PickupStoresTest`,
  `StoreStockHoldTest`, `BootWithWeLaunchTest`, `BootWithOldWooCommerceTest`,
  `BootWithWpOptimizerTest`, `SettingsWithoutPortoTest`,
  `QuickViewStoreWithoutPortoTest`, `HookArityTest`); the
  `*WithoutWeLaunchTest` cases run in the shared process, where it is absent.
  A feature built on WeLaunch asks for that function in `register()` and has a
  case on each side. `RCUS_TESTS_WP_OPTIMIZER=1` defines WP Optimizer's
  `RWPO_VERSION` (`BootWithWpOptimizerTest`), and `SettingsWithFintocTest`
  defines Fintoc's `FINTOC_WC_VERSION` in its own process.
- The Porto stubs live in `tests/stubs/porto.php`, loaded unless
  `RCUS_TESTS_PORTO=none`. A feature built on a Porto function checks that the
  function exists and steps aside otherwise, and has a case in its own process
  proving it (`WidgetThumbnailWithoutPortoTest`,
  `WidgetSalePriceWithoutPortoTest`, `QuickViewStoreWithoutPortoTest`,
  `SettingsWithoutPortoTest`). Ask for a Porto function when the output is
  printed, never in `register()`: plugins boot before the theme's
  `functions.php`, so there it never exists. `get_template()` can be asked
  anywhere: a feature built on Porto's stylesheet rather than a function -
  `Birthdate_Style`, on its input border's CSS variable - asks it in
  `register()`, as `Quick_View_Store` does, which is the answer the Porto mark
  gives, and has its case too (`BirthdateStyleWithoutPortoTest`).
- `Widget_Thumbnail` edits Porto's `<img>` with core's `WP_HTML_Tag_Processor`,
  using only what 6.5 already has (`next_tag()`, `get_attribute()`,
  `set_attribute()`, `get_updated_html()`). The bootstrap loads core's HTML API
  from `tests/stubs/html-api/` (7.1.2, unchanged) when it is missing, so its
  cases run the real parser.
- `Coupon_Split` writes its split into `WC_Discounts::$discounts`, because
  WooCommerce has no hook for the split itself. Its cases run WooCommerce's own
  `WC_Discounts` and `NumberUtil`, which the WooCommerce stubs load from
  `tests/stubs/woocommerce-core/` (11.2.2, unchanged); `WP_Stub::$live_filters`
  runs a callback where such a class calls one through a filter.
- The WooCommerce stubs live in `tests/stubs/woocommerce.php`, which the
  bootstrap loads only when `WC_VERSION` is defined. A process started with
  `RCUS_TESTS_WC=none` therefore has no WooCommerce function or class at all,
  exactly like a plain WordPress site, and code of the WordPress half that
  calls into WooCommerce by mistake fails its test with a fatal instead of
  passing on a stub. Keep them there.
- `tests/HookArityTest.php` registers every service and fails when a hooked
  method needs more arguments than its hook passes - an `ArgumentCountError` on
  every request that fires it. A new service goes into its list. It runs with
  WeLaunch active, so the WeLaunch services hook too. A static method hooked
  by its class is checked like an object's, since the stubs record its class.
- A filter that WordPress core feeds is tested with the value core really
  passes, read from core's source - not with the value it resolves to later. A
  case fed the resolved value passes while the feature does nothing on the
  shop.
- `__()` hands every text back unchanged until a case fills
  `WP_Stub::$translations`, which stands for the translations the plugin loads
  on `init`. The services register on `plugins_loaded`, before that, so a
  translated text read in `register()` would print in English on every shop;
  the shipping messages' cases and the RUT messages' fill it after registering
  to pin that.
- `.github/workflows/ci.yml` runs all three plus the JS linters and the build.

## Options

They are declared once in `Settings::fields()`. From there come the default
values (`Settings::defaults()`, on activation), the form
(`Admin\Settings_Page`), the list the uninstall guard checks
(`Settings::option_ids()`) and the copy of WP Optimizer's values (see WP
Optimizer, below). Adding an option means touching `fields()`, the list in
`uninstall.php` and the catalogues in `languages/`, since its `title` and
`desc` are strings (see Translations). A test fails if you skip the uninstall
list, whether or not the field declares a `default`.
`PluginTest::test_defaults_come_from_the_field_definition` and
`SettingsPageTest::test_every_checkbox_is_drawn_as_a_switch` list the options
one by one, and take the new one too. The second counts only what the form
draws in the shared test process, so an option whose `'needs'` that process
lacks stays out of its count, and its message says it is left out.

A default reaches only a fresh install and an option an update adds
(`Install::seed_defaults()` uses `add_option()`): changing one never flips a
site that already has the row. A `textarea`'s default is the exception: it is
a translated sentence, and `Settings::defaults()` is first built on
`plugins_loaded`, before the translations load, so seeded it would sit in the
row in English on every shop. `defaults()` leaves it out, the row stays empty
until the screen is saved, and `Settings::text()` translates the default
wherever it is printed. Saving the screen stores what the box shows, in the
admin's language.

The list has one section per domain, in this order: the master switch alone in
an untitled one, then WooCommerce, Porto and WeLaunch Multi Inventory (owner's
decision, 2026-10-06), each with its own groups. There is no WordPress
section: nothing this plugin does works without WooCommerce. Every row of the
last three, its `title` and `sectionend` included, carries `'requires'` with
its section's mark: `Settings::REQUIRES_WOOCOMMERCE`, `REQUIRES_PORTO` (the
theme's own WooCommerce markup: a supported WooCommerce and `get_template()` is
`porto`, the same mark and the same answer as the Optimizer's) or
`REQUIRES_MULTI_INVENTORY` (a supported WooCommerce and WeLaunch's
`welaunch_woocommerce_multi_inventory_run()`). `Settings::available_fields()`
drops a section whole on a site without what it needs, and the form draws and
saves that filtered list. Everything else reads the full list: an option still
exists while its section is hidden, and gets back its value the day the missing
piece is installed. A feature that works on Porto's or WeLaunch's own output
goes in their section, not in WooCommerce's.
`PluginTest::test_each_conditional_section_is_marked_row_by_row` walks the list
and fails when a row sits on the wrong side of a mark; a new conditional card
adds its title id and mark to the map there.

The screen offers only the options that can act on the site (owner's request,
2026-10-16). An option that needs more than its section promises carries
`'needs'`, a list of marks (`Settings::NEEDS_*`, or a section mark such as
`REQUIRES_PORTO`), and `available_fields()` drops it where any is missing. Each
mark is answered in `Settings::conditions()` from what is installed - a
plugin's constant, the theme, WooCommerce's version, the class a feature
writes into - and must be the same condition the feature itself stops at:
hiding an option turns nothing off, and `Settings::feature()` still reads its
row. Never a mark read from another plugin's settings (Hold stock, the
checkout block, WeLaunch's own options): a switch must not come and go when
someone changes a setting elsewhere. What depends on the theme or another
plugin is read this way, never asked: there is no profile for the admin to
pick. Today's needs are `NEEDS_FINTOC` (`FINTOC_WC_VERSION`),
`NEEDS_COUPON_ITEMS` (`Coupon_Split::can_split()`, imported by `Settings`) and
`REQUIRES_PORTO` on the quick view switch. A new mark goes into `conditions()`
in the same change as its first field; `SettingsNeedsTest` fails on a mark it
does not answer, since a typo would hide the option on every site. Each plugin
of the group declares and answers only its own marks: one built on another
plugin's class is a fatal whenever the screen is drawn or saved. A card left
with no option is not drawn at all. The rules live in
`Settings::fields_for_marks()`, pure so the cases pin them with fields of their
own.

Every switch ships `'default' => 'yes'` except the four that change the
shopper's journey: both skip-the-cart switches and hiding the checkout's coupon
and login prompts, which ship `'no'` (owner's decision, 2026-10-16: the plugin
goes on every client with its defaults, and hidden switches keep it from doing
anything where it cannot). The two shipping rules ship on with their lists
empty, which leaves them inert until the owner picks what they act on. The
master switch ships `'yes'` too. Its declared default is what a row never
saved means, for `Settings::is_enabled()` as for every `feature()`: the
template ships it off, this plugin ships it on, and the shell stays the same
either way.

A row that is form structure rather than an option (`title`, `sectionend`)
carries `'is_option' => false`. `Settings_Page::sanitize_submission()` checks
that flag, and a test isolates it: without it a crafted post stores the section
id as an option nothing reads.

An option that holds a list - a `multiselect` - is read through
`Settings::chosen()`, once, in `register()`, and one that holds a text - a
`textarea` - through `Settings::text()`, once, the first time it is printed
(see the textarea default above: read in `register()`, a row never saved
reads as English). A row can hold anything.
`chosen()` reads a non-array as the field's declared default, because it
cannot be filtered at all (`array_filter()` on a string is a `TypeError` on a
storefront page), while an empty array is a real choice - it is what
`Settings_Page::sanitize_submission()` stores when nothing is selected. Then it
keeps only strings, and only the ids the field's static `'options'` offer. A
field whose choices come from its `'options_callback'` cannot be asked on a
storefront request, so it declares an `'id_format'` - `Settings::DIGITS` for
shipping method instance ids - and `chosen()` keeps the strings that match it,
or nothing without one. Filtering per field, not against a union, is what
keeps an id one list could never have offered from surviving a crafted post.
`text()` reads anything but a string as the declared default, and an empty
string as the cleared box it is. Their pure forms, `chosen_from()` and
`text_from()`, are what the cases hand fields of their own.

One switch per feature: never put two features behind one checkbox, because the
screen exists to turn off the one that misbehaves and keep the rest. `'group'`
is the small label the screen prints above a run of related switches. When an
existing switch is split, each piece carries `'inherits' => '<old key>'`:
`Install::inherit_split_options()` hands the old value down on the update, and
until it has run `Settings::feature()` reads the old row itself, so the
storefront never falls back to the defaults in between. The old row is kept for
a rollback; `Settings::retired_option_ids()` feeds the uninstall list, which a
test checks, and `Settings_Page::save()` refuses a form that still names a
retired switch. A switch removed outright, with nothing to inherit it, goes into
`Settings::REMOVED` for the same three reasons. Both are empty: the plugin
started at 1.0.0 with WP Optimizer's switches already split, and nothing has
been retired since.

Each `checkbox` says what it does in a few plain words (owner's request,
2026-10-28): a `title` of a few words that starts with a verb, and a `desc` of
one sentence saying what the owner or the shopper notices - no history,
measurements or internals. The only caveats it keeps are the ones needed to
decide: which pages to check where it can change a layout, and which switch it
needs, named by that switch's title. A `multiselect`'s `title` names what is
picked and a `textarea`'s names what is written; each `desc` is one sentence
of what the owner or the shopper notices, under the same rules.

Do not add a switch that does nothing on the shops measured, however standard
it is elsewhere, and measure again before relying on an old answer: check the
live pages of the shops before adding one.

Do not add a switch for anything `wp-config.php` sets (`WP_POST_REVISIONS`,
`DISABLE_WP_CRON`, memory limits, debug): a constant there always wins, so the
switch could only be overruled or disagree with it.

## Interface

- The plugin contributes **one** entry to the shared menu node, and that entry
  is one screen: the settings form (`Admin\Settings_Page`) and nothing else.
  Reports and diagnostics do not go there; they crowd the switches the screen
  exists for. `Admin\Menu` draws the heading; the form prints itself. No tabs -
  each domain is a card of the one form, and a tab bar would cost a click on
  every visit.
- `Menu::register_pages()` ends with `remove_submenu_page()`. Without it the
  node and the back-link core adds to it both 404: WordPress gives a top-level
  item the href of its first child, and the shared parent has no callback.
- `Menu::move_below_dashboard()` filters `menu_order` to keep the node under
  Dashboard, at `PHP_INT_MAX` so it runs last (Site Kit splices its own node
  right after Dashboard from a filter it adds on `init`, and at the same
  priority it took the slot), and `Menu::enable_menu_order()` returns true so
  WordPress honours it. `MENU_POSITION` alone cannot: on a taken slot `add_menu_page()` breaks the
  tie with a hash of the slug, so the node lands wherever chance puts it.
- The parent node and entry names live in the `RCUS_MENU_PARENT_SLUG`,
  `RCUS_MENU_PARENT_TITLE` and `RCUS_MENU_TITLE` constants of
  `rhinnoit-customizer.php`. The first two, plus `Menu::PARENT_ICON`,
  `Menu::MENU_POSITION` and `Menu::CAPABILITY`, must hold the same value across
  every plugin of the group. Do not give them the plugin prefix.
- Two hooks belong to the group, not the plugin, and carry no prefix either:
  `Settings_Page::SAVED_HOOK` (`rhinnoit_settings_saved`), fired after every
  save of the screen, and `Install::DIRTY_HOOK` (`rhinnoit_page_cache_dirty`),
  fired with `'site'` when an update changes the version, and with a reason and
  a product id by anything else that makes cached pages stale. Every plugin of
  the group fires the same two, so the one that clears the page cache (today
  the Optimizer) hears all of them; with no listener they do nothing. They are
  the group's contract, not example code. Like the menu constants they hold the
  same value in every plugin, and `AdminTest` pins it. This plugin fires both,
  and listens to `SAVED_HOOK` only to bump WooCommerce's shipping version
  (`Shipping_Communes::refresh_rates()`, whatever the switches say), so the
  rates each shopper's session stored follow the shipping rules at once. It
  hooks it by `Shipping_Communes::SAVED`, the name written out and pinned to
  `SAVED_HOOK` by a test, as the Optimizer's `Page_Cache` does: the services
  register on every storefront request, and reading the constant would load
  the admin class there. What it prints in cached pages stays fresh only
  through the Optimizer.
- Check permissions with `Menu::capability()`, never `Menu::CAPABILITY`
  directly. It answers `Menu::CAPABILITY` (`manage_woocommerce`, shared by the
  group) where WooCommerce is active and `Menu::FALLBACK_CAPABILITY`
  (`manage_options`) where it is not: WooCommerce registers
  `manage_woocommerce` itself, so on a plain WordPress site nobody holds it and
  the page would be invisible to everyone.
- `Menu::PARENT_ICON` is a Dashicons class. The plugin that creates the node is
  the one whose icon shows, and load order decides which, so it changes in every
  plugin of the group at once or not at all.
- Do not create loose pages outside the node: one entry per plugin is what
  keeps it readable on a shop running several.
- Forms post to `admin-post.php` with `wp_nonce_field()` +
  `check_admin_referer()` + `current_user_can()`, and redirect after saving.
- The form draws its own fields, because `WC_Admin_Settings` does not exist
  without WooCommerce. The look is the card-and-row pattern of
  `@wordpress/components` in plain markup, styled by `src/admin.scss` and
  scoped under `.rcus-settings`: stay inside WordPress's own design language,
  no framework and no React app for a form of switches. Checkboxes are drawn
  as switches but stay real checkboxes with `role="switch"`, so the page works
  the same without the stylesheet. It knows five types: `title`,
  `sectionend`, `checkbox`, `multiselect` and `textarea`. A `multiselect` is a
  native `<select multiple>` named `id[]` with WooCommerce's
  `wc-enhanced-select` class, which WooCommerce's own script turns into the
  searchable box of its "Sell to specific countries" setting, each choice kept
  as a chip with an x; without the script it is a native multiple select that
  posts the same list. Its choices are static `'options'` - id => label, or a
  group key => (id => label) drawn as an `<optgroup>`, which a
  `'group_labels'` callback can name when the form draws it - or, for choices
  that live in the database, an `'options_callback'` the form calls only when
  it draws or saves the screen, never on a storefront request. A `textarea`
  is one text, sanitized with `sanitize_textarea_field()` and printed with
  `esc_textarea( wp_specialchars_decode( ... ) )`: core's sanitizer stores a
  lone `<` and the rest of the text after it as entities, which the shop's
  `esc_html()` shows as typed, so the box decodes them to show what the shop
  shows, and a re-save stores the same row (`SettingsFieldTypesTest`). A
  switch sits beside its text; a list or a text box goes below it and fills
  the row (`.rcus-row--wide`). Adding a type means adding it to
  `Settings_Page::render_field()` and
  `Settings_Page::sanitize_submission()` in the same change: a type one knows
  and the other does not is either unsaveable or unsafe. The save reads
  only the ids the field list declares, each through its own type's sanitizer,
  never the keys of `$_POST`, and only the fields the form says it drew
  (`Settings_Page::DRAWN_FIELD`). A post that lists nothing it drew is refused
  like one from an older version: it would otherwise save nothing and say it
  had.

## Assets

- Sources in `src/`, one entry point: `admin`, loaded only on the plugin page
  (`Menu::is_current_page()`). `src/admin.js` and `src/admin.scss` are the
  shell and stay even while the first is empty, so admin behaviour never
  starts by wiring a bundle. Empty scaffolding is fine; an example feature to
  delete is not.
- On that page, and only where WooCommerce is supported,
  `Assets::enqueue_admin()` also enqueues WooCommerce's own
  `wc-enhanced-select` script and `woocommerce_admin_styles` stylesheet, the
  selectWoo a `multiselect` needs, each only if `wp_script_is()` /
  `wp_style_is()` says WooCommerce registered it, and before the bundle, so
  this plugin's stylesheet comes after WooCommerce's. It runs at priority 20:
  WooCommerce registers its admin stylesheet from an `admin_enqueue_scripts`
  callback at 10 that it adds on `init`, after this plugin's, so at 10 there
  was nothing registered yet.
- The storefront has no bundle. What a feature prints there is inline, on a
  handle already on the page: `wp_add_inline_script()` on WooCommerce's
  `wc-checkout` or WeLaunch's `woocommerce-multi-inventory-public`
  (`Inventory_Links::HANDLE`), and `wp_add_inline_style()` on WeLaunch's
  stylesheet or on a file-less handle of the feature's own
  (`wp_register_style( $handle, false )`). It costs no request and loads only
  where what it extends loads. Add a `frontend` entry point only with a real
  need for it, enqueued from `Assets` behind a switch, because anything it
  loads costs every visitor; the template's storefront bundle is the one to
  bring back.
- Build with `npm run build`. `build/` is in `.gitignore`, so it has to be
  compiled before deploying (or drop that line if the deployment copies the
  repository as-is).
- Enqueue a built file only from `Assets`, through the helper that reads
  `build/*.asset.php` (dependencies and version come from there; never
  hardcode them). That helper validates the manifest and catches a
  `ParseError`: a half-uploaded build must degrade to "no script", never to a
  fatal error.
- Pass data to JS with `wp_add_inline_script` + `wp_json_encode`, not with
  `wp_localize_script` (it casts the values to string).

## Never break the shop

`wp_enqueue_scripts` and the storefront hooks run on every request, so anything
reached from there has to fail soft:

- Validate whatever comes from the filesystem or the database before using it.
  A `require`d file can return anything, or fail to parse.
- Never print the result of `wp_json_encode()` into inline JS without checking
  it: `false` would emit `window.x = ;` and break every script on the page.
- Guard `$_GET` / `$_POST` reads with `is_string()` before sanitizing: a query
  string can send an array.
- Decide which page this is on the `wp` action and keep the answer. Inside a
  render filter (`the_content`, `wp_get_attachment_image_attributes`) the
  queried object may already point somewhere else - a theme's posts grid
  re-points it at every item it renders, so a filter asking `is_product()`
  there answers about the grid item.
- An option that holds a list gets filtered against the ids the code knows
  before it is used, elements included: `is_string()` before comparing, since
  casting an array to a string is a warning on a storefront page.
- Throttle anything that writes to the log from a storefront code path:
  `Logger::error_once( $key, $message )`, with a key specific to that failure.
  One key shared by two failures means the first one silences the second for
  the whole window. `Logger::error()` has no window on purpose, for the paths
  that run once.
- Do not wrap service registration in try/catch to hide bugs. Fix the failure
  mode instead; swallowing errors only moves the breakage somewhere harder to
  find.

## Communes, shipping rules and checkout fields

The four features of 1.1.0 live by these rules:

- Chile's communes exist once, in `Frontend\Commune_Select::COMMUNES`: the 346
  official names, keyed by WooCommerce's 16 region codes. The checkout's
  dropdown offers them, and `Settings::fields()` builds both shipping rules'
  lists from them - each name its own id, grouped by region and headed with
  WooCommerce's names through `Commune_Select::region_names()` - so where the
  dropdown shows, the city a package ships to is one of the names a rule can
  hold. Never write a second list, or a commune into code. A commune created
  or renamed by law changes there and only there (`CommuneSelectTest` and
  `ShippingSettingsTest` count the 346); a renamed one drops out of every
  saved list, since `chosen()` keeps only the ids its field offers, so the
  release has to say that the shops which had picked it pick it again.
- A package matches by its city alone (`Shipping_Communes::matches()`), both
  sides normalized by `Shipping_Communes::normalize()`; the region is never
  compared. Both rules ask `Shipping_Communes`, never a copy of their own.
- Nothing a rule acts on is written in code - no method, instance id or
  commune - and its message is the owner's, over a translated default: the
  old plugins wrote all of it there, and another zone or another shop meant
  editing them. The owner picks them on the screen. A method is stored by its
  instance id, so renaming it changes nothing.
- **Stop deliveries to some communes** keeps exactly the rates whose method id
  is in `Shipping_Exclusion::PICKUP` - WooCommerce's `local_pickup` and the
  block checkout's `pickup_location` - and takes every other rate, whatever
  zone or plugin offers it (owner's decision, 2026-11-21: only deliveries go,
  store pickup stays). A pickup method another plugin registers under an id of
  its own is a delivery to the rule until that id is added there, with a case.
- Both rules filter `woocommerce_package_rates` at `PHP_INT_MAX`, after the
  couriers' plugins. WooCommerce keeps the rates it calculated in each
  shopper's session, keyed by its `shipping` transient version, so every save
  of the screen bumps that version: `Shipping_Communes::refresh_rates()` on
  `Shipping_Communes::SAVED` (see Interface), hooked by both services before
  they read their switch. Keep it unconditional - what turns a rule off has to
  free the rates it hid, a save as much as a deactivation. So the same static
  callback is hooked to core's `activated_plugin` and `deactivated_plugin`,
  for which WooCommerce bumps nothing (the old shipping plugins' deactivation
  among them), and `Install::activate()` and `Install::deactivate()` call it,
  the second registered with `register_deactivation_hook()` in the main file
  (`PackagingTest` reads both), since this plugin's own deactivation turns its
  rules off, and the request that activates it registers no service to hear
  `activated_plugin`. Make anything else that ever writes these options (a
  migration, an import) bump it too, with
  `WC_Cache_Helper::get_transient_version( 'shipping', true )`.
- The submit check (`woocommerce_after_checkout_validation`) reads two things.
  A chosen rate still in the rates WooCommerce stored before the rule changed
  is in the session (`Shipping_Communes::chosen_rates()`). A rate the submit
  posted that is gone from the rates WooCommerce worked out again is only in
  the posted data: WooCommerce moves that package to its default rate - the
  first one left on the classic checkout - and places the order without a
  word, so `Shipping_Communes::posted_rates_gone()` reads
  `$data['shipping_method']` by package index, and a refusal of that kind also
  sets `refresh_totals` (`Shipping_Communes::redraw_review()`), which has the
  checkout's script redraw the review. A package with no rate left is skipped
  (`Shipping_Communes::has_rates()`): WooCommerce refuses it itself, saying no
  shipping method has been selected, and a rule's notice would only repeat
  that, or ask for another method where there is none. Each rule refuses
  once, at the first package it takes, so a cart of several packages gets one
  notice per rule. Whether a rule took a posted id is read from the id
  itself, never only from what ran in this request, since an earlier request
  may have worked the rates out: Stop deliveries takes every id whose method
  id, before the first colon, is not in `PICKUP`; Hide shipping methods one
  whose second segment is a chosen instance id and whose first is that
  instance's own method id (a zone rate is `method_id:instance_id[:suffix]`,
  and a method outside every zone builds the same shape with a number of its
  own, as `pickup_location:N` does with a location), or one `hide_rates()`
  took in this request, for ids of another shape.
- Choices that live in the database - today every zone's shipping methods,
  `Shipping_Block::method_choices()` - are built only when the screen draws or
  saves: they come from a field's `'options_callback'`, which only
  `Settings_Page` calls, and `Settings::chosen()` checks the stored ids by the
  field's `'id_format'` instead. A storefront request never lists the zones;
  the message row asks WooCommerce for the one zone its package matches, as
  WooCommerce itself does to rate it, and only the submit reads one
  instance's method id, from its zone row
  (`WC_Shipping_Zone_Data_Store::get_method()`), for a posted id
  gone from a package of the communes that names a chosen instance. Never
  `WC_Shipping_Zones::get_shipping_method()`: it builds the method's class,
  a fatal at the submit when a shipping plugin registered a class it never
  defines, and its test stub throws. `ShippingSettingsTest` and `ShippingBlockTest` count the listings
  (`WP_Stub::$zone_listings`), and `ShippingBlockTest` the instance reads
  (`WP_Stub::$method_lookups`). A `'group_labels'` callback, too, runs only
  when the screen draws.
- `billing_rut`, its Chilean/foreigner radio `billing_is_chilean` and
  `billing_birthdate` belong to another plugin, which adds them to the checkout
  (owner's decision, 2026-11-21). This plugin never creates, removes or
  reorders them, and never ticks the radio: `Rut_Validation` takes only
  `billing_rut`'s `required` flag and `validate-required` class (what the flag
  said stays as `data-rcus-required`, and its script keeps the label's
  required mark true), formats and validates it, and empties and disables it
  for a foreigner; `Birthdate_Style` only styles `billing_birthdate`. Each does
  nothing on a checkout without its field. Their switches are offered
  wherever their card is, since no mark can tell what another plugin's
  checkout holds. The radio's value is read through `Rut_Validation::choice()`
  (and the script's `n()`, which mirrors it), never compared raw.
- These features act on the classic (shortcode) checkout, which the shops use:
  the RUT check, the birth date field, and the shipping rules' message and
  submit check. The block checkout draws its own fields and runs none of their
  hooks, so there they do nothing and break nothing, and
  `cart_checkout_blocks` stays declared compatible. The rules' rates go on
  both, since `woocommerce_package_rates` serves both.

## WP Optimizer

Every shop this plugin goes on ran WP Optimizer first. Two pieces hand it
over, and both go once no shop runs WP Optimizer:

- `Plugin::boot()` boots nothing but a notice while WP Optimizer's constant
  (`Plugin::LEGACY_CONSTANT`) is defined: that plugin runs the same features
  under its own switches, and with both booted the shop would get each change
  twice. It asks on `plugins_loaded`, never at file scope: WordPress loads the
  active plugins in the order of their folder names, and this one comes
  before `rhinnoit-wp-optimizer/`. The activation hook is not booted from
  there, so the copy still runs beside WP Optimizer.
- `Install::migrate_legacy_options()` copies the `rwpo_` row of each of
  `Settings::option_ids()` with `add_option()`, first in `seed_defaults()`:
  after the defaults, `add_option()` would keep them and the copy would do
  nothing. It never overwrites and never deletes, and runs on activation and
  on each version change.
- `uninstall.php` never deletes an `rwpo_` row - WP Optimizer's own uninstaller
  does, and deleting them here would reset WP Optimizer on a site that still
  runs it - nor `_rwpo_store_units_taken`, which is order data.
- A switch moved between this plugin and the Optimizer later needs its own
  hand-over: the copy reads only `rwpo_`.

The four kept keys stay when the rest goes: they are this plugin's own names
now (see Prefixes).

## What not to do

- Do not leave example features, empty hooks or unused options. An empty entry
  point in `src/` is the exception, and the only one: see Assets.
- Do not key a feature to a product, a store id, a page id or a file name.
  Rules are structural - the cart's own lines, the store WeLaunch names as
  delivery or default, the queried product - so they hold when the shop's
  content changes and nobody has to re-apply anything. What the owner picks on
  the screen is the one exception: the shipping rules act on the methods and
  communes chosen there, never on ones written in code.
- Do not add abstraction layers for a single use case.
- Do not use `admin-ajax` when the REST API solves the case.
- Do not query the database directly when a WordPress or WooCommerce API
  exists (`wp_load_alloptions()`, `wp_count_posts()`, `wc_get_orders`,
  `wc_get_products`, `$order->get_meta()`). The one exception is
  `Store_Stock_Hold`'s read of `wc_reserved_stock`, as WooCommerce's own
  `ReserveStock` reads it: there is no API listing those rows, and live rows
  must not be cached.
- Do not add `readme.txt` or wordpress.org directory headers: the plugin is
  private and carries `Update URI: false`.

## Translations

Visible strings are English in the code and ship translated to `es_ES` and
`es_CL` in `languages/`. WordPress does not fall back between Spanish locales,
so both catalogues are kept complete; they differ only in local vocabulary
("añadir" / "agregar"). Changing or adding a string means regenerating the
`.pot`, updating both `.po` files and recompiling both `.mo` files (commands in
the README). `tests/TranslationsTest.php` fails if any of the three steps is
skipped or a translation changes its placeholders; it reads the PHP and the
bundle sources in `src/`, and refuses plurals and contexts (`_n()`, `_x()`)
until it is taught to read them. A string that came from WP Optimizer keeps
WP Optimizer's translations.

## Versioning

The version lives in two places that must match: the `Version:` plugin header
(read by WordPress and by the zip builder) and the `RCUS_VERSION` constant (read
by `Install::check_version()`). Bump both. A test fails if they drift, so CI
catches it. `package.json` deliberately carries no version:
nothing reads it and it would only go stale.

## Packaging

`composer release` runs every check and only then packages the zip, stopping at
the first failure. `composer dist` skips the checks, for when the tree is
already known to be green. Both read the slug and version from the plugin
header, both rebuild `build/` first when `node_modules` is there (the zip has no
`src/`, and a stale manifest ships the old version hash too, so the previous
bundle stays cached), and both exclude every hidden file by rule so
`.distignore` never has to be kept in step with `.gitignore`.

The `.distignore` patterns are anchored to the plugin root. A bare `vendor` line
unanchored also matched `includes/vendor/`, which is where a plugin vendors its
update library. A pattern that has to match at any depth starts with a `*`.

The zip is a build artefact: `dist/` is gitignored and the zip is never
committed. It gets written on its own twice: `.githooks/pre-push` runs
`bin/release.sh` before a push leaves the machine (enable it per clone with
`git config core.hooksPath .githooks`; `git push --no-verify` skips it), and the
CI uploads it as a run artifact. Neither one is a place to put a check that
`bin/release.sh` does not already run.

## Commands

```bash
composer lint        # phpcs
composer lint:fix    # phpcbf
composer analyse     # phpstan level 8
composer test        # phpunit
npm run build        # compiles src/ into build/
npm run lint:js
```
