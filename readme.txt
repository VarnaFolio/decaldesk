=== DecalDesk ===
Contributors: decaldesk
Tags: woocommerce, decals, stickers, product automation, ai
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn a folder of decal/sticker design files into finished WooCommerce products — filename parsing, area-based pricing, mockup generation, no manual work.

== Description ==

DecalDesk automates turning a folder of design files (PNG, JPG/JPEG, WEBP, GIF) into fully configured WooCommerce products. Built for shops that sell physical products priced by area — vinyl stickers, car wraps, wall decals, kitchen backsplashes, canvas prints, window film, and signs.

**Included in the free version:**

* Filename parsing (`name_widthxheight_material_category.ext`) — no manual data entry
* Price calculated automatically by area (€/m²), configurable per shop
* Static, customizable description template
* One mockup template per category, rectangular placement zone
* Batch upload (drag & drop up to 50 files), background processing
* Draft or publish products directly from the upload screen
* Bulgarian transliteration for product slugs (latin URLs from Cyrillic titles)

**Pro features (upgrade from inside the plugin):**

* AI-generated descriptions, short descriptions, and SEO meta (Google Gemini or Anthropic Claude), including AI Vision (the AI looks at the actual design image)
* Freeform (polygon) zone editor for mockups on irregular surfaces — e.g. a car door at an angle
* Up to 4 mockup templates per category (show a design on several product variants in one gallery)
* Variable products — size / material / color dropdowns, one product instead of many
* WebP/JPEG mockup compression (smaller, faster-loading product images)

DecalDesk requires WooCommerce to be active.

== Installation ==

1. Upload the `decaldesk` folder to `/wp-content/plugins/`, or install directly through the WordPress admin (Plugins → Add New → Upload Plugin).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Make sure WooCommerce is installed and active.
4. Go to **DecalDesk → Settings** and set your price per m², minimum price, and categories (the category slug must match the "category" part of your filenames).
5. Go to **DecalDesk → Categories** and upload a mockup template for each category, then position the design zone on it.
6. Go to **DecalDesk → Upload** and drag in your design files.

== Frequently Asked Questions ==

= Is DecalDesk free? =

Yes. The core workflow — filename parsing, area-based pricing, a static description template, and single-template mockup generation — is free with no time limit. Pro unlocks AI descriptions, the freeform zone editor, multiple mockup templates per category, variable products, and mockup compression.

= What's the difference between Free and Pro? =

Free covers the complete core workflow for a single mockup template per category with a rectangular placement zone. Pro adds AI-generated descriptions (Gemini/Claude), a freeform (polygon) zone editor for irregular surfaces, up to 4 mockup templates per category, variable products (size/material/color), and WebP/JPEG mockup compression. See the Description tab above for the full breakdown.

= Does this work without WooCommerce? =

No, DecalDesk creates WooCommerce products, so WooCommerce must be installed and active.

= What file formats are supported for designs? =

PNG, JPG/JPEG, WEBP, and GIF.

= Can I use my own AI API key? =

Yes (Pro). You can use a free Google Gemini API key (from Google AI Studio, no card required) or a paid Anthropic Claude key — both configurable directly in DecalDesk → Settings, or via `wp-config.php` constants for a more secure setup.

== Screenshots ==

1. Upload screen — drag & drop batch upload with live options
2. Categories & mockup template zone editor
3. A generated product with AI description and mockup

== Changelog ==

= 1.5.1 =
* Fix: variant sizes are now width-only — height is calculated automatically to match each uploaded design's own proportions, so a configured width can no longer produce a variant that looks stretched/mismatched against the shared mockup image (legacy "widthxheight" input is still accepted for backward compatibility, but only the width is used).
* Fix: checking "Create with selectable variants" and clicking "Upload files" without first clicking the separate "Save" button used to silently create a Simple Product with no variants and no error. Uploading now auto-saves the current width/material/color fields first, and warns instead of silently falling back if no size is configured.
* Checking "Create with selectable variants" now automatically expands the width/material/color configuration panel.
* Fixed hardcoded Cyrillic "см" unit label on the Size attribute/variation values, regardless of the configured content language - now "cm".

= 1.5 =
* Version bump for the Premium (Freemius) build — full feature set (AI descriptions, freeform zone editor, multi-template mockups, variable products, mockup compression).
* Removed the standalone uninstall.php file per Freemius requirements — cleanup logic now runs solely through the Freemius after_uninstall hook.

= 1.3.6 =
* Added standalone uninstall.php for standard WordPress-compliant cleanup, alongside the existing Freemius-driven cleanup hook.
* The plugin now fully stops initializing (menu, AJAX handlers, admin_init tasks) if WooCommerce is deactivated while DecalDesk remains active, instead of only showing a notice.
* Added a deactivation hook that unschedules any pending background jobs.
* Added a capability check to the History screen's bulk-action handler, on top of the existing nonce check.
* The Action Scheduler fallback path (used only if Action Scheduler is unavailable) now queues via WP-Cron instead of processing AI/mockup generation synchronously inside the request.

= 1.3.5 =
* Multi-template mockups and WebP/JPEG output: removed the remaining hardcoded restriction in the free build's mockup generator and its surrounding admin UI, since the underlying capability isn't present in this build at all.
* Removed leftover license-check code paths and settings storage (variant configuration UI/AJAX, freeform zone save, AI test-connection, AI/variant/mockup-format settings fields) that had no effect in the free build but referenced or stored data for functionality it doesn't contain.
* Removed non-standard development files (patches, example env, build tooling) from the distributed package; the build script (tools/build-free-zip.js) now excludes them for future builds.
* Added missing translators comments for two translatable strings with placeholders.
* Escaped admin notice output at the point of echo (defense in depth, matching WordPress escape-late guidance).
* Switched file deletion calls to wp_delete_file() per WordPress.org coding standards.

= 1.3.4 =
* WordPress.org review feedback: multi-template mockups, variable products, and WebP/JPEG compression are now fully separate code in the free build too (not just license-locked).
* Improved input sanitization (uploaded filenames, category/zone settings).
* Removed unnecessary core file includes and the manual translation loader (WordPress.org auto-loads translations).

= 1.3.3 =
* WordPress.org-compliant free build: AI descriptions and the freeform zone editor are now fully separate code, not just license-locked, in the free package.
* Fix: "Test AI connection" and the AI settings panel now correctly require a Pro license.

= 1.3.2 =
* AI descriptions, freeform zone editor, multiple mockup templates, variable products, and WebP/JPEG mockup optimization are now Pro features, gated behind a valid Freemius license.

= 1.3.1 =
* Fix: freeform (polygon) zone editor points now align exactly with the clicked position on non-square templates.
* Freemius licensing integration.

= 1.3.0 =
* Initial GitHub-tracked release.
