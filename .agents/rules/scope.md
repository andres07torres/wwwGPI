# Project Scope & Analysis Boundaries

To optimize performance and context usage, the agent must restrict its analysis and search scope as follows:

## 1. Excluded Directories (Do Not Scan or Index)
Do not perform broad searches, recursive directory listings, or deep analysis on the following folders:
- `vendor/`: Third-party PHP packages and dependencies.
- `node_modules/`: Frontend JavaScript/npm packages.
- `var/`: Temporary cache, logs (`var/log/`), and generated build files.
- `public/build/` and `public/bundles/`: Compiled frontend assets.
- `.phpunit.cache/`: Test cache files.
- Version control (`.git/`) and hidden tool directories.

Unless the user explicitly asks about a specific vendor class or internal package file, never index or scan these folders.

## 2. Active Scope (Primary Focus)
Direct all code searches, architectural analysis, and changes to:
- `src/`: Core PHP application logic (Controllers, Entities, Repositories, Services, Commands, etc.).
- `config/`: Symfony configuration, packages, and routes.
- `templates/`: Twig views and templates.
- `assets/`: Source stylesheets, JavaScript/TypeScript, and controllers (AssetMapper / Vite).
- `tests/`: Automated test suites.
- `translations/`: Translation files.

## 3. Targeted Lookups
- When inspecting vendor packages or libraries, target only the specific class or documentation needed instead of scanning the entire `vendor/` directory.
