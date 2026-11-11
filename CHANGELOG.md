# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/).

The panel interface is in Russian: button, menu and section names are given here in English translation. Links to `docs/` lead to documentation written in Russian.

## [4.10.2] — 2026-11-12

### Added

- **Six more ready-made modules for `fastleo:module`**, for a company site: `services`, `prices` (price list), `team`, `branches` (branches and addresses), `certificates` (licenses and certificates) and `partners` (partners and clients). Each copies an admin section, model, migration, factory, seeder and Pest test into the site as your own code, plus the optional public part the command asks about. The six sections share the panel menu group "Company" (`$group` in the resource class).
  - **Addresses:** `/services`, `/services/{slug}`; `/prices`; `/team`, `/team/{slug}`; `/branches`; `/certificates`; `/partners`. Services and team have a page of their own (slug, RichText body, SEO); the others are lists.
  - **Services, price list:** the price is a plain string ("from 1 500 ₽") shown as typed; price list items are grouped by a plain-string group, items without a group first (like the FAQ).
  - **Branches:** phones one per line (ten at most, `Branch::phoneLinks()` builds the `tel:` links), hours of work, optional latitude and longitude; `Branch::mapUrl()` gives a Yandex Maps link by the coordinates (longitude first) or by the address — no iframe and no third-party script in the template.
  - **Certificates:** (the panel section is called "Documents", which fits the side menu) a scan and an optional PDF file; the thumbnail links to the PDF, or to the scan when there is no file.
  - **Partners:** a logo and an optional link; the panel accepts only `http(s)://`, and the template links only such an address, with `rel="noopener nofollow"`.

### Fixed

- **The create and edit forms of a section with a `Seo` field (or a `Repeater`/`Blocks` item with missing keys) failed with a 500 error when the site enabled `Model::shouldBeStrict()`** (the usual advice for local development): a missing key of the stored JSON is an empty value, not an error. The sections of the `news`, `portfolio`, `services` and `team` modules were hit by it.

## [4.10.1] — 2026-11-11

### Added

