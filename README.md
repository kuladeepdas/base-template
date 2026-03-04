# Inspire Frontend

Frontend tooling plugin for Craft CMS projects.

It provides:

- A **Settings -> Frontend** admin page under the **System** group.
- Utility actions for frontend package/build maintenance.
- Build-script sync from plugin frontend source into project `frontend/`.
- Git status + commit/push helper actions.
- Frontend Twig helpers for image rendering without manual imports.
- A `craft.prefetch` variable for link preload/prefetch helpers.

## Package

From [`composer.json`](./composer.json):

- Package: `inspire/frontend`
- Handle: `inspire-frontend`
- Plugin class: `Inspire\\Frontend\\Frontend`

## Requirements

- Craft CMS `^5.0`
- PHP compatible with your Craft install
- `putyourlightson/craft-blitz-hints`

## Installation (Local Plugin)

Example project `composer.json` path repository setup:

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

Then run:

```bash
composer update inspire/frontend
php craft plugin/install inspire-frontend
```

## CP Settings Page

Path:

- `Settings -> Frontend`

Tabs:

1. `Rebuild Assets`
2. `Update Build Script`
3. `Git Status`

### Rebuild Assets

Actions:

- **Check for Updates**
  - Runs `npm outdated` in project root `frontend/`
  - In production env: runs with `--omit=dev`
- **Install Updates**
  - Runs `npm update` (production: `--omit=dev`)
- **Reinstall**
  - Deletes `frontend/package-lock.json`
  - Deletes `frontend/node_modules/`
  - Runs fresh `npm install` (production: `--omit=dev`)
- **Rebuild**
  - Runs `npm run dev` (non-production)
  - Runs `npm run prod` (production)

### Update Build Script

Compares plugin frontend source:

- `plugins/inspire-frontend/src/frontend/`

against project frontend:

- `<projectRoot>/frontend/`

Then applies differences.

#### `package.json` merge behavior

- Non-dependency fields are synced from plugin source.
- `dependencies` are merged from source into target (existing extra target deps are kept).
- `remove` section in source is treated as control metadata:
  - It is **not copied** to target `package.json`.
  - During check, if any `remove` package exists in target dependencies, update is flagged.
  - During apply, those packages are removed from target dependencies.

### Git Status

Operates in project root:

- **Check Status**: `git status --short`
- **Commit and Push**:
  - `git add -A`
  - `git commit -m "Updates from Local Admin"` (env `local`)
  - otherwise: `git commit -m "Updates from Admin[skip ci]"`
  - `git push`

## Twig Helpers (Images)

You can use image helpers in frontend templates **without importing** `_helpers/images`.

Available styles:

- `{{ singleImg(image, size, options, classes) }}`
- `{{ responsiveImg(image, styles, options, sizes, classes) }}`
- `{{ images.singleImg(...) }}`
- `{{ images.responsiveImg(...) }}`

Resolution order for helper macros:

1. Site template `_helpers/images` / `_helpers/images.twig`
2. Plugin fallback `plugins/inspire-frontend/src/templates/_helpers/images.twig`

## Template Roots

Site template roots include plugin templates, so fallback templates are available if not present in project templates.

## Prefetch Variable

The plugin registers `craft.prefetch` for site requests.

Examples:

```twig
{% do craft.prefetch.preconnect('https://fonts.gstatic.com') %}
{% do craft.prefetch.preload('/dist/app.css', { as: 'style' }) %}
```

Service methods are in:

- [`src/services/PrefetchService.php`](./src/services/PrefetchService.php)

## Frontend Build Source

Plugin frontend source lives in:

- [`src/frontend/`](./src/frontend/)

This is the source used by **Update Build Script** checks/applies.

## Notes

- Utility actions are CP/admin protected.
- Commands are executed server-side; check logs in the UI panels.
- Environment-sensitive behavior is based on `Craft::$app->config->env`.
