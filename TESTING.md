# Validation

Validated on 5 November 2026 using PHP 8.3.6 and MariaDB 10.11.14 with a fresh disposable database.

- All nine PHP files passed PHP syntax checks.
- assets/app.js passed Node's JavaScript syntax check.
- The supplied integration harness passed 113 assertions, including successful HTTP responses as well as behavior checks.
- Exercised initial administrator setup and installer lockout, citizen registration, officer creation, login, protected routes, role injection rejection, CSRF rejection, coordinate validation, fake-image rejection, JPEG processing, authenticated photo delivery, duplicate distance/category filters, support, escaped comments, assignment permissions, status transitions, mandatory resolution evidence, reopening ownership, notifications, duplicate linking and cycle prevention, dashboards, CSV export, profile changes, category creation and account deactivation.
- Application logs contained no PHP warnings, fatal errors, deprecated-function notices or SQL errors during those tests. Expected rejected actions were logged by the application's error handler.

Not verified in a real browser: visual layout at mobile/desktop widths, Leaflet/CDN/tile availability, browser geolocation permissions, drag/drop map interaction and image preview. Responsive CSS and manual-coordinate/report-list fallbacks are included. XAMPP/Windows and PHP 7.4 were not available for direct testing; no PHP syntax requiring a version newer than 7.4 is used.

The integration harness requires Python with requests and Pillow and is only for a fresh disposable local installation. Run after importing schema.sql, before creating any user:

    python tests/integration.py http://127.0.0.1:8765

Tests intentionally create and modify data. Never point them at a live user database.

## Map policy correction (v2)

PHP syntax and JavaScript syntax were checked again. A targeted JavaScript test confirms that the shared map initializer uses the canonical OSM tile URL and an explicit strict-origin-when-cross-origin image referrer policy. The response header and script cache version were also checked. Live tile rendering in the user's browser remains unverified.

## Location search (v3)

23 focused JavaScript checks passed with a mocked DOM/Leaflet/Photon response. These cover explicit search (no typing requests), minimum query length, Sri Lanka bounds, worldwide scope, coordinate order, marker movement, address fill/preservation, result selection, caching, no-results state, provider errors, invalid coordinates, escaped remote names, Enter key behavior and a distinct community search marker. PHP and JavaScript syntax checks passed, and the full 113-assertion HTTP/database integration suite passed again. Provider availability and visual behavior in the user's browser still require browser verification.
