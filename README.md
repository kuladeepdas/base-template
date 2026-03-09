# Inspire Frontend

Frontend operations and frontend feature plugin for Inspire Craft CMS projects.

## Package

- Name: `inspire/frontend`
- Handle: `inspire-frontend`
- Class: `Inspire\\Frontend\\Frontend`
- Version: `1.0.0`

## Requirements

- PHP `^8.2`
- Craft CMS `^5.0`
- `craftcms/ckeditor` `^5.0`
- `inspire/auth` `^1.0`
- `inspire/base` `^1.0`
- `inspire/forms` `^1.0`
- `inspire/seo` `^5.0`
- `putyourlightson/craft-blitz-hints`

## Settings Location

- `Settings -> Frontend`

## Settings Tabs

- Rebuild Assets
- Update Build Script
- Git Status
- Inspire Fields
- Asset Locations
- Expanded Singles
- Embedded Videos
- Image Resizer
- Bulk Image Resizer
- Image Resizer Logs

## Main Features

- Frontend package/build maintenance actions from CP
- Build script sync to project `frontend/`
- Git status + commit/push utility actions
- Inspire Fields snapshot export/import/apply tools
- Asset Locations integration (moved from standalone plugin)
  - Native Asset field layout element: `Asset Locations`
  - Asset index `In Use` table attribute
  - Settings toggle to enable/disable feature (`enabled` by default)
- Prefetch variable registration (`craft.prefetch`)
- Twig image helper support (`singleImg`, `responsiveImg`)
- Embedded Videos integration
- Expanded Singles integration
- Image Resizer integration

## Build (Vite)

Run in `plugins/inspire-frontend`:

```bash
npm install
npm run build
```

### Build Targets

- `embeddedvideos-main`
- `embeddedvideos-preview`
- `inspire-ui`
- `image-resizer`
- `expanded-singles`

Output:

- `src/resources/dist/`

## Local Installation

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "plugins/inspire-frontend"
    }
  ],
  "require": {
    "inspire/frontend": "1.0.0"
  }
}
```

```bash
composer update inspire/frontend
php craft plugin/install inspire-frontend
```
