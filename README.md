# V2Board Panel

This repository contains a Laravel panel in `backend/` and the Vue administration interface in `admin/`.

The backend is derived from [v2board/v2board](https://github.com/v2board/v2board) and retains its MIT license and copyright notice in `backend/LICENSE`. The administration interface is derived from [Shannon-x/XBoard-admin](https://github.com/Shannon-x/XBoard-admin); its upstream repository does not currently declare a license, so the backend's MIT license should not be assumed to cover that interface.

## Administration Interface

Install dependencies and run `npm run build` in `admin/`. The resulting `dist/` directory corresponds to `backend/public/assets/admin/`. Synchronize both the assets and manifest when deploying an updated build.

## Configuration

Set the panel URL in the application settings before generating subscriptions. The bundled Clash templates resolve their rule-provider URLs against that setting. The example deployment configuration must be adapted to the installation; do not commit real domains, credentials, databases, or backups.

This source uses renamed client identifiers and is not compatible with clients that expect the previous identifiers. Review the application configuration, required services, and dependencies before deployment.