- **Ready-made modules can install the public part of the site.** After the modules are chosen and before any file is written, `fastleo:module` (and the module step of `fastleo:install`) asks one question: put the public part on the site too — routes, controllers and plain Blade templates without styling — so that only the design is left to apply ([modules](docs/modules.md#публичная-часть)). "No" gives the panel sections only, as before; without a terminal (CI, Claude, `--no-interaction`) the public part is installed. The command has no new flags.
  - **Addresses:** `/contacts`; `/` and any page address (pages, through `Route::fallback`; the untouched Laravel welcome route is replaced, but only together with the stock `welcome` template — a site that rewrote it, or has a route of its own at `/`, keeps it); `/news`, `/news/{slug}`; `/reviews`; `/portfolio`, `/portfolio/{slug}`; `/faq`; the shop: `/shop`, `/shop/categories/{slug}`, `/shop/{slug}`, the cart (`/cart`: POST, PATCH, DELETE) and `/checkout` with a thank-you page. The site menu module adds the header and footer menu templates.
  - **One shared layout** `resources/views/layouts/site.blade.php` (no CSS), created once if missing; the page and section templates extend it (the menu templates are includes for it), so the site design is applied by editing one file and then the section templates. The templates are yours: a template that already exists is kept and reported, a layout without `@yield('content')` is reported too.
  - **The routes of a module are appended to the end of `routes/web.php`** as plain `Route::…` calls with full class names (no `use`) and no comments, so the file stays yours. The block of the pages module (`Route::fallback`, it answers `/` and every page address) stays the last among the module routes: modules added later are inserted above it (your own code appended after the block stays below it; it does not matter for the site, Laravel always matches `Route::fallback` last). Before writing, the command checks whether such an entry already exists: the same code, a route with the same address, a route with the same name; then the public part of that module is skipped and the reason is printed. To remove a module, delete its routes (find them by its controllers or by the names `site.<module>.*`) and its files.
  - **Per-module checks:** a module whose public part is taken (an address, a route name or a file of the site, the block of the module already in `routes/web.php`, a `Route::fallback` of the site, the panel on the domain root, a read-only `routes/web.php`) is installed without it, the other modules with it; the command prints what is taken and still succeeds. The panel part keeps its all-or-nothing check.
  - **Warnings about routes that win over the public part:** a route of the site with an unconstrained parameter (`/{slug}`) swallows `/news`, `/faq` and the other addresses because the module routes are appended to the end of `routes/web.php`; a route of your own at `/` keeps the home page of the pages module from opening; a route such as `/{category}/{slug}` is reported too, because it swallows the record pages (`/news/{slug}`, `/portfolio/{slug}`, `/shop/{slug}`). A site whose `bootstrap/app.php` does not connect `routes/web.php` (API only) is warned that the routes will not work. A `routes/web.php` that holds only blank lines starts with `<?php` before the routes are appended.
  - **The shop path for a buyer:** a catalog, a session cart (50 positions, 99 pieces each; hidden or sold-out products leave the cart by themselves), and an order form protected the way leads are — a one-time form stamp, a trap field, 5 order form submissions per 10 minutes per address, text fields free of NUL bytes, a phone with at least five digits — with a thank-you page that shows the order number only. An order whose total changed between showing the form and sending it (a price was edited) is refused: the buyer returns to the form and sees the new total, as happens when a product sold out or was hidden.
  - **Pages are tolerant of odd data:** a block whose field is not a string (the type of the field was changed) is skipped instead of failing the page, a block field named like a Blade variable (`__env`, `errors`, `obLevel`) cannot replace it, the home page opens at `/` whatever its parent is, and the menu does not read the blocks of the pages.
- **Shop module:** `ShopProduct::available()` (published and not in a hidden category, the parent chain included) and `ShopCategory::visible()`; `ShopOrder::place()` throws `ProductUnavailableException` (with the product id) and `OrderLimitException` (more than 100 positions, 9999 pieces of one product or a total beyond the column), cuts the phone to the length of the panel field and notifies the panel users after the outer transaction commits, at most 20 a minute. The "Orders" section is closed to AI agents (MCP) and to export, as the leads are.
- **Shop:** a category that still holds products or subcategories cannot be deleted (a product without a category is available to buyers, so deleting a hidden category would have shown its products in the catalog). `fastleo:doctor` checks the cache of a site that has the checkout: the `null` driver is an error (every order is answered "the form was already sent"), `array` a warning (the form stamp is not one-time and the limit does not work).
- **Pages and menu examples:** the pages seeder also creates the "Privacy policy" page that the footer link of the menu seeder points to (it led to a 404 page); the "In the site menu" hint of a page says that the header is built by the site menu module when it is installed.
- **Menu and FAQ:** the parent of a menu item is chosen only among the items of the same place (header or footer), because the child of an item of another place was shown nowhere; on the FAQ page the questions without a group come first (without a heading they looked like a part of the previous group); the answer column is `mediumText` (the editor allows more than 64 KB).

- **A "To the site" link in the panel header** (top right, opens in a new tab) when the panel is not the home page itself: a panel under a path (`/fastleo`, `/admin`) leads to the home page of the same domain, a panel on its own domain (`fastleo.domain`) leads to the site address from `APP_URL` (no link when `APP_URL` has the same host as the panel). A panel that takes the root of its domain shows no link. `Fastleo::siteUrl()` returns the address (`null` — no link) ([configuration](docs/configuration.md#адрес)).

### Changed

- **`<x-fastleo::blocks>` passes a block field to the template as a variable only if its name starts with a Latin letter** and is not taken by Blade (`errors`, `obLevel`): `_x`, `__env` and the like stay available through `$block['name']` only ([blocks](docs/blocks.md)).
- **Ready-made modules require Pest.** A module copies its tests onto the site, and they are written in Pest, so `fastleo:module` and `fastleo:install` with modules refuse to run (before anything is chosen or written) when the site has no `pestphp/pest` in `composer.json` and no `tests/Pest.php`; the message gives the commands to install it. An installation without modules does not need Pest. Before, the tests were quietly left out.
- **Module code is copied to the usual Laravel folders, with no `Fastleo` subfolder:** models `app/Models` (`App\Models\Page`), enums `app/Enums`, exceptions `app/Exceptions`, factories `database/factories`, seeders `database/seeders`, and for the public part controllers `app/Http/Controllers`, requests `app/Http/Requests` and the shop cart `app/Http/ShopCart.php`. `app/Fastleo` keeps only what belongs to the panel: resources, the contacts settings page, actions and `Dashboard`; the module tests stay in `tests/Feature/Fastleo`. The model namespace follows the application namespace, not `fastleo.resources.namespace`. A file of the site with the same name (your own `App\Models\Page` or `PageController`) is never overwritten: the panel part of the module is refused and the taken files are listed, and only the public part of a module whose file is taken is skipped.

### Fixed

- **`<x-fastleo::seo>` canonical address of a twin address.** The route is matched against the decoded path (`/shop%2Fphone` opens `/shop/phone`), but the canonical address was built from the raw path, so the twin pointed at itself. The path is decoded and encoded again segment by segment.
- **Shop (public part):** the cart and the checkout page tell the buyer when the quantity of a position was reduced to the stock left (it was cut silently); the cart limit message and the "product is gone" message are shown in the catalog too (a product that sold out between showing and clicking no longer ends on a bare 404 page); the one-time stamp of the checkout form is freed on any unexpected error (a deadlock, for example), not only on the three expected ones, so the same form can be sent again.
- **The length of an email is checked before its format** in the lead form, the leads API and the shop checkout: a string of a million characters was parsed first and took about 2 seconds and 350 MB, now it is refused by the 255-character limit at once.
- **The hint printed when `Dashboard.php` cannot be patched** shows the namespace of your sections instead of a literal `{{ namespace }}`. `fastleo:install --modules=,` (a list without a single name) fails with a message instead of quietly installing nothing.

### Security

Fixes from an independent source review of the whole package (nothing critical or high was found; every item below is low).

- **`HasMany::min()` could be bypassed:** a block with an invented or foreign `id` counted as "kept", although saving skips it, so one such block plus no real ones passed `min(1)` and deleted every real block. Only filled new blocks, blocks that already belong to the record (each once: a repeated `id` saves the record once) and records the form never saw (saving does not delete them) count now.
- **Long lists:** a JSON body with hundreds of thousands of list items (`Repeater`, `Blocks`, `HasMany`, `Gallery`, `Tags`, `KeyValue`, `BelongsToMany`, multiple `Select`) made Laravel expand the `field.*` rules per element before `max` was checked. A list is cut to "limit + 1" before validation (the `max` error stays), also inside the items of `Repeater`, `Blocks`, `HasMany` and `HasOne`; an array where a plain value is expected becomes an empty array. The same applies to settings pages, action mini forms and the old input the form gets back after an error. A multiple `Select` takes at most 1000 values. New `Field::listLimit()`: a custom list field must return the number from its `max:N` rule ([custom fields](docs/fields/custom.md)).
- **`class` in RichText:** when a site allows the `class` attribute, the record page of the panel no longer shows it (the panel's own classes such as `fixed inset-0 opacity-0` turned an inserted link into an invisible overlay). The site and the database keep the classes.
- **Max pictures:** the upload address from the Max API answer is accepted only as `https` on the default port and a public DNS name (no IP address, single-label or internal-zone name such as `.internal`, `.svc` or `.localdomain`, login or other port).
- **Import reports:** the file of skipped rows is named after the uploaded file (a UUID), not after the import number, so it cannot be guessed on a disk the web server serves. Files of earlier versions are still removed with the report; for imports finished before the update the "download all skipped rows" button disappears (the report itself stays).
- **Account lock:** resetting the password by the link from the email lifts the hourly lock of password attempts (the two-factor code counter stays), so an owner whose sign-in was kept closed by a visitor who knows the email can return without server access.
- **Upload blacklist:** `FileTypes::FORBIDDEN` also refuses `bat`, `cmd`, `msi`, `ps1`, `lnk`, `dll`, `mathml`, `gpx`, `xsd`, `wsdl`, `mhtml`, `config`, `cshtml`, `ashx` and other formats, even when a site adds them to `files.extensions`. If a site kept one of these formats (for example `gpx`) in `files.extensions` and already uploaded such files, a record whose field points at one of them no longer passes the file rule on save until the file is replaced.

### Upgrade notes

- Sites that copied the modules of 4.10.0 keep their files in `app/Fastleo/Models`, `app/Fastleo/Enums`, `app/Fastleo/Exceptions`, `database/factories/Fastleo` and `database/seeders/Fastleo`; only new installs use the new folders. The public part of a module (copied by hand from `stubs/modules/<name>/site`) refers to `App\Models`, `App\Enums` and `App\Exceptions`, so on such a site change those namespaces to the old ones or move the files ([modules](docs/modules.md#сайты-с-модулями-из-4100)).
- Sites that copied a module earlier keep their files; the public part is not added to them (a repeated command stops at "table already exists", "section with this address already exists" or "file already exists"). To get it, remove the module (the list of files is in [modules](docs/modules.md#как-убрать-модуль)) and install it again with the answer "yes".

## [4.10.0] — 2026-11-10

### Added

- **"Sign in as"** (`fastleo.users.impersonate`, off by default) — the owner signs in as another user (a manager, for example) to see exactly what they see, and returns with a banner button. Needs an owner the site knows (roles or `Fastleo::resolveOwnerUsing()`); `fastleo:doctor` reports the option turned on without one. The owner is never signed out and no history record or "new device" email is written; the profile, two-factor protection, "sign out other devices", reading notifications and saving the dashboard layout are closed meanwhile, and opened records are not marked as read; the state is checked on every request (the owner is still an owner, the password is unchanged, at most an hour) and any failure signs out this device without touching the user's remember token. `Fastleo::impersonator()` tells the site who is really working ([auth](docs/auth.md#войти-как)).
- **Leads — a built-in part of the package** (`fastleo.leads`, off by default): the "Leads" section, a site form (`<x-fastleo::lead-form />`, `POST /leads`, bot protection without external services) and intake from other sites by API with access keys (`POST /api/leads`, section "Access for sites" with a ready instruction for the site developer). Tables `fastleo_leads` and `fastleo_lead_api_keys` come with the package migrations. The key travels only in the `Authorization: Bearer` header, is stored encrypted with a hash for the search, the route has no session, cookie or CSRF; limits per key and per failed address, a 16 KB body limit, atomic duplicate check, bot-safe notifications with a ceiling per key, and a `fastleo:doctor` error when the site cache keeps nothing (every API lead would be answered as a duplicate) ([leads](docs/leads.md)).
- **Settings from `.env`:** every simple value of `config/fastleo.php` (on/off, numbers, strings, addresses — 67 keys) is read from a `FASTLEO_*` variable named after the key path: `path` → `FASTLEO_PATH`, `auth.two_factor_required` → `FASTLEO_AUTH_TWO_FACTOR_REQUIRED`. A color starting with `#` goes in quotes (`FASTLEO_BRAND_COLOR="#047857"`). Section classes, lists and the resources folder and namespace stay in the file ([configuration](docs/configuration.md#настройки-из-env)).
- **Files page:** smaller tiles (up to 8 in a row) and an eye button on image tiles (shown on hover) that opens the picture in a window.
- **`APP_URL` checks.** `fastleo:doctor` warns when `APP_URL` points to `localhost` (the Laravel skeleton default), and the "Server status" widget shows an error when the panel is opened from an address other than `APP_URL` (a warning when only `www.` differs): file addresses, thumbnails in the panel and links in emails are built from it and do not open otherwise.
- **The code of every ready-made module now lives in `App\Fastleo\…`** (models in `app/Fastleo/Models`, enums, exceptions, factories in `database/factories/Fastleo`, seeders in `Database\Seeders\Fastleo`, site tests in `tests/Feature/Fastleo`), so it never collides with the site's own `App\Models\Page`, factories or seeders. Models point to their factories with `#[UseFactory]`. Sites that already copied a module keep their files as they are; new installs and `fastleo:module` use the new layout, and the "next steps" hint prints seeders with their full class names.
- **Online store module `shop`** for `fastleo:module`: categories (tree), products (price, optional stock, photos, specs, SEO) and orders with item snapshots, statuses (a "Change status" action, cancelling returns the stock), a "Paid" flag and a customer snapshot, as admin sections in one "Shop" menu group (tables `shop_categories`, `shop_products`, `shop_orders`, `shop_order_items`). `ShopOrder::place()` creates an order from the site's cart: prices come from the database, stock is written off atomically, one transaction, admins are notified. The storefront, cart and checkout stay the site's ([modules](docs/modules.md#интернет-магазин)).
- **Four more ready-made modules for `fastleo:module`**: `menu` (site menu: a tree of header and footer items, `MenuItem::tree('header')`), `reviews`, `portfolio` and `faq` (questions and answers). Each copies an admin section, model, migration, factory, seeder and Pest test into the site as its own code (tables `menu_items`, `reviews`, `portfolio_items`, `faq_items`); the lists keep the order set by dragging, and `published()` scopes return the published records in that order. Public pages are still yours ([modules](docs/modules.md#меню-сайта)).

### Changed

- **Neutral wording by default: "panel" and "Users" instead of "admin panel" and "Administrators".** Fastleo is also the base of CRMs and dashboards, where most people who sign in are managers, not site administrators. The built-in users section is now **"Users" / "User"** with the access toggle **"Access to the panel"**; the password reset and "new sign-in" emails, console messages, `fastleo:doctor` hints, MCP descriptions and the generated stubs say "panel" and "user". The "Administrator" role preset and the words about administrators of a Telegram/Max channel and of SoftNetica are unchanged.
- **The `pages` and `news` modules no longer put their sections in the "Content" group**: like every ready-made module except `shop`, they stand ungrouped at the top of the menu under the dashboard. Sites that already copied them keep their code as is; add `protected static ?string $group = '…';` to a section to group it ([modules](docs/modules.md)).
- **`fastleo:module` also checks section addresses**: a module whose section address the panel already has (a site section `news` and the `news` module) is refused before anything is written, instead of breaking the whole panel with a duplicate address.
- **`fastleo:install` no longer gives the first user the name "Administrator"**: `fastleo:user` takes the name from the email, as it does anywhere else.

### Removed

- **The copied `leads` module of `fastleo:module`** — replaced by the built-in leads above. `fastleo:install --modules=leads` and `fastleo:module leads` now answer "no such module". Sites that already copied the module keep their own code and table `leads`; do not turn on `leads.resource` while it is there (see "Moving from the copied module" in [leads](docs/leads.md#переход-с-копируемого-модуля)).

### Upgrade notes

- **Texts changed, behavior did not.** If the old names are shown to your people or checked in your tests, set them back in `config/fastleo.php` (the values are Russian, as the interface is): `'users' => ['label' => 'Администраторы', 'singular' => 'Администратор', 'access' => 'Доступ в админку']` ("Administrators", "Administrator", "Access to the admin panel"). Any other string can be replaced with `lang/vendor/fastleo/ru/*.php` in the site (for example `auth.php` for the two emails) — Laravel picks the files up by itself ([configuration](docs/configuration.md)).
- If your site has its own "Users" section next to the built-in one (the built-in used to be "Administrators"), set `users.label` so the menu and Ctrl+K do not show two equal items. Doc anchors that contained "administrators" were renamed (`#администраторы` → `#пользователи`, `#роли-администраторов` → `#роли-пользователей`, `#4-администратор` → `#4-первый-пользователь-панели`): external links to them stop working.
- **`FASTLEO_*` variables need the new `config/fastleo.php`.** A file published before 4.10.0 has fixed values that win over `.env`: publish it again (`php artisan vendor:publish --tag=fastleo-config --force`) and carry your changes over, or replace the values you want in `.env` with `env('FASTLEO_…', …)`.
- **Leads:** nothing is switched on until you set `'leads' => ['resource' => Fastleo\Leads\LeadResource::class]` ([docs/leads.md](docs/leads.md#как-включить)). Run `php artisan migrate`: it creates two empty tables (`fastleo_leads`, `fastleo_lead_api_keys`) even while leads are off, and `fastleo:doctor` reports them missing until then. A site with its own intake on `POST /api/leads` (for example the old recipe) must not turn the built-in API on at the same address: set `leads.keys` to `null` or change `leads.api_path`. A site that has the copied `leads` module must not enable `leads.resource` until it moves to the built-in part (migration steps in the same page). `fastleo:install` no longer offers "Leads" in its survey.
- "Sign in as" adds a reserved address (`impersonate`: a site section with this slug stops the panel with an error until it gets another `$slug`), the route `POST /fastleo/impersonate/leave` and the `ValidateImpersonation` middleware in `Fastleo::adminMiddleware()`. A site with its own `UserResource` subclass must return `ImpersonateUser` from `actions()` to get the button (`fastleo:doctor` warns). Site listeners of `Illuminate\Auth\Events\Login` fire on it, and at most 10 sign-ins a minute per owner are allowed.
- **Republish the assets** if your `composer.json` has no `post-update-cmd` with `vendor:publish --tag=laravel-assets`: `php artisan vendor:publish --tag=fastleo-assets --force`. Old CSS/JS miss the "Sign in as" banner and the view button on file tiles.
- A site that overrides the panel layout (`resources/views/vendor/fastleo/components/layouts/app.blade.php`) gets no "Sign in as" banner with the Return button: copy the banner from the package layout ([auth](docs/auth.md#войти-как)).
- Mail filters and monitoring that match the subjects of the reset and sign-in emails need the new wording ("Новый пароль для панели …" — "New password for the panel …", "Вход в панель … с нового устройства" — "Sign-in to the panel … from a new device").

## [4.9.5] — 2026-11-10

### Added

- **`Fastleo::resolveOwnerUsing()`, `Fastleo::isOwner()` and `Resource::$ownerOnly` / `$ownerOnlyIndex`** — an owner/others split without roles and `spatie/laravel-permission`. The site says who the owner is; files, the error log, cache clearing, SoftNetica, settings, custom pages and the users section are then for the owner only, and a section can close groups of abilities (`view`, `create`, `update`, `delete`), exact abilities (`export`, `import`, `forceDelete`) or itself entirely (`'*'`) to everybody else. The check stands above Policies and `can()`; no owner at all closes such a section to everybody and `fastleo:doctor` reports it. With roles on, the role decides and the resolver is ignored ([authorization](docs/authorization.md#владелец-и-owneronly)).
- **`Resource::$recordView`** — the record page mode per section (`'modal'`, `'page'`, `false`; `null` follows `fastleo.record_view`), so a section that is read but not edited (messages for a manager) shows a card even when the site has no record pages; `fastleo:doctor` checks the value (and reports a mistyped ability in `$ownerOnly` or a resolver that roles make useless), `fastleo:inspect` shows `record_view`, `owner_only`, `owner_only_index` and `computed`. `Resource::viewModalUrl()` is now an instance method ([resources](docs/resources.md#просмотр-записи-в-разделе)).
- **`Field::computed()`** — a list column counted in PHP from the record (phone + name, links): display, export (tags stripped) and MCP, no column in the table, no writing; `sortable()` or `searchable()` on it throws a `LogicException` ([list](docs/list.md#столбец-который-считается-в-php-по-записи)).
- **`fastleo.users.label|singular|access`** — names of the users section, a person and the access toggle ([configuration](docs/configuration.md)); the built-in users section also shows an "Owner" column when `resolveOwnerUsing()` is set.
- **`Fastleo\Testing\ActsAsFastleoUser`** — `actingAsFastleo($user)` signs a user in and drops the previous one, for tests that switch between an owner and a manager ([testing](docs/testing.md#смена-пользователя-посреди-теста)).

### Changed

- **A form with no field the admin may change has no Save buttons**, and Ctrl+S in it sends nothing.
- **The card above the form (`summaryView()`) refreshes after a related record is saved in a window** (`Related::editIn('modal')`).
- **`fastleo:doctor` no longer asks for the parent field in a related table that has no Add button** (`withoutAddButton()`).
- **`Fastleo::showBellTo()` shows the bell only for a strict `true`** from the closure; a `1` from an uncast column used to count and no longer does (cast it to `bool`).
- **`$ownerOnlyIndex` closes everything that returns the whole list**: the list page, export, import, filters, Ctrl+K search, the menu item and MCP; tables of the section on other records' pages stay open.
- **A relation field (`BelongsTo`, `MorphTo`) to a section closed by `$ownerOnly` is read-only and gives no option titles** even when roles are off; the link in a list opens the card by the related section's own record page mode.
- **A failing owner resolver is reported once per user** instead of on every check.
- **Removed `Fastleo::recordView()` and `Fastleo::recordViewInModal()`**, which nothing called: use `Resource::hasRecordView()` and `Resource::recordViewInModal()` (per section).

## [4.9.4] — 2026-11-10

### Added

- **`Fastleo::showBellTo()`** — show the notification bell and the Notifications section only to some admins (e.g. owners); the others get no bell, their addresses answer 404 and nothing is stored for them. `Fastleo::bellFor($user)` tells whether a user sees it ([notifications](docs/notifications.md#колокольчик-только-некоторым)).

## [4.9.3] — 2026-11-09

### Added

- **`Related::withoutSinglePageFooter()`** — no "Records 1–3 of 3" line under a related table while all its records fit on one page; with more pages or "All" the line with pages is shown as usual ([related](docs/related.md#строка-записи-под-таблицей)).

### Changed

- **Opened rows of related tables stay open after a reload** (`Related::expandable()`): after an action in a row (e.g. Sell) or a save the page reloads with the same rows opened; remembered for the browser tab ([related](docs/related.md#раскрывающиеся-строки)).
- **`HasMany` with `min()` opens an empty list with that many empty blocks** — no need to press Add first ([HasMany](docs/fields/has-many.md)).
- **A number edited in a list cell (`inlineEditable()`) sits at the right edge of the cell**, under its right-aligned column header.
- **One Reset button in the list:** with filters applied by a button, the Reset next to the Filters button hides while the filter panel (which has its own Reset) is open.

## [4.9.2] — 2026-11-09

Changes that remove the need to copy the package templates in a CRM: a list with filters applied by a button, a main button in the row, a record card above the form, and a check that a record may be attached to its parent. Everything new is opt-in: a site that sets none of it works as before.

### Upgrade notes

- If your site overrides the list, row actions, form or filter templates (copies in `resources/views/vendor/fastleo`), copy them again: the old copies do not know the button mode of filters, the main row button, the record card and the own delete warning.

### Added

- **Filters applied by a button:** `protected static bool $filtersApplyOnChange = false` — a filter no longer submits the list when a value is chosen; the **Filter** button (and **Reset** when something is filtered) stands in the same row as the fields, so several values and dates can be set in peace. All the ready filters honour it ([list](docs/list.md#фильтры-по-кнопке-и-свёрнутая-панель)).
- **`protected static bool $filtersCollapsed = true`** — the filter panel stays closed until opened, even when filters are applied or set by default; the open or closed state is remembered for the browser tab ([list](docs/list.md#фильтры-по-кнопке-и-свёрнутая-панель)).
- **`Resource::primaryRowAction(Model $record)`** — a labelled main button in the list row (`['key' => …, 'label' => …, 'primary' => true]`); the rest of the actions stay in the row menu ([list](docs/list.md#главная-кнопка-строки)).
- **Record page hooks:** `Resource::summaryView()` (a read-only card above the form and the tabs of related tables), `Resource::addButtons($record)` (buttons in the page header, e.g. "Add order" for a client) and `Resource::deleteWarning($record)` (own text of the delete confirmation) ([resources](docs/resources.md#страница-записи-карточка-кнопки-предупреждение)).
- **`Related::withoutAddButton()`** — no Add button above a related table (the permissions and `quickAdd()` stay); the table inside a form tab no longer has the extra top margin.
- **`BelongsTo::mustAllow($ability = 'update')`** — the relation is saved only if the administrator may do the ability to the chosen record (its model's Policy and the `can()` of its section): a record cannot be attached to somebody else's or a closed parent. The saved value is not re-checked on edit; add `hideOnEdit()` to forbid moving ([BelongsTo](docs/fields/belongs-to.md#привязка-только-к-тому-что-можно-изменять-mustallow)).
- **`protected static bool $readOnOpen = false`** — opening a record does not set the `$readColumn` mark; it changes only by the Mark read / Mark unread actions or by `$resource->markRead()` — for a CRM where read means "taken to work", not "somebody opened it" ([resources](docs/resources.md#отметка-просмотрено)).
- **`fastleo:doctor` warns about a foreign key without an index** (a `BelongsTo` field or a related table) in a table of 1000 rows or more: `constrained()` makes the index on MySQL and MariaDB only, PostgreSQL and SQLite need `->index()`.

### Changed

- **A saved `BelongsTo` link to a record outside the field's options (`query()`) shows the record's title** in the form and in an `inlineEditable()` cell, as the list already did, instead of its key: e.g. a Manager field limited to managers shows the owner's name on an order the owner created. The record still cannot be chosen anew; a record in the trash keeps the key as its label ([BelongsTo](docs/fields/belongs-to.md)).

## [4.9.1] — 2026-11-09

### Upgrade notes

- **The notification bell is off by default** (`notifications.bell`, default `false`). A site whose published `config/fastleo.php` has no `bell` key loses the bell after the update: add `'bell' => true` under `notifications` to keep it. While it is off, the bell is not in the header, its addresses answer 404, and nothing is stored for it — `Fastleo::notify()`, the import result, a failed publication and the site's own notifications with the `fastleo` channel are skipped. `Fastleo::notifyAdmins()` still sends mail and bot messages if those are on. A site with the Leads section and every channel off now gets a `fastleo:doctor` warning ([notifications](docs/notifications.md#как-включить-колокольчик)).
- **The bell's JSON moved to `/fastleo/notifications/feed`** (route `fastleo.notifications.feed`); `/fastleo/notifications` is now the Notifications page. If your site overrides `components/layouts/app.blade.php` (a copy in `resources/views/vendor/fastleo`), copy the template again: the old copy asks the page instead of the JSON (the bell stays empty), shows the bell while it is switched off (its requests answer 404), never refreshes the unread count (the badge disappears once the bell is opened) and has no All notifications link.
- **Menu:** the notification bots section is renamed from Notifications to **Notification bots**; it moved together with Channels from the System group, and Publications from the top level, to a new **Messengers** group (addresses `notification-bots`, `channels` and `publications` are unchanged). A site that sets `menu.groups` gets Messengers after the listed groups — add `'Мессенджеры'` to the list to place it.
- **New migration** adds an index `(user_id, created_at)` to `fastleo_notifications`: run `php artisan migrate`.
- **The login page no longer names the package or calls the panel an admin panel:** the heading is Sign in, the name · site address line inside the form and the Powered by Fastleo line are gone, the access error and the session-expired hint are neutral, and with neither `brand.name` nor `app.name` set the name is the host of `app.url` instead of Fastleo.

### Added

- **`brand.login.heading`, `brand.login.text`, `brand.login.footer`** — the texts of the login page: the heading (default Sign in), the line under it (default none) and a line at the bottom of the guest pages, e.g. a copyright ([theming](docs/theming.md#страница-входа)).
- **Notifications section** (System → Notifications, `/fastleo/notifications`, the All notifications link at the bottom of the bell): all own notifications for the whole retention period, read ones too, 30 per page, an All / Unread switch, Open (marks read and follows the link), Mark read and Mark all read. Shown while the bell is on ([notifications](docs/notifications.md#колокольчик)).
- **`notifications.poll`** — how often the bell refreshes the unread count, in seconds (default `60`, from 10 seconds to a day; `null` — no polling, the count refreshes on page load and when the bell is opened).
- **`Fastleo::notifyAdmins($message, via: ['bell', 'mail', 'bots'])`** — send to some channels only; a channel that is off in the config is skipped, an unknown name, a non-string item or an empty list throws `InvalidArgumentException` ([where to send](docs/notifications.md#куда-отправлять)).
- **`fastleo:doctor` warnings:** the bell is off while the site has imports or publications (their results reach nobody), and the Leads section exists while the bell, mail and bots are all off.

### Fixed

- **`notifications.mail` is read leniently:** `null` from `env()` without the variable no longer fails the site form that calls `notifyAdmins()`.

## [4.9.0] — 2026-11-09

Fastleo is now described as a panel for Eloquent models — a site admin panel, a CRM or a back office — and the setup with the whole application at the domain root is documented and checked by `fastleo:doctor`. Menu groups collapse, a multiple `Select` is a set of chips, and the examples in the documentation are neutral. The release also closes the findings of a review of the whole package (regular and adversarial, several rounds): one crash of the PHP worker, holes in the MCP guards, the import, the password reset and the image tools. This is a minor release, not a patch: it adds a required PHP extension and turns several silent mistakes of a site into errors (see "Upgrade notes").

### Upgrade notes

- **PHP needs `ext-gd`.** `composer update` refuses to install the package on PHP without GD (add the extension, or install with `--ignore-platform-req=ext-gd` where pictures are not used). `ext-exif` is suggested: without it phone photos stay rotated.
- **A `Secret` field needs an encrypting cast on its column** (`'encrypted'`, `AsEncrypted…` or a cast class of the site): without one, saving the form fails with an error instead of writing the token to the database in plain text. `php artisan fastleo:doctor` lists such fields.
- **A relation with a key other than the primary key** (`belongsTo(Model::class, 'x_code', 'code')`, `belongsToMany` with `relatedKey`) is an error when the field is written or its options are listed in a form (a field that is only shown — `readonly()`, `onlyInList()`, a read-only section — and filters keep working), and a malformed `$defaultOrder` is an error when the sections are registered; `fastleo:doctor` names the section. Before, the first silently erased the value on saving and the second broke the list with an SQL error. The list and the record page still show such a relation as before; only a field that is written needs a relation by the primary key.
- **`HasMany` and `BelongsToMany` forms carry a hidden `{field}__seen` input** with the keys shown when the form was opened; only those can be removed on saving. A site that overrides the templates `fastleo/fields/nested.blade.php` (`HasMany`) or `fastleo/fields/combobox.blade.php` (`BelongsToMany`) in `resources/views/vendor/fastleo` must copy the input from the new ones: without it the list is replaced as a whole, as before, and a stale form can erase rows added by someone else (MCP, import and quick-add send no such input and keep the old rule). The file manager and the file window of a form read the new `data-max-files` attribute (`files/index.blade.php`, `partials/file-picker.blade.php`): an old copy still works but uploads all the files in one request, so those over PHP's `max_file_uploads` are dropped silently, and more than 50 files at once are refused as a whole. The dashboard chart (`widgets/chart.blade.php`) takes the ready JSON from the new `$json` variable: an old copy keeps failing the dashboard on a `NAN` or broken UTF-8 in the labels.
- **Templates your site may have copied into `resources/views/vendor/fastleo` changed:** `resources/index.blade.php` (the export button no longer reads the removed constant `Fastleo\Exports\Exporter::FORMATS`: a copy that still does fails with "Undefined constant" on every list with an export; use `Fastleo\Exports\SpreadsheetFormats::all()`), `imports/dialog.blade.php` (the hints take a `:formats` value; an old copy shows the literal `:formats` and still offers `.xlsx`), `fields/rich-text.blade.php` (it shows a stored text that is too complex for the editor as a notice or as HTML code; an old copy opens it as an empty editor that the next save erases), together with `fields/nested.blade.php`, `fields/combobox.blade.php`, `fields/combobox-control.blade.php`, `files/index.blade.php`, `partials/file-picker.blade.php` and `widgets/chart.blade.php` named above, and `partials/menu-item.blade.php` (an old copy keeps the label of an item inside the scrolling icon strip, where it is cut off and not seen on hover). Compare your copies with the new ones or delete them.
- **A multiple `Select` is a set of chips** instead of a `<select multiple>`: every option is visible, the checked ones are filled with the brand colour and marked with a tick. The checkboxes are named `field[]` as before, so the submitted data does not change; a site that overrides `fastleo/fields/select.blade.php` keeps its own look (a dependent `<select multiple>` still reloads its options). Checkboxes carry no `required`: the server checks that at least one is chosen.
- **`dependsOn()` on a field with several values** (`Select::multiple()`, `Tags`, `BelongsToMany`) is an error when the section is described, like `visibleWhen()` already was: the form sent only one of the values and the server ignored the array, so such a dependency never worked.
- **The sidebar template `components/layouts/app.blade.php` changed:** group headings are buttons that collapse the group. A copy in `resources/views/vendor/fastleo` keeps static headings.
- **The slug `up` is reserved** for Laravel's health check (`/up`): a section with `$slug = 'up'` is an error. Otherwise, with the panel at the domain root, the section routes took the health check address.
- **Password reset answers no sooner than one second** (or `auth.timebox_duration`, if it is larger). In tests of your site call `Sleep::fake()` before posting to the reset routes, or each request takes a second.
- **`RichText` refuses a text with more than 30,000 opening tags (and as many closing ones)** or with unclosed formatting that the parser would recreate in too many blocks; the check counts tags and does not parse. It is a heuristic by tag names: an exotic markup that makes the parser ignore a closing tag can still use up the memory of one request of an editor, so do not set `memory_limit` above 256 MB and never to `-1` on a server with editors you do not trust. The estimate of the unclosed formatting is cautious: old sloppy HTML such as `<p><b>text</p>` repeated more than about 450 times is refused although the parser would take it.
- **A text already stored that the check refuses is not opened in the editor.** The form shows a notice in place of the field and does not send it, so saving the record leaves the text as it was (before this release such a text would open as an empty editor and be erased by the next save). To replace it, simplify the markup in the database or import the text. A language of a translatable field and a sub-field of a `Repeater` or `Blocks` item cannot be left out of the form this way (their value is built from what the form sends), so there the text is shown as HTML code under the notice and the form is not saved until the markup is simplified.
- **Reset password email is sent in the web process right after the response** instead of the queue: the link token no longer lands in the `jobs` table. A failure of the mail server only goes to the error log, and there is no retry; check the mailer of the site.
- **Excel (xlsx) is off in the panel: export, import and the file of skipped rows work with CSV only.** The "Export" button in a list is one CSV link, the import window and the check of the uploaded file accept `.csv`, and the file of skipped rows is a CSV (`;` delimiter, UTF-8 with BOM, a text that looks like a formula starts with `'`, which is removed when the file is uploaded back). The code that reads and writes xlsx stays in the package for further work and can be switched on with `'xlsx' => true` in `config/fastleo.php`, but it is not supported until it is reworked. The files of skipped rows of reports made before this release (`…-errors.xlsx`) are not offered for download any more. A request with `?format=xlsx` gets a CSV. Export and import are limited to 10 requests a minute per administrator: a script that polls `/export` gets the answer 429.
- **The list of files that are never accepted is longer** (`php2`, `php6`, `phtm`, `shtm`, `hta`, `jar`, `wasm` were added): a site that allowed one of them in `fastleo.files.extensions` loses it.
- **Setting a new password has its own limiter `fastleo-password-reset`** (5 a minute per IP address): a site that redefined `fastleo-password` to change the limit of `password.update` must redefine the new one instead.
- **Import limits:** a csv record has at most as many cells as Excel has columns (16,384); a workbook (only if `fastleo.xlsx` is turned on) has up to 1,000 entries in the archive, 256 sheets and 1,000 relations; a bigger file is refused with a clear message. The file of skipped rows holds at most 5,000,000 cells, and its last row says when it was cut.

### Security

- **Deeply nested HTML no longer crashes the server:** about 16,000 nested tags (50 KB) made `symfony/html-sanitizer` recurse until the PHP process died (SIGSEGV), so an administrator with the "Editor" role could take the site down through a `RichText` field, an import or MCP. HTML nested deeper than 199 tags is now rejected with a clear validation error. Parsing is also expensive when a text has many tags (a hundred thousand `<div>` with stray closing tags took 13 seconds that no PHP time limit interrupts) or leaves formatting open (190 `<b>` and 3,000 paragraphs, 25 KB, exhausted 128 MB of memory), so a text with more than 30,000 opening tags (a well-formed table of 4,999 rows by five columns fits), or with more than 1,000 attributes in one tag (parsing time grows with the square of that number: 160,000 names in `<p a1 a2 …>` took about a minute), or with unclosed formatting that the parser would recreate in too many blocks, is rejected before it is parsed; the check counts tags and cannot be fooled by how a text closes them. A text over the length limit is no longer parsed at all.
- **Password reset:** setting a new password has its own limit (5 a minute per IP address) and no longer shares the hourly limit of "send the link", which let anyone lock an administrator out of the link just received; every answer takes at least one second (or `auth.timebox_duration`, if the site set it higher) for an administrator, a repeated request, an unknown address and a wrong token, so the time does not tell whether the address exists even on a server where hashing a password takes longer than the 0.2 s of the framework's own box.
- **MCP:** the tools run with the `fastleo` guard as the default one, as the admin pages do. Before, `auth()` in the code of a site (`query()`, `can()`, `hidden()`, observers) saw a guest, so a rule such as "not a viewer" or a `created_by = auth()->id()` column worked differently for an agent than for the same administrator in the browser.
- **Login throttling:** the counter of a non-existent account is keyed by a hash of the entered address, so "too many attempts" no longer tells whether the account exists and a made-up `id:1` cannot burn someone else's counter.
- **New-login email:** the browser name and the address are escaped, so a crafted `User-Agent` cannot put a link into a real email from the panel.
- **`Secret` field:** refuses to save without an encrypting cast on the column (a token used to land in the database in plain text); `fastleo:doctor` reports it. Only `encrypted…`, `AsEncrypted…` and cast classes of the site count: an enum or `AsArrayObject` does not encrypt.
- **`Password` field:** a password of spaces no longer replaces the real one.
- **Import** (the xlsx checks matter only when `fastleo.xlsx` is turned on; the csv check always): an archive of thousands of entries, a workbook of hundreds of sheets or relations (the reader goes through them again for every sheet) and a csv record of a million cells (also one that is spread over many lines of the file by line breaks inside quotes) are refused before they take the worker; a negative `spans` of a part the reader never opens can no longer cancel the estimate for the real sheet. An xlsx with a million empty cells, a huge `spans` or `dimension` value, or thousands of rows that each claim the widest span no longer holds a worker for hours or takes gigabytes of memory (at most 1,000 cells in a row, no row beyond the last row of Excel, a span written as `9e8` counts too, a cell without an address is placed one column to the right of the previous one, as the reader does, and a `dimension` is read the way the reader reads it, a sheet stored under any file name in the archive is checked — also one with a `#` in its name — and the estimated work of the reader over the whole file, cells counted wherever they stand and the text runs inside one cell or one shared string included, is limited to about two minutes; an archive that repeats a name is read once; the reading time of an import is limited too, `import.max_read_seconds`, 600 s, which also covers what the estimate cannot know); export and import are limited to 10 requests a minute; the import job runs as the `fastleo` guard in the panel language, so observers see who uploaded the file.
- **Styles in `RichText`** (when `rich_text.styles` is enabled): overlays that could put an invisible link over the page (`position`, `z-index`, `transform` also with a browser prefix, `offset-*`, and margins and offsets other than plain non-negative lengths, so `calc()` and `var()` cannot hide a negative shift) are removed.
- **MCP:** `delete-record` runs `beforeDelete()` like the panel; records locked with `isLockedForMcp()` are protected from deletion and from actions; an action with `$danger = true` is not available to an agent unless deletion is enabled; the server instructions say that field values are data, not commands.

### Fixed

- **Forms with tabs:** "Save" keeps the tab that was open instead of returning to the first one.
- **A dependent multiple `Select`:** after its options are reloaded, the first chip keeps the id of the field, so the label and the hint still point to it.
- **The "Back" button of the error pages** (404, 403, 419, 429, 500) works: it went nowhere because a Blade directive was left unprocessed inside the button's script (the template `errors/content.blade.php` changed).
- **A long menu collapsed to the icon strip** no longer covers the bottom panel and the "Expand menu" button: the strip scrolls, and the label of an item appears next to it on hover (the template `partials/menu-item.blade.php` changed).
- **Images and files:** a GIF whose frame lies outside its canvas is refused with a message instead of failing the whole upload with a server error (the answer about the files of the same batch that were already stored was lost); a GIF of tens of thousands of tiny frames (2 MB, 100,000 frames) is refused before it is decoded (more than 5,000 frames) instead of exhausting the memory of the process; a refused file whose name is not valid UTF-8 no longer turns the whole answer into a server error; `fastleo:images` keeps the ready variants of a picture that this process has no memory to open (the console limit differs from php-fpm) instead of erasing them.
- **Filter by a relation with a key other than the primary key** finds the records through the relation: the filter offers the primary keys of the related records, and the column keeps another key (`code`).
- **MCP `update-record`:** a value of a nested record that is not a date (`abc`) is a validation error of the field, not an "internal server error".
- **Forms:** the id of a list field is put into the script expressions of `fields/combobox-control.blade.php` as an escaped string, as everywhere else (it comes from the keys of a block after a form error); a forged `{field}__seen[]=x` no longer breaks the next display of the form after a validation error; the `fastleo.xlsx` setting accepts any yes or no (`1`, `'true'`) instead of failing every list with an export button.
- **`HasMany` and `BelongsToMany`:** an outdated form (a second tab) no longer deletes the rows or detaches the links added elsewhere; the form sends a hidden `{field}__seen` list.
- **`$defaultOrder` on a computed column** (`fromQuery()`) no longer breaks the list, export, search and MCP with an SQL error; the shape of `$defaultOrder` is checked when sections are registered (a column with a table name, `categories.name`, and a JSON key, `data->title`, are still accepted).
- **A relation with a key other than the primary key** (`belongsTo(..., 'code')`, `belongsToMany` with `relatedKey`) is reported as an error instead of silently erasing the value.
- **Import:** row numbers in the report count empty rows; `Select` with numeric labels no longer fails; the guard and the language are the panel's.
- **Publishing:** a 429 answer on a `sync` queue no longer leaves the delivery "pending" forever; `MaxBot` retries on the real `attachment.not.ready` code.
- **Error log:** a forged line with an impossible date no longer breaks the page.
- **Dashboard:** a `Chart` with `NAN`, `INF` or broken UTF-8 no longer fails the whole dashboard.
- **Site settings:** image variants are generated after saving a settings page.
- **`<x-fastleo::seo>` excerpt:** the text is cut before the regular expression, so a crafted page cannot cost seconds of CPU on every view.
- **`fastleo:make-resource`** for a reserved name (`Page`, `Setting`…) gives the section a free address instead of a resource that breaks the whole panel.
- **Server status:** the `storage:link` warning no longer appears on S3-like disks.
- **Chat search** for notification bots with a policy `update(User, NotificationBot)` no longer answers 500.
- **Files:** more files than PHP's `max_file_uploads` (and no more than the 50 that one request takes) are sent in batches that also fit `post_max_size` each, instead of being silently dropped or refused as a whole; a file the server refuses (a wrong extension, too big) no longer cancels the other files of its batch or the batches after it: the good ones are stored and the refused ones are named in the answer; a disk that refuses to write no longer leaves `<picture>` with links to missing files; GD built without WebP no longer fails thumbnails and image variants with a PHP error (the manager shows the original).
- **Import (csv):** a file with a UTF-8 byte order mark and one byte in another encoding is checked by the bytes the reader sees (before, such a file with a million commas in quotes slipped past the width check and took 2 GB of memory); the reports of earlier versions keep no `…-errors.xlsx` file behind after they are removed (they hold the rows of the clients), also when the administrator is deleted or the job crashes; an import queued before xlsx was turned off fails with a message instead of reading the xlsx.
- **`RichText`:** a stored text that the check refuses is no longer cut off silently when it is longer than 4,000,000 bytes (the sanitizer reads no more); in a language of a translatable field and in a sub-field of `Repeater`, `Blocks` or `HasMany` such a text is shown as HTML code under a notice, also after a refused form (before it vanished from the form and the next save erased it); a required field with such a text may be left out of the form, but a blank value sent to it is still refused.
- **Relation by a key that is not the primary key:** `fastleo:doctor` no longer reports a field that is only shown (`onlyInList()`, a read-only section), and a filter by such a field no longer fails the whole list: it finds the records through the relation, because the column keeps another key than the one the filter offers.
- **Pictures:** a `.webp` or `.jpg` name on a picture that the GD of the server cannot save in that format (GD built without WebP or JPEG) refuses the file with a message instead of an error 500 in the middle of a batch; `fastleo:images` on a PHP without WebP no longer deletes the variants that are already made.
- **Big uploads and deleting in the file manager:** when a later batch of a big upload fails, the files of the earlier batches stay in the list next to the error instead of vanishing from the screen; when deleting fails halfway, the list is refreshed and shows what is left on the disk.
- **Roles and a model with two sections:** a relation to a model that has several sections ("Articles" and "News" over one model) is open when the administrator may open any of them, and the link to the related record leads to the first section that is open; before, the first section by name decided and could close a relation that another section allowed.
- **`fastleo:images`** also remakes the pictures inside `Repeater`, `Blocks` and site settings pages.
- **Styles in `RichText`** (when `rich_text.styles` is enabled): margins written the way Word writes them (`.5em`, `.0001pt`) and the newer units (`dvh`, `svh`, `lh`, `Q`, container units) are kept again; a minus in front of them is still refused.
- **`fastleo:make-action`, `make-filter`, `make-widget`, `make-field`** refuse a name that the template imports (`Action`, `Filter`, `Widget`, `Text`, `Model`, `Override`…) instead of writing a file that fails with "Cannot redeclare class".
- **Deleting an administrator** also removes his bell notifications (they hold the address and the browser of logins) and the reports of his imports with their files, so a new administrator that gets the same id cannot see them; publications stay but lose their author.
- **MCP `update-record`** with empty `values` answers "the record is not changed" instead of "the record is saved".
- **Forms:** a form cannot be sent twice by a double click (actions, import, record forms); a chain of dependent `Select` fields clears all the levels below the changed one.
- **Password reset and profile:** an address sent as a list (`?email[]=x`) no longer ends in a 500 error; a user model without `CanResetPassword` gets the usual "link is invalid" answer instead of an exception.
- **Site lead forms:** `Fastleo::notifyAdmins()` no longer fails the page when messenger bots are enabled but cannot be read (a missing migration, an unreachable database); the reason goes to the error log.
- **File manager:** a path with invisible Unicode characters (a zero-width space, a right-to-left override) or broken UTF-8 gives a validation error instead of a 500; when the disk refuses to create a folder or delete a file, the manager says so instead of reporting success.
- **Mini-forms of actions in a related table:** an uploaded file in the form no longer breaks the answer (a file cannot be stored in the session).
- **Server report:** `QUEUE_CONNECTION=null` shows "the queue is off" instead of "failed to load"; a request that came through a proxy Laravel does not trust adds a warning ("Visitor address": logins share one limit until `trustProxies` is set).
- **`DateTime` and `Time`:** saving a form no longer rewrites the seconds of a value that was not changed on screen (`10:00:45` stays).
- **`HasMany` with `min()`:** an empty new block no longer counts towards the minimum, so the last saved blocks cannot be removed by adding an empty one.
- **Multilingual `RichText`:** "required" applies to the main language only.
- **Site settings:** the value of an encrypted field is not put back into the form through the session after a validation error.
- **Trees:** a section with `orderByRaw()` parameters in `query()` no longer breaks the tree.
- **Interface:** auto-refresh of a tree with expanded branches works; a click on an empty place of a row's menu no longer opens the record; typing in the search of a list field or in a tag draft is not an unsaved change; `$&` in a file name no longer garbles the "file is too large" message.
- **Publishing:** a server error (5xx) while a message is being sent to Telegram or Max gives the delivery the status "unknown" (the message may have arrived) instead of "failed", so a manual retry does not duplicate the post.
- **Login history:** a double click on "Sign in" from a new device no longer fails when both requests record the same device.
- **Reset password email** is not queued any more (the link token no longer lands in the `jobs` table); it is sent in the web process right after the response.
- **Profile and administrators section:** the address check counts users hidden by a global scope of the user model (soft deleted, tenant) — the database index holds their addresses too.
- **Administrator data** (2FA secret, login history, dashboard layout) is removed together with the user; a soft deleted user keeps it.
- **MCP:** `describe-resource` no longer lists fields and filters hidden from the administrator; the "no such section" error names only sections the administrator may open; `values: {}` is accepted (an empty update, or a create where nothing is required); `page` is limited; `update-record` is marked as destructive for the client (it replaces lists) and with an empty `values` it saves nothing, so the observers of the site (`saved`) do not run for no change.
- **Import:** a row whose key matches a record that exists but is hidden from the section (trash, `query()`) is skipped with an error instead of creating a duplicate; a database failure on every row of a large file writes only the first three entries to the error log.
- **Cell and toggle editing:** a value is not cleared when a rule such as `exclude_if` excludes the field from the checked data.
- **`Select`:** an option value must be a string or an integer; `true` and `1.0` from JSON are refused instead of being saved as a boolean or a float.
- **Lists:** `Code` and `Markdown` columns honour `formatUsing()`.
- **Dashboard chart:** grouping by day uses the expression, not the alias, so a `day` column of the table cannot break it on PostgreSQL.
- **Files:** `php2`, `php6`, `phtm`, `shtm`, `hta`, `jar`, `wasm` are always forbidden; two uploads of the same name wait for each other's lock (up to 5 seconds) instead of ignoring a taken one; file addresses encode a space, `#`, `?` and `%` on local, FTP and SFTP disks; the error log page skips a log file that disappeared.
- **Generators:** the generated policy lists its imports alphabetically; `make-resource` keeps a column comment that looks like the slug marker.
- **CI** jobs have a time limit.
- **Thumbnails of the file manager:** the redirect to the file is remembered by the browser for 5 minutes instead of running Laravel for every picture of the grid (a guest's redirect to the login page is never remembered); the dashboard counts the cache size once a minute.

### Added

- **The whole application at the domain root:** `'path' => ''` without a `domain` serves the panel at `/`, `/login` and `/{section}` — for a CRM or a back office that is the product itself. The documentation describes the three placements (next to a site, its own subdomain, the domain root); `fastleo:doctor` checks the site's GET routes without parameters warns when one of them opens the panel and reports an error when one takes an address of the panel (a welcome route at `/`, a site `/login`): such a route replaces the panel's route and every page of the panel fails with "Route [fastleo.dashboard] not defined"; an address without a route shows the panel's 404 page, while errors of the site's own routes keep the site's pages.
- **Collapsible menu groups:** a click on a group heading collapses it, also in the phone menu; all groups start open and the choice is kept in the administrator's browser. The group of the open section is shown open on its page; the narrow icon strip shows the icons of every group.
- **The demo application (`workbench`)** has showcase sections grouped by feature, each one an example of what the package can do.
- **`SECURITY.md`:** how to report a vulnerability privately and which versions get fixes.
- **`fastleo:user`** applies the password rules of the site (`Password::defaults()`) like the profile does. A password of spaces keeps the old password of an existing user.
- **`fastleo:make-action|filter|widget|field`** refuse names PHP keeps for itself (`Print`, `List`, `Match`…) instead of writing a file that cannot be parsed.
- **`fastleo:doctor`** checks the PHP image extensions: an error without GD (or GD without JPEG/WebP), a warning without `exif` (phone photos stay rotated).
- **Documentation:** the record key requirements, why a file name with ZWJ/ZWNJ cannot be opened in the file manager (Flysystem refuses it), how a visitor can forge a line of the error log and how to close it, the languages missing from `fastleo.locales`, the admin-panel error page override path, installation requirements (GD, exif, database versions), the effect of `$danger = true` on MCP, macros in old Office formats, headers on 404/405 answers, the shared per-IP limit for 2FA codes, who opens the trash (`viewAny`), uploads into hidden file-manager folders, which of several sections of one model a relation uses, the limits of `Repeater` and `Blocks` subfields.

### Changed

- **Positioning:** the README, the `composer.json` description and the texts for Claude (skill, Boost guidelines, `llms.txt`) describe Fastleo as a panel for Eloquent models — a site admin panel, a CRM or a back office — rather than only an admin panel of a site.
- **A multiple `Select` in the form is a set of chips** (see "Upgrade notes").
- **Neutral examples:** the documentation, PHPDoc and command hints use a shop and a blog (`Product`, `Brand`, `uploads/products/…`) instead of examples from one industry.
- **`ext-gd` is required in `composer.json`** (the documentation already called it mandatory): on PHP without GD, `composer require` refuses to install the package instead of failing at the first picture upload; `ext-exif` is suggested. On an image without GD, add the extension (or install with `--ignore-platform-req=ext-gd` where pictures are not used).
- **`league/commonmark` ^2.10.2 is required explicitly** (the Markdown field and `Str::markdown()` use it through Laravel): versions up to 2.10.1 have a published vulnerability, and the package no longer relies on another package to keep them out.
- **Tests:** guest access to every route, the rate limit of each limited route, the trash and the "view" permission, records outside `query()`, both directions of the role matrix, no real outgoing HTTP requests; `composer test:databases` no longer lets the MySQL and PostgreSQL runs erase each other's files.

## [4.8.3] — 2026-11-05

A security dependency update and security checks locked in by tests. The package code did not change.

### Security

- **`symfony/html-sanitizer` 8.1.11 or newer:** before 8.1.11, `srcset`/`imagesrcset` were not checked against scheme and host, `allowLinkHosts()` and `allowMediaHosts()` could be bypassed, and `meta refresh` was only partially parsed. The default Fastleo sanitizing profile does not use these features, but sites with their own profile via `sanitizeUsing()` or `rich_text.attributes` could be affected. Sites should run `composer update symfony/*`: Symfony 8.1.11 also fixes `http-foundation`, `http-kernel`, `routing` and `yaml`.

### Changed

- **Tests after a mutation testing run** of login, two-factor authentication, files, images and import: path and size boundaries, login lockout durations, the failed-attempt window, hiding the 2FA secret, the backup code format, the crop frame, CSV delimiters, the size limit after unpacking.
- **The two-factor authentication test** no longer fails when the 30-second step boundary falls between computing the code and checking it.

### Added

- **`composer test:databases`:** runs the suite on SQLite, MySQL and PostgreSQL at once (package development only).

## [4.8.2] — 2026-11-04

A deep review of security, reliability and code style: holes in sanitizers and login closed, file upload races, import failures and interface details fixed. Released without a GitHub Release (tag only).

### Added

- **Publication locking for MCP:** a publication with the status "Scheduled" or "Sending" cannot be changed through MCP (`mcp.locked`); an administrator edits it as before. A site's own section can lock records the same way with the `Resource::isLockedForMcp(Model $record)` method.
- **`fastleo:doctor`:** an error if `fastleo_admin` can be mass-assigned; warnings about a uuid model key without `HasUuids` and about cached routes that still contain the previous admin address.

### Changed

- **Blocks of an unknown type:** `Blocks` keeps a block whose type was removed from `types()` instead of erasing it when the form is saved.
- **`visibleWhen()`:** an empty list of values and `*` in a value now give a clear error when the field is described, instead of a wrong rule.
- **Generators without a name:** `make:*` without a name and without a terminal (CI, a script) answer with an error instead of waiting for input.
- **Leads (`leads`):** a mark from another session gives a form error instead of being silently dropped; notifications to administrators are limited by a common ceiling.
- **Code style:** `new Foo()` with parentheses throughout the package (PSR-12); the scheduler cache key is `fastleo.schedule-ran`.

### Fixed

- **Login and sessions:** after 2FA is confirmed, other devices are signed out; password reset sends the email in the background and does not reveal whether the address exists; closing the site checks the session password; `fastleo:mcp --user` is case-insensitive.
- **Sanitizers:** `position: absolute`, `image-set` and `src(` are blocked in styles; a video link with `..`, `%2e` or `%5c` is rejected; links in leads and notifications are checked more strictly (control characters, paths with a tab).
- **File upload:** two simultaneous files with the same name no longer overwrite each other; a thumbnail is recreated if the original was replaced; after 10 name collisions a random suffix is added; a corrupted path does not break image variants; `markdown` has a rate limit.
- **Import and export:** a row whose key the database cannot compare gives a row error instead of aborting the import; the list of unknown columns in the report is capped; import is not interrupted by the PHP time limit; numbers longer than 15 significant digits go into xlsx as text, without losing precision.
- **Races:** the sitemap is built once under a lock, and file numbers beyond `MAX_FILES` are not collected; the settings cache is reset after commit; the last administrator is protected by a row lock; the command cache file is written through a temporary file.
- **Publications:** when deleting again from Max, messages that are already deleted are not searched for again; editing in Telegram keeps the post layout; the "any enabled" channel choice no longer sticks in a long-lived queue worker; notifications with the `sync` queue are sent after the response.
- **Fields:** `BelongsToMany` has at most 1000 relations and `Password` at most 255 characters; the integer 0 is not treated as an empty nested block; `Select` with a non-string option label does not crash; `KeyValue` does not compare pairs when `max` is exceeded.
- **Section and record:** the `dashboard` slug is reserved for the system; the tree cycle check uses queries instead of loading the whole tree; manual order is rolled back if the model cancelled the save; a database error shows the driver message, not the raw query.
- **Installer:** no crash on a dashboard widget error, a hint about `module_cache`, template characters in module paths are escaped, `?>` and CRLF line endings in `routes/web.php` are preserved; `fastleo:skill --path` is counted from the site root; `fastleo:install` does not ask about an administrator if one exists.
- **Interface:** resubmitting a form and submitting during a file upload are blocked; import polling shows errors and reloads the page when the session has expired; dependent lists do not show a stale response; a corrupted localStorage does not break the page; links and format labels of the editor come from translations.
- **Details:** the error log reads a record title that contains `]`; `Slug` does not accept a trailing line break; sitemap pages and `robots.txt` do not get the admin error page; `HealthText` hides network and relative paths.

## [4.8.1] — 2026-11-04

Fixes: field validation messages with a rule name, MySQL default values, filters on hidden fields, small details of login, sitemaps, site closing and the installer. Released without a GitHub Release (tag only).

### Fixed

- **Filter on a hidden field:** if a field is hidden from the administrator (`hidden(fn …)`), the filter on it is neither shown nor applied — previously the hidden field's value could be guessed from the number of found records and the filter options. `fastleo:doctor` and `fastleo:inspect` still show all filters.
- **Error text from the wrong rule:** for a field named like a rule (`url`, `email`, `image`, `date`), the message of the same-named rule was shown instead of the message of a rule object (`VideoLinkRule`, `Rule::date()`, `Rule::in()`). `ValidationMessages::all()` accepts form rules (`ValidationMessages::all($rules)`) and gives such texts exactly to the fields that have the rule.
- **Default values from MySQL:** unquoted string values (`#ffffff`, `a,b`, `x/y`) were lost and did not reach the create form; expressions (`_utf8mb4\'x\'`, `-(1)`, `ARRAY[]`, functions with a schema) are still not treated as values.
- **Slug:** if the address source field cannot be changed (`readonly()`, hidden), saving could write an address that is already taken.
- **Two-factor login code:** the page erased a code that a password manager had filled in.
- **"Per page":** on sites with a config published before 4.5.0, the choice of the number of records disappeared; now, if the `list.per_page_options` key is missing, `[25, 50, 100]` applies.
- **Restoring from the trash:** if the model cancelled the restore (`restoring` returned `false`) or the database refused (for example, a unique address is taken), a message is shown instead of "Record restored" or a 500 error page.
- **Publishing to messengers:** literal `&amp;`, `&copy;` in the text and `&` in links reached Telegram and Max distorted.
- **Telegram bot chat search:** no longer resets the set of events configured for the bot by the site (`allowed_updates`). If the bot previously received a restricted set without `message`, `channel_post` and `my_chat_member`, "Find chats" finds nothing — enter the chat number by hand.
- **Site closing:** while the settings table does not exist, a read error was written to the log on every settings access; now once per request.
- **Sitemap:** if the cache (Memcached) does not accept a large sitemap file, it was rebuilt on every request; now a warning with advice to reduce `per_file` goes to the log once a day.
- **Field name in the uniqueness error:** instead of the generic "this value already exists" — "the value in the field "slug" already exists in another record" (SQLite, PostgreSQL and MySQL with the default index name).
- **Installer on a production server:** the question "Run migrations now?" defaults to "no"; the question "Create an administrator now?" is not asked if an administrator already exists.
- **Filter list:** `hidden(fn …)` is evaluated once per request, not for every filter.

## [4.8.0] — 2026-11-04

Administrator roles: a receptionist does not open settings, an editor does not delete, an observer only reads. Released with a GitHub Release.

### Added

- **Administrator roles** based on `spatie/laravel-permission` ([documentation](docs/roles.md)). Off by default (`fastleo.roles.enabled`); the package is needed only by sites with roles. An action is allowed only if roles allow it, the resource `can()` method allows it and the model Policy allows it. Permissions are named `fastleo.{section}.{group}` (view, create, update, delete), live under the `fastleo` guard and do not mix with the site's roles. An administrator without roles can do nothing; if roles are enabled but the `HasRoles` trait or the tables are missing, access is closed.
- **The owner role** (`fastleo.roles.owner`, `fastleo-owner` by default) passes all checks and is the only one that opens "Administrators", "Roles", settings, custom pages, files, the error log, cache clearing, SoftNetica, site closing and the "Server status" widget. If the site set its own Gate or `canAccess()` for them, the site decides.
- **The "Roles" section** with a "section × view / create / update / delete" matrix: ticking update or delete ticks view, clearing view clears the rest. The owner role cannot be changed or deleted.
- **Preset roles** "Administrator", "Editor" and "Observer" (`php artisan fastleo:roles --presets`); the command does not touch existing roles. "Editor" and "Observer" do not get the message delivery sections (channels, notifications, publications).
- **The "Roles" field** on an administrator (a list of checkboxes) and a "Roles" column in the administrator list.
- **The `fastleo:roles` command:** creates the owner role, `--owner=email` gives it to an administrator, `--presets` creates the preset roles.
- **Last owner protection:** they cannot be deleted, stripped of the role or of admin access, including by simultaneous requests from two administrators.
- **The `Resource::beforeDelete(Model $record)` hook:** a check before deletion inside the transaction (from a section, from the trash and from a bulk action); `OperationCancelled` with a reason shows it to the administrator ([documentation](docs/authorization.md#проверка-перед-удалением-beforedelete)).
- **Closed relation:** a `BelongsTo` / `BelongsToMany` field pointing to a section the role does not open becomes gray with a hint, and the filter gives no options.
- **403, 404, 419 and 429 error pages** in the admin style (in the menu for a signed-in user, on the login screen for a guest); JSON requests and site pages do not change. The template is overridden through `vendor/fastleo/errors/page.blade.php` ([themes](docs/theming.md)).
- **`fastleo:inspect`, MCP `describe-resource` and `fastleo:doctor`** show role permissions and check the setup (no owner, administrators without roles, teams mode, directly granted permissions).

## [4.7.5] — 2026-11-04

Record selection in blocks, the `MorphTo` field; the change log was removed from the package. Released without a GitHub Release (tag only).

### Added

- **Record selection in `HasMany`/`HasOne` blocks:** `BelongsTo` now works inside a block (a product in a bundle, a product in an order). Columns of a many-to-many relation (quantity, "main") are described the same way: the pivot table needs its own `id` and a model, and the parent needs a `HasMany` to it ([recipe](docs/fields/has-many.md#выбор-записи-в-блоке)). Options in a block are always searched on the server, labels of selected records of all blocks are loaded with one query; a record that dropped out of the options stays selected. The block search address is `fastleo.resources.options` with the name `block.field`. `fastleo:doctor` does not warn about server search for such fields.

- **The `MorphTo` field:** a record of one of several sections (a note on an article or a product) with two lists — the section and the record with server-side search ([field](docs/fields/morph-to.md)). Types are only sections from `types()`, and the choice can be narrowed for each; an arbitrary class from the request is not accepted. The type is stored as `getMorphClass()` (respects `Relation::morphMap()`); the relation is not read through Eloquent, so a record with an unknown type is shown as text and does not break the list. Only a record of a section the administrator may open (`viewAny`) can be chosen; a saved pair stays as long as neither the type nor the key changed. Import and export use `articles:5`, MCP uses `{type, id}`, `fastleo:inspect` shows `types`, `fastleo:doctor` checks the columns, `$fillable`, `ownerKey`, the type map and keys of different kinds. Record labels on the list page and in the `Table` widget are loaded with one query per section (the new `Field::prepareFor()` method).

- **The `fastleo:drop-activity` command:** deletes the `fastleo_activity` table of the old log and the record of its migration (asks for confirmation, `--force` skips the question).
- **`BelongsTo` in a block:** repeating one value across blocks is rejected by the `->rules(['distinct'])` rule; a block field named like the relation's foreign key (`product_id` for `bundleItems`) is a description error; values of all blocks are checked with one query.
- **`MorphTo`:** a record value with an unknown type or a section closed to MCP is returned by MCP as `{type: null, id, morph_class}` and accepted back unchanged; a saved pair is not rewritten without a column change.

### Removed

- **The change log** (who, when, "was → became", rollback, the history panel, the "Log" menu item). Writing still goes through Eloquent: if you need a log, connect a site package (for example, `spatie/laravel-activitylog`) or your own observers. Removed from the public API: `$activity` and `$historyPanel` on the resource, the methods `logsActivity()`, `hasHistoryPanel()`, `showsHistoryPanel()`, `Field::hasHiddenCondition()`, the Gate `viewFastleoActivity`, the `activity` key in `config/fastleo.php`, the routes `fastleo.activity.*` and `fastleo.resources.history`, the classes `Fastleo\Activity\*`, the `history_panel` field in `fastleo:inspect` and MCP. The `activity` address is free again for your own section.
- **After updating,** run `php artisan optimize:clear` (a stale route cache refers to the removed class and controller: otherwise all admin pages after login answer 500). The package does not touch the `fastleo_activity` table itself; when it is no longer needed, delete it with `php artisan fastleo:drop-activity` (it also removes the migration record); `fastleo:doctor` reminds you while the table or the `activity` key in `config/fastleo.php` remain.

### Fixed

- **The bulk action panel** in the list no longer goes under the side menu ("Selected" had its start cut off).
- **`KeyValue`:** on a wide screen the row delete button is on the right, not to the left of the handle; columns match their headings.
- **Video (`<x-fastleo::video>`):** the iframe has no duplicate `allowfullscreen` and `allow`, so the browser does not warn.
- **The `Stat` tile:** a long number (an amount with currency) shrinks to fit instead of being cut off with an ellipsis; the full value is in the tooltip.
- **`HasMany` blocks:** removed blocks are deleted before the rest are written, so with a unique index (parent + selected record) a row can be replaced; a saved record that dropped out of the options stays only in its own block and is not offered in a new one.

## [4.7.4] — 2026-11-04

Nested lists, an image description and a video field. The release also contains new features — in meaning it is a minor release.

### Added

- **`Repeater` and `Blocks` inside `Repeater` and `Blocks`** (two levels, no deeper): pricing plans with items, work stages with steps, a weekday with opening hours. Validation, errors on the field of the right item, `required()`, `min()`/`max()`, `RichText` cleaning, image variants and `visibleWhen()` (by the field of its own item) work on both levels; dragging and adding items do not affect the neighboring level. A third level gives a clear error when the section is opened ([Repeater](docs/fields/repeater.md#список-внутри-элемента), [Blocks](docs/blocks.md)).
- **`Image::alt('cover_alt')`:** an image description for the site in a paired column — an input under the image (with the same access as the image); inside `Repeater`/`Blocks` it is a neighboring item key ([Image](docs/fields/image.md)).
- **The `Video` field:** a YouTube, Rutube or VK Video link is stored as pasted, with a player under the field in the form; on the site it is the `<x-fastleo::video :url="…" />` component ([Video](docs/fields/video.md)).
- **Recipes** in `docs/recipes.md`: coordinates, a weekly schedule, a gallery with captions.
- **`fastleo:inspect`** shows `Repeater` subfields, `Blocks` types and fields, and the image `alt`; **`fastleo:doctor`** finds description errors inside `Repeater`/`Blocks` (a third level, a forbidden subfield).

### Fixed

- **MCP `get-record`** returned `Blocks` in a form that `update-record` did not accept: the value is now taken in the input format, so a "get — write back" exchange changes nothing.
- **Item order after saving:** the order of inner items of a nested list (including after dragging) is saved as in the form.
- **Text editor in a block moved while collapsed:** HugeRTE starts again and the remains of the old instance are removed.

## [4.7.3] — 2026-11-03

Fixes after an adversarial review of 4.7.0–4.7.2. The main one is the addresses of admin links in notifications.

### Fixed

- **A link to a record in Telegram, Max and email was built from the request host or from `APP_URL`:** a site visitor could forge the `Host` header (`X-Forwarded-Host` behind a trusted proxy) in `POST /leads` of the leads module — administrators got a link to a foreign domain, and on a site with a separate admin domain (`fastleo.domain`) the email and the bots led to the site domain (404). The full address is now built by `Fastleo::adminUrl()`: the admin domain if set, otherwise `APP_URL`; the request host is not used anywhere. `Fastleo::recordUrl($record)` also gives such an address and is suitable for an email sent from a queue.
- **Numeric filter:** accepts `.5`, `5.` and `1e3` (a `type=number` field sends them as is); a fractional bound goes to the database as a string — a number longer than 14 significant digits is no longer rounded.
- **Tree:** a huge page number in the address (`?page=9223372036854775807`) gives an empty page instead of a 500 error.
- **`Image::inTitle()->sortable()`:** there is no sorting by an image that has no column (`?sort=cover` is ignored).
- **The preview window** inserts only the card of its own site (`?frame=1`) into the window; any other address opens as a regular page.
- **Installer:** a conflict with a not yet executed site migration that creates the same table (`*_create_leads_table.php`) is found before files are written; `Dashboard.php` with Windows line endings (CRLF) is edited instead of being left "by hand"; without a terminal (Windows) the `fastleo:user` questions are not asked on the first run — the installer suggests the command.
- **Module stubs:** a lead's phone in the admin is up to 50 characters (as in the column and the site form); news in the admin is sorted by creation date (for news without a publication date the order depended on the database); the link of the "Call to action" block accepts only `/path` or `http(s)://…`.
- **Documentation:** the install questions and flags (`installation.md`), `README`, links to a record for email and bots, buttons in the preview window, a note about full-page caching and the common lead limit (`modules.md`).

## [4.7.2] — 2026-11-03

Fixes after a review of 4.7.1. If your code calls `Resource::recordUrl()`, replace it with `openUrl()`.

### Fixed

- **Record window:** Esc closes the window when focus is in a list field (`BelongsTo`, `combobox`, a relation filter): the list consumes Esc only while open; Esc does not close the window if a popup inside the form has already handled the keypress; "Loading…" is announced; a link to a record with a foreign address opens as a regular page.
- **Tabs made only of emoji** (no letters or digits in the name) get the key `tab-2` by number instead of a 500 error.
- **Documentation:** the administrator example in tests (`tap()` with a function), the record link in email (`Fastleo::mailUrl()`), links in notifications without the request host (`Fastleo::recordUrl($record, false)`, including in the leads module).

### Changed

- **`Resource::recordUrl()` is renamed to `Resource::openUrl()`:** the address from lists and search with a permission check; `Fastleo::recordUrl()` (without a permission check) kept its name so the two methods are not confused.

## [4.7.1] — 2026-11-03

A number filter, tree pages by top-level records and list column settings. The release also contains new features (tabs with tables of related records, the record window, manual order in a table) and changes the default of `fastleo.record_view` to `'modal'` — in meaning it is a minor release.

### Added

- **`NumberRangeFilter`:** a list filter "from … to …" for numbers (price, year, mileage); bounds are integer or decimal (dot or comma), either may be omitted; for an integer column a fractional bound is rounded inward to the range, and a bound wider than the column type is clamped to it (PostgreSQL otherwise answered with an error) ([filters](docs/list.md#фильтры)).
- **Column selection in the list:** a "Columns" button above the list; the choice is remembered by the administrator's browser ([list](docs/list.md#выбор-столбцов)).
- **`hideWhenEmpty()`:** a column disappears from the list while the value is empty for all records on the page ([list](docs/list.md#какие-поля-видны)).
- **`Image::inTitle()`:** the cover as a thumbnail before the title in the first cell instead of a separate column ([image](docs/fields/image.md)).
- **A table of related records in a form tab:** `Tab::make('Reviews', [Related::make('reviews', ReviewResource::class)])` in `fields()`. A tab holds either fields or tables; the table is outside the form and is not saved with it, and the tab is remembered in the address (`#reviews`) ([related tables](docs/related.md#таблица-во-вкладке-формы)).
- **`Related::editIn('modal'|'page')`:** "Edit" and "Add" from a table open the section form in a window (after saving the window closes and the table refreshes) or on the section page with a return, as before ([editing from a table](docs/related.md#правка-записи-из-таблицы-страница-или-окно)).
- **`fastleo.record_view`:** by default (`'modal'`) the "eye" button in lists and tables, a `BelongsTo` link and a row of the "Table" widget open the record card in a window over the page without navigation (no menu and buttons, no editing in the window; the first list column leads to the page); `'page'` — a separate page, `false` — the view page can be switched off: the address answers 404, links lead to the edit form or stay text ([settings](docs/configuration.md#страница-просмотра-записи)); `Resource::recordUrl()`, `Resource::viewUrl()` and `Fastleo::recordUrl($record)` — a link to a record that adapts to the setting; `fastleo:doctor` reports an error (`record_view_unknown`) if the setting value is not `'modal'`, `'page'` or `false`.
- **`BelongsTo` in a list as a link** to the related record if the administrator may view it (`Fastleo::resources()->forModel()`): per the `fastleo.record_view` setting — in a window or on a page.
- **Manual order in a related table:** for a child section with `$sortColumn`, records of the `Related` table are dragged by a handle; the order changes among records of one parent ([related tables](docs/related.md#ручной-порядок)).
- **`Table::query(Closure)`:** the "Table" dashboard widget narrows records with a condition (moderation queue, unanswered leads) ([dashboard](docs/dashboard.md#table--последние-записи)).
- **The Gate `manageFastleoSoftNetica`:** the SoftNetica page can be closed by role, like logs, files and cache ([permissions](docs/authorization.md#сайт-с-ролями-что-закрыть)).
- **`fastleo:make-resource --generate`:** a column with an enum cast becomes `Enum::make(...)->enum(...)` instead of `Text`.
- **Documentation:** a "Site with roles" checklist, validation by the values of other fields (`DataAwareRule`), an "Order items" recipe, the `date:Y-m-d` cast, a list inside a block, what is not translated, "Adapting a module to the site", the administrator seeder, `APP_URL`, `retry_after`, a composite import key.

### Fixed

- **A record link in notifications, webhooks and the queue** is built without checking the current administrator's permissions (`Resource::recordAddress()`): previously, with a Policy, the link was lost where there is no administrator.
- **Record window:** after saving, a "saved" page answers at once, without navigation: a record that left the section's selection does not give 404, and the flag is not lost; Esc and the cross with unsaved changes ask for confirmation; Esc with a file picker open closes only the picker; a foreign site address is not opened in the window.
- **A row of the "Table" widget** with Ctrl/Cmd/Shift opens as a regular link; the `view` Policy for the address and the window is called once per row (`Resource::viewModalUrl($url)`).
- **Tab description errors:** `Related` outside a tab (in `fields()`, a section), `Related` outside a section form (settings, an action mini-form, `HasMany` blocks) and two tabs with the same address key (`SEO` and `Seo`) give a clear exception.
- **An action with a mini-form** in a tab table shows the form again on an input error.
- **A form with `Aside`:** `Aside` fields no longer move below the form; on a tab with a table of related records `Aside` hides together with the "Save" button.
- **`BelongsTo` with `titleColumn()` on a translatable column** shows the title in the main language, not the record number.
- **The preview window by kind:** the card takes as much height as it needs (a form in a window — 85% of the screen); the cross sits in the corner, with no empty strip above the title; a click on the background closes the window (a text selection that ended outside the window does not close it); the page under the window does not scroll; the window name for screen readers is the record title.
- **Interface details:** the tab panel has no lonely record count above the table; a cell edit error wraps by lines instead of being cut off; a quick-add field with an error is highlighted and gets focus; the "Unsaved changes" mark stands next to the explanation and does not shift the page buttons.
- **`fastleo:doctor`:** finds an unknown icon (`$icon` of a section, action, settings page, custom page) that crashed every admin page; finds `Text` on a column with an enum cast (a wrong value gave 500); the "server search instead of a list" warnings are merged into one; `BelongsToMany` with server search is not counted in `max_input_vars`; the description error of a child section names the related table, not the parent.
- **Login form:** Tab from the email field goes to the password, not to "Forgot password?".
- **A custom `UserResource` subclass** in the sections folder without the `fastleo.users.resource` setting: the error about a duplicate address suggests the setting, not `$slug`.

### Changed

- **Tree pages count records of one level:** "25 per page" means 25 top-level records (or direct children of the open branch) with all their nested ones, not 25 rows in traversal order. Collapsed branches do not stretch the page count, and a record is not torn from its nested ones. The caption under the list is "Top-level records 1–25 of 80 (with all nested)", in a branch — "Branch records …", the counter in the heading is all records of the section; a tree page is no longer than `fastleo.list.max_all` rows — the tail of a very large branch is not shown, with an explanation under the list ([tree](docs/list.md#дерево)).

## [4.7.0] — 2026-11-02

Installation with a questionnaire and ready-made site modules: `fastleo:install` asks what to install and brings the site to a working admin panel at once.

### Added

- **`fastleo:module`:** ready-made admin sections are copied into the site as its own code — `contacts` (contact settings), `pages` (a tree of pages made of blocks and SEO), `news` (news; a publication date in the future postpones showing), `leads` (the "Leads" section and a `POST /leads` handler with bot protection without external services, a notification to administrators). A module includes the section, admin home page widgets, migrations, factories, seeders and Pest tests (if the site has Pest with `tests/Pest.php`). **Modules do not create the public part (layout, templates, site pages)** — the site does, with examples in docs. If a file, table or address is taken, nothing is written; on a write error, what was created is removed ([modules](docs/modules.md)).
- **A questionnaire in `fastleo:install`:** the install kind (basic — without ready-made modules, custom — with a choice) → the list of modules; migrations, `storage:link`, sample data and an administrator — with confirmation or the flags `--type=basic|custom`, `--modules`, `--no-modules`, `--setup`, `--seed`, `--admin-email`, `--admin-password` ([installation](docs/installation.md#опрос-при-установке)).

### Changed

- **`fastleo:install` asks questions in interactive mode.** Scripts and CI run it with `--no-interaction`: then nothing is asked and nothing is executed unless flags are given.
- **`fastleo:install` exit codes:** `1` also when a step that was requested by flags or confirmed (`migrate`, administrator, `fastleo:doctor`) failed; contradictory flags (`--type=basic --modules=…`, `--no-modules --modules=…`) are an error, not a silent choice of one of them; `--admin-email` without executed migrations is an error; `--seed` in production warns that it was skipped.
- **First run on a clean site:** when the installer has just connected the user model, the administrator and `fastleo:doctor` run in a separate `php artisan` process (the model class in the installer is already loaded in its previous form) — one run is enough.

## [4.6.1] — 2026-11-01

Installation on a clean Laravel 13 without manual model editing, bot notifications and publications off by default, review fixes and documentation with generator commands.

### Changed

- **`fastleo:install` connects the user model itself:** it adds the `FastleoUser` interface and the `HasFastleoAccess` trait to `app/Models/User.php` and leaves the rest of the file alone. If the model is outside the `app` folder, the file is not writable, the connection was already started by hand or the class is declared unusually, the file is not touched — the command says what to do and exits with code `1`. The result is checked with `php -l` before writing. The `fastleo_admin` cast to `bool` is set by the trait itself; the model's own cast takes precedence ([installation](docs/installation.md#2-модель-пользователя)).
- **Off by default** (how to enable them, with examples and variants, is in the [configuration](docs/configuration.md)): the "Notifications" section with bots (`notifications.bots`), the "Channels" and "Publications" sections (`publishing.channels`, `publishing.publications`). **For sites where fastleo is already installed:** the published `config/fastleo.php` is not overwritten, so these features stay enabled there; only new installs get the new values (or a site where the file was deleted and `fastleo:install` was run again).
- **`fastleo:doctor` before `migrate`** gives one error "Fastleo migrations have not been run…" with a list of tables instead of a separate error for every table and every built-in section.
- **`fastleo:install`** reminds about `php artisan storage:link` while the `public/storage` link is missing and about `php artisan migrate` only while the package migrations have not been run.

### Added

- **`fastleo:make-page --report`:** a report page with a period filter and a tile, without its own template ([reports](docs/reports.md)).
- **`fastleo:make-resource --generate`** makes a `read_at` column (a nullable date with time) the `$readColumn` property instead of a field, and does not warn about it.

### Fixed

- **Connecting the model does not corrupt the file:** for a model with `implements` and a curly brace in PHPDoc the trait could land inside the comment; it is now inserted into the class body. Files with Windows line endings and `implements` over several lines are supported; a file with mixed line endings and hosting without `proc_open` — manual connection instead of editing or an error.
- **Related tables respect the conditions of the relation method:** for `hasMany(Task::class)->whereNull('done_at')` the table, its counter and search show only matching records, not all records of the parent. Relation conditions are put in parentheses on top of the child section's `query()`: `orWhere` in the relation method does not open records of other parents ([related tables](docs/related.md)).
- **Field rules see the other fields of the record** in the quick toggle, the bulk `SetToggle` and the cell edit (`prohibited_if:title,…`); `same:` and `different:` compare values the same way as in the form, and a field whose value cannot be read does not crash the request.
- **The number on a menu item** with `$readColumn` counts list rows if the section's `query()` groups.
- **Custom pages with their own template** again get an empty `data()` by default; report variables go only to a report page.
- A non-string 2FA secret gives a refusal instead of a 500 error; an action mini-form input is put into the session only if such a related table exists.

### Documentation

- Before every example of a new class there is the generator command with the path of the file it creates (`fastleo:make-*`, `make:model`, `make:migration`, `make:observer`, `make:enum` and others); examples are aligned with what the generators create.
- Everything that is off by default has a "How to enable" section: notification bots, publications, deletion through MCP.
- Examples fixed: the `LeadCreated` notification without `toMail()`, the page properties table (`view()` and `data()` have defaults), the model import in the page example.
- Related tables: "Add" and quick add set only the foreign key, not the values from the relation's `where`; the "Pages" section — `$slug` right after generation.

## [4.6.0] — 2026-10-31

A "viewed" mark for inbound records, custom page permissions on its routes, case-insensitive search in SQLite and an FAQ of 275 practical questions. Details are in [resources.md](docs/resources.md#отметка-просмотрено) and [docs/faq](docs/faq/README.md).

### Added

- **A "viewed" mark** for inbound records (leads, messages): `protected static ?string $readColumn = 'read_at';`. An opened record becomes viewed for all administrators, the menu number counts unviewed ones (with the section's `query()`), their list rows are highlighted, and there are the actions "Mark viewed" / "Mark unviewed". The mark does not change `updated_at` and does not call observers; `fastleo:doctor` checks that the column exists and is a date with time that allows NULL ([sections](docs/resources.md#отметка-просмотрено)).

### Changed

- **Built-in action keys are reserved in any section:** `delete`, `restore`, `force-delete`, `mark-read`, `mark-unread`. Previously a custom `restore` action in a section without a trash passed, and after `SoftDeletes` was added to the model the section stopped opening; now it is a description error right away ([actions](docs/actions.md)).

### Fixed

- **A custom template for a subclass of any field.** `SiteImage extends Image` (likewise subclasses of `Text`, `Email`, `File`, `Enum`, `Slug`, `KeyValue`, `Repeater`, `Seo`, `Blocks`, `HasMany`, `HasOne`, `BelongsTo`, `BelongsToMany`) with the file `resources/views/fastleo/fields/site-image.blade.php` now uses that template even without its own `view()`. Previously subclasses of these fields silently kept the package template ([custom fields](docs/fields/custom.md)).
- **Custom page permissions on its routes.** `Fastleo::routes(fn () => …, page: ReportPage::class)` carries the page checks over to the routes of buttons and forms: `isAvailable()` (otherwise 404) and `canAccess()` (otherwise 403). Previously the route of a closed page was open to any administrator ([custom pages](docs/pages.md#кнопки-и-формы-на-своей-странице)).
- **Case-insensitive search on SQLite and for Cyrillic.** Search in the list, relation options and filters finds "Product" by the word "product" on all databases; previously SQLite compared case-insensitively only Latin letters.
- **Text is not escaped twice** in `alt` of `<x-fastleo::picture>` and in the `<x-fastleo::seo>` title: `alt="{{ $product->name }}"` with `&` or quotes no longer turns into `&amp;amp;`.

### Documentation

- **FAQ** — [docs/faq](docs/faq/README.md): about 275 practical questions with ready code, from ordinary pages and sections to CRM, complex logic and integrations; the `docs/README.md` table of contents in study order.
- Navigation between sections ("Next", links to permissions, security and `fastleo:doctor`), field pages brought to one form, long paragraphs split; descriptions that diverged from the code were fixed (the Checkbox/Toggle look in the list, the Seo image path, Range and Url validation, Enum with a value outside the options).

## [4.5.0] — 2026-10-31

Features for CRM-like admin panels: related tables in the record card, cell editing, computed columns, default filters, list auto-refresh and report pages. Details are in [related.md](docs/related.md), [list.md](docs/list.md) and [reports.md](docs/reports.md).

### Added

- **Related tables in the record card** — `Resource::relations()` and `Related::make('cars', CarResource::class)`. This is a child section table under the form on view and edit: columns, search, sorting, pages, actions and permissions come from the child section. "Add" opens its form with the parent filled in and returns to the card. Permissions: the table is visible with `view` of the parent and `viewAny` of the child section, "Add" requires `update` of the parent and `create` of the child. Each table is loaded with its own query, so search and pages of one do not affect the others. The column linking to the parent is not shown.
  - `->expandable()` — a row expands the related tables of the child record, to any depth: order → items → offers.
  - `->aside()` — a table in the side column of the card.
  - `->quickAdd(['title', 'due_at'])` and `->addLabel()` — a small form under the table: a record is created without going to another page, and the parent is set automatically.
  - `->columns([...])` — only the needed columns, `->perPage()`, `->label()`.
  - The "Per page" choice and the "All" button right in the table.
- **Cell editing** — `->inlineEditable()` on text, number, a single-choice list and `BelongsTo`. The value is saved when leaving the field or on Enter, validated by the field's rules, and written through Eloquent. Computed columns of the row are recalculated after saving.
- **Computed columns** — `->fromQuery()`: the value is taken from the resource's `query()` (`withCount()`, `withSum()`, a subquery). Such a column is display-only, sorts by the alias and is included in the export.
- **Default filter** — `->default(fn () => auth()->id())`. Applied while the address has no filter value; "All" switches it off. The "Reset" button is visible only when the list view differs from the default view.
- **Prefilling the create form from a link** — `?prefill[client_id]=5`. Only simple visible fields are filled, and values are validated by the field's rules.
- **Colored status badges** — `Select::badges([...])` and `Enum::badges()` with a `tone()` method on the enum.
- **Row highlighting** — `Resource::rowTone(Model $record)` returns `success`, `warning`, `danger`, `info` or `neutral`. Works in the list, in phone cards and in related tables.
- **List auto-refresh** — `protected static ?int $poll = 10;`. The list and related tables of the section are re-requested while the tab is visible and the administrator is not editing a cell, has not selected records and is not dragging a row.
- **Report pages** — a `Page` subclass with `filters()` and `widgets()`:
  - the filters `Period` (with the buttons "Today", "Yesterday", "7 days", "Month", "Quarter") and `Choice`;
  - `Chart::series()` — several lines on a chart;
  - the `Rows` widget — a table with its own rows and a total row;
  - `$columns = 6` — six tiles in a row.
- **A link to the existing record on an "already exists" error** — `->duplicateOf(fn (string $value) => …)`. The link appears only if the record is visible in the section's `query()` and can be opened.
- **Menu and login settings:** `home` — the start page after login (a section or a custom page); `menu.dashboard` — hide the "Dashboard" item; `menu.collapsed` — the menu is collapsed or expanded by default.
- **"Per page" under the list** — `list.per_page_options` in the config or `$perPageOptions` in the resource, 25/50/100 by default. The choice is kept across search, filters and sorting.
- **`$createLabel`** — the caption of the create record button ("New lead").
- **`fastleo:doctor`:**
  - a related table without a foreign key field in the form (`related_foreign`);
  - a required field outside quick add (`related_quick_required`);
  - a computed field without a value in `query()` (`from_query_missing`);
  - `inlineEditable()` on a read-only field (`inline_readonly`);
  - an unknown start page (`home_unknown`).
- **`fastleo:inspect`:** a section gained `relations` and `poll`, fields gained `badges`, `from_query` and `inline_editable`, filters gained `has_default`.
- **Documentation for Claude:** the "What to choose" and "Common mistakes" tables in [claude.md](docs/claude.md). Recipes in [recipes.md](docs/recipes.md): several phones, "only own records", "take into work" without a race, leads from the site through an API, menu counters, old passwords.

### Changed

- **The record title** in the heading, breadcrumbs and search is now taken from the first list column that is not a relation. Previously child sections with a relation first showed "Lead #145" instead of their own title.
- **A wide table no longer stretches the page** (since 4.0: on a phone the page shrank). Row buttons are pinned to the right edge of the table.
- **Chart lines** do not go beyond point values. Axis dates are shown as `dd.mm`.
- **`fastleo:doctor` does not require `$fillable`** for a field that is not in the form (`onlyInList()`).

### Fixed

- **A database error in MCP tools** (`create-record`, `update-record`, `delete-record`, `run-action`) arrives as a clear response instead of crashing with a `TypeError`.
- **Image cropping** reports an error if the disk did not write the file. Previously the field saved the path to a nonexistent file.
- **The return address** does not accept `..` and `%2e` in the path.
- **Clearing the error log** with a file closed for writing answers with a clear error instead of 404.
- **Saving the dashboard layout** accepts sizes sent as text.
- **`list.per_page_options = null`** in the published config no longer breaks lists.
- **A record with the key `0`** is saved without an address building error.

## [4.4.1] — 2026-10-31

Fixes after an adversarial review of the admin panel.

### Fixed

- **`SetToggle` checks the field rules**, like the quick toggle in the list: records the rule does not pass are skipped, and the reason is visible in the message ("Skipped, the value did not pass the field check: N (…)"). Writing goes the same way as from the form — through `$fillable` and the field's `fill()`.
- **XML formats in which a browser would run a script are forbidden:** `xht`, `xslt`, `rss`, `atom`, `rdf`, `kml`, `mml`, `xspf` — they cannot be uploaded even if allowed in `files.extensions`.

## [4.4.0] — 2026-10-30

Fixes from the experience of moving three sites off old admin panels: `fastleo:doctor` checks, the record title, the "empty" value, quick export.

### Added

- **`Resource::title(Model $record)`** — the record title in the form and view heading, breadcrumbs, the tree branch caption and search results. By default, as before, the first list column (now always as plain text, for a translatable field the first language); override the method to avoid depending on field order.
- **`Field::emptyValue($value)`** — what to write instead of `null` when the field is left empty: `Select::make('category_id')->placeholder('No main category')->emptyValue(0)`, `RichText::make('text')->emptyValue('')` for a NOT NULL column. Works on writing from the form, import, MCP and in nested records; for `Select`, `Radio` and `BelongsTo` such a value in the form, list and export counts as empty, for other fields it is shown as an ordinary one. Not available for checkboxes, passwords, files, lists, nested records, `multiple()`, `translatable()` and JSON subfields — `LogicException`. In `fastleo:inspect` the field has the key `empty_value`.
- **`fastleo:doctor` checks:** no password reset links table (`auth.passwords.fastleo`; the hint accounts for the old `password_resets` that the site uses); the package migration is not recorded in the `migrations` table, but its table or the `fastleo_admin` column already exists — `migrate` will fail (a warning if not yet executed site migrations run first). A field with `emptyValue()` is not treated as the "NOT NULL column without `required()`" error.
- **`docs/migrating.md`** — moving from another admin panel: administrators, password reset, "already exists" on `migrate`, image paths, the "empty" value, the record title.

### Changed

- **Select and Radio do not erase a saved value that is not among the options.** Previously only dependent lists behaved this way; now the value stays selected in the form and passes validation until the administrator chooses another (for example, `0` in `category_id`). A new value outside the options is still rejected. In block subfields (`Repeater`, `Blocks`, `HasMany`, `HasOne`) a value outside the options is, as before, not saved. The `in` rule of `Select` in `fastleo:inspect` is output as before (`in:"a","b"`).
- **Export reads records in chunks by keys, without offset.** On a table of hundreds of thousands of rows, a chunk from the end is exported as fast as the first; the PHP time limit for export is lifted. xlsx is still assembled whole and delivered at the end — for very large tables choose csv (`import-export.md`).
- **`fastleo:clear` output and docs:** the section list cache is optional, sections are found without it too.

## [4.3.0] — 2026-10-30

Fixes from the experience of the first site that moved to 4.2: styles in the editor, custom field templates, doctor checks, a safe cache clearing button.

### Added

- **Site styles in the RichText editor.** The settings `rich_text.content_css` (addresses of the site stylesheets) and `rich_text.content_style` (your own CSS rules) — logos, fonts and images in the editor look as they do for the visitor. Invalid addresses (`//host`, `http://`, quotes) are an error, and `fastleo:doctor` shows it.
- **`fastleo:doctor --sanitize-diff`.** Walks through all records of RichText fields and shows which ones the HTML cleaning will change on the first save: the record key and "was → will be" around the first difference (including changed attributes and entities like `&l`). With `--json` it gives the same data.
- **`fastleo:doctor` warnings:** a column wider than the `Text` length limit (`varchar(500)` with the default `maxLength` of 255); the queue's `retry_after` is not greater than the import timeout (3600 s) on a site with import; a `BelongsTo`/`BelongsToMany` with more records than `relations.preload` — the form shows server search.
- **Documentation:** an "operation → model events" table (`resources.md`), a queue on hosting with a single cron (`installation.md`), the lookup order of custom field templates (`fields/custom.md`).

### Changed

- **A field subclass template is looked up in `resources/views/fastleo/fields/`** (where `fastleo:make-field` writes), then in the package templates: `SiteRichText extends RichText` with the file `fastleo/fields/site-rich-text.blade.php` uses it without overriding `view()`. Previously such a file was silently ignored.
- **RichText is not shown as a list column by default**, like Textarea. To bring it back: `->showInList()`. The `fastleo:make-resource` generator no longer adds `->hideInList()` to RichText.
- **A field's `default()` is applied on the server when creating through the form too:** if the field was not sent at all, its default value is taken (as was already the case with MCP and import) — `required()` together with `default()` does not give the "Fill in the field" error. A sent value, including an empty one, is not replaced.
- **MCP no longer closes sections with phones and email by itself.** Closing is only `protected static bool $mcp = false`; the `$mcpPersonalData` property and detecting people's data by field names are removed (sites that declared `$mcpPersonalData` keep working: the property is simply not used). Removed from `fastleo:inspect`: the `mcp_personal_field` key and the "people's data" reason; from `Resource`: the `mcpPersonalField()` method. "Administrators", channels and notification bots are still closed.
- **The "Clear cache" button on the dashboard** runs `cache:clear`, `view:clear` and `fastleo:clear` instead of `optimize:clear`: configuration and route caches on a production site stay.

## [4.2.1] — 2026-10-30

### Fixed

- **Page buttons are on the same line as the heading.** In lists they were at the level of the description. On a record (view and edit), on the dashboard ("Customize"), in "Files" (search, "New folder", "Upload") and in the "Error log" ("Clear log") the buttons took a separate row, now they are in the page header.
- In the search window (Ctrl+K / ⌘K) the input no longer has a clipped focus ring.

## [4.2.0] — 2026-10-30

A dashboard that can be customized: widget sizes in code and each administrator's layout.

### Added

- **Dashboard layout: widget width and height.** `Widget::rowSpan(1…4)` next to `columnSpan()`; cards fill the whole grid cell, so tiles can be stacked next to a tall chart without gaps.
- **A "Customize" button on the dashboard.** The administrator drags widgets, changes their width and height and hides unneeded ones; the layout is stored separately for them (the `fastleo_dashboard_layouts` table), and "Restore default" returns the layout from code. A hidden widget does not compute data.
- `Widget::key()` — a permanent widget key for saved settings (without it the key is built from the class and the title).

### Changed

- After updating, run `php artisan migrate` (the new `fastleo_dashboard_layouts` table; `fastleo:doctor` checks that it exists). Until the migration, the dashboard works with the layout from code, but your own cannot be saved.
- Dashboard cards stretch to the height of their row (previously they were pressed to the top); a chart takes the full height of the card.

## [4.1.1] — 2026-10-30

Fixes from the experience of updating the first site to 4.1.0.

### Fixed

- **`isAvailableInMcp()` on a resource is static again, as in 4.0.0.** In 4.1.0 it became an object method, and the call `ProductResource::isAvailableInMcp()` crashed. Now both calls work: `ProductResource::isAvailableInMcp()` and `$resource->isAvailableInMcp()`.
- **`fastleo:doctor` no longer writes "The section list cache does not match" when site closing is enabled** (`closing.enabled`): the "Site closing" page, like the SoftNetica pages, does not go into the section cache.
- **The `fastleo:doctor` hint about losing RichText markup on a field with its own `sanitize()`** names that method, not `fastleo.rich_text`.
- `SetToggle` on a single record (the record page, the list row menu) shows only the button that changes the value; the selected records bar shows both.

### Added

- `Action::visibleFor(Model $record)`: hide an action button on a single record when it does not need it.
- `Stat::url()` accepts a closure: the link is chosen when the dashboard is shown.
- In `docs/import-export.md`: import cleans the HTML of a `RichText` field the same way as the form (verified by a test).

## [4.1.0] — 2026-10-30

Fixes and improvements from the experience of the first site that moved from 3.0.

### Changed

- **The MCP server does not see sections with people's data.** A section with an `Email` or `Tel` field, or a field with `email`, `phone` or `tel` in its name (`client_phone`, `phone2`, `phoneNumber`; including inside `HasMany`, `Repeater`, `Blocks`) is closed unless the resource has `protected static bool $mcpPersonalData = true;`. "Administrators" is always closed (a `UserResource` subclass with `$mcp = true` and `$mcpPersonalData = true` opens it). `$mcp` stays `bool`, as in 4.0.0; `isAvailableInMcp()` became an object method (it was static before); `fastleo:inspect` shows whether a section is visible to the agent and why it is closed.
- **The create form takes default values from the model and the database:** the field's `default()`, then the model's `$attributes`, then the column's DEFAULT. A `Toggle` without `default()` on a `default(true)` column is on in a new record.
- **The `Chart::countByDay` chart counts days in the admin time zone** (`fastleo.timezone`).
- **The "Site checks" widget in the admin shows texts as they are**; addresses, email and paths are hidden only in the report for SoftNetica.
- **`php artisan optimize` and `optimize:clear` reset the site settings cache.**
- A subclass of a ready-made field (`SiteRichText extends RichText`) takes the template of the nearest parent without overriding `view()` and `type()`.

### Added

- **The quick toggle `Toggle::make('menu')->quickToggle()`:** the icon in the list (table and phone cards) becomes a button, and a click changes the checkbox without opening the form and without a reload. Checks as in the form: the `update` Policy, the field is not `readonly()` and not hidden, the field rules; writing goes through Eloquent (observers). The route is `fastleo.resources.toggle`; `fastleo:doctor` warns about `quickToggle()` on a `readonly()` field; `fastleo:inspect` shows `quick_toggle`.
- **The `Fastleo\Actions\SetToggle` action:** ready-made "Show" / "Hide" for selected records (`SetToggle::make('menu', 'Show on the site', true)->icon('eye')`); `fastleo:doctor` catches a field that is not in the resource or is not a checkbox.
- **`files.hidden` in `config/fastleo.php`:** names of folders and files (with `*` allowed) that the file manager does not show, open or delete individually (like everything inside a hidden folder) — for example, the old `thumbs` from fastleo 3.
- **`rich_text` in `config/fastleo.php`:** extra tags, attributes (`class`, `role`, `data-widget`…), player addresses for `iframe` and `style` as a whole (without `url()`, `expression`, `position: fixed`). `script`, `on*`, `srcdoc` and `javascript:` cannot be allowed.
- **`fastleo:doctor` checks the `rich_text` setting and warns for how many RichText records (the first 500 in a section) tags, attributes and players will be lost on save.**
- In the migration document: "this is not an update, it is a different admin panel", the `pages` address is taken (`$slug`), SEO columns, the order of actions with RichText.

## [4.0.0] — 2026-10-30

The package is written from scratch. There is no compatibility with 2.x and 3.0: how to move — [docs/upgrade-from-3.md](docs/upgrade-from-3.md).

The Composer name is `camanru/fastleo`, the namespace is `Fastleo\`.

### Requirements

- PHP 8.4+, Laravel 13.
- The site does not need npm: the admin CSS and JS are built into the package.
- A queue is optional: without it, tasks (WebP variants, import, notifications) run during the request.

### Sections

- An admin section is one resource class `app/Fastleo/*Resource.php`; sections are found automatically, and the list is cached by the `fastleo:cache` command (part of `optimize`).
- Only fields from `fields()` take part in the form and in writing; writing goes through Eloquent in a transaction (casts, mutators, observers, `SoftDeletes`).
- List: search, sorting, pagination and "all", mobile cards, filters (Select, BelongsTo, BelongsToMany, Boolean, DateRange, custom).
- Form layout: tabs, sections, columns, a side panel, dependent fields and lists.
- A trash for `SoftDeletes`, manual order and a tree with dragging, a view-only section, "open on the site".
- Per-record and bulk actions with a mini-form (`Fastleo\Actions`).
- Streamed xlsx/csv export, import with an error report and a file of skipped rows for re-upload (OpenSpout).

### Fields

- 35 types: Text, Textarea, Number, Checkbox, Toggle, Select, Radio, Range, Date, DateTime, Time, Email, Url, Tel, Color, Hidden, Password, Slug, Enum, Tags, Code, Markdown, RichText (HugeRTE, HTML is cleaned), Image, File, Gallery, BelongsTo, BelongsToMany, HasMany, HasOne, Repeater, KeyValue, Blocks, Seo, Secret (tokens and API keys).
- Multilingual fields with `->translatable()`, custom fields (`fastleo:make-field`).

### Files and images

- A file manager on `Storage` disks with path and extension protection.
- WebP image variants, a watermark, the `<x-fastleo::picture>` component; block output — `<x-fastleo::blocks>`.

### Environment

- A dashboard with widgets (Stat, Chart, Table, Sections, server status).
- Site settings on fields and `fastleo_setting()`, custom pages, an error log.
- Notifications in the header and Telegram and Max bots for administrator chats (the "Notifications" section), `Fastleo::notifyAdmins()`, an email copy (`fastleo.notifications.mail`).
- Publishing to Telegram and Max channels: the "Channels" and "Publications" sections, the `Publish` button in site sections, scheduled sending, retry without duplicates, editing and deleting a post in the channels.
- Admin-wide search with Ctrl+K.
- The SEO field, `<x-fastleo::seo>`, `/sitemap.xml` (above 50,000 addresses — an index of `/sitemap-N.xml` files), `/robots.txt` from the package.
- Closing the site for visitors: a 503 page, the admin panel and administrators work as usual.
- A connection to SoftNetica (a site maintenance service): a widget and a section with the site status. Until a token is entered, the package makes no requests to SoftNetica; the feature can be disabled in the config.
- The "Administrators" section, dark mode, the primary color and logo from the config.

### Login and security

- The `fastleo` guard on top of the site's users, access through `canAccessFastleo()` on every request.
- Login attempt limits, "Remember me", sign-out from all devices on a password change, password reset by email, an email about a login from a new device.
- Two-factor authentication (TOTP + backup codes), can be mandatory.
- Permissions — model Policies on every action.
- After login the admin panel returns only to its own pages; 2FA confirmation and new backup codes require the current password; `Password` fields with any name do not end up in the session.
- A section form refuses to save if PHP truncated it by `max_input_vars`; import is protected from an xlsx "archive bomb" and from an interrupted job.
- Security headers on all admin pages. Details — [docs/security.md](docs/security.md).

### Claude

- A skill for Claude Code (`fastleo:skill`), Laravel Boost guidelines, `docs/llms.txt`.
- `fastleo:inspect` — a description of sections, `fastleo:doctor` — a check of the site and sections.
- Generators `fastleo:make-resource --generate`, `make-action`, `make-filter`, `make-widget`, `make-page`, `make-field`, `make-settings`, `make-dashboard`, `make-policy`.
- The MCP server `fastleo:mcp`, acting as an administrator with their permissions.

### Commands

`fastleo:install`, `fastleo:user`, `fastleo:unlock`, `fastleo:cache`, `fastleo:clear`, `fastleo:images`, `fastleo:prune`, `fastleo:doctor`, `fastleo:inspect`, `fastleo:skill`, `fastleo:mcp`, `fastleo:softnetica-sync`, `fastleo:make-*`.

[4.9.5]: https://github.com/Camanru/fastleo/releases/tag/4.9.5
[4.9.4]: https://github.com/Camanru/fastleo/releases/tag/4.9.4
[4.9.3]: https://github.com/Camanru/fastleo/releases/tag/4.9.3
[4.9.2]: https://github.com/Camanru/fastleo/releases/tag/4.9.2
[4.9.1]: https://github.com/Camanru/fastleo/releases/tag/4.9.1
[4.9.0]: https://github.com/Camanru/fastleo/releases/tag/4.9.0
[4.8.3]: https://github.com/Camanru/fastleo/releases/tag/4.8.3
[4.8.2]: https://github.com/Camanru/fastleo/releases/tag/4.8.2
[4.8.1]: https://github.com/Camanru/fastleo/releases/tag/4.8.1
[4.8.0]: https://github.com/Camanru/fastleo/releases/tag/4.8.0
[4.7.5]: https://github.com/Camanru/fastleo/releases/tag/4.7.5
[4.7.4]: https://github.com/Camanru/fastleo/releases/tag/4.7.4
[4.7.3]: https://github.com/Camanru/fastleo/releases/tag/4.7.3
[4.7.2]: https://github.com/Camanru/fastleo/releases/tag/4.7.2
[4.7.1]: https://github.com/Camanru/fastleo/releases/tag/4.7.1
[4.7.0]: https://github.com/Camanru/fastleo/releases/tag/4.7.0
[4.6.1]: https://github.com/Camanru/fastleo/releases/tag/4.6.1
[4.6.0]: https://github.com/Camanru/fastleo/releases/tag/4.6.0
[4.5.0]: https://github.com/Camanru/fastleo/releases/tag/4.5.0
[4.4.1]: https://github.com/Camanru/fastleo/releases/tag/4.4.1
[4.4.0]: https://github.com/Camanru/fastleo/releases/tag/4.4.0
[4.3.0]: https://github.com/Camanru/fastleo/releases/tag/4.3.0
[4.2.1]: https://github.com/Camanru/fastleo/releases/tag/4.2.1
[4.2.0]: https://github.com/Camanru/fastleo/releases/tag/4.2.0
[4.1.1]: https://github.com/Camanru/fastleo/releases/tag/4.1.1
[4.1.0]: https://github.com/Camanru/fastleo/releases/tag/4.1.0
[4.0.0]: https://github.com/Camanru/fastleo/releases/tag/4.0.0
