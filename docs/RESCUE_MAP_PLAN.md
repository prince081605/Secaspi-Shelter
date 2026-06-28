# Rescue Map Rework — Progress Tracker

Goal: show the rescue location **per report** (the specific incident), not as one global map.
Add a **Detail** button in Rescue Reports that opens the report's info, photo, and the exact point
on a map — and make reporters provide a precise location so that point is accurate.

Status: **implemented & pushed** (commit `ecf620b`) — review below.
Last updated: 2026-06-27

---

## ✅ DONE

### Admin — Rescue Reports "Detail" view
- [x] New **Detail** button on each report row (next to Triage)
- [x] Detail panel shows: reporter, contact, urgency, status, location, description, notes
- [x] Shows the report **photo**
- [x] Shows a **map with a single marker at that report's exact coordinates** (colored by urgency)
- [x] Reports without a pin show a clear "no precise pin" note (no broken map)
- [x] Opening Detail marks the report read (clears the unread highlight)

### Public — precise location capture (so the map point is accurate)
- [x] More detailed Location field guidance ("house no. / street, landmark, barangay, city")
- [x] **Interactive map**: reporter taps to drop / move the exact pin
- [x] "Use my current location" button (geolocation) that also recenters the map
- [x] Pin saved with the report (`latitude` / `longitude`)

### Cleanup
- [x] Removed the standalone global **Rescue Map** screen + its sidebar item
- [x] Deleted `RescueMapAdmin.jsx` and the unused `adminRescueMap` API helper
- [x] Backend unchanged (coords already stored on submit + returned in the list)
- [x] Build passes; backend suite **111 green**

---

## ⏳ NOT DONE / OPTIONAL (your call)

- [ ] **Make the map pin required** on the public form (right now it's optional, so a report can be
      submitted without an exact point). Say the word and I'll require it.
- [ ] **Backfill coordinates** for older reports that have no pin (the 5 seeded demo reports already
      have pins; any report without one shows the "no precise pin" note).
- [ ] **Auto-geocode** the typed location into a pin (turn "123 Rizal St, Calamba" into coordinates
      automatically). Needs a geocoding service — small/optional add.

---

## How to test
1. Admin → **Requests → Rescue Reports** → click **Detail** on a report → see info, photo, and the
   map centered on its exact pin.
2. Home page → rescue form → type a detailed location, **tap the map** to pin the spot → submit →
   open it in admin **Detail** to confirm the point + photo.

## Key files
- `frontend/src/pages/admin/RescueReportsAdmin.jsx` — Detail button + per-report map
- `frontend/src/pages/user/LandingPage.jsx` — public form map picker (`RescueForm`)
- `frontend/src/pages/user/Dashboard.jsx` — removed the global Rescue Map nav
- backend `RescueReportController` / `rescue_reports` table — stores `latitude` / `longitude`
