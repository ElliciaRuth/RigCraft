# RigCraft — Certified Pre-Owned PC Parts

A full system for refurbishing, stress-testing and selling second-hand PC components. All prices are in **Philippine pesos (₱)**.

- **Public store:** home page with today's deals, a shop with filters, a QR-linked test report for every unit (with side-by-side compare), and a cart, checkout and order history for shoppers with an account.
- **Staff console:** dashboard, intake with QR labels, inventory, unit management (edit, re-test, sell/undo sale, delete), the online order queue, user management and an account page.

**Stack**

| Part | Technology |
|---|---|
| Backend | **CodeIgniter 4.7** (PHP 8.2) JSON API: controllers, models, filters, validation, migrations |
| Frontend | **Vue 3** single-page app built with **Vite**, Vue Router, Bootstrap 5.3, Font Awesome 6, Chart.js, `qrcode` |
| Database | MySQL / MariaDB (`rigcraft_db`) |

All frontend libraries and fonts are bundled at build time, so the site doesn't depend on CDNs.

## Setup (XAMPP)

You need [XAMPP](https://www.apachefriends.org/) (PHP 8.2+ with the `intl` extension, MySQL), [Composer](https://getcomposer.org/) and [Node.js](https://nodejs.org/) 20+.

1. Clone into XAMPP's web folder so the app lives at `/RigCraft/`:
   ```bash
   cd C:\xampp\htdocs
   git clone <your-repo-url> RigCraft
   ```
2. Install dependencies and build the app (the repo doesn't include `vendor/`, `node_modules/` or the build):
   ```bash
   cd RigCraft\backend
   composer install
   copy .env.example .env
   cd ..\frontend
   npm install
   npm run build
   ```
3. Start **Apache** and **MySQL** in the XAMPP Control Panel.
4. Create the database, either:
   - phpMyAdmin → **Import** → `database/rigcraft_db.sql`, or
   - `C:\xampp\mysql\bin\mysql.exe -u root < database\rigcraft_db.sql`, or
   - with CodeIgniter: create an empty `rigcraft_db`, then from `backend/` run `php spark migrate` and `php spark db:seed RigCraftSeeder`.
5. Open <http://127.0.0.1/RigCraft/>.
   (On this PC, `localhost` gets redirected to the BSU Inventory HTTPS site, so use `127.0.0.1`.)

While working on the Vue code:

```bash
cd frontend && npm run dev     # live-reload dev server: http://127.0.0.1:5173/RigCraft/
cd frontend && npm run build   # update frontend/dist, which Apache serves
```

The dev server forwards `/RigCraft/api` to Apache, so XAMPP must be running.

Database settings live in `backend/.env` (`database.default.*`). If MySQL's root user has a password, set `database.default.password` there.
Re-importing the SQL file resets everything to the sample data. Sample dates are relative to the day you import.

### Sample logins — change these after first sign-in

| Username | Password | Role |
|---|---|---|
| `admin` | `admin123` | Administrator |
| `marcus` | `tech1234` | Technician |
| `aisha` | `tech1234` | Technician |

Staff sign in at **/RigCraft/staff/login** (also linked as "Staff login" in the store footer). Change passwords under **My account**; admins can reset anyone's password under **Users**.

Demo shopper for the store (not the console): `juan@example.com` / `customer123`. Anyone can create a shopper account at **/RigCraft/register**.

## Pages

| Address (under `/RigCraft`) | Who | What it does |
|---|---|---|
| `/` | Public | Storefront: hero, brand ticker, live stats, today's deals, "marketplace vs RigCraft", how we test. The nav highlights the section in view. |
| `/shop` | Public | Every unit for sale with filters (search, category, brand, price, peak temperature, below-new-price) and sorting; filters stay in the URL |
| `/verify` | Public | Pick a unit (search by name or hash), or `?hash=…` for its test report: status, metrics, stress-test chart, checklist, warranty, and **compare** with another unit of the same category (`&compare=…`) |
| `/cart`, `/checkout` | Shopper | Cart (kept in the browser, per shopper account) and checkout: pickup or delivery, cash or GCash on handover. Placing the order marks its units **Sold** |
| `/orders` | Shopper | Order history; **cancel** an order until it's handed over |
| `/signin`, `/register` | — | Shopper sign-in and sign-up |
| `/staff/login` | — | Staff sign-in |
| `/staff` | Staff | Dashboard: stock, revenue, profit, pass rate, weekly intake chart, test queue, recent tests and sales |
| `/staff/intake` | Staff | Register a unit with or without its first stress test; prints the QR label |
| `/staff/inventory` | Staff | Search, filter, sort, paginate; filters stay in the URL |
| `/staff/units/RC-…` | Staff | Edit, re-test, sell / undo sale, test history, QR label; admins can delete |
| `/staff/orders` | Staff | Online orders: mark completed on handover, or cancel to put the units back on sale |
| `/staff/users` | Admin | Add staff, change roles, deactivate, reset passwords |
| `/staff/account` | Staff | Profile and change your own password |

**Old addresses still work.** QR labels printed before the move point at `…/frontend/verify.php?hash=…`, and they open the new verify page. The same goes for every other old `*.php` page.

### Unit lifecycle

```
Intake ──► Testing ──► (stress test) ──► Certified ──► Sold
                              │              ▲   │        │
                              └──► Rejected ─┘   └── undo ┘
                                   (re-test)
```

- **Pass rule:** peak temp **≤ 72.0 °C** *and* **0 memory errors**. The intake form preview, the API and a `CHECK` constraint in the database all enforce it.
- A re-test always adds a new history row, and the latest run decides the status.
- **Warranty:** 6 months by default, editable per unit. It counts from the sale date for sold units, otherwise from the certification date.
- If an intake's manufacturer S/N is already on file, the intake page flags it as a possible duplicate.
- **Online orders:** checkout locks the units, refuses any that sold in the meantime, records the order and marks each unit **Sold** in one transaction, so two shoppers can't buy the same unit. Nothing is charged online. A unit sold online can't be "undone" from its unit page; cancel the order instead (shopper or staff), which puts its units back on sale.
- **Shopper accounts** are separate from staff accounts (own table and session key), so a shopper can never open the console. Staff never see the cart, and checkout refuses a browser with a staff session.
- **Brand-new SRP (optional):** shown in the shop as the struck-through new price, "Save ₱…", a −% badge and a "Best deal" tag.

## API

Every endpoint returns JSON: `{ ok: true, … }` or `{ ok: false, error, fields? }`. POST/PUT/DELETE need the `X-CSRF-Token` header. The app gets the token from `GET /api/session` and a fresh one after each sign-in or sign-out.

| Method & path | Access | Purpose |
|---|---|---|
| `GET /api/session` | Public | Who is signed in, CSRF token, business rules |
| `GET /api/shop` · `GET /api/verify/{hash}` · `GET /api/cart?hashes=` | Public | Catalogue, public test report, cart prices |
| `POST /api/auth/staff/login` · `…/staff/logout` | — | Staff sign-in / out |
| `POST /api/auth/customer/login` · `…/register` · `…/logout` | — | Shopper sign-in / sign-up / out |
| `POST /api/checkout` · `GET /api/my/orders` · `POST /api/my/orders/{no}/cancel` | Shopper | Checkout, order history, cancel |
| `GET /api/stats` | Staff | Dashboard numbers |
| `GET/POST /api/units` · `GET/PUT /api/units/{hash}` | Staff | Inventory search, intake, unit detail, edit |
| `POST /api/units/{hash}/retest` · `/sell` · `/unsell` · `/status` | Staff | Unit actions |
| `GET /api/orders` · `POST /api/orders/{no}/status` | Staff | Order queue, complete / cancel |
| `POST /api/account/password` | Staff | Change own password |
| `DELETE /api/units/{hash}` · `GET/POST /api/users` · `PUT /api/users/{id}` | Admin | Delete a unit, manage staff |

`php spark routes` (in `backend/`) lists them with their filters.

## Folder structure

```
RigCraft/
├─ .htaccess              /RigCraft/api/* → backend; everything else → frontend/dist
├─ dev-router.php         Same rules for PHP's built-in server (start-lan-server.bat)
├─ start-lan-server.bat   Serves the app on port 8080 for phone testing
├─ database/
│  └─ rigcraft_db.sql     Schema + sample data (phpMyAdmin import)
├─ backend/               ── CodeIgniter 4 (only public/index.php is web-reachable)
│  ├─ .env                Environment, base URL, time zone, DB login, session cookie
│  └─ app/
│     ├─ Config/          RigCraft.php (business rules), Routes.php, Filters.php, Services.php …
│     ├─ Controllers/Api/ BaseApiController, Session, Auth, Store, Shopper,
│     │                   Dashboard, Units, Orders, Users
│     ├─ Filters/         ApiAuth (staff / admin / customer), ApiCsrf
│     ├─ Libraries/       RcAuth (sessions, sign-in, lockout, CSRF), UnitRules, OrderService
│     ├─ Models/          User, Customer, Component, DiagnosticLog, Order, OrderItem
│     ├─ Validation/      RigCraftRules (ph_phone, not_future, rc_hash)
│     ├─ Exceptions/      ApiError
│     └─ Database/        Migrations/ (schema), Seeds/RigCraftSeeder
└─ frontend/              ── Vue 3 + Vite
   ├─ src/
   │  ├─ main.js, App.vue, router/index.js
   │  ├─ layouts/         PublicLayout (store nav + footer), StaffLayout (console sidebar)
   │  ├─ views/public/    Home, Shop, Verify, Cart, Checkout, Orders, SignIn, Register, NotFound
   │  ├─ views/staff/     Login, Dashboard, Intake, Inventory, Unit, Sales, Users, Account
   │  ├─ components/      DealCard, UnitSearch, ModalDialog, QrLabel, TestFields,
   │  │                   PasswordInput, CountUp, CartToast
   │  ├─ lib/             api (client), session, cart, format, validation, photos, useShopCart
   │  └─ assets/rigcraft.css
   ├─ public/img/products/  Product photos (transparent .webp, <model-name-slug>.webp, listed in lib/photos.js)
   ├─ photo-originals/      The same photos with their original white backgrounds (not served)
   └─ dist/                 Production build served by Apache (npm run build)
```

## Security notes

- Passwords use bcrypt. Sessions are CodeIgniter sessions in their own cookie (`RIGCRAFTSESS`), HttpOnly and SameSite=Lax, with a new session ID issued at every sign-in and sign-out.
- After 5 failed sign-ins, that sign-in form is locked for 60 seconds for the session.
- Every POST/PUT/DELETE needs the session's CSRF token (`ApiCsrf` filter).
- Roles are checked on the server for every API call (`ApiAuth` filter); the Vue router guards only decide which page to show.
- The public `verify`/`shop`/`cart` responses never include buyout cost or sale price.
- `.htaccess` (and `dev-router.php`) block direct access to `backend/` (except the front controller), the Vue sources, `node_modules`, `database/` and project files.

## Scanning QR codes with a phone

A phone can't open `localhost` or `127.0.0.1`. Run `start-lan-server.bat`, then open the `http://<your-LAN-IP>:8080/RigCraft/` address it prints, on this PC, and sign in.
QR labels made there point to an address the phone can reach. You can also set the URL in the **QR Target** box on the intake page.

## Limitations

- The verify-page chart rebuilds a 30-minute curve from the logged peak temperature. Store per-minute sensor samples to plot real data.
- Sign-in lockout is per session, not per IP. Put the console behind a VPN or a proper reverse proxy before exposing it publicly.
