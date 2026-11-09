# CivicCare — Community Issue Reporting System

A complete PHP/MySQL application for reporting and managing local road damage, garbage, streetlight, drainage and water issues. Built for XAMPP; no Composer, Node build or API key is required.

## Install with XAMPP

1. Start **Apache** and **MySQL** in XAMPP.
2. Copy the `community_issue_system` folder to `C:\xampp\htdocs\`.
3. Open `http://localhost/phpmyadmin`, create a database named `community_issues` using utf8mb4, select it, and import `database/schema.sql`.
4. Edit `config.php` if your MySQL host, port, username or password differs from XAMPP defaults (`127.0.0.1`, `3306`, `root`, empty password).
5. Ensure GD and PDO MySQL are enabled in `C:\xampp\php\php.ini`. Uncomment `extension=gd` and `extension=pdo_mysql` if needed; restart Apache. Set `upload_max_filesize=6M` and `post_max_size=8M`. The application accepts photos up to 5 MB.
6. Open `http://localhost/community_issue_system/setup.php` **on the XAMPP computer** and create your administrator. There are no default passwords. Setup only works from localhost and locks when any account exists. Delete `setup.php` after installation.
7. Sign in. Under **People & categories**, create an officer account. Citizens use **Create an account** on the sign-in page.
8. Reports, maps and photos are available after sign-in.

If PHP says a database table is missing, import schema.sql into the database named in config.php. If images fail to upload, check GD, PHP upload limits, and write permission on storage/uploads. Files must be real JPG/PNG/WebP images, under 5 MB and 24 megapixels. Upload processing removes image metadata and re-encodes the photo as JPEG; preview orientation may differ for camera images with EXIF rotation. A failed submission requires selecting the photo again.

PHP 7.4+ code compatibility; PHP 8.2+ is recommended for a new installation. PHP GD must support the selected image format. Use JPG/PNG if the installed GD does not support WebP. Database: MySQL 5.7+ or MariaDB 10.4+. The supplied code uses no framework dependencies.

## Features

- Citizen registration, login, account profile/password updates and role-based access.
- Admin-created officer/admin accounts; enable/disable citizen and officer access.
- Photo reports with description, category, landmark/address and map coordinates.
- Leaflet/OpenStreetMap map picker, draggable pins, manual coordinates, and optional browser geolocation.
- Similar open reports in the same category within **300 metres**, sorted by distance. This is a location/category heuristic, not image recognition or automatic duplicate classification. Citizens can support existing reports.
- Admin duplicate linking with support copying and retained report histories. One original may have multiple duplicates. A report already used as an original cannot itself be merged, preventing chains and cycles. Linking is irreversible in this version; verify the original first.
- Status workflow, officer assignment, priorities, update notes, mandatory resolution photo and reporter/admin reopening.
- Community grid, searchable/filterable paginated reports and map view (500 markers per page).
- Role-specific dashboard, category counts, overdue attention indicator and average resolution time.
- In-app notifications for reporters, supporters and assigned officers; no email/SMS service is required or included.
- Issue comments and full report event history, admin audit log (latest 200 entries displayed), admin CSV export and category creation.
- Responsive desktop/mobile layout; manual coordinate entry and report lists remain available if map assets are blocked.
- Prepared database queries, escaped output, CSRF protection, hashed passwords, regenerated sessions, basic login throttling and MIME/image checks with re-encoding. Apache rules prevent direct access to internal files and uploads.

## Workflow

| Current status | Admin can move to | Assigned officer can move to |
| --- | --- | --- |
| Submitted | Verified, Rejected | — |
| Verified | Assigned, Rejected | — |
| Assigned | In Progress, Rejected | In Progress |
| In Progress | Resolved, Rejected | Resolved |
| Rejected | Verified | — |
| Resolved | Reopen to Verified through the separate action | — |

An admin may keep the same status while changing assignment or priority and adding a note. An assigned officer may keep the same status to post a progress note. Assigned/In Progress/Resolved statuses require an active officer when assigning. Resolving requires a new resolution photo. Reopening preserves the latest resolution image in history/context and clears the resolved timestamp. A reporter can reopen their resolved report. Duplicate reports direct updates to the original.

All authenticated users can view community reports, names in comments, photos and locations. Do not upload confidential information. Emails/user administration and audit/export screens are admin-only. Officers can modify only reports currently assigned to them; citizens cannot choose role, priority, assignment or status on submission. Users with Assigned/In Progress work must have those reports reassigned before deactivation. Administrator accounts cannot be disabled through this UI.

## Suggested demonstration

