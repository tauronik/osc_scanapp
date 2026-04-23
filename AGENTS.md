# Agent Guidance

## Repo Structure
- `scanapp.php` - Single-file HTML5 ticket scanner (main application)
- `includes/application_top.php` - osConcert bootstrap (required dependency)
- `QWEN.md` - Development notes (German)
- `README.md` - User documentation

## Security Modes
Configure via `$security` variable at top of scanapp.php:

| Mode | `$security` value | Authentication |
|------|-------------------|---------------|
| None | `'none'` | No authentication (trusted environments) |
| Login | `'login'` | Box office account (email + password, country_id = 999) |
| PIN | Any 5-12 digit number | Static PIN configured in scanapp.php |

## Key Integration Points
- **Barcode format:** `{orders_id}_{products_id}_{quantity}`
- **Database tables:** `orders_barcode`, `orders_products`, `orders`
- **Required osConcert includes:** `includes/application_top.php` (defines `DIR_WS_HTTP_CATALOG`, `tep_db_*`, etc.)
- **Optional constants:** `PLUS_TIME` / `MINUS_TIME` (ticket validity window, defaults: +2h / -3h)

## Authentication
- **Session variable:** `$_SESSION['pin_authenticated']` stores PIN auth flag (PIN mode)
- **Session variable:** `$_SESSION['customer_id']` stores box office user (login mode)
- **Auth endpoint:** `scanapp.php?auth_action=login|pin` returns JSON
- **Logout:** `scanapp.php?logout=1` clears session and redirects

## Important Quirks
- **Camera requires HTTPS** (except localhost)
- **Duplicate scan prevention:** 10-second window per location
- **Debug mode:** Set `$debug = true;` at line 46 of scanapp.php
- **Location cookie:** `scanner_location`, 1-year expiration, default "dev1"
- **Date parsing:** Uses `products_model` field with `/` replaced by `.`
- **Scan saves only if:** `scanned=0` AND `scanned_date=0` (first scan)

## Tech Stack
- PHP 7.4+, MySQL 5.7+
- ZXing library via CDN (unpkg primary, jsdelivr fallback)
- Web Audio API (no external sound files)
- Mobile-first, single-file integration
- AJAX for authentication (XMLHttpRequest)

## Testing
- No test suite in this repo
- Manual testing requires HTTPS and camera access
- Test barcode: `scanapp.php?barcode=123_456_1&location=door1`
- Test PIN: `scanapp.php?auth_action=pin&pin=12345`
- Test login: `scanapp.php?auth_action=login&email=test@test.com&password=xxx`