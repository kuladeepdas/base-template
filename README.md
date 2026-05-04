# Base Template

Base Template is a Craft CMS plugin for frontend-focused project tooling and field extensions.

## Requirements

- PHP `^8.2`
- Craft CMS `^5.0`

## Installation

Install via Composer:

```bash
composer require kdgraphics/base-template:^1.0
```

Then install in Craft:

```bash
php craft plugin/install base-template
```

## What It Provides

- Frontend project update/build helpers in plugin settings
- Content model snapshot tools (built-in template + import/export)
- Asset Locations integration
- Embedded Videos integration
- Advanced Color field functionality
- Frontend helper macros/functions shipped by the plugin

## Usage

### Build Tools (Plugin Settings)

- Open `Settings -> Base Template`.
- Use:
  - `Update Packages` to run package updates and install flow.
  - `Update Build Script` to compare/apply plugin frontend scaffold updates.
  - `Git Status` to check repo status and commit/push from CP tools.

### Content Models (Snapshots)

- Open `Settings -> Base Template -> Content Models`.
- `Default Template`: applies the built-in template snapshot.
- `Export`: downloads current site template snapshot.
- `Import`: uploads and applies a snapshot file.

Use this when bootstrapping new environments with matching fields/entry types/sections/filesystems and editor config.

### Asset Locations

- Enable/disable in `Settings -> Base Template -> Asset Locations`.
- In asset edit pages, the field lists where the asset is used:
  - Entry title
  - Status
  - Frontend link
  - Block/location tags (if nested inside blocks)

### Embedded Videos

- In Asset fields with allowed kind set to include embedded video, `Embed Video` buttons are available.
- Paste a YouTube/Vimeo URL in the HUD and save.
- The created embedded asset is attached to the field relation.

Twig usage example:

```twig
{% set video = entry.videoField.one() %}
{% if video %}
  {% set embedded = craft.embeddedVideos.get(video) %}
  {% if embedded %}
    {{ embedded.code|raw }}
  {% endif %}
{% endif %}
```

### Advanced Color Field

- Add field type `Advanced Color` in field settings.
- Configure optional custom swatches in field settings.
- In entries, use the color circle to open the picker (HEX/RGB/RGBA/HSL/HSLA + alpha).

Twig usage example:

```twig
{% set c = entry.advancedColor %}
{% if c %}
  <div style="color: {{ c.hex }};">HEX</div>
  <div style="color: {{ c.rgb }};">RGB</div>
  <div style="color: {{ c.rgba }};">RGBA</div>
  <div style="color: {{ c.hsl }};">HSL</div>
  <div style="color: {{ c.hsla }};">HSLA</div>
{% endif %}
```

### Frontend Image Helpers

The plugin exposes global helper functions for image macros without manual imports.

Use directly in templates:

```twig
{{ singleImg(entry.image.one()) }}
{{ responsiveImg(entry.image.one(), {
  class: 'img-fluid',
  loading: 'lazy'
}) }}
```

## Asset Build

From plugin root:

```bash
cd plugins/base-template
npm install
npm run build
```

Available scripts:

- `npm run build`
- `npm run watch`
- `npm run dev`
- `npm run prod`

## Project Structure

- `src/` PHP plugin source
- `src/resources/src/` JS/SCSS source files
- `src/resources/dist/` built assets
- `src/templates/` CP and Twig templates

## Notes

- In production environments, run built assets only.
- If control panel updates are not reflected, clear Craft caches.

## License

Craft License
