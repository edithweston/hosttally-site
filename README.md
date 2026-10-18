# HostTally · hosttally.co.uk

Private source for the HostTally website and app. © 2026 Conrad & Claude · HostFactor. All rights reserved.

## How changes go live
1. Claude (or anyone) proposes a change as a **pull request**.
2. Conrad reviews it and presses **Merge**.
3. GitHub builds the site and uploads only the changed files to the hosting by FTP (see `.github/workflows/deploy.yml`).

Nothing reaches the live site without a merge into `main`.

## Folders
- `site/` – the readable website. `site/app/` is the HostTally app.
- `build/build.js` – copies `site/` to `dist/` and minifies the pages.
- `config.php` and the `data/` folder live **only on the server** and are never uploaded or overwritten by a deploy.

## Secrets (GitHub > Settings > Secrets and variables > Actions)
`FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, and optionally `FTP_DIR` (default `public_html/`).