1. Set up an admin and create two officers.
2. Register a citizen, upload a photo and pin an issue.
3. Register a second citizen and select the same category and nearby location. Open the suggestion and support it.
4. Admin verifies the report, chooses an officer and saves as Assigned.
5. Officer signs in, selects Assigned to me, changes to In Progress, then uploads a new photo and marks Resolved.
6. Citizen sees the notification and resolution evidence; reopen if needed.
7. Create another nearby report and use the admin duplicate action with the original report ID.
8. Inspect the dashboard, map, event history, audit log and CSV export.

## Structure

- `index.php`: front controller, authentication gates and export/API routing.
- `app/bootstrap.php`: database, sessions, validation, uploads and shared helpers.
- `app/actions.php`: server-side mutations and permissions.
- `app/pages.php`, `app/public.php`, `app/layout.php`: screens/layout.
- `assets/style.css`, `assets/app.js`: responsive UI, maps and duplicate suggestions.
- `media.php`: authenticated JPEG response; uploads are not linked directly.
- `setup.php`: one-time local administrator setup.
- `config.php`: database and timezone configuration.
- `database/schema.sql`: tables, indexes, relationships and starter categories.
- `storage/uploads`: processed report photos; include this folder in backups.
- `tests/integration.py`: end-to-end HTTP/database test harness, intended only for a disposable local test database.

## Maps, time and hosting

Map JavaScript/CSS loads from unpkg.com and tiles from tile.openstreetmap.org. An internet connection is required for maps. Map tile attribution is retained. Location-name search uses Photon (see the v3 notes below). This project does not include routing or offline map tiles. Geolocation requires HTTPS except localhost and needs user permission. Manual latitude/longitude always works. Default map view is Sri Lanka; any valid global coordinates may be reported. Dates and database sessions use Asia/Colombo (+05:30).

XAMPP is the supported local target. For public hosting, configure HTTPS, a dedicated limited database user, backups, PHP error logging with display_errors disabled, and Apache 2.4 AllowOverride for the supplied rules. Other servers require equivalent access-denial rules for app/, database/, tests/, storage/, and config.php. PHP's built-in development server does not enforce .htaccess and should only bind localhost for testing. A reverse proxy can affect the localhost setup check; install locally and remove setup.php before exposing the application. No map availability or government service response deadline is guaranteed. The seven-day overdue threshold is an internal attention indicator.

Back up the MySQL database and storage/uploads together. Restore both to retain image references. This version intentionally has no issue/account delete action, password reset email, live chat, automated authority integration or push notification service.

## License

MIT. See LICENSE. Leaflet and OpenStreetMap are external services/libraries with their own licenses and usage policies.

## Map access-blocked fix (v2)

The initial version set `Referrer-Policy: same-origin`, which suppressed the referrer on cross-origin tile requests. The updated version uses `strict-origin-when-cross-origin` in both the PHP response and the Leaflet tile layer, and uses OSM's documented canonical HTTPS tile endpoint. A JavaScript version query ensures the corrected script is fetched.

For an existing installation, replace **only** `app/bootstrap.php`, `assets/app.js`, and `app/layout.php` from this ZIP. Keep your existing config.php, database and storage/uploads. Reload the map page. No SQL import or setup run is needed. If blocked tiles remain cached, clear cached images/files for the site and reload. Browser privacy extensions or antivirus settings that remove referrers can still cause the provider to reject tiles.

## Location search (v3)

The report form and Community map now have a location-name search box. Enter a town, road, or landmark (for example Maharagama or Galle Road), choose Sri Lanka or Worldwide, press Search (or Enter), and select a result. Report selection sets latitude/longitude, places the draggable issue pin and fills the address only when it is empty or still contains a previous search-generated address. Check the exact location and drag the pin as needed. Community map selection pans the map and adds a translucent search marker explicitly labelled as a searched place rather than an issue. Report filters remain unchanged.

Search uses the Photon public demo endpoint at https://photon.komoot.io/api/ with OpenStreetMap data, five results per query, a 12-second timeout, explicit submission, a per-tab 1.2-second request interval and a 40-query in-memory cache. No lookup is made while typing. Queries are sent directly from the browser to the provider; avoid entering private information. No API key is required by this included demo integration. Service availability and local address coverage are not guaranteed. Errors and empty results have messages, and manual map/coordinate selection remains available. For a high-traffic deployment, use an appropriate hosted provider or self-hosted Photon endpoint. Optionally add `geocoding_url` to config.php for another Photon-compatible HTTPS endpoint with browser CORS enabled. The public Nominatim endpoint is not used.

**Existing installation update:** copy the contents of this ZIP's `app` and `assets` folders over the corresponding installed folders. This includes the earlier map referrer fix. Keep config.php, your database, storage/uploads and setup state. Do not run setup.php or reimport SQL. Script and stylesheet version parameters are updated to v3.

Run the focused search behavior tests with `node tests/location_search.test.cjs`. No Node dependencies are needed for this test. Node is not required to run the PHP application.
