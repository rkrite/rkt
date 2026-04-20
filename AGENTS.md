# Project AGENTS.md - Data Converter (RKT)

This document provides essential context and instructions for AI agents working on the **Data Converter** project (internal name: `rkt`).

## 1. Project Overview
The Data Converter is a Laravel-based utility designed to transform raw data logs from various sources (currently VBox `.vbo` and DJI Flight Log `.json`) into standardized formats like GPX.

- **Primary Goal**: Facilitate easy data conversion for standardization and common tool usage.
- **Local URL**: [http://rkt.tst/](http://rkt.tst/)
- **Live URL**: [https://vbox.discooctopus.com/](https://vbox.discooctopus.com/)

## 2. Tech Stack
- **Backend**: Laravel 12.2.0 (PHP 8.2+)
- **Frontend**: 
    - **CSS**: Tailwind CSS v4.
    - **Implementation**: Currently uses a local script `/css/rkt-tw-css.js` and in-page `@theme` configuration in `layouts/head.blade.php`.
- **Package Managers**: Composer (PHP), NPM (JS).
- **Deployment**: Custom FTP sync script (`sync.sh`).

## 3. Core Architecture & Logic
- **Routing**: Minimal routes in `routes/web.php`.
- **Controller**: `DataController` handles the request flow.
- **Business Logic**: **CRITICAL**: The actual conversion logic is centralized in `app/Helpers/helpers.php`.
    - This file is autoloaded via `composer.json`.
    - Functions here handle file parsing, coordinate transformation, and GPX generation.
- **Helpers**:
    - `GConvertVBoxDataFile`: Logic for `.vbo` files.
    - `GConvertDJILogDataFile`: Logic for DJI `.json` files.
    - `GCreateGPXRecord`: Shared XML/GPX structure generator.

## 4. Coding Conventions
This project follows specific naming conventions that MUST be maintained:

- **Function Naming**: Global helper functions in `helpers.php` are prefixed with `G` (e.g., `GConvertVBoxDataFile`).
- **Variable Naming**: Local variables within functions are prefixed with `v` (e.g., `$vDataArray`, `$vRowNum`, `$vLat`).
- **Layouts**: Uses Blade `@include` for `layouts/head.blade.php`, `layouts/footer.blade.php`, and `layouts/foot.blade.php`.

## 5. UI & Design System
- **Theme**: Defined in `resources/views/layouts/head.blade.php`.
- **Custom Colors**:
    - `--color-blueish`: `#257`
    - `--color-redish`: `#725`
    - `--color-greyish`: `#888`
    - `--color-lightgrey`: `#ccc`
- **Typography**: Uses 'Instrument Sans'.

## 6. Key Directories & Files
- `app/Helpers/helpers.php`: The "heart" of the application logic.
- `app/Http/Controllers/DataController.php`: Request handling.
- `resources/views/data/index.blade.php`: The main application interface.
- `sync.sh`: Deployment script using `lftp` to sync uncommitted files to the remote host.
- `storage/app/private/datafiles`: Used for temporary processing of DJI logs.

## 7. Deployment Workflow
Changes are synced to the production server via `sync.sh`. This script:
1. Identifies uncommitted or modified files via `git ls-files`.
2. Uses `lftp` to upload them to the configured host.
3. Overwrites existing files on the remote server.

## 8. Planned Updates
- Support for raw DJI `.txt` files.
- KML output format.
- API Key management for DJI log decryption.
