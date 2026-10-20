# Admin roles: Desk, Data and System Admin

Sir Miro (panel) asked for two things:
- Remove the all-powerful admin role.
- Split its powers among three admin types, each limited to its own area.

Before this change the system had one full-access role, `admin`. It passed every permission check, and there was no separate role named "Super Admin". That `admin` role is gone, and no admin type replaces it with full access. The other roles (User, Volunteer, Staff) keep exactly the access they had.

| Admin type | Its area | What it can't do |
|---|---|---|
| **Desk Admin** | Day-to-day front-desk work: requests, records, donations, expenses, personnel, helping people. | Change the dropdown lists. Manage users, roles or settings. |
| **Data Admin** | The dropdown lists and master data (breeds, record types, ID types, volunteer categories, shelter areas), animal import from Excel, and every report. | Desk operations. Manage users, roles or settings. |
| **System Admin** | Users and roles, site settings, security (blocking reporters who send fake rescue reports). | Desk operations. Edit the dropdown lists. |

## Where it lives in the code
- **One source of truth:** `backend/app/Support/Permissions.php` maps each *ability* to the roles that hold it.
- **Routes:** each one is gated with `perm:<ability>` (`App\Http\Middleware\EnsurePermission`).
- **Controllers:** they check `$user->hasPermission('...')`.
- **Frontend:** the signed-in user arrives with `permissions` and `role_label`. The frontend reads those (`frontend/src/lib/permissions.js`) and never keeps its own copy of the map.

| Ability | Roles |
|---|---|
| `desk.operate` | Staff, Desk Admin |
| `desk.supervise` | Desk Admin |
| `reports.view` | Staff, Data Admin |
| `reports.financial` | Data Admin |
| `master_data.manage` | Data Admin |
| `users.manage` | System Admin |
| `settings.manage` | System Admin |
| `security.manage` | System Admin |

## Permission table
Cells show view, create, edit, delete, approve, export, or none. Staff are shown for reference only; their access did not change.

| Feature | Desk Admin | Data Admin | System Admin | Staff (unchanged) |
|---|---|---|---|---|
| Dashboard: pending counts, overview cards, activity feed | view | none | none | view |
| Analytics charts | none | view | none | view |
| Animals: records, photos, archive, QR, location moves | view, create, edit, delete (archive) | none | none | view, create, edit, delete (archive) |
| Bulk photo update | edit | none | none | none |
| Animal import from Excel | none | create | none | none |
| Medical records, vaccinations | view, create, edit, delete | none | none | view, create, edit, delete |
| Health reminders | view, edit | none | none | view, edit |
| Intakes, documents, convert to animal | view, create, edit, delete, approve | none | none | view, create, edit, delete, approve |
| Adoption and foster applications | view, approve | none | none | view, approve |
| Post-adoption: check-ins, updates, stories, returns | view, edit, approve | none | none | view, edit, approve |
| Rescue reports: triage, status, mark invalid | view, edit | none | none | view, edit |
| Visit requests | view, approve | none | none | view, approve |
| Messages inbox | view, create (reply) | none | none | view, create (reply) |
| Donations list and stats | view | none | none | view |
| Donation verify / reject | approve | none | none | none |
| Expense ledger | view | none | none | view |
| Expenses record / edit / delete | create, edit, delete | none | none | none |
| Personnel: add volunteer, remove, tasks, teams, volunteer applications | view, create, edit, delete, approve | none | none | view, create, edit, delete, approve |
| Task verification, hours rendered | approve, edit | none | none | none |
| Attendance log | view | none | none | view |
| Attendance corrections | create, edit, delete | none | none | none |
| Dropdown lists (breeds, behavioral issues, record types, ID types, volunteer categories) | view (uses them in forms) | view, create, edit (incl. deactivate), delete | none | view (uses them) |
| Shelter areas | view (uses them) | view, create, edit, delete | none | view (uses them) |
| Operational reports | none | view, export | none | view, export |
| Financial reports (donations, expenses) | none | view, export | none | none |
| Users: list, change role, suspend / reactivate | none | none | view, edit | none |
| Settings: shelter info, page content, images, funding goals | none | none | view, edit | none |
| Security: reporters flagged for fake reports, block / unblock | none | none | view, create, delete | none |
| Audit logs | gap: the feature does not exist | | | |

## What the old admin role could do, and who owns it now

| Former admin-only power | New owner |
|---|---|
| Verify / reject donations; open any donor's donation | Desk Admin |
| Record / edit / delete expenses | Desk Admin |
| Add, correct, time-out, delete attendance records | Desk Admin |
| Verify a task as completed; change hours rendered | Desk Admin |
| Bulk photo update | Desk Admin |
| Daily health-reminder notifications | Desk Admin |
| Messages and post-adoption alerts (staff kept theirs) | Desk Admin |
| All the day-to-day desk work the admin could also do | Desk Admin (staff kept theirs) |
| Categories (dropdown lists): add, rename, hide, delete | Data Admin |
| Shelter areas: add, rename, remove | Data Admin |
| Animal import from Excel | Data Admin |
| Donations and expenses reports and their export | Data Admin |
| Operational reports and analytics (staff kept theirs) | Data Admin |
| Users: list, change roles, suspend / reactivate | System Admin |
| Settings (shelter info, content, images, goals) | System Admin |
| Block / unblock reporters | System Admin (new Security page) |
| Adding staff (was "+ Add staff" in Personnel) | System Admin, from Users → role |

## Actions that touch two areas: one owner each

| Action | Owner | Why |
|---|---|---|
| Approving a volunteer application, which grants the volunteer role | Desk Admin | The role is a result of approving a request, which is desk work. |
| Removing someone from Personnel, which sets them back to User | Desk Admin | Staff always could. It ends their personnel record. |
| Making someone Staff or any admin type | System Admin | It is a role change, made only on the Users page. |
| Funding goals and cost per meal | System Admin | They live in Settings. The Data Admin sees them through the reports. |
| Import from Excel, which creates animal records | Data Admin | It is a bulk data load, checked row by row. |
| Bulk photo update | Desk Admin | It updates animal records. After an import the Data Admin is told a Desk Admin adds the photos. |
| Donation verification | Desk Admin | It is a day-to-day transaction, not report data. |
| Blocking a reporter | System Admin | It is a security decision. The desk closes the fake report; the System Admin blocks the reporter. |

## Overlaps (flagged)
- **Dropdown lists and shelter areas:** Desk and Data both *view* them. This is intended: the desk uses the lists, and only the Data Admin changes them.
- **Between admin types:** no two admin types share any create, edit, delete, approve or export action. An automated test checks that no ability is held by two admin types.
- **Staff and Desk Admin** overlap on desk work, and **staff and Data Admin** overlap on operational reports and analytics. Both overlaps stay because staff access must not change.

## Gaps (flagged)
- **Audit logs:** the system has no audit log, so nobody owns it. It was left out on purpose.
- **Desk Admin and reports:** a Desk Admin can't see reports, although staff can. This is by design.
- **Data Admin and record fixes:** a Data Admin can't correct a wrong record directly (no desk work). A Desk Admin makes the fix.
- **Adding vs removing staff:** a Desk Admin can remove a staff member from Personnel, but only a System Admin can make someone staff.
- **Out of scope:** no login lockout and no two-factor sign-in.

## Who became what
Every account that had the old `admin` role became a **System Admin**. That was the approved mapping, done by migration `2026_10_20_000000_split_admin_role`, which writes each converted account to the log. Locally that was one account, "Admin" (Admin@gmail.com).

There must always be at least one System Admin, because only a System Admin can hand out roles. Nobody can change their own role or suspend themselves, so a System Admin can only be removed by another System Admin.

**After deploying:**
1. Sign in as the System Admin.
2. Open **System → Users** and give the Desk Admin and Data Admin roles to the right people.
3. To recover a lost System Admin from the server's command line, run `php artisan secaspi:make-admin <email> system`. The type (`desk`, `data` or `system`) is required.

## Test results (2026-10-20)
**Automated (`php artisan test`, 258 passing).** `AuthorizationTest` reads every route and checks that each `/api/admin` route has a `perm:` gate. For all six roles it checks:
- 403 on every route the role's abilities don't allow
- not blocked on every route they do allow
- 401 for guests

`AdminRolesTest` covers:
- the ability map above
- that no role has full access and no two admin types share an ability
- that the old `admin` role is rejected and gives nothing
- the migration
- role changes, including that nobody can change their own role
- the Security page
- the desk's volunteer picker
- that desk notifications reach staff and Desk Admins only, never suspended accounts

**In the browser (headless Chrome, local app), signed in as each role:**
- **User and Volunteer:** only the User Dashboard.
- **Staff:** the same menu as before (desk work plus operational reports). No Verify, no Record expense, no financial reports.
- **Desk Admin:**
  - Desk menu only, with no Reports.
  - Can verify and reject donations, record expenses and update photos in bulk.
  - "Staff are added by a System Admin" shows in place of "+ Add staff".
  - No Block button in rescue reports.
- **Data Admin:** Categories (all lists, including shelter areas), Import animals, and Reports including Donations and Expenses. The home screen shows the analytics.
- **System Admin:** Users (the role list shows all six roles, with what each admin type does), Security and Settings (Shelter info and My account only). The home screen shows accounts by role.
- **Direct API requests with each role's token** gave 403 everywhere outside that role's area.
- **"Super Admin" appears on no screen.** No role value `admin` remains in the code.
