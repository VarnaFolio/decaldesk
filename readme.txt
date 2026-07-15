=== DecalDesk ===
Contributors: decaldesk
Tags: woocommerce, decals, stickers, product automation, ai
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn a folder of decal/sticker design files into finished WooCommerce products — filename parsing, area-based pricing, mockup generation, no manual work.

== Description ==

DecalDesk automates turning a folder of design files (PNG, JPG/JPEG, WEBP, GIF) into fully configured WooCommerce products. Built for shops that sell physical products priced by area — vinyl stickers, car wraps, wall decals, kitchen backsplashes, canvas prints, window film, signs.

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
