# Agent Guidance

## Repo Structure
- `scanapp.php` - Single-file HTML5 ticket scanner (main application)
- `includes/application_top.php` - osConcert bootstrap (required dependency)
- `QWEN.md` - Development notes (German)
- `README.md` - User documentation

## Key Integration Points
- **Barcode format:** `{orders_id}_{products_id}_{quantity}`
- **Database tables:** `orders_barcode`, `orders_products`, `orders`
- **Required osConcert includes:** `includes/application_top.php` (defines `DIR_WS_HTTP_CATALOG`, `tep_db_*`, etc.)
- **Optional constants:** `PLUS_TIME` / `MINUS_TIME` (ticket validity window, defaults: +2h / -3h)

## Important Quirks
- **Camera requires HTTPS** (except localhost)
- **Duplicate scan prevention:** 10-second window per location
- **Debug mode:** Set `$debug = true;` at line 42 of scanapp.php
- **Location cookie:** `scanner_location`, 1-year expiration, default "dev1"
- **Date parsing:** Uses `products_model` field with `/` replaced by `.`
- **Scan saves only if:** `scanned=0` AND `scanned_date=0` (first scan)

## Tech Stack
- PHP 7.4+, MySQL 5.7+
- ZXing library via CDN (unpkg primary, jsdelivr fallback)
- Web Audio API (no external sound files)
- Mobile-first, single-file integration

## Testing
- No test suite in this repo
- Manual testing requires HTTPS and camera access
- Test with: `scanapp.php?barcode=123_456_1&location=door1`