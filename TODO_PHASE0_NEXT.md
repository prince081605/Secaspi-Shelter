# Phase 0 next steps (Role-based access + React guards + layout)

## 1) Role-based access (backend)
- [ ] Implement authorization checks:
  - Only users with `role=admin` can access admin/protected-admin endpoints.
  - Only `role=volunteer` can access volunteer endpoints.
- [ ] Update `AuthController@me` response to include `role` and `email_verified` (already partially present).
- [ ] Add middleware/ability helpers (ex: `role:admin`, `role:volunteer`, `role:user`).

## 2) API base client + route guards (frontend)
- [ ] Update `frontend/src/lib/api.js` to support token auth.
  - Current: uses cookies with `credentials: include`
  - Needed: send Authorization: Bearer token header
- [ ] Update `frontend/src/lib/auth.js` to store the issued token from `/api/login`.
  - Actually: login returns token but it's NOT stored - auth.js just returns the data
- [ ] Update `AppRouter.jsx` `RequireAuth` to use token auth (instead of cookie-only `credentials: 'include'`).
- [ ] Add `RequireRole` guard component to protect dashboard/admin pages.

## 3) Initial navigation/layout (frontend)
- [ ] Create layout components for:
  - Public shell: header/footer
  - Protected shell: includes logout and role-aware nav
- [ ] Wire routes:
  - `/dashboard` under protected layout
  - `/admin` under admin layout (optional placeholder)

**STATUS**: Phase 0 incomplete - token handling not wired in frontend API client.