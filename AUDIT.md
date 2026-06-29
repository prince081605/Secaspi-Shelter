# SECASPI Shelter — Audit Report

**Status:** ✅ complete — all 12 modules audited · report-only (no application code changed)
**Dates:** 2026-06-29
**Method:** static code tracing + live app run (Laravel :8001 + Vite :5173, admin session + public
pages) + live API probes + full PHPUnit suite (111 green). **Project average ≈ 79/100** (see Appendix A4).

Severity legend: **[CRIT]** critical · **[HIGH]** high · **[MED]** medium · **[LOW]** low ·
**[NIT]** cosmetic/nice-to-have.

Each issue is written as: **Problem → Why it matters → Proposed fix → File(s)**.
The audit itself changed no application code; fixes are applied in separate, approved passes and
tracked below.

---

## Fix progress tracker

Legend: ✅ done · ⏳ in progress · ⬜ pending.

| Pass | Scope | Status | Where |
|---|---|---|---|
| **Global cleanup** | §0.1 dead code: `App.jsx`, `dashboardMockData.js`, `dummy.php`, orphaned rescue-map endpoint + route + 2 tests; planning docs → `/docs` | ✅ done | branch `audit/report-and-global-cleanup` (commit `147f801`); suite 109 green, build clean |
| §0.2 structural (backend) | `PublicHomeController` extraction (4 home/* closures), shared `PublicStats::maskName` (was 3×), shared `PublicStats::topDonors` (was 2×) | ✅ done | branch `audit/report-and-global-cleanup`; suite **113 green** (+4 new `PublicHomeTest`), routes rebound |
| §0.2 structural (frontend) | split oversized components (`AnimalsAdmin`, `Dashboard`, `LandingPage`, `AdoptionRequestsAdmin`); shared admin `markRead`/`adminIndex` trait | ⬜ pending | per module (2, 3, 4, 11) |
| **HIGH security (auth)** | `throttle` on login/register/forgot/reset (§1); revoke tokens on password reset + revoke other sessions on change-password (§1) | ✅ done | suite **116 green** (+3 new `AuthTest` cases) |
| **HIGH security (public/AI)** | Rate-limit public rescue write (§6) + public AI chat (§10) | ⬜ pending | Appendix A3 #1 |
| **HIGH functional** | Admin-table pagination via shared `components/Pagination.jsx` — **✅ §3 Animals, §5 Donations, §6 Rescue, §8 Visitations** (browser-verified Donations 1→2 of 9, Animals 1→2 of 3); ⬜ §4 Adoption/Foster + §7 Volunteers/Personnel/Intakes (multi-list, pending) | ⏳ in progress | Appendix A3 #3 |
| MED security | Private-disk uploads (§5/6/7); CSV-injection (§11); staff-grant gate (§7); public field exposure (§2/3); AI cost cap (§10) | ⬜ pending | Appendix A3 #4–8 |
| MED functional/perf | Orphaned mark-reads (§4/7/8); foster status sync (§4); donation dates (§5); queue notifications + N+1 (§9); aggregate poll/stat endpoints (§3/11); code-split (§2/11) | ⬜ pending | Appendix A3 #9–11 |
| LOW | Remaining dead code (axios, email-verif stub, `staff()` report), debounce, autocomplete, magic numbers, `<Link>` nav | ⬜ pending | Appendix A2/A3 |

> Module scorecards reflect the **as-audited** state; they're not re-scored until a module's fixes
> land. As each pass completes, its row flips to ✅ and the affected module sections get a resolution
> note (see §0.1 for the pattern).

---

## 0. Global findings (project-wide)

### 0.1 Confirmed dead code / unused (grep-verified)

> ✅ **RESOLVED — cleanup pass, 2026-06-29.** Verification: backend suite **109 green**
> (was 111; −2 removed map-endpoint tests), frontend **build clean**.

- **[MED] ✅ Removed `frontend/src/App.jsx`** (returned `null`, imported nowhere).
- **[MED] ✅ Removed `frontend/src/lib/dashboardMockData.js`** (82 lines, referenced nowhere).
- **[MED] ✅ Removed `backend/app/Http/Middleware/dummy.php`** (class `dummy`, never aliased).
- **[LOW] ✅ Removed the orphaned rescue-map endpoint** — deleted `RescueReportController@map`,
  the `GET /rescue-reports/map` route, and its 2 endpoint tests in `RescueMapTest.php` (kept the
  still-valid coordinate-submission test). The per-report Detail map (§6) had replaced it.
- **[LOW] ✅ Moved root planning docs** (`AI_PLAN.md`, `RESCUE_MAP_PLAN.md`, `TODO_PHASE1.md`)
  into `/docs` (tracked moves preserve history). `mock datas/` (8 unreferenced dev images) left
  in place per the maintainer's choice.

### 0.2 Structural / duplication candidates (confirmed per module)

> ⏳ **PARTIALLY RESOLVED — structural pass (backend), 2026-06-29.** The two backend duplication
> items below are done; the frontend component splits + the `markRead`/`adminIndex` trait remain.
> Verification: backend suite **113 green** (+4 new `PublicHomeTest`), routes rebound to the new
> controller (`php artisan route:list`).

- **[MED] Oversized files** (maintainability / re-render cost): `AnimalsAdmin.jsx` (1219),
  `Dashboard.jsx` (819), `LandingPage.jsx` (623), `AdoptionRequestsAdmin.jsx` (576);
  backend `AnimalController` (484), `ReportController` (459). → Extract sub-components /
  form/table/modal pieces and helper services. _(Detailed per module. **⬜ pending.**)_
- **[MED] ✅ ~270 lines of raw analytics inlined in `routes/api_public.php`** (`home/stats`,
  `home/impact`, `home/transparency`, `home/featured-animals`) — **moved to `PublicHomeController`**.
  The route file now registers four controller actions; the closures (and the file's `DB`/`Storage`
  imports) are gone. Behaviour preserved verbatim (error contracts + the `80220` default are left
  for the separate §2 security/magic-number passes). _(Module 2.)_
- **[MED] ✅ Duplicated "First L." donor-name masking** (was 3×: `ImpactController::maskName` +
  inline copies in `home/impact` and `home/transparency`) — **extracted to
  `App\Support\PublicStats::maskName`**. The near-identical `top_donors` query (was 2×:
  `ImpactController::leaderboard` + `home/impact`) is now the shared `PublicStats::topDonors`.
  _(Module 2/5.)_
- **[LOW] Repeated admin `markRead` / `adminIndex` patterns** across controllers
  (adoption, foster, rescue, visitation, volunteer-application). → Assess a shared trait/base.
  _(Cross-cutting, Module 12. **⬜ pending.**)_

> These structural items are starting hypotheses; each is confirmed, quantified, or dismissed in
> its module section below.

---

## 1. Auth & Profile

**Scope:** `AuthController`, `Auth/EmailVerificationController`, `ProfileController`,
`EnsureActive`/`EnsureAdmin`/`EnsureRole`, `User` model, `routes/auth.php` (public part) ·
`Login`, `Register`, `ForgotPassword`, `ResetPassword`, `AuthLayout`, `lib/auth.js`,
`lib/api.js`, `RequireAuth` (in `AppRouter.jsx`).

**Testing performed:** full static trace; live API runs against the running server
(:8001) — happy-path login, bad password, register, forgot-password (dev token), 12×
rapid failed logins; reviewed `AuthTest.php` (12 cases, all relevant ones passing).

### Strengths (done right — keep)
- **Mass-assignment hardening is excellent.** `role`/`status`/`email_verified` are excluded
  from `$fillable`; `AuthTest::test_profile_update_cannot_escalate_role` and
  `test_register_cannot_self_assign_admin_role` enforce it. _(`User.php:19`)_
- **Suspension handling is thorough:** login block + `EnsureActive` backstop on every route +
  token revocation on suspend, all test-covered.
- Password-reset token uses `hash_equals`, 30-min TTL, single-use; forgot-password returns a
  generic message (no account enumeration via response body). `Hash` casting on password.

### A. Functional
- **[MED] Register API response returns `role: null`.** Reproduced live:
  `POST /api/register` → `{"user":{...,"role":null}}`, yet the DB row is `role='user'`.
  The default is applied by the **DB column** (`->default('user')`), not in app code, and the
  model isn't refreshed after `User::create()`. Harmless in the current UI (Register.jsx only
  reads `username`), but it's an incorrect contract and fragile (any DB without the column
  default would persist a null role). → **Fix:** set `'role' => 'user'` explicitly in
  `create()` (or `$user->refresh()` before responding). _(`AuthController.php:86`,
  migration `...create_secaspi_tables.php` users block.)_
- **[LOW] Email-verification feature is half-built.** `me()` returns `email_verified`, the
  column + `VerifyEmailRequest` + a migration exist, but `EmailVerificationController` is an
  empty stub and nothing sends or checks a verification link. → Decide: implement or remove the
  scaffolding. _(`Auth/EmailVerificationController.php`, `Requests/VerifyEmailRequest.php`.)_
- **[INFO] Register deliberately does not auto-login** (shows a success card → "Continue to
  login"); the `if (data?.token)` branch in `auth.register` is dead defensive code (register
  never returns a token). Not a bug — flagged so it isn't "fixed" by accident; the dead branch
  can be removed. _(`lib/auth.js`, `Register.jsx:34`.)_

### B. UI / UX
- **[MED] Auth links use raw `<a href>` instead of router `<Link>`** → full page reload
  (re-downloads the SPA bundle, white flash, lost state). Affects every auth page:
  Login→register/forgot, Register→login (×2), Forgot→login, Reset→login. → **Fix:** `<Link to>`.
  _(`Login.jsx:32,69`, `Register.jsx:48,68`, `ForgotPassword.jsx:34`, `ResetPassword.jsx:51`.)_
- **[LOW] No `autocomplete` attributes** on email/password inputs (`email`, `username`,
  `current-password`, `new-password`). Hurts password managers and accessibility. _(all 4 pages)_
- **[LOW] Labels not programmatically associated with inputs** (styled `className` labels, no
  `htmlFor`/`id`). Screen-reader + click-to-focus gap. _(all auth forms)_
- **[LOW] Password show/hide affordance is an emoji (🙈/👁️)** and exists only on Login, not
  Register/Reset → cross-OS rendering inconsistency + inconsistent UX. _(`Login.jsx:61`)_
- **[LOW] Logo is a `div` with `onClick`** (not a link/button) → no keyboard focus/Enter.
  _(`AuthLayout.jsx:22`)_
- **[NIT] Register has no confirm-password field**, while Reset does — inconsistent.

### C. Code
- **[MED] Dead code:** empty `EmailVerificationController`; unused `auth.register` token branch.
- **[LOW] User payload is hand-built 4 different ways** (login: no phone/verified; `me`:
  +phone +verified; register: role=null; profile.update: no username/verified). → Extract one
  `userPayload(User)` helper or an Eloquent API Resource for a single consistent shape.
- **[LOW] Per-method `Validator::make`** everywhere; the project already has a `Requests/` dir —
  FormRequest classes would slim controllers and centralize rules.

### D. Performance
- **[LOW] `RequireAuth` calls `auth.me()` (network) on every protected-route mount** with no
  shared auth context/cache → repeated `/api/user` round-trips while navigating. Acceptable for
  security, but a lightweight auth context would remove redundant calls. _(`AppRouter.jsx:25`)_
- **[LOW]** Full-page reloads from raw anchors (B-1) compound this.

### E. Security
- **[HIGH] ✅ RESOLVED — rate limiting added to `login`/`register`/`forgot-password`/
  `reset-password`.** Applied `throttle:10,1` to login/register/reset and `throttle:5,1` to
  forgot-password (per-IP, per-route; a 429 is returned past the cap). Covered by
  `AuthTest::test_login_is_rate_limited_after_repeated_attempts` (11th attempt → 429).
  _(`routes/auth.php:33-37`. `username/suggest` left unthrottled — it's a live keystroke preview.)_
- **[HIGH] ✅ RESOLVED — password reset now revokes existing tokens.** `resetPassword()` calls
  `$user->tokens()->delete()` after saving, so any session created before the reset is
  invalidated. Covered by `AuthTest::test_password_reset_revokes_all_existing_sessions`.
  _(`AuthController.php`.)_
- **[MED] ✅ RESOLVED — `changePassword` now revokes other sessions.** Deletes all of the user's
  tokens except the current one (kept so they stay logged in here). Covered by
  `AuthTest::test_change_password_revokes_other_sessions_but_keeps_the_current_one`.
  _(`ProfileController.php`.)_
- **[LOW] User-enumeration timing side channel:** login skips `Hash::check` when the user is
  missing (faster), and forgot-password only sends mail for real accounts. Response bodies are
  generic (good), but response timing differs. → Optional: constant-time dummy-hash path.
- **[LOW] Token stored in `localStorage`** (`secaspi_token`) → XSS can exfiltrate it.
  Architectural note; httpOnly-cookie auth would remove this class of risk. _(`lib/api.js:2`)_
- **[LOW] Register password policy is `min:8` only** — no max length (note bcrypt's 72-byte
  cap), no complexity, no confirmation server-side. Low risk at this scale; consider `max:255`.

### F. Database
- **[LOW] `role`/`status` defaults live only at the DB-column level** (`->default(...)`), not in
  app code — root cause of the register `role:null` response (A-1).
- **[LOW] `email_verified` column is currently dead** (no feature behind it).
- **[LOW] `users` has no `updated_at`** (`$timestamps = false`); `created_at` is set via
  `useCurrent()` only. No timestamp on profile/password changes — minor audit-trail gap.

### G. API
- **[MED] Inconsistent user object shape across endpoints** (login vs `me` vs register vs
  profile.update) — see C-2. Same fix resolves it.
- **[LOW]** `login` returns `422 "Invalid credentials"` on *validation* failure but
  `401 "Invalid credentials"` on *auth* failure — reusing the same message across two codes is
  slightly ambiguous. Status codes themselves are otherwise correct (201/401/403/422).

### H. Edge cases
- **[LOW] `full_name` max length is inconsistent:** `255` on register vs `150` on profile
  update (and the column is `varchar(150)`). A 151–255 char name registers fine but can't be
  edited without shortening, and register itself could exceed the column. → Align to 150.
  _(`AuthController.php:76` vs `ProfileController.php:15`)_
- **[LOW]** Concurrent password resets: last requested token wins (cache overwrite) — acceptable.
- **[LOW]** Suspended user can still reset their password (but still can't log in) — acceptable.
- **[PASS]** Invalid/expired reset token → `401` (tested); emoji/Unicode names handled via
  `mb_*` in `generateUsername`.

### Module 1 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 88/100 | Works end-to-end; `role:null` response + half-built verify |
| UI | 80/100 | Clean, but raw anchors, emoji control, no autocomplete |
| UX | 82/100 | Solid flows; full-reload nav is the main drag |
| Performance | 85/100 | Per-mount `me()` calls; reload-on-nav |
| Security | 62/100 | No rate limiting + no token revocation on reset are the big hits; strong mass-assignment/suspension handling offsets |
| Accessibility | 70/100 | No label/`htmlFor`, div-as-button logo, emoji control |
| Code Quality | 80/100 | Readable; payload duplication + dead stub |
| Maintainability | 80/100 | Small files; verification scaffolding is debt |
| **Overall** | **≈78/100** | Healthy core, two HIGH security gaps to close |

**Top fixes (when we reach the fixing pass):** (1) add `throttle` to auth routes [HIGH],
(2) revoke tokens on password reset [HIGH], (3) revoke other sessions on change-password [MED],
(4) fix register `role:null` response [MED], (5) `<Link>` instead of `<a>` on auth pages [MED],
(6) consolidate the user payload shape [MED].

---

## 2. Public Site / Home

**Scope:** `routes/api_public.php` home/* closures (`home/stats`, `home/impact`,
`home/transparency`, `home/featured-animals`, `home/settings`), `SettingController@publicIndex`,
`ImpactController` (`leaderboard`, `me`, badges), `MatchmakerController` +
`AdoptionMatchService` · `Home` (pass-through), `LandingPage` (+ sub-components),
`Transparency`, `Matchmaker`, `lib/publicHomeApi`, `impactApi`, `matchmakerApi`, `settingsApi`.
_(The rescue **form** lives in `LandingPage` but its submission path is audited in Module 6.)_

**Testing performed:** full static trace; live calls to `/api/home/settings` (confirmed config
over-exposure), `/api/home/impact`, `/api/home/featured-animals`; verified `leaderboard`/`impact`
endpoints are consumed by `ImpactPanel` (not dead).

### Strengths (keep)
- **Best accessibility in the app so far:** `LandingPage` has a skip-link, `aria-label`s, a
  hamburger with `aria-expanded`, and keyboard-navigable featured cards; `Matchmaker` uses
  proper `htmlFor` labels.
- `Transparency` and `Matchmaker` have real **loading / empty / error** states.
- Donor names are **masked** ("Juan D.") on every public surface; anonymous donations honored.
- `MatchmakerController` eager-loads `mainPhoto` (**no N+1**) and validates input with strict
  `in:` allow-lists; `AdoptionMatchService` is clean, documented, and explainable.

### A. Functional
- **[MED] Matchmaker `preferred_size` is collected but ignored.** The frontend renders a
  "Preferred size" select and the controller validates `preferred_size`, but
  `AdoptionMatchService::score()` never reads it — only `home_type` vs the animal's size. The
  user's size choice has **zero effect** on results. → **Fix:** factor `preferred_size` into
  scoring, or drop the question. _(`AdoptionMatchService.php:30`, `Matchmaker.jsx:24`.)_
- **[MED] LandingPage shows fabricated fallback data.** On empty/failed API it renders 4 fake
  dogs (`animalsFallback`: Bingo/Maya/Boss/Luna — clicking any just goes to `/adopt`) and
  hardcoded stats (312 rehomed, 48 in care, 96 volunteers, ₱1.2M). On a shelter's public page,
  showing invented numbers/animals as if real is misleading and the fake numbers briefly flash
  before real data loads. → **Fix:** show skeletons/empty states; never fabricate public stats.
  _(`LandingPage.jsx:29-34, 533-544`.)_

### B. UI / UX
- **[MED] Internal nav uses raw `<a href>`** on `LandingPage` (Navbar: `/adopt` `/visit`
  `/volunteer` `/transparency`; footer links; logo) → full page reloads on SPA routes. (Hash
  links `#donate`/`#report` are correctly anchors.) → `<Link>`. _(`LandingPage.jsx:92-126,413-435`.)_
- **[LOW] No loading skeletons;** hardcoded fallbacks double as "loaded" content, causing a
  visible flash when real data arrives.
- **[LOW] Inline `<style>` string blocks** repeat across `Transparency`, `Matchmaker`,
  `ImpactPanel`, `AuthLayout`. Works, but inconsistent with the CSS-file approach used elsewhere.

### C. Code
- **[MED] ✅ RESOLVED — "First L." name masking was duplicated 3×** (`ImpactController::maskName`
  plus inline copies in `home/impact` and `home/transparency`). Now one shared
  `App\Support\PublicStats::maskName`. _(was `ImpactController.php:82`, `api_public.php:101-112, 170-179`.)_
- **[MED] ✅ RESOLVED — `top_donors` computed twice** by near-identical queries
  (`ImpactController::leaderboard` and `home/impact`). Now the shared `PublicStats::topDonors`.
- **[MED] ✅ RESOLVED — ~270 lines of analytics lived as route closures** in `api_public.php`
  instead of a controller. Extracted to `PublicHomeController` (`stats`/`impact`/`transparency`/
  `featuredAnimals`); the route file now binds four controller actions. _(was §0.2.)_
- **[LOW] `Home.jsx` is a pointless pass-through** (`return <LandingPage/>` + ~36 trailing blank
  lines). → Route `/` directly to `LandingPage`, delete `Home.jsx`.
- **[LOW] `SettingController::publicIndex` and `adminIndex` have identical bodies** (`Setting::getAll()`).

### D. Performance
- **[MED] No route-level code splitting.** `AppRouter` imports all pages eagerly, so heavy
  dependencies load up front — notably **Leaflet + react-leaflet** (pulled in by `LandingPage`
  for the rescue-form map, used by a tiny fraction of visitors) and Recharts elsewhere. → Lazy
  -load routes (`React.lazy`) and the map component. _(Quantified in Module 12; manifests here.)_
- **[LOW] `LandingPage` fires 4 independent mount fetches** (featured, impact, settings,
  `auth.me`). The `auth.me()` only toggles a Login/Dashboard label yet makes every anonymous
  visitor incur a `401` round-trip. _(`LandingPage.jsx:462-520`.)_
- **[LOW] Public aggregates are uncached.** `home/impact`/`home/transparency` recompute several
  sums/counts/group-bys on every public hit. → Cache (5–15 min).

### E. Security
- **[MED] Public endpoints leak raw exception messages.** `home/transparency` and
  `home/featured-animals` catch blocks return `'error' => $e->getMessage()` with `500` to
  anonymous clients → internal/SQL detail disclosure. → Log server-side; return a generic
  message. _(`api_public.php:203-208, 277-283`.)_
- **[MED] `/api/home/settings` dumps ALL settings publicly.** Confirmed live: anonymous response
  includes `ai_assistant_enabled`, `ai_daily_message_cap`, `ai_persona` (and would include
  `cost_per_meal`). The public page needs only branding/contact/hero/social. → Whitelist public
  keys in `publicIndex`. _(`SettingController.php:11`.)_
- **[PASS]** No SQLi (bound query builder); strict matchmaker validation; consistent donor masking.

### F. Database
- **[LOW] Repeated heavy aggregates, no caching** (see D-3) on every public page view.
- **[PASS]** `featured-animals` uses a `ROW_NUMBER() OVER (PARTITION BY animal_id …)` subquery so
  multi-photo animals don't fan out into duplicate cards, with a SQLite-safe fallback.

### G. API
- **[LOW] Public home/* are closures with ad-hoc JSON shapes** (no controller/Resource),
  inconsistent with the controller pattern used everywhere else.
- **[LOW] Inconsistent error contract:** `transparency`/`featured-animals` try/catch→500+error,
  while `stats`/`impact` don't catch at all (uncaught → framework 500). → Standardize.

### H. Edge cases
- **[LOW] Magic-number drift for the monthly goal:** the `home/transparency` closure defaults to
  `80220` while the seeded/admin value is `80000` — two different "defaults". → Single source.
  _(`api_public.php:138`.)_
- **[PASS]** Division-by-zero guarded (`Math.max(1, …)` on charts; `min/round` guards in PHP);
  zero-available-animals matchmaker → "No available animals…"; empty donor name → "Anonymous".

### Module 2 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 84/100 | Polished; `preferred_size` ignored + fabricated fallbacks |
| UI | 85/100 | Strong landing page design |
| UX | 84/100 | Good states; raw-anchor reloads + stat flash |
| Performance | 74/100 | No code splitting, Leaflet upfront, uncached aggregates |
| Security | 72/100 | Exception leakage + settings over-exposure; good masking/validation |
| Accessibility | 86/100 | Skip link, aria, `htmlFor` — best in app |
| Code Quality | 78/100 | Closures-as-controllers, 3× masking dup |
| Maintainability | 75/100 | Inline analytics + duplication |
| **Overall** | **≈80/100** | Attractive, functional public site; debt is duplication + a few leaks |

**Top fixes (later pass):** (1) whitelist public settings keys [MED-sec], (2) stop returning
`$e->getMessage()` on public endpoints [MED-sec], (3) use `preferred_size` in matchmaker [MED],
(4) replace fabricated fallbacks with skeletons/empty [MED], (5) extract `PublicHomeController` +
one `maskName` helper + dedupe `top_donors` [MED], (6) `<Link>` nav + route-level code splitting.

---

## 3. Animals & Medical

**Scope:** `AnimalController` (484), `MedicalRecordController`, `VaccinationController`,
`Animal`/`AnimalPhoto`/`CareGuide` models, QR generation, `SyncsHealthReminders` (trait) ·
`AnimalsAdmin.jsx` (1219), public `Adoption`, `AnimalDetail`, `lib/animalsApi`.

**Testing performed:** full static trace; live API (`/api/animals`, `/api/animals/57`) — confirmed
medical cost/vet exposure; **live browser** screenshot of the public Adoption page (DOM-verified:
12 cards = working page 1, h1 correct). Admin pagination cap confirmed from code + the 58-row DB.

### Strengths (keep)
- **Public `Adoption.jsx` is well built:** real pagination (Prev/Next + page meta), **300ms
  debounced** search, keyboard-navigable cards, full loading/empty/error states.
- **`AnimalDetail.jsx`:** keyboard-navigable photo gallery (`Enter`/`Space`, `aria-pressed`,
  `aria-label`), care-guide + behavioral sections, and it correctly **hides** cost/vet in the UI.
- Medical/Vaccination controllers are clean and share a `SyncsHealthReminders` trait
  (reminders auto-create/clear); `clearSupersededBoosters` is a thoughtful touch.
- `AnimalsAdmin` is **well-decomposed** into sub-components despite its size; careful controlled
  -input null coercion; duplicate-detection on create with inline preview + "add anyway".
- Mass-assignment uses `validated()` only; uploads validated `image|max:5120`.

### A. Functional
- **[HIGH] ✅ RESOLVED — Admin animal list now paginates.** `AnimalsAdmin` was reading only
  `data.data`, never sending `page`, and rendering no controls — so with 58 animals staff could
  only ever reach the newest 20. Added `page`/`meta` state, sends `page`, renders Prev/Next +
  "Page X of Y", and resets to page 1 on filter change / after mutations (reuses the
  `Adoption.jsx` pattern). Browser-verified: page 1→2 of 3, Prev disabled on page 1, all 58 rows
  reachable (20+20+18). _(`AnimalsAdmin.jsx`, `AnimalsAdmin.css`; backend `adminIndex` already
  paginated.)_
- **[MED] Same cap likely hits the Intakes table** in this file (`adminListIntakes` → `data?.data`,
  no page controls) — confirmed in Module 7.

### B. UI / UX
- **[MED] Admin search has no debounce.** `filters.q` is an effect dependency, so every keystroke
  fires a list request (typing "golden" = 6 requests). The public page debounces 300ms; admin
  should too. _(`AnimalsAdmin.jsx:986,1109`.)_
- **[LOW] Heavy inline styles** throughout `AnimalsAdmin` mixed with CSS classes — inconsistent,
  harder to theme.
- **[LOW] Emoji-as-semantic-heading** (💉 Medical, 💊 Vaccinations, 📋 Intake Queue …) — meaning
  carried by emoji.
- **[PASS]** Strong a11y on both sides (aria-labels on search/filters/icon buttons, `role="group"`
  view toggle, semantic tables, table+cards modes); public layout polished (screenshot).

### C. Code
- **[MED] `AnimalsAdmin.jsx` (1219 lines) mixes two domains.** ~370 lines are the **Intakes** UI
  (`NewIntakeForm`, `AssessmentPanel`, `IntakeRow`, `IntakesSection`) that belong to the Intakes
  module. → Extract `IntakesAdmin.jsx` (~30% smaller file). _(Coordinated with Module 7.)_
- **[MED] `show()` and `toAdminDetail()` duplicate the response-shaping** (medical/vaccination/
  photo/qr blocks). → One shared serializer / API Resource.
- **[MED] Behavioral-trait vocabulary is hardcoded in 3 places** — `AnimalsAdmin.BEHAVIORAL_ISSUES`,
  `AdoptionMatchService` needle constants, and the CareGuide seeder — bridged only by substring
  matching. Drift risk. → Single source of truth.
- **[LOW] `URLSearchParams` builder copy-pasted 4×** in `animalsApi.js`. → One `toQuery()` helper.

### D. Performance
- **[MED] Stat strip fires 4 full list requests** (`Promise.all` of `adminListAnimals` with 4
  status filters) only to read pagination `total` — plus the main list = **5 requests on mount**.
  → One lightweight count endpoint. _(`AnimalsAdmin.jsx:990-1009`.)_
- **[MED] Redundant `adminGetAnimal` refetches.** Photos, Medical, and QR each re-fetch the full
  animal; every sub-manager create/delete calls `refresh()`, which refetches the whole list **and**
  re-runs the 4 stat calls. → Cache detail; targeted updates.
- **[MED] No admin search debounce** (perf + UX; see B-1).
- **[LOW] QR generated inside GET requests** — first `show`/`adminGetAnimal` does `Storage::put` +
  DB `update` (side-effect on read; concurrent first-views can double-generate). _(see E-3.)_

### E. Security
- **[MED] Public animal detail leaks treatment cost + vet name.** Confirmed live: anonymous
  `GET /api/animals/57` returns `medical_records[].cost` ("5603.00") and `vet_name` ("Dr. Lim").
  The UI hides them, but the API ships them to anyone. → Omit cost/vet_name/notes from the public
  `show()` payload. _(`AnimalController.php:101-109`.)_
- **[MED] `destroy()` cascade runs without a transaction.** It deletes medical records,
  vaccinations, adoption + foster applications, and photo files, then the animal — a mid-way
  failure leaves partial/orphaned data. It also **erases adoption/foster application history**.
  → Wrap in `DB::transaction`; reconsider deleting application history. _(`AnimalController.php:233-252`.)_
- **[LOW] GET-with-side-effect** (QR) lets anonymous `show` trigger server-side file writes.
- **[LOW]** Create duplicate-check + insert is non-atomic and there's no DB unique constraint on
  (name, species).
- **[PASS]** All mutations role-gated; uploads validated.

### F. Database
- **[MED] No DB-level FK cascades** — deletion integrity depends entirely on `destroy()` app code
  (see E-2). → Add FK `onDelete` or guarantee transactional cascade.
- **[LOW]** `Animal` has `$timestamps = false` (no created/updated_at; "newest" = `orderByDesc('id')`).
- **[LOW]** No unique index on (name, species) though the app enforces it logically.

### G. API
- **[MED] Divergent serializers for the same model:** public `show` returns `photos` as flat URL
  strings + `care_guides`; `adminShow` returns `photos` as objects (id/is_main) and no care guides.
  (Same fix as C-2.)
- **[PASS]** Good status codes (201 create, 409 duplicate **with the existing record**, 404
  photo-mismatch, 422 validation).

### H. Edge cases
- **[PASS]** Editing re-hydrates from `adminGetAnimal` (won't wipe weight/story/behavioral);
  controlled-input null coercion prevents the React keystroke-drop bug; non-image / >5MB uploads
  → 422; deleting the main photo still resolves a fallback via `mainPhoto` ordering (verified — not
  a bug).
- **[LOW]** `age`/`weight` have `min:0` but no max (age 999 accepted).

### Module 3 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 78/100 | Admin pagination cap (HIGH) is the headline |
| UI | 82/100 | Polished public side; admin a bit utilitarian |
| UX | 80/100 | Great public UX; admin lacks debounce/paging |
| Performance | 68/100 | 5 requests on mount, refetch chatter, GET side-effects |
| Security | 72/100 | Public medical exposure + non-transactional cascade |
| Accessibility | 85/100 | Strong on both public and admin |
| Code Quality | 74/100 | 1219-line file spanning 2 domains, dup serializers |
| Maintainability | 70/100 | Size, 3× behavioral vocab, dup query builders |
| **Overall** | **≈76/100** | Capable feature set; admin UX + a few data-exposure/integrity gaps |

**Top fixes (later pass):** (1) add pagination to `AnimalsAdmin` [HIGH], (2) strip cost/vet from
public `show` [MED-sec], (3) wrap `destroy()` in a transaction [MED-sec], (4) debounce admin
search + replace 4-call stat strip with a count endpoint [MED-perf], (5) extract `IntakesAdmin.jsx`
+ unify the animal serializer [MED].

---

## 4. Adoption & Foster

**Scope:** `AdoptionApplicationController` (207), `FosterApplicationController` (176) ·
`AdoptionApply`, `FosterApply`, `AdoptionRequestsAdmin` (576, hosts the foster toggle →
`FosterRequestsAdmin`), `lib/animalsApi` application helpers.

**Testing performed:** full static trace; grep-verified the orphaned mark-read helper; confirmed
the missing animal-status guard and the foster→animal-status gap in code.

### Strengths (keep)
- **Adoption approval correctly reserves the animal** (→ `adopted`) and **restores it** on a
  decline-from-approved, then notifies the applicant (`AdoptionStatusChanged`).
- **30-day re-apply guard** on both adoption and foster, with a friendly `409` message.
- **`printAdoptionContract` escapes every interpolated value** (`escapeHtml`) → no XSS in the
  generated letterhead contract; thoughtful `replaceState` print-footer fix.
- Good admin UX: `StatusBadge`, semantic `dl/dt/dd` detail lists, unread row highlighting,
  loading/empty/error states, sub-tab navigation. Errors logged server-side with context.

### A. Functional
- **[MED] Reviewing an adoption application never clears its "unread" highlight.** The backend
  built `adminMarkRead` (route + controller) **specifically** so review-only clears unread, and
  `adminMarkAdoptionApplicationRead` exists in `animalsApi.js:92` — but it is **called nowhere**;
  the "Review" button only expands the row. Unread clears only on a status change. → Call it when
  a row is expanded. _(`AdoptionRequestsAdmin.jsx:227`.)_
- **[MED] Foster approval never updates the animal's status.** Unlike adoption, no foster
  transition (approved/active/completed/declined) touches the animal — so a fostered animal stays
  publicly `available` and can still receive adoption applications. → Sync animal status
  (e.g. active→`fostered`, completed/declined→`available`). _(`FosterApplicationController.php:120-146`.)_
- **[MED] Apply endpoints don't check animal availability.** `store()` (both controllers) only
  runs the 30-day guard — never `$animal->status`. A direct link lets a user apply to adopt/foster
  an already-`adopted` or `archived` animal. → Reject when status isn't applicable.
  _(`AdoptionApplicationController.php:16`, `FosterApplicationController.php:16`.)_
- **[MED] No pagination on any requests table** (adoption application/ongoing/completed + foster)
  — all read `data?.data`, capping at 20 (same pattern as Module 3).
- **[LOW] Approving one application doesn't auto-decline the animal's other pending applications**
  (nor notify those applicants); stale `pending` rows linger after the animal is reserved.

### B. UI / UX
- **[LOW] Foster has no reference number** (adoption shows `ADP-XXXX` everywhere; foster generates
  none) and **no read/unread tracking** — inconsistent triage vs adoption.
- **[LOW] Apply forms lack `autocomplete` + `htmlFor`** (name/tel/address) — same a11y gap as auth.
- **[PASS]** Strong states, unread highlighting, contract print, sub-tabs.

### C. Code
- **[MED] The two controllers are ~80% duplicated** (store guard, validation, index, adminIndex,
  adminUpdate, `toAdminItem`). → Shared base/trait.
- **[MED] `AdoptionApply.jsx` & `FosterApply.jsx` are near-duplicate forms.** → One
  `ApplicationForm`.
- **[LOW] `AdoptionRequestsAdmin`** has 3 near-identical fetch effects (main/approved/completed)
  and 3 row components repeating the animal/applicant cell; `HomeVisitPanel` ≈ foster
  `MonitoringPanel`. → Consolidate.

### D. Performance
- **[MED] `AdoptionRequestsAdmin` fetches all three tabs' data on mount** (main + approved +
  completed) + settings = 4 requests, though only one sub-tab is visible. → Lazy-load the active
  tab. _(`AdoptionRequestsAdmin.jsx:371-426`.)_
- **[PASS]** Admin lists eager-load `animal.mainPhoto` + `user` (no N+1).

### E. Security
- **[PASS]** Contract generation is XSS-safe; `index` scoped to `user_id`; admin routes
  `role:staff`; status is server-forced to `pending` on create (not user-settable).
- **[LOW]** The 30-day guard (`exists()`→`create()`) is non-atomic (race → double application);
  no unique constraint backs it.

### F. Database
- **[LOW] Schema asymmetry:** `FosterApplication` has neither `read_at` nor `reference_no`, unlike
  `AdoptionApplication`.
- **[LOW]** No unique constraint enforcing the single-application rule (app-enforced only).

### G. API
- **[MED] Two parallel application APIs with duplicated response shapes** (adoption vs foster);
  `toAdminItem` is duplicated and overlaps the Module 3 animal serializer.
- **[LOW]** Status enums diverge (foster adds `active`) without documentation.
- **[PASS]** Correct codes: 201 create, 409 duplicate (with message), 422 validation.

### H. Edge cases
- **[PASS]** Foster `end_date:''`→null handled in both store + update; `after_or_equal:start_date`
  validated; re-apply within 30 days → friendly 409.
- **[LOW]** Applying to an unavailable animal succeeds (see A-3).

### Module 4 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 76/100 | Orphaned mark-read, foster-status gap, apply-to-adopted, no paging |
| UI | 82/100 | Clean admin + contract feature |
| UX | 80/100 | Adoption/foster asymmetry (ref no, unread) |
| Performance | 75/100 | 3-tab eager fetch on mount |
| Security | 84/100 | XSS-safe contract, good scoping; minor race |
| Accessibility | 80/100 | Semantic lists; forms miss autocomplete/htmlFor |
| Code Quality | 72/100 | Heavy adoption/foster duplication |
| Maintainability | 70/100 | Two parallel stacks to keep in sync |
| **Overall** | **≈77/100** | Solid workflow; wire the mark-read + close the status gaps |

**Top fixes (later pass):** (1) wire `adminMarkAdoptionApplicationRead` to Review [MED], (2) sync
animal status on foster transitions [MED], (3) guard `store()` against unavailable animals [MED],
(4) add pagination to the requests tables [MED], (5) lazy-load the active sub-tab [MED-perf],
(6) extract a shared application controller + apply form [MED-maint].

---

## 5. Donations

**Scope:** `DonationController` (177), `Donation` model · `Donate`, `DonationHistory`, `Receipt`,
`DonationsAdmin`, `lib/donationsApi`. (Public transparency financials covered in Module 2.)

**Testing performed:** full static trace; live tinker (confirmed the donation payload has
`donated_at` but **no** `created_at`); live `curl` confirming the public disk serves uploads
unauthenticated (HTTP 200).

### Strengths (keep)
- **Privacy-first:** `is_anonymous` defaults to **true**; donor names masked on public surfaces.
- **Clean RBAC:** list is staff-viewable, **verify is admin-only** (route + `isAdmin` prop gating).
- **Efficient admin stats:** `adminStats` is a single grouped aggregate (contrast Module 3's
  4-call stat hack); `DonationHistory` has real pagination; `Receipt` is print-friendly.
- The **donate form is well built**: presets + custom amount, conditional proof (required for
  GCash), live proof preview with `URL.revokeObjectURL` cleanup, monthly-goal progress, clear
  anonymity opt-in, success state with reference + next actions.
- Mass-assignment safe (status server-forced `pending`); errors logged with context.

### A. Functional
- **[MED] Donation dates render blank in History and Receipt.** Both read `d.created_at`, but the
  `donations` table has no `created_at` (only `donated_at`; model `$timestamps = false`). Confirmed
  live: the payload has `donated_at`, no `created_at` → the Date column/field is empty.
  (`DashboardController` maps `donated_at`→`created_at`; `DonationController::index` returns the raw
  model without that mapping.) → Expose a `date`/`donated_at` field and read it in the UI.
  _(`DonationController.php:68`, `DonationHistory.jsx:89`, `Receipt.jsx:86`.)_
- **[MED] ✅ RESOLVED — `DonationsAdmin` now paginates** (was `data?.data` only, capping at the
  newest 20 of 180). Uses the shared `Pagination` component; browser-verified page 1→2 of 9.
- **[LOW] `verify()` has no state-machine guard** — an already-verified donation can be re-verified
  or flipped verified↔rejected repeatedly, each firing a `DonationStatusChanged` notification; no
  `verified_at`/`verified_by` audit. → Guard transitions; record who/when.

### B. UI / UX
- **[LOW]** `DonationHistory` applies heavy inline styles on every `<td>`/`<th>` — repetitive;
  move to classes.
- **[LOW]** Donate form lacks `autocomplete`/`htmlFor` (less critical here).
- **[PASS]** Strong donate flow; History/Receipt have loading/empty/error states; Receipt prints.

### C. Code
- **[LOW] Helper duplication:** `statusVariant` is copy-pasted in `DonationHistory` + `Receipt`;
  `photoSrc`/`fileSrc`/`peso`/`money` are re-defined across many components (photoSrc in 6+).
  → A shared `format`/`url` util module. _(cross-cutting — appendix.)_
- **[LOW]** Preset amounts defined twice (LandingPage strings vs Donate numbers).

### D. Performance
- **[PASS]** `adminStats` = one grouped aggregate; `DonationsAdmin` = list + stats (2 calls);
  `adminIndex` eager-loads `user` (no N+1); user list paginates (12).

### E. Security
- **[MED] Donation proof screenshots are served from the public disk with no auth.**
  `FILESYSTEM_DISK=public` + `->store('donations')` → `/storage/donations/xxx`. Confirmed the
  public disk serves uploads unauthenticated (a sample upload returned HTTP 200). GCash proofs
  contain names, phone numbers, reference numbers, and balances — sensitive PII reachable by anyone
  who obtains/guesses the URL, with no access control. → Store proofs on a **private** disk and
  serve via an authorized signed route. _(`DonationController.php:33`; same risk for intake docs —
  Module 7.)_
- **[LOW]** `show()` returns the **full raw model** (all columns) to the owner — minor self-data
  over-exposure and inconsistent with the curated shapes elsewhere.
- **[LOW]** No max `amount` (`min:1` only) — a ₱999,999,999 donation is accepted and would skew
  transparency totals (data-integrity / troll vector).
- **[PASS]** verify() admin-only; show() ownership-checked; status not user-settable.

### F. Database
- **[PASS]** `donations.user_id` has an explicit FK (`constrained('users')`).
- **[LOW]** `amount` is `nullable` in the schema yet validated `required` — schema/validation
  mismatch (should be NOT NULL).
- **[LOW]** `donated_at` (useCurrent at insert) = submission time, so "raised this month" is keyed
  to submit date, not verify date (semantic note, not a defect).

### G. API
- **[MED] Inconsistent date field** across the app: this endpoint exposes `donated_at` while the
  frontend convention expects `created_at` (root of A-1).
- **[LOW]** `show()` returns the raw model while `store`/`adminIndex` return curated shapes —
  inconsistent serialization.
- **[PASS]** Correct status codes (201/403/422).

### H. Edge cases
- **[PASS]** GCash requires proof (`required_if` + frontend `required`); cash needs none; proof
  preview cleaned up on change.
- **[LOW]** Custom amount accepts decimals/huge numbers (no max; see E-3).
- **[LOW]** Re-verifying/re-rejecting not blocked (see A-3).

### Module 5 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 78/100 | Blank dates, no admin paging, verify replay |
| UI | 84/100 | Polished donate flow |
| UX | 84/100 | Clear amounts/anonymity/goal; good states |
| Performance | 86/100 | Dedicated stats endpoint; proper pagination (user side) |
| Security | 70/100 | Public proof-image exposure is the main hit |
| Accessibility | 78/100 | Forms miss autocomplete/htmlFor |
| Code Quality | 78/100 | Repeated format/url helpers |
| Maintainability | 78/100 | Helper + preset duplication |
| **Overall** | **≈79/100** | Solid, privacy-aware donations; fix the date field + proof storage |

**Top fixes (later pass):** (1) move donation proofs to a private disk + signed URLs [MED-sec],
(2) fix blank donation dates (expose/read `donated_at`) [MED], (3) paginate `DonationsAdmin` [MED],
(4) guard `verify()` transitions + add `verified_at/by` [LOW], (5) cap max amount; make `amount`
NOT NULL [LOW].

---

## 6. Rescue Reports

**Scope:** `RescueReportController` (150, incl. the orphaned `map()`), `RescueReport` model ·
`RescueReportsAdmin` (244, Detail + Triage + per-report map), the `LandingPage` rescue form
(reviewed in Module 2), `lib/rescueApi`.

**Testing performed:** full static trace; live abuse test — 6 rapid anonymous `POST /api/rescue
-reports` all returned `201` (no throttle/captcha), then cleaned up; verified mark-read is wired.

### Strengths (keep)
- **Mark-read is correctly wired** — opening Detail/Triage calls `adminMarkRescueReportRead` when
  unread. This is the model the adoption module (§4 A-1) should follow.
- **Per-report Detail map** (the rework): plots the report's exact pin, urgency-colored, with a
  clean "No precise pin was provided" fallback (no broken map).
- **Anonymous reporting** supported; solid validation (lat/long bounds, urgency enum, `image|max`);
  `created_at` is set explicitly (no blank-date bug, unlike donations); errors logged with context.
- Sensible triage workflow with next-status progression (pending→assigned→in_progress→resolved)
  and unread highlighting.

### A. Functional
- **[MED] Orphaned `map()` endpoint + route + `RescueMapTest` (dead code).** Confirmed no frontend
  caller — the global rescue-map screen was deleted and replaced by the per-report Detail map; only
  the test keeps it alive. → Remove `map()`, `GET /rescue-reports/map`, and `RescueMapTest.php`.
  _(confirms §0.1; `RescueReportController.php:95`, `routes/auth.php:64`.)_
- **[MED] ✅ RESOLVED — `RescueReportsAdmin` now paginates** (was `data?.data` only, capping at the
  newest 12). Uses the shared `Pagination` component; page is kept on mark-read/triage so an open
  Detail/Triage panel isn't collapsed (only the status filter resets to page 1).
- **[PASS]** Mark-read on Detail/Triage open works; per-report map + no-pin fallback verified.

### B. UI / UX
- **[PASS]** Strong triage UX: Detail vs Triage modes, urgency-colored marker + popup,
  one-click next-status, unread highlighting, loading/empty/error states.
- **[LOW]** Leaflet is embedded here **and** in the landing form — both eager-loaded
  (code-split opportunity; cross-cutting with Module 2/12).
- **[LOW]** `DetailPanel`/`TriagePanel` partly duplicate the contact/location/description/photo block.

### C. Code
- **[LOW]** `photoSrc` re-defined again (cross-cutting helper duplication; see §5 C-1).
- **[LOW]** `RescueReport.$fillable` includes `created_at`/`read_at`/`status` — safe today because
  store/update build explicit arrays, but mass-assignment-risky if any future code does
  `update($request->all())`. Defensive note.
- **[LOW]** `index()` returns the raw model (only `photo_url` transformed) — over-broad shape.

### D. Performance
- **[PASS]** No N+1 (flat table, no relations). Paginates server-side (just no UI; see A-2).

### E. Security
- **[MED] Public rescue `store()` has no rate limiting or captcha and accepts file uploads.**
  Confirmed live: 6 rapid anonymous POSTs → all `201`, never `429`. A bot can flood the triage
  queue and fill storage with 5 MB images (spam / cost / DoS). → Add `throttle` + a honeypot/captcha
  (the `image|max:5120` rule helps but doesn't limit volume). _(`api_public.php:31`.)_
- **[MED] Rescue photos stored on the public disk** (`rescue-reports/`) → served unauthenticated
  (same mechanism proven in §5). Lower sensitivity than financial proofs but still uncontrolled.
  → Private disk + signed URLs (shared fix with donations/intakes).
- **[PASS]** Validated input (bounds/enum/image); status server-forced `pending`; admin updates
  `role:staff`-gated and use `validated()` (no mass-assignment).

### F. Database
- **[LOW]** `$timestamps=false`; `created_at` set explicitly (populated), but no `updated_at` (no
  record of last triage change). No index on `status`/`read_at` (filters scan) — fine at scale.

### G. API
- **[LOW]** Status update is `POST /rescue-reports/{report}/status` (mutation via POST, not
  PUT/PATCH) — minor REST inconsistency the app repeats elsewhere.
- **[PASS]** 201 create, 422 validation, role-gated admin routes.

### H. Edge cases
- **[PASS]** No-pin → fallback (no broken map); anonymous → "Anonymous"; out-of-range lat/long →
  422.
- **[LOW]** `location`/`description` have no max length — unbounded text accepted.

### Module 6 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 82/100 | Orphaned map + no admin paging; core works well |
| UI | 85/100 | Good triage + per-report map |
| UX | 85/100 | Detail/Triage modes, color-coded urgency |
| Performance | 80/100 | Fine; Leaflet eager-loaded |
| Security | 68/100 | Public no-throttle write + public photo disk |
| Accessibility | 80/100 | Filter aria-label, semantic tables; map less a11y |
| Code Quality | 80/100 | Some panel duplication, shared-helper dup |
| Maintainability | 80/100 | Clean controller; remove the dead map trio |
| **Overall** | **≈80/100** | Well-executed triage; lock down the public write + remove dead map |

**Top fixes (later pass):** (1) throttle + captcha the public rescue endpoint [MED-sec],
(2) move rescue photos to a private disk [MED-sec], (3) remove the orphaned `map()` + route + test
[MED], (4) add pagination to `RescueReportsAdmin` [MED].

---

## 7. Volunteers & Intakes

**Scope:** `VolunteerController` (216), `VolunteerApplicationController` (166), `IntakeController`
(244), `Volunteer`/`VolunteerApplication`/`VolunteerTask`/`Intake`/`IntakeDocument` models ·
`VolunteersAdmin` (503), `VolunteerApply` (232), the Intakes UI (currently inside `AnimalsAdmin`),
`lib/volunteersApi`, `lib/intakesApi`.

**Testing performed:** full static trace; grep-confirmed `adminMarkVolunteerApplicationRead` is
never called (orphaned); reviewed the staff-promotion path end-to-end (controller + route + UI).

### Strengths (keep)
- **Clean role onboarding:** approving a volunteer application (or adding personnel) creates a
  `Volunteer` and promotes the account role via `forceFill` (role is **not** mass-assignable — a
  good escalation guard at the model layer); removal demotes back to `user`.
- Sensible guards: `unique:volunteers,user_id`, "already a volunteer/pending" 409s, delete-blocked
  -with-tasks (409), convert-twice blocked (409), `adminUpdate` refuses `status=converted`.
- **Intake→Animal conversion copies (not references) documents** into `animals/` so deleting the
  intake never removes the animal's photos — a genuinely careful detail.
- `VolunteersAdmin` is well-decomposed and **fetches lazily per active view** (better than §4);
  `VolunteerApply` has a clean 3-state self-service UX (dashboard / pending / form).

### A. Functional
- **[MED] Volunteer-application mark-read is orphaned** (second instance after §4). 
  `adminMarkVolunteerApplicationRead` (`volunteersApi.js:38`) is **never called**, and
  `VolunteersAdmin` doesn't import it or render any unread highlight — so the backend's
  `read_at` / `unread` filter / mark-read endpoint are **entirely unwired**. → Wire mark-read +
  unread highlighting, or remove the unused backend capability.
- **[MED] No pagination** in `PersonnelRoster`, `VolunteerRequests`, or the Intakes table — all
  `data?.data`, `paginate(20)`. Same systemic gap as Modules 3–6.
- **[LOW] `AddPersonForm` user search only covers page 1** (`adminListUsers`→`data?.data`); picking
  a user beyond the first page requires typing a query.
- **[LOW] Intake→Animal conversion drops `estimated_age`** (string "~2 years" can't map to the
  integer `age`) — the new animal has null age until staff re-enter it.

### B. UI / UX
- **[PASS]** Mode/submode tabs, `ConfirmButton` on destructive actions, semantic `dl` lists, good
  states; `VolunteerApply` 3-state flow is clean.
- **[LOW]** No unread highlight for volunteer requests (consequence of A-1) — admins can't see
  which requests are new.
- **[LOW]** Apply form lacks `autocomplete`/`htmlFor`.

### C. Code
- **[MED] The Intakes UI lives inside `AnimalsAdmin.jsx`** (~370 lines) but belongs here. →
  Extract `IntakesAdmin.jsx` (also §3 C-1).
- **[MED] Role-onboarding logic is duplicated** between `VolunteerController::adminStore` and
  `VolunteerApplicationController::adminUpdate` (forceFill role + `Volunteer` create). → Share.
- **[LOW]** More per-controller serializers (`toItem`/`toAdminItem`/`toListItem`/`toDetail`)
  repeating the user/applicant block; `URLSearchParams` builder duplicated again (cross-cutting).

### D. Performance
- **[PASS]** `adminIndex` eager-loads `user` + `tasks` (no N+1); `VolunteersAdmin` fetches only the
  active view.

### E. Security
- **[MED] Staff can grant the `staff` role (escalation within the staff tier).**
  `VolunteerController::adminStore` (route `role:staff`) plus the "Add staff" UI promote any user
  to `type=staff`, which `forceFill`s their account `role` to `staff`. A staff member can thus mint
  more staff — granting staff-level access is normally an **admin**-only action. → Gate
  `type=staff` creation behind `admin`; keep volunteer-creation at `role:staff`.
  _(`VolunteerController.php:74-97`, `routes/auth.php:135`; nav gating confirmed in Module 11.)_
- **[MED] Intake documents stored on the public disk** (`intakes/`) → served unauthenticated (same
  systemic issue as §5/§6). Docs can include animal photos + reporter PII. → Private disk + signed
  URLs.
- **[LOW] `adminConvert` isn't transactional** (Animal create → doc copies → intake update); a
  mid-way failure leaves a created animal but an unconverted intake.
- **[PASS]** role not mass-assignable (forceFill only); unique/validation guards; document/photo
  ownership checks (404 on mismatch).

### F. Database
- **[LOW]** Intake delete cascades documents in app code (not transactional).
- **[LOW]** `hours_rendered` is freely settable by staff and drives the public leaderboard/badges
  (data-integrity / trust note).

### G. API
- **[LOW]** Two onboarding paths with overlapping effects (see C-2).
- **[PASS]** Proper codes (201/409/422/404); convert idempotency (409).

### H. Edge cases
- **[PASS]** Double-add blocked; already-volunteer application blocked; convert-twice blocked;
  delete-with-tasks blocked — all with friendly 409s.
- **[LOW]** `estimated_age` not carried to the converted animal (A-4).

### Module 7 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 78/100 | Orphaned mark-read (#2), no pagination |
| UI | 84/100 | Well-structured admin + self-service |
| UX | 84/100 | Good flows; missing unread cue |
| Performance | 84/100 | Lazy per-view fetch; no N+1 |
| Security | 70/100 | Staff-mints-staff + public intake docs |
| Accessibility | 80/100 | Semantic lists; forms miss autocomplete/htmlFor |
| Code Quality | 74/100 | Intakes-in-Animals, onboarding duplication |
| Maintainability | 74/100 | Two onboarding paths to keep in sync |
| **Overall** | **≈78/100** | Capable personnel/intake system; tighten the staff-grant boundary |

**Top fixes (later pass):** (1) gate `type=staff` creation behind `admin` [MED-sec], (2) move
intake docs to a private disk [MED-sec], (3) wire/await volunteer mark-read + unread [MED],
(4) add pagination [MED], (5) extract `IntakesAdmin.jsx` + share the onboarding logic [MED-maint].

---

## 8. Visitations & Reminders

**Scope:** `VisitationController` (165), `ReminderController` (65), `SyncsHealthReminders` trait,
`Visitation`/`Reminder` models · `VisitationBooking`, `VisitationsAdmin`, `RemindersAdmin`,
`lib/visitationsApi`, `lib/remindersApi`.

**Testing performed:** full static trace; grep-confirmed `adminMarkVisitationRead` is never called
(third orphaned mark-read).

### Strengths (keep) — cleanest module so far
- **`SyncsHealthReminders` is an exemplary shared abstraction:** polymorphic
  (`remindable_type`/`id`), `updateOrCreate`, and it preserves a *completed* reminder when the due
  date is unchanged (only re-arms to pending when the date actually moves). This is the model the
  rest of the app should follow for its duplicated serializers/onboarding.
- **`ReminderController` is cron-free and correct:** an always-current upcoming+overdue feed,
  **column-limited eager load** (`animal:id,name,species`, no N+1), overdue-first ordering.
- **Solid validation** on booking (future date, ≤30 days, slot enum, 1–20 visitors) + a 30-day
  duplicate guard; `VisitationBooking` and `RemindersAdmin` have clean states and overdue
  highlighting.

### A. Functional
- **[MED] Visitation mark-read is orphaned** (third instance, after §4 and §7).
  `adminMarkVisitationRead` (`visitationsApi.js:30`) is never called and `VisitationsAdmin` shows
  no unread highlight — despite the backend comment claiming it "mirrors the adoption pattern."
  → Wire it, or drop the unused backend capability.
- **[MED] ✅ RESOLVED — `VisitationsAdmin` now paginates** (was `data?.data` only). Uses the shared
  `Pagination` component. (`RemindersAdmin` is a bounded 30-day feed — no pagination needed.)
- **[PASS]** Reminder auto-sync works end-to-end (vaccination `next_due` / medical
  `follow_up_date` → reminder, with overdue surfacing).

### B. UI / UX
- **[PASS]** Booking: slot buttons with time hints, date min/max, party size, success state,
  own-requests list; `RemindersAdmin`: overdue row highlight + badge, friendly empty state.
- **[LOW]** No unread cue for visit requests (consequence of A-1); booking form lacks
  `autocomplete`.

### C. Code
- **[LOW]** `toItem`/`toAdminItem` serializer pattern repeats (consistent with other modules).
- **[PASS]** `SyncsHealthReminders` demonstrates the right de-duplication approach.

### D. Performance
- **[PASS]** Reminder feed eager-loads a column subset (no N+1); `adminIndex` eager-loads `user`.

### E. Security
- **[PASS]** Booking is authenticated; validated input; status server-forced `pending`; admin
  routes `role:staff`; no file uploads / public exposure here; mass-assignment safe.
- **[LOW]** No rate limit on booking (authenticated → low risk).

### F. Database
- **[PASS]** `Visitation` has `read_at` + `created_at`; reminders are polymorphic with `animal_id`.
- **[LOW]** No index on `reminder_date`/`status` (feed scans) — fine at this scale.

### G. API
- **[PASS]** Consistent verbs here (reminder update = PUT; visitation update = PUT); codes correct
  (201/409/422).

### H. Edge cases
- **[LOW] Timezone off-by-one risk in booking:** `toDateInput` uses `toISOString()` (UTC) for the
  date `min`/`max`, while the backend validates `after:today` in **server** time. Near midnight /
  for PH users (UTC+8), the frontend bounds can disagree with the backend by a day. → Compute
  min/max in local time. _(`VisitationBooking.jsx:28-37`.)_
- **[PASS]** Too-early/too-far dates blocked both sides; duplicate slot → 409; 1–20 visitors
  enforced both sides.

### Module 8 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 82/100 | Orphaned mark-read (#3), no visitation paging |
| UI | 86/100 | Clean booking + reminders |
| UX | 86/100 | Slot hints, overdue highlight, good states |
| Performance | 88/100 | No N+1; efficient reminder feed |
| Security | 84/100 | Authenticated, validated, no uploads |
| Accessibility | 80/100 | Forms miss autocomplete/htmlFor |
| Code Quality | 84/100 | The reminder trait is exemplary |
| Maintainability | 84/100 | Small, focused controllers |
| **Overall** | **≈84/100** | Strongest module; just wire mark-read + paginate |

**Top fixes (later pass):** (1) wire/await visitation mark-read + unread cue [MED], (2) paginate
`VisitationsAdmin` [MED], (3) compute booking date bounds in local time [LOW].

---

## 9. Messaging & Notifications

**Scope:** `MessageController` (180), `NotificationController` (43), `AppNotification` + 9
notification classes, `Conversation`/`Message`/`Notification` models · `Messages`,
`NotificationBell`, `lib/messagesApi`, `lib/notificationsApi`.

**Testing performed:** full static trace; confirmed no `ShouldQueue` in any notification; confirmed
React-escaped message rendering (XSS-safe).

### Strengths (keep)
- **`AppNotification` is a clean dual-channel abstraction:** one event → one email + one in-app row,
  via a single `sendTo()`. Becomes real email the moment SMTP is configured (no code change).
- **Proper authorization:** `canAccess` (staff+ or conversation owner) gates show/reply;
  notification mark-read is ownership-checked (403).
- **XSS-safe:** message bodies render via React text interpolation (no `dangerouslySetInnerHTML`);
  subject/body length-capped (150/5000).
- Solid chat UX (bubbles, unread badges, auto-scroll, responsive) + a polished bell (mark-all,
  click-outside, optimistic updates).

### A. Functional
- **[LOW] Notifications don't deep-link.** `NewMessage` (and the status notifications) store `data`
  (e.g. `conversation_id`), but clicking a bell item only marks it read — it doesn't navigate to
  the entity. → Route on click using `data`. _(`NotificationBell.jsx:50`.)_
- **[LOW] Conversations are never closeable** (status is always `open`; no close action).
- **[PASS]** Start/reply/read-tracking/fan-out all work end-to-end.

### B. UI / UX
- **[PASS]** Clean chat UI + bell dropdown; responsive; auto-scroll to latest.
- **[LOW]** No deep-link from notifications (A-1).

### C. Code
- **[PASS]** `AppNotification` is a good abstraction; serializers consistent with the app pattern.

### D. Performance
- **[MED] N+1 in the conversation lists.** `listItem()` runs **2 queries per conversation** (latest
  message + unread count); `adminIndex` maps **all** conversations → `1 + 2N` queries, **with no
  pagination**, and the staff inbox **polls every 30 s**. → Aggregate with `withCount` + a
  latest-message subquery, and paginate. _(`MessageController.php:143-161, 34-45`.)_
- **[MED] Notifications are not queued.** No `ShouldQueue`, so a member message fans out a
  **synchronous** email to every staff/admin in-request — once real SMTP is set, a message to N
  staff blocks the HTTP response on N sequential sends. → `implements ShouldQueue`.
  _(`AppNotification.php:29`; affects all 9 notification types.)_
- **[LOW]** Two 30 s pollers (bell always; messages when open); the messages poll re-runs the N+1.

### E. Security
- **[PASS]** XSS-safe rendering; authorization enforced; bodies length-capped; no uploads.
- **[LOW]** `notifyOtherParty` emails every staff/admin — a member's subject/snippet lands in all
  staff inboxes (appropriate for a shared pool, but noted).
- **[LOW]** No rate limit on messaging (authenticated → low risk).

### F. Database
- **[PASS]** `Message` has proper timestamps + `read_at`; `Conversation` has `last_message_at`;
  `app_notifications.data` cast to array.
- **[LOW]** No composite index on `messages(conversation_id, read_at)` — the per-conversation
  unread-count query scans, compounding the N+1.

### G. API
- **[PASS]** Consistent verbs; codes correct (201/403/422).

### H. Edge cases
- **[PASS]** Forbidden access → 403; empty reply blocked client-side; body capped at 5000.

### Module 9 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 84/100 | Works; no deep-link / close |
| UI | 86/100 | Nice chat + bell |
| UX | 84/100 | Near-realtime via polling |
| Performance | 68/100 | N+1 list + unqueued email + polling |
| Security | 86/100 | XSS-safe, authorization solid |
| Accessibility | 78/100 | Bell aria-label; chat inputs unlabeled |
| Code Quality | 82/100 | Good notification abstraction |
| Maintainability | 82/100 | Clean controllers |
| **Overall** | **≈80/100** | Solid messaging; fix the N+1 + queue the emails |

**Top fixes (later pass):** (1) `implements ShouldQueue` on `AppNotification` [MED-perf],
(2) remove the conversation-list N+1 (`withCount` + subquery) + paginate the staff inbox [MED-perf],
(3) deep-link notifications via `data` [LOW].

---

## 10. AI Assistant & FAQ

**Scope:** `AiAssistantController` (175), `AiAssistantService` (117), `FaqMatcher` (136),
`FaqController` (60), `FaqEntry` model · `AiAssistant` widget, `FaqTrainingAdmin`,
`lib/assistantApi`, `lib/faqApi`.

**Testing performed:** full static trace; confirmed XSS-safe rendering, env-based API key, and the
unthrottled public chat route.

### Strengths (keep) — well-engineered module
- **Excellent cost design:** FAQ-first (free) → AI only when enabled **and** a key is set →
  per-visitor daily cap → identical-question cache (1h). Works at **$0** with AI off, and returns a
  consistent `{reply, source}` (`faq`/`ai`/`limit`/`disabled`).
- **`FaqMatcher` is a proper TF-IDF + cosine matcher**, cached on a signature (enabled count + max
  `updated_at`) so admin edits invalidate immediately without rebuilding vectors per request.
- **`AiAssistantService`:** grounded system prompt (real shelter facts + available animals),
  no-invention / on-topic-only instructions, trimmed history (last 6 turns, 500 chars), 20s
  timeout, null-on-failure → FAQ fallback. **API key in env**, not the settings table.
- **XSS-safe** rendering everywhere (widget, admin list, test box); the `FaqTrainingAdmin` live
  "test a question" box (showing the match `source`) is a genuinely good training UX.

### A. Functional
- **[PASS]** The full FAQ→AI→cap→cache flow works; live animal answers are grounded in real data
  with sensible temperament steering for family/apartment queries.
- **[LOW]** Greeting/intent answers are hardcoded inline in the controller **and** the trainable KB
  lives in the DB — two places maintain "what can you do"-style answers.

### B. UI / UX
- **[PASS]** Floating FAB + dialog (role="dialog", aria-labels), busy indicator, `maxLength` matched
  to the backend; admin training with live test + hits column + enabled toggle.

### C. Code
- **[PASS]** Clean separation (controller orchestration / service for the API / matcher for TF-IDF).
- **[LOW]** Inline greeting/intent vs DB KB split (A-2).

### D. Performance
- **[PASS]** Model cached (6h / signature), cosine over a small in-memory doc set; AI prompt built
  only on capped cache-miss calls.
- **[LOW]** `FaqMatcher::match` runs 2 lightweight queries (count + max `updated_at`) per call just
  to build the cache key, even on a cache hit.

### E. Security
- **[MED] Public `/api/assistant/chat` has no rate limiting.** The **free** path (TF-IDF match +
  `animalAnswer` DB queries + a `hits` increment) runs on every anonymous request — a CPU/DB DoS
  vector even when AI is off (the paid path is capped; the free path isn't). → Add `throttle`.
  _(`api_public.php:37`.)_
- **[MED] AI cost controls are weak against a determined abuser.** The daily cap keys on
  `$request->ip()` — unreliable behind Render's proxy (shared/proxy IP unless TrustProxies is set;
  NAT'd networks share one cap; trivially bypassed by IP rotation), and there is **no global daily
  spend cap** (many IPs → unbounded model spend). → Trust proxies for the real client IP + add a
  global daily cap. _(`AiAssistantController.php:51`.)_
- **[LOW] Client-supplied `history` primes the model.** It's injected as conversation turns; roles
  are coerced to user/assistant (never `system`) and content is length-capped, and the context
  holds only public shelter data (no secrets/tools) — so prompt-injection blast radius is low
  (worst case: off-topic/embarrassing output). Note only.
- **[PASS]** XSS-safe; FAQ CRUD is admin-only; message length-capped; key in env.

### F. Database
- **[LOW]** `FaqEntry.hits` increments per match (write amplification on a public endpoint); FAQ
  list unpaginated (small admin-curated set — fine).

### G. API
- **[PASS]** `{reply, source}` contract is clean and consistent; FAQ CRUD uses proper codes.

### H. Edge cases
- **[PASS]** AI off/no key → FAQ-only at $0; cap reached → graceful "limit" message; model
  failure/timeout → FAQ fallback; empty FAQ model → null (no crash); off-topic → fallback.
- **[LOW]** The widget's enable-check reads `/api/home/settings` (the over-exposed endpoint, §2 E-2)
  — when whitelisting, keep `ai_assistant_enabled` public but drop the other `ai_*` keys.

### Module 10 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 90/100 | Thoughtful FAQ→AI→cap→cache flow |
| UI | 86/100 | Clean widget + training UI |
| UX | 88/100 | Live test box is excellent |
| Performance | 84/100 | Cached model; minor per-call signature queries |
| Security | 74/100 | No chat throttle + weak cost cap (XSS-safe, key in env) |
| Accessibility | 84/100 | Dialog role + aria |
| Code Quality | 88/100 | Good separation + matcher |
| Maintainability | 86/100 | Inline vs DB answer split is the only smell |
| **Overall** | **≈85/100** | Strong, cost-aware module; add throttle + a global spend cap |

**Top fixes (later pass):** (1) throttle the public chat endpoint [MED-sec], (2) trust proxies for
the real client IP + add a global daily AI spend cap [MED-sec], (3) consolidate inline greetings
into the KB [LOW].

---

## 11. Admin Dashboard, Analytics, Reports & Settings

**Scope:** `DashboardController` (87), `AnalyticsController` (161), `ReportController` (459, CSV/PDF),
`SettingController` (also §2), `BackupController` (62) · `Dashboard.jsx` (819 hub), `AnalyticsAdmin`,
`ReportsAdmin`, `SettingsAdmin`, `lib/reportsApi`/`analyticsApi`/`dashboardApi`.

**Testing performed:** full static trace; verified `animals.created_at` exists/populated (analytics
sound); **live admin dashboard** via an ephemeral minted token — DOM-confirmed Role: admin, all 3
nav categories, 6 chart surfaces, no console errors (token revoked + localStorage cleared after).

### Strengths (keep)
- **Solid RBAC:** role-ranked nav gating (`ITEM_MIN_ROLE`, fail-closed to admin); Users/Settings/FAQ
  **double-guarded** (client render check + backend `admin` middleware); the donations report is
  admin-gated in `resolveData` (defense beyond the route).
- **`AnalyticsController`** does DB-agnostic PHP bucketing (Postgres/SQLite) with honest metrics
  (live-release rate, length of stay) and candid approximation comments.
- **`BackupController`** is well-designed: token-guarded (`hash_equals`), disabled without a token,
  honest non-zero exit codes so the cron alerts on failure.
- `DashboardController` correctly maps `donated_at`→`created_at` for the activity feed (the fix
  `DonationController::index` lacks — see §5 A-1).

### A. Functional
- **[LOW] Dead `staff` report path.** Backend `staff()`/`staffData()` + `TYPES` include `staff`, but
  there's **no route** and **no UI option** (`REPORT_TYPES`/`FILTER_CONFIG` omit it) — reachable
  only via an export `type=staff` the UI never sends. → Route+expose, or remove.
- **[LOW] Analytics "adopted this year" / length-of-stay key off application `created_at`** (no
  completion timestamp exists) — reflects application date, not completion (acknowledged in code).

### B. UI / UX
- **[PASS]** Polished collapsible categorized sidebar with aggregate badges, role chip, per-role
  tabs; clean charts/reports/settings; good a11y (aria-expanded, aria-labels, semantic nav).
- **[LOW]** User dashboard renders `UserApplications` **and** `UserProfile` together on the
  `dashboard` nav (profile appears in two nav states) — minor redundancy.
- **[LOW]** `donation_monthly_goal` placeholder "80220" (the api_public magic number, not the
  seeded 80000) — reinforces §2 H-1.

### C. Code
- **[MED] `Dashboard.jsx` (819 lines) is a mega-component** — it holds `OverviewCards`,
  `ActivityFeed`, `UserApplications`, `UserProfile`, `VolunteerTasksPanel`, `ReadOnlyAnimals` **and**
  the full 3-dashboard hub (nav, routing, polling, state). → Split into per-role dashboards +
  extracted panels.
- **[MED] It eagerly imports every admin panel** (AnimalsAdmin, AnalyticsAdmin/Recharts, all
  `*Admin`), so a regular **user** downloads the entire admin + charts bundle on `/dashboard`.
  → `React.lazy` the admin panels, gated by role (cross-cuts §2 D-1).
- **[LOW]** Inline `ROLE_RANK`/`atLeast` duplicates the backend hierarchy (kept in sync manually).

### D. Performance
- **[MED] `fetchPendingCounts` polls 7 endpoints every 30 s** (rescue/adoption/foster/donation/
  visitation/reminders/volunteer) + `NotificationBell`'s poll ≈ **8 requests / 30 s** per open admin
  dashboard. → One aggregated "pending counts" endpoint. _(`Dashboard.jsx:505-535`.)_
- **[MED] Recharts + all admin panels ship in the main bundle** (no code-split) — heavy initial load
  for everyone (see C-2). `ResponsiveContainer` also animates continuously (the page never reached
  "idle", which blocked the screenshot tool).
- **[LOW] Reports preview + PDF load ALL rows** (`*Data`→`->get()`, no limit) — a large dataset
  renders a huge table and risks DomPDF memory/timeout. → Cap preview rows; bound the PDF.
- **[LOW]** Analytics recomputed each view (no cache); `flowByMonth` plucks all 12-month rows into PHP.

### E. Security
- **[MED] CSV formula injection in exports.** `exportCsv` writes user-controlled fields (rescue
  `reporter_name`/`location`, donor/applicant names) via `fputcsv` with no neutralization — a value
  like `=HYPERLINK(...)`/`=cmd|...` executes when the CSV opens in Excel/Sheets. The rescue reporter
  fields are **anonymous public input**, so a planted payload fires when staff export. → Prefix
  leading `= + - @` with `'`. _(`ReportController.php:72-79`.)_
- **[LOW] Backup endpoint returns Artisan output** (and `$e->getMessage()`) — token-guarded, but
  leaks infra detail if the token leaks. → Return status only; log details.
- **[PASS] Confirmed the §7 staff-promotion path at the UI layer:** `ITEM_MIN_ROLE.volunteers =
  'staff'`, so Personnel (with "Add staff") is staff-visible, and the `role:staff` gate on
  `adminStore` then lets staff mint staff. (Carried as the §7 E-1 fix.)
- **[PASS]** Users/Settings/FAQ double-guarded; donations report admin-gated; settings upload
  validated; backup token constant-time compared.

### F. Database
- **[PASS]** Analytics/reports use clones for counts; eager loading avoids N+1.
- **[LOW]** No caching for analytics/overview (recomputed per request).

### G. API
- **[LOW]** `staff()` unrouted (dead); report preview endpoints unbounded.
- **[PASS]** `streamDownload` CSV + DomPDF download; consistent `{summary, columns, rows}` contract.

### H. Edge cases
- **[PASS]** Empty data graceful (0 cards, "No records", null length-of-stay → "—"); role fallback to
  `user` on auth failure; forced nav to admin sections blocked client + backend.
- **[LOW]** PDF of a huge report → potential timeout/memory (D-3).

### Module 11 scorecard
| Dimension | Score | Note |
|---|---|---|
| Functional | 84/100 | Works broadly; dead staff report, metric caveats |
| UI | 88/100 | Polished admin shell |
| UX | 86/100 | Categorized nav, badges, charts |
| Performance | 66/100 | 819-line hub, eager admin+Recharts bundle, 8 polls/30s, unbounded reports |
| Security | 74/100 | CSV injection + backup output; strong RBAC otherwise |
| Accessibility | 84/100 | aria-expanded/labels, semantic nav |
| Code Quality | 74/100 | Mega-component |
| Maintainability | 72/100 | One file spans 3 dashboards |
| **Overall** | **≈78/100** | Capable admin suite; split the hub, code-split, neutralize CSV |

**Top fixes (later pass):** (1) neutralize CSV formula injection [MED-sec], (2) lazy-load admin
panels + Recharts (code-split) [MED-perf], (3) one aggregated pending-counts endpoint [MED-perf],
(4) split `Dashboard.jsx` [MED-maint], (5) cap report preview/PDF rows [LOW], (6) route or remove the
`staff` report [LOW].

---

## 12. Shared / Cross-cutting

**Scope:** `bootstrap/app.php`, `config/cors.php`, exception handling, `routes/console.php`,
`routes/web.php`, packages, migration integrity, build/lint config · `lib/api.js`, `StatusBadge`,
`ConfirmButton`, `TypeToConfirmButton`, `theme.css`/`index.css`, dead files.

**Testing performed:** full static trace; ran the **full PHPUnit suite (111 passed, 206 assertions,
~15 s)**; confirmed axios is unused, 0 leftover `console.log`, `.env` gitignored.

### Strengths (keep)
- **Good shared components** that the app *does* reuse: `StatusBadge` (status→variant map),
  `ConfirmButton` (inline confirm), `TypeToConfirmButton` (type-`DELETE` modal, `autoFocus` +
  aria-labels). The abstractions exist — they're just under-extended (see B-2).
- **`bootstrap/app.php`** is a clean API-only setup: `api/*` → 401 **JSON** (no login-redirect 500),
  `admin`/`active`/`role` middleware aliases.
- **CORS** is env-driven with specific origins + credentials (correct pairing); `console.php`
  schedules reminders + nightly backups (with the clever HTTP backup trigger for Render Free).
- **Healthy baseline:** suite 111 green, 0 stray `console.log`, `.env`/`vendor`/`node_modules`
  gitignored.

### A. Dead code & hygiene
- **[LOW] Confirmed dead code (from §0):** `frontend/src/App.jsx` (returns null, unimported),
  `lib/dashboardMockData.js` (82 lines, unreferenced), `Middleware/dummy.php`, empty
  `Auth/EmailVerificationController` + unused `VerifyEmailRequest`, the orphaned rescue `map()` +
  route + `RescueMapTest`. → Remove all.
- **[LOW] Unused dependency:** `axios` (`frontend/package.json`) is imported nowhere — `api.js` uses
  `fetch`. → Remove.
- **[LOW] Backend web root** (`GET /`) serves Laravel's default `welcome` view on an API-only
  backend. → Replace with a redirect/health note or remove.

### B. Architecture / consistency (the through-lines)
- **[MED] No route-level code splitting.** `AppRouter` + `Dashboard` import everything eagerly →
  Leaflet + Recharts + all admin panels in the initial bundle. → `React.lazy` per route/panel.
  (§2 D-1, §11 C-2.)
- **[MED] Repeated helpers never centralized:** `photoSrc`/`fileSrc` (6+ copies), `peso`/`money`,
  `statusVariant`, the `URLSearchParams` query builder (5+ copies), date-slice formatting.
  → `lib/format.js` + `lib/url.js`. (§3/5/6/7.)
- **[MED] Per-controller serializers duplicated** (`toItem`/`toAdminItem`/`toListItem`/`toDetail`
  repeat the user/animal/applicant blocks across ~10 controllers). → API Resources / shared
  serializers. (§3/4/7/8.)
- **[LOW] Inline `<style>` string blocks** in many pages (Transparency, Matchmaker, ImpactPanel,
  AuthLayout, Donate, Adoption, AnimalDetail, Receipt, DonationHistory) mixed with CSS files. →
  Consolidate.
- **[LOW]** Inline `ROLE_RANK` (frontend) duplicates the backend `User::ROLE_RANKS` — manual sync.

### C. Security (systemic)
- **[HIGH] No rate limiting anywhere** — auth (§1), public rescue write (§6), public AI chat (§10),
  messaging. → Global `throttle` + tighter per-route limits on auth / public-write / AI.
- **[MED] All uploads on the public disk** (`FILESYSTEM_DISK=public`) served unauthenticated —
  donation proofs (§5), rescue photos (§6), intake docs (§7) are sensitive. → Private disk + signed
  URLs for sensitive uploads.
- **[PASS] Strong foundations:** mass-assignment hardening (role/status excluded, with tests),
  XSS-safe React rendering throughout, parameterized queries (no SQLi), constant-time token
  compares, 401-JSON exception handling, no client-stored secrets beyond the localStorage token.

### D. Performance (systemic)
- **[MED]** No bundle code-splitting (B-1) — one large initial chunk.
- **[MED]** Polling-heavy dashboards (≈8 req/30 s admin + bell + messages); unqueued notifications
  (§9) send email synchronously in-request.

### E. Tooling / Ops
- **[PASS]** Suite 111 green (206 assertions); Pint configured; ESLint config present.
- **[LOW]** `reminders:dispatch` has **no HTTP trigger** (unlike backup), so on a sleeping Render
  Free service the proactive reminder emails never fire — the dashboard feed is the reliable surface
  (acknowledged in code).

### Module 12 scorecard
| Dimension | Score | Note |
|---|---|---|
| Code Quality | 80/100 | Good shared components; duplication + dead files |
| Maintainability | 76/100 | Helper/serializer duplication is the main tax |
| Security | 72/100 | Systemic rate-limit + public-disk; strong foundations |
| Performance | 72/100 | No code-split; polling |
| Architecture | 78/100 | Clean API-only setup; consistent patterns |
| **Overall** | **≈77/100** | Healthy bones; centralize helpers, split bundle, lock uploads |

---
---

# Appendix

## A1. Systemic themes (recurring across modules)

| Theme | Modules | Severity | Fix |
|---|---|---|---|
| **No rate limiting** (auth, public rescue, public AI chat, messaging) | 1, 6, 9, 10 | HIGH | Global `throttle` + tight per-route caps |
| **Admin tables have no pagination** (read only page 1, cap 12–20) — ⏳ §§3,5,6,8 done (shared `Pagination`); §§4,7 pending | 3, 4, 5, 6, 7, 8 | HIGH/MED | Reuse the public `Adoption.jsx` pagination pattern |
| **Orphaned "mark-read on review"** (route+controller+helper built, never called) | 4, 7, 8 (wired only in 6) | MED | Wire the 3 helpers, or remove the unused backend capability |
| **Sensitive uploads on the public disk** (served unauthenticated) | 5, 6, 7 | MED | Private disk + signed URLs |
| **No frontend code-splitting** (Leaflet/Recharts/all admin panels eager) | 2, 11 | MED | `React.lazy` per route/panel |
| **Duplicated helpers & serializers** (`photoSrc`/`peso`/query-builder; `toItem`/`toAdminItem`) | 3–8, 11 | MED | `lib/format.js`/`lib/url.js`; API Resources |
| **Public endpoints leak internals** (raw `$e->getMessage()`, over-exposed settings, medical cost/vet) | 2, 3 | MED | Whitelist fields; generic error bodies |
| **Half-wired / dead features** (rescue `map()`, email verification, `staff` report, `App.jsx`, axios) | 0, 1, 6, 11, 12 | LOW | Remove or finish |

## A2. Files / code to remove (verified)
- `frontend/src/App.jsx` · `frontend/src/lib/dashboardMockData.js`
- `backend/app/Http/Middleware/dummy.php`
- `backend/app/Http/Controllers/Auth/EmailVerificationController.php` (empty) + `Requests/VerifyEmailRequest.php` (unused) — or implement verification
- `RescueReportController@map` + `GET /rescue-reports/map` + `tests/Feature/RescueMapTest.php`
- `axios` from `frontend/package.json`
- Root planning docs (`AI_PLAN.md`, `RESCUE_MAP_PLAN.md`, `TODO_PHASE1.md`) + `mock datas/` → move to `/docs` or delete
- `ReportController::staff()` (unrouted) — route+expose or remove
- Dead `auth.register` token branch; inline `Home.jsx` pass-through

## A3. Fix backlog by severity

**HIGH**
1. ⏳ Rate-limit auth + all public write/AI endpoints (§1, 6, 10) — brute force / spam / cost.
   **✅ auth done** (`throttle` on login/register/forgot/reset); ⬜ public rescue write (§6) + AI chat (§10) pending.
2. ✅ **DONE** — Revoke Sanctum tokens on password reset (and other sessions on change-password) (§1).
3. ⏳ Add pagination to every admin table — the Animals list silently hides 38 of 58 records (§3; also 4–8).
   **✅ §§3,5,6,8 done** (shared `components/Pagination.jsx`, browser-verified); ⬜ §4 Adoption/Foster + §7 Volunteers/Personnel/Intakes pending.

**MEDIUM (security)**
4. Move donation proofs / rescue photos / intake docs to a **private** disk + signed URLs (§5/6/7).
5. Neutralize **CSV formula injection** in report exports (§11).
6. Gate `type=staff` personnel creation behind `admin` (staff can currently mint staff) (§7).
7. Whitelist public settings keys; stop returning `$e->getMessage()` publicly; drop medical cost/vet from the public animal payload (§2, §3).
8. Trust proxies for real client IP + add a **global** AI daily spend cap (§10).

**MEDIUM (functional / perf)**
9. Wire the 3 orphaned mark-reads (§4/7/8); fix the foster→animal status sync + apply-to-unavailable guard (§4).
10. Fix blank donation dates (expose/read `donated_at`) (§5); fix register response `role:null` (§1).
11. Queue notifications (`ShouldQueue`) + remove the messaging N+1 (§9); replace the 4-call animal stat strip and the 8-poll dashboard with aggregated endpoints (§3, §11); code-split the bundle (§2/11).

**LOW** — dead-code removal (A2), debounce admin searches, autocomplete/`htmlFor` on forms, magic-number `80220`→single source, timezone-safe booking bounds, `<Link>` instead of `<a>` on internal nav, cap max donation/report rows.

## A4. Overall project scorecard

| Module | Overall /100 |
|---|---|
| 1. Auth & Profile | 78 |
| 2. Public Site / Home | 80 |
| 3. Animals & Medical | 76 |
| 4. Adoption & Foster | 77 |
| 5. Donations | 79 |
| 6. Rescue Reports | 80 |
| 7. Volunteers & Intakes | 78 |
| 8. Visitations & Reminders | 84 |
| 9. Messaging & Notifications | 80 |
| 10. AI Assistant & FAQ | 85 |
| 11. Dashboard/Analytics/Reports | 78 |
| 12. Shared / Cross-cutting | 77 |

**Project average ≈ 79/100.**

| Dimension (project) | Score | Notes |
|---|---|---|
| Functionality | 82 | Feature-rich and works; gaps are untested edge paths, not broken happy-paths (suite 111 green) |
| UI | 84 | Consistent, polished design system |
| UX | 83 | Strong public flows; admin lacks pagination/debounce in places |
| Performance | 71 | No code-splitting, polling-heavy, a few N+1 / multi-call patterns |
| Security | 73 | Excellent foundations (mass-assignment, XSS, SQLi, RBAC); systemic gaps in rate-limiting + public-disk uploads |
| Accessibility | 81 | Skip links, aria, keyboard nav in many places; forms miss autocomplete/`htmlFor` |
| Code Quality | 79 | Clean, documented; oversized hub files + helper duplication |
| Maintainability | 77 | Good patterns under-extracted (serializers, helpers) |
| **Technical debt** | **Moderate** | Concentrated in 2 mega-components + duplication; no architectural rewrites needed |

**Critical issues:** none (nothing enabling full compromise or data loss in normal operation).
**Headline risks:** missing rate-limiting (auth/public/AI) and unauthenticated sensitive uploads.
**Estimated maintainability uplift** from the MEDIUM cleanups (centralize helpers/serializers,
split the two mega-components, code-split, remove dead code): **~25–30%** less duplicated surface
to maintain, with no functional change.

---
*End of audit. Report-only — no application code was modified. Fixes are scheduled as a separate,
per-module approved pass (Phases 4–7).*
