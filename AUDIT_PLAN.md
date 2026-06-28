# SECASPI Shelter — Full Professional Software Audit (Plan)

> This is a read-only copy of the approved audit plan, placed in the project root so you can
> view it. The live findings document is **`AUDIT.md`** (same folder).

## Context

A professional, multi-disciplinary audit (Senior Full-Stack, QA, UI/UX, Security, Performance,
Code Review) of the **entire** SECASPI Shelter project — disciplined, module-by-module, with
explicit approval gates.

The system is a **Laravel 13 / PHP 8.3 REST API** (Sanctum token auth, 26 controllers, 22
models, 31 migrations, 15 test files) plus a **React 19 / Vite SPA** (react-router 7, Leaflet,
Recharts, axios, ~10.6k LOC). All admin panels mount inside a single `Dashboard.jsx` hub via an
`activeNav` flag; public + user routing lives in `AppRouter.jsx`.

**Confirmed decisions:**
1. **Report-only first.** No code changes during the audit. Every finding (module → problem →
   fix) is captured in **`AUDIT.md`**. We fix later, module by module.
2. **Run the app live** for testing (Laravel + Vite), driving the real UI, plus PHPUnit + static
   tracing.
3. **Foundational-first order**, one module at a time, **waiting for approval after each module**.

## Per-module procedure
Trace every file → live-test features → run relevant tests → audit across **A** Functional,
**B** UI/UX, **C** Code, **D** Performance, **E** Security, **F** Database, **G** API, **H** Edge
cases → write the module's `AUDIT.md` section (Problem → Why → Fix → Severity) → **stop for
approval**.

## 12 modules (foundational order)
1. Auth & Profile
2. Public Site / Home
3. Animals & Medical
4. Adoption & Foster
5. Donations
6. Rescue Reports
7. Volunteers & Intakes
8. Visitations & Reminders
9. Messaging & Notifications
10. AI Assistant & FAQ
11. Admin Dashboard, Analytics, Reports & Settings
12. Shared / Cross-cutting

## AUDIT.md structure
§0 Global findings → one section per module (8 dimensions + a /100 scorecard each) → appendix
(files to remove · duplicate logic · unused packages · fix backlog by severity).

## Live-run setup
Local DB is MySQL (`secaspi_shelter_mock`); deps installed. Boot `php artisan migrate --seed` →
`php artisan serve` (:8000); `npm run dev` (Vite); drive UI via browser automation; run
`php artisan test`. Fall back to SQLite (phpunit config) if MySQL is down.

## Status
- [x] Plan approved
- [x] §0 Global findings + all 12 modules audited → see `AUDIT.md`
- [x] Appendix complete (systemic themes, files-to-remove, severity backlog, project scorecard)
- [ ] Fixing pass (Phases 4–7), scheduled separately after the audit is complete

**Audit result: project average ≈ 79/100. No critical issues. Headline risks: missing
rate-limiting (auth/public/AI) + unauthenticated sensitive uploads.**
