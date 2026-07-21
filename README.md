# DecalDesk

WordPress/WooCommerce plugin for automatically creating products from design files (PNG, JPG/JPEG, WEBP, GIF).

**Support:** [support@decaldesk.com](mailto:support@decaldesk.com) · **Website:** [decaldesk.com](https://decaldesk.com)

## Installation

1. Upload the `decaldesk/` folder to `wp-content/plugins/`.
2. Activate the plugin from WP Admin → Plugins.
3. Go to **DecalDesk → Settings** and check/set the price per m², minimum price, and categories (the slug must match the "category" part of the filename).
4. (Optional) Enable **AI-generated descriptions** and enter your Anthropic API key — see the section below.
5. Go to **DecalDesk → Categories** — add categories and upload a mockup template for each one (directly through the browser, no FTP needed). Also set the design's position on the template by dragging the frame.

## AI descriptions (free Gemini or paid Claude)

From **DecalDesk → Settings**, choose one of three modes:
- **Off** — static template (no AI)
- **Free (Google Gemini)** — with a daily limit (10 products/day by default, configurable). Get a free API key from https://aistudio.google.com/app/apikey — no card required.
- **Paid (Anthropic Claude)** — no plugin-enforced limit; the real limit is whatever your Anthropic plan allows.

Both AI modes generate: a long description, a short description, and an SEO meta description (saved to both Yoast SEO and RankMath). The meta description is also embedded directly into the mockup's PNG file (Title/Description/Comment metadata via Imagick).

### AI Vision (analyzing the design image itself)

From the same page you can enable **"Look at the design itself"** — the AI then actually looks at the PNG file (not just its filename) and writes the description based on what it sees (motif, colors, style), not just size/material/category. Works with both providers. The image is automatically downscaled to 1024px before sending, to save bandwidth and tokens.

The daily counter for the free mode is stored in a database option (`decaldesk_ai_daily_usage`) and resets automatically at midnight in the site's timezone. If the daily limit is reached, subsequent products automatically fall back to the static template — no errors, no interruption to the workflow.

**About API keys:** the safest option is to define them in `wp-config.php` instead of storing them in the database:
```php
define( 'DECALDESK_GEMINI_API_KEY', 'AIza...' );
define( 'DECALDESK_AI_API_KEY', 'sk-ant-...' );
```

If AI is disabled or the request fails (network issue, quota exhausted, etc.), the plugin automatically falls back to the static template — an upload never fails because of AI.

Settings also has a **"Test AI provider connection"** button — it calls the API directly and shows the exact response or the exact error, without waiting for a real file upload.

## Latin product slug

The product title stays exactly as given in the filename (often in Cyrillic), but the **slug is always generated in Latin script** via transliteration using the standard Bulgarian system (e.g. "Коледа" → `koleda-50x70-kitchen`). No extra transliteration plugin needed.

## Filename format

```
name_widthxheight_material_category.extension
```

Example: `koleda_50x70_matte_kitchen.jpg` (or `.png`, `.webp`, `.gif`)

- `name` — the design's name (used as the product title)
- `width x height` — dimensions in centimeters
- `material` — material (matte, gloss, transparent...)
- `category` — category, must match a slug configured in settings
- **The extension doesn't matter for parsing** — PNG, JPG/JPEG, WEBP, and GIF are all supported

## How it works

1. Upload a file (PNG/JPG/WEBP/GIF) via drag & drop in **DecalDesk → Upload**.
2. The plugin parses the filename (`includes/parser.php`).
3. Calculates the price based on area (`includes/pricing.php`).
4. Generates descriptions via AI or the fallback template (`includes/ai-content.php`).
5. Generates the mockup (format configurable — WebP/JPEG/PNG, see settings below) via Imagick/GD and embeds the meta description into it (`includes/mockup.php`).
6. Creates a WooCommerce product — draft or published, with a Latin-script slug and SEO meta description (`includes/product.php`).

## Structure

```
decaldesk/
├── decaldesk.php
├── uninstall.php
├── admin/
│   ├── admin-menu.php
│   ├── admin-page-upload.php
│   ├── admin-page-history.php     (WP_List_Table with all processed designs)
│   └── admin-page-categories.php  (categories + mockup templates + zone editor)
├── includes/
│   ├── parser.php
│   ├── pricing.php
│   ├── ai-content.php   (AI descriptions + Cyrillic transliteration)
│   ├── mockup.php        (zone-based design placement on the template)
│   ├── product.php
│   ├── database.php      (DB table for background jobs + query helpers)
│   ├── background.php    (Action Scheduler queue)
│   ├── notices.php       (admin notices for AI fallback/errors)
│   └── settings.php
└── assets/
    ├── js/uploader.js
    ├── js/categories.js  (drag-box editor for positioning)
    ├── css/style.css
    └── templates/ (built-in default mockup backgrounds; custom templates are uploaded through the UI into uploads/decaldesk/templates/)
```

## Categories and mockup templates

From **DecalDesk → Categories**:
- Add a category (name + slug — the slug must match the "category" part of the filename)
- Each category can have **up to 4 different templates** ("Add another template") — useful, for example, for a "cars" category where you want the design shown on several different models for a more convincing presentation
- Upload a mockup template for each slot directly through the browser (PNG/JPG/WEBP)
- Set the design's position for each template individually — choose between **"Rectangle"** (drag a frame, contain-fit — the whole design fits inside) or **"Freeform"** (a pen-tool-like editor, cover-fit — fills the entire outlined shape, with a realistic "bleed" at the edges, just like actual cutting/application)
- In "Freeform" mode: click on the template to add points, drag them to move them — outline any shape you like (useful for irregular surfaces, e.g. a car door at an angle)
- Optionally upload a temporary test design (browser-only, never saved to the server) to preview the result more precisely before saving

When uploading a design, the **"Generate mockups from all templates in the category"** checkbox (off by default, since it's slower) determines whether the product gets just 1 mockup (from the first template), or one mockup per configured template — the first becomes the featured image, the rest go into the gallery alongside the original design.

The setting is **per category, not per upload** — configure it once, and all future uploads to that category automatically use the configured templates/positions. If a category has no custom zone set, a sensible default is used (a centered rectangular zone, 70% of the template's width and height).

## Size variants (Variable Products)

Instead of renaming/duplicating the same design for every size, you can configure a list of standard sizes (+ optional material/color) once, directly in **DecalDesk → Upload**, in a collapsible section under the "Create with selectable variants" checkbox ("Configure sizes / materials / colors"):

- **Sizes** (required for the feature to work) — one per line, format `widthxheight` in cm (e.g. `50x70`)
- **Materials** (optional) — comma-separated, e.g. `matte, gloss, transparent`
- **Colors** (optional) — comma-separated, e.g. `white, black, clear film`

When uploading, check **"Create with selectable variants"** instead of the draft/publish status applying to a single product per size. The plugin creates **one WooCommerce Variable Product** with a dropdown for size (and material/color, if configured) — the customer picks the combination directly on the product page. Each variation gets:
- Its own automatically calculated price using the €/m² formula for that specific size
- A unique SKU (transliterated to Latin script, regardless of Cyrillic in material/color) — e.g. `koleda-50x70-matte-white`

If no size is configured, the checkbox is disabled and the plugin falls back to a regular Simple Product (one file = one product), exactly as before.

## Mockup image optimization

From **DecalDesk → Settings → Mockup image optimization**:
- **Format**: WebP (recommended, ~75% smaller than PNG at comparable quality), JPEG (widest compatibility), or PNG (lossless, but heaviest)
- **Compression quality** (1-100, applies only to WebP/JPEG) — 80-85 is a sensible balance

If the server has no WebP support (neither Imagick nor GD with `imagewebp`), the plugin automatically falls back to PNG instead of failing.

## Interface language (i18n)

As of version 1.2.0, the plugin's source code is in **English** (standard WordPress convention — source is always the "universal" language, and every other language comes in as a translation). A ready-made **Bulgarian translation** is included:

```
languages/
├── decaldesk.pot        (template for new translations)
├── decaldesk-bg_BG.po   (human-readable Bulgarian translation)
└── decaldesk-bg_BG.mo   (compiled, actually loaded by WordPress)
```

If the WordPress site's `Language` is set to Bulgarian in Settings → General, DecalDesk's interface automatically shows in Bulgarian — nothing to configure. In any other language (including English by default), it shows in English.

**To add a translation for another language:** open `languages/decaldesk.pot` with Poedit (free) or a similar tool, translate all the strings, save as `decaldesk-{locale}.po` (e.g. `decaldesk-de_DE.po` for German), compile the `.mo` file, and upload it to the `languages/` folder.

Note: AI-generated product descriptions (the ones store customers see) are controlled separately from the admin interface language, via **DecalDesk → Settings → AI content language** — they follow the language your store's customers read, not the admin's locale. This defaults to English and can be changed to Bulgarian or any other language.
