# Bangued Gas Map

An interactive map of gas stations in Bangued, Abra, with fuel prices and a
public/admin site split: anyone can browse the map, only a signed-in
administrator can edit stations and prices.

This is a Laravel port of the original Node/Express version. The frontend
(HTML, CSS, JS) and the HTTP API are unchanged, so every URL, JSON field name
and login flow behaves exactly as it did before.

## Requirements

- PHP 8.2 or newer (built and tested on 8.5)
- Composer 2
- SQLite (the default; MySQL 8.0.16+ also supported, see `sql/` in the Node
  repo for the equivalent schema)

## Running it

```bash
composer install
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
php artisan bangued:seed      # brands, stations and the admin user
php artisan serve
```

Then open:

- <http://localhost:8000/> — public map
- <http://localhost:8000/about> — what the map is and how accurate it is
- <http://localhost:8000/privacy> — what the site collects (nothing, for a visitor)
- <http://localhost:8000/login> — admin sign-in
- <http://localhost:8000/admin> — dashboard (redirects to /login when signed out)

The seeder prints the admin credentials it created. The defaults come from
`config/bangued.php` and are `admin` / `admin123` — **change the password
before putting this on a public server.**

To load clearly-labelled *fictional* fuel prices so the price UI is populated,
add `--demo-prices`. Prices are otherwise never seeded: inventing them would
present fabricated data as real.

```bash
php artisan bangued:seed --demo-prices
```

### Seeding and resetting

```bash
php artisan bangued:seed                 # idempotent: updates existing rows
php artisan bangued:seed --force         # wipe the tables, re-migrate, reload
php artisan bangued:seed --force --demo-prices
```

`--force` drops all tables including the sessions table, so everyone is signed
out afterwards.

## Tests

```bash
php artisan test
```

The suite runs against an in-memory SQLite database and seeds itself, so it
never touches `database/database.sqlite`. It pins the API contract the
frontend depends on, including the `/api/meta` exception to the `{data: ...}`
envelope and the price-history rules.

## How it fits together

| Path | Role |
| --- | --- |
| `resources/views/about.blade.php` | `/about`, the public "what is this and how good is it" page |
| `resources/views/privacy.blade.php` | `/privacy`, written against what the code actually does |
| `resources/views/layouts/page.blade.php` | shared layout for those two |
| `public/css/page.css` | their stylesheet - standalone, because app.css's shell assumes a full-screen map |
| `app/Http/Controllers/PublicApiController.php` | `/api/meta`, `/api/brands`, `/api/stations` |
| `app/Http/Controllers/AdminApiController.php` | station CRUD, prices, price history, config |
| `app/Http/Controllers/AuthController.php` | login, logout, session probe, password change |
| `app/Http/Middleware/AttachUser.php` | resolves the session user for API requests |
| `app/Http/Middleware/RequireAdmin.php` | 401 when signed out, 403 when not an admin |
| `app/Http/Validate.php` | shared input validation, all errors shaped as `{error:{message}}` |
| `app/Support/Bangued.php` | boundary GeoJSON, map viewport, coordinate limits |
| `config/bangued.php` | map, admin, contact-address and fuel-type configuration |
| `database/seeders/BanguedSeeder.php` | brands, stations, optional demo prices, admin |
| `public/` | the frontend, copied unchanged from the Node version |

### Before going public

Three things in this repo are deliberately set to something you must replace:

1. `ADMIN_PASSWORD` — the seeder's default is `admin123`.
2. `CONTACT_EMAIL` — the corrections address published on `/about` and
   `/privacy`. It defaults to an obviously-invalid placeholder rather than a
   plausible-looking one, so it is visible rather than quietly wrong.
3. The "Maintained by" line in `resources/views/about.blade.php`.

### API contract

- Read endpoints answer `{"data": ...}`.
- `/api/meta` is the one exception and answers unwrapped, because `map.js`
  reads `meta.map` directly.
- Errors answer `{"error": {"message": "..."}}`, which is what `public/js/api.js`
  surfaces to the user.
- Unknown `/api/*` paths answer JSON 404 rather than an HTML error page.
- `/api/stations` does **not** carry the nested `manager` object. No public page
  reads it, and for an account created from the "+ New manager" dialog the
  username is the person's email address - so publishing it would put staff
  email addresses on an unauthenticated endpoint. The admin endpoints do carry it.

### Admin auth

Session-based, not token-based: login writes a `laravel_session` cookie and the
admin gate reads it back. The 12-hour session lifetime matches the JWT expiry
the Node version used.

The login page calls `/api/auth/me` to check whether you are already signed in.
For a signed-out visitor that answers **401**, which the browser logs as a
failed network request. That is expected, and the page handles it by showing
the form. It is not an application error.

## Coordinate provenance

Read `database/seeders/BanguedSeeder.php` before editing station coordinates.
Shell and Seaoil came from the project owner. Caltex uses the OpenStreetMap
centroid of a village node and is flagged `approximate` — verify and correct it
in the dashboard. The three records with no coordinates are seeded as
`is_pending` placeholders and deliberately do not appear on the public map
until an admin fills them in.

## Deploying

Any standard Laravel host works. Point the document root at `public/`, set
`APP_ENV=production`, `APP_DEBUG=false`, and a real `APP_KEY`, then run
`php artisan migrate --force`. The default seed command is
`php artisan bangued:seed --force`; do not pass `--demo-prices` in production.
