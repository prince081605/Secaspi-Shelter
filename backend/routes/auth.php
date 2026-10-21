<?php

use App\Http\Controllers\AdoptionApplicationController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AnimalController;
use App\Http\Controllers\AnimalImportController;
use App\Http\Controllers\AnimalPhotoMatchController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BlockedReporterController;
use App\Http\Controllers\CategoryOptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FosterApplicationController;
use App\Http\Controllers\ImpactController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\MedicalRecordController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MyAdoptionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentSessionController;
use App\Http\Controllers\PostAdoptionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RescueReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShelterLocationController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VaccinationController;
use App\Http\Controllers\VisitationController;
use App\Http\Controllers\VolunteerApplicationController;
use App\Http\Controllers\VolunteerController;
use App\Models\CategoryOption;
use Illuminate\Support\Facades\Route;

// Public routes.
// Sensitive auth endpoints are rate-limited per IP (the `throttle` middleware keys guests by IP
// and namespaces the counter per route) to blunt brute force, credential stuffing, and reset
// -email bombing — a 429 is returned past the cap. forgot-password is tighter since each hit can
// send an email. `username/suggest` is a live keystroke preview, so it stays unthrottled.
// Login, register and forgot-password also need the honeypot + CAPTCHA check ('human',
// App\Http\Middleware\VerifyHuman), which runs after the rate limit.
Route::post('/login', [AuthController::class, 'login'])->middleware(['throttle:10,1', 'human']);
Route::post('/register', [AuthController::class, 'register'])->middleware(['throttle:10,1', 'human']);
Route::post('/username/suggest', [AuthController::class, 'suggestUsername']);
// Email verification. `verify-email` takes a token from the emailed link; `resend-verification`
// re-issues one and is tighter (like forgot-password) since each hit can send an email.
Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:10,1');
Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->middleware('throttle:5,1');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware(['throttle:5,1', 'human']);
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

// Protected routes. `active` runs after `auth:sanctum` so a suspended user holding
// a still-valid token is rejected (with their suspension reason) on every endpoint.
//
// Ability gating: 'perm:<ability>' (App\Http\Middleware\EnsurePermission). Who holds each
// ability is set in App\Support\Permissions — there is no all-powerful role:
//   desk.operate       — day-to-day operations. Staff and Desk Admin.
//   desk.supervise     — donation verify, expense writes, attendance corrections. Desk Admin.
//   reports.view       — operational reports, exports, analytics. Staff and Data Admin.
//   reports.financial  — the donations and expenses reports. Data Admin.
//   master_data.manage — dropdown lists, shelter areas, animal import. Data Admin.
//   users.manage / settings.manage / security.manage — System Admin.
// Routes with no role middleware are open to any authenticated user (their own data).
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Current user is exposed as GET /api/user (see routes/api.php).
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/donations', [DonationController::class, 'index']);
    Route::post('/donations', [DonationController::class, 'store']);
    Route::get('/donations/{donation}', [DonationController::class, 'show']);
    Route::post('/donations/{donation}/verify', [DonationController::class, 'verify'])->middleware('perm:desk.supervise');
    // Simulated checkout (AspinPay). Reissuing a link and paying are the donor's own
    // actions on their own donation, so ownership is enforced inside the controllers
    // rather than by role middleware. authorize/confirm are throttled for the same
    // reason /login is: they take a secret (an MPIN, a 6-digit code) and must not be
    // brute-forceable.
    Route::post('/donations/{donation}/checkout', [DonationController::class, 'checkout']);
    Route::get('/payments/{token}', [PaymentSessionController::class, 'show']);
    Route::post('/payments/{token}/authorize', [PaymentSessionController::class, 'authorize'])->middleware('throttle:20,1');
    Route::post('/payments/{token}/confirm', [PaymentSessionController::class, 'confirm'])->middleware('throttle:20,1');
    Route::post('/payments/{token}/cancel', [PaymentSessionController::class, 'cancel']);
    Route::get('/admin/donations', [DonationController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::get('/admin/donations/stats', [DonationController::class, 'adminStats'])->middleware('perm:desk.operate');

    // ---- Expense ledger ----
    // Staff can read the ledger (they need to know what the shelter is spending), but writing to
    // it is the Desk Admin's — the same line drawn at donation verification.
    // "stats" precedes {expense} so it isn't bound as an id, as with /admin/animals/stats.
    // Update is POST, not PUT (the convention elsewhere here): it accepts a receipt upload, and
    // PHP only populates $_FILES for POST — a multipart PUT arrives with an empty file bag.
    Route::get('/admin/expenses', [ExpenseController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::get('/admin/expenses/stats', [ExpenseController::class, 'adminStats'])->middleware('perm:desk.operate');
    Route::post('/admin/expenses', [ExpenseController::class, 'store'])->middleware('perm:desk.supervise');
    Route::post('/admin/expenses/{expense}', [ExpenseController::class, 'update'])->middleware('perm:desk.supervise');
    Route::delete('/admin/expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('perm:desk.supervise');
    Route::post('/animals/{animal}/adopt', [AdoptionApplicationController::class, 'store']);
    Route::post('/animals/{animal}/foster', [FosterApplicationController::class, 'store']);
    Route::get('/adoption-applications', [AdoptionApplicationController::class, 'index']);
    Route::get('/foster-applications', [FosterApplicationController::class, 'index']);
    Route::get('/admin/adoption-applications', [AdoptionApplicationController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::put('/admin/adoption-applications/{application}', [AdoptionApplicationController::class, 'adminUpdate'])->middleware('perm:desk.operate');
    Route::post('/admin/adoption-applications/{application}/read', [AdoptionApplicationController::class, 'adminMarkRead'])->middleware('perm:desk.operate');

    // ---- Post-adoption care ----
    // The adopter's side ("My Adopted Pets"): only ever their own completed adoptions, checked in
    // the controller. Sending an update uploads photos, so it's throttled like other uploads.
    Route::get('/my-adoptions', [MyAdoptionController::class, 'index']);
    Route::post('/my-adoptions/{application}/updates', [MyAdoptionController::class, 'storeUpdate'])->middleware('throttle:10,1');
    Route::post('/my-adoptions/{application}/updates/{update}/stop-sharing', [MyAdoptionController::class, 'stopSharing']);
    Route::post('/my-adoptions/{application}/return', [MyAdoptionController::class, 'requestReturn'])->middleware('throttle:5,1');
    // The family reports their pet missing, passed away, rehomed, or home again (takes effect at once).
    Route::post('/my-adoptions/{application}/status', [MyAdoptionController::class, 'reportStatus'])->middleware('throttle:5,1');
    // Staff: check-ins, updates and Happy Tails review, return requests.
    Route::get('/admin/post-adoption/summary', [PostAdoptionController::class, 'summaryIndex'])->middleware('perm:desk.operate');
    // Every adopted animal and where it is now (in home, missing, deceased, …), and changing that.
    Route::get('/admin/post-adoption/adoptions', [PostAdoptionController::class, 'adoptions'])->middleware('perm:desk.operate');
    Route::put('/admin/post-adoption/adoptions/{application}/status', [PostAdoptionController::class, 'updateStatus'])->middleware('perm:desk.operate');
    Route::get('/admin/post-adoption/check-ins', [PostAdoptionController::class, 'checkIns'])->middleware('perm:desk.operate');
    Route::put('/admin/post-adoption/check-ins/{followUp}', [PostAdoptionController::class, 'recordCheckIn'])->middleware('perm:desk.operate');
    Route::get('/admin/post-adoption/updates', [PostAdoptionController::class, 'updates'])->middleware('perm:desk.operate');
    Route::post('/admin/post-adoption/updates/{update}/read', [PostAdoptionController::class, 'markUpdateRead'])->middleware('perm:desk.operate');
    Route::put('/admin/post-adoption/updates/{update}/story', [PostAdoptionController::class, 'reviewStory'])->middleware('perm:desk.operate');
    Route::get('/admin/post-adoption/returns', [PostAdoptionController::class, 'returns'])->middleware('perm:desk.operate');
    Route::put('/admin/post-adoption/returns/{return}', [PostAdoptionController::class, 'resolveReturn'])->middleware('perm:desk.operate');
    Route::get('/admin/foster-applications', [FosterApplicationController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::put('/admin/foster-applications/{application}', [FosterApplicationController::class, 'adminUpdate'])->middleware('perm:desk.operate');
    Route::get('/rescue-reports', [RescueReportController::class, 'index'])->middleware('perm:desk.operate');
    Route::post('/rescue-reports/{report}/status', [RescueReportController::class, 'updateStatus'])->middleware('perm:desk.operate');
    Route::post('/rescue-reports/{report}/read', [RescueReportController::class, 'adminMarkRead'])->middleware('perm:desk.operate');
    // Blocking a reporter who sends fake reports is a System Admin (security) call, made from the
    // Security page; the desk closes those reports as invalid.
    Route::post('/rescue-reports/{report}/block', [BlockedReporterController::class, 'store'])->middleware('perm:security.manage');
    Route::get('/admin/blocked-reporters', [BlockedReporterController::class, 'index'])->middleware('perm:security.manage');
    Route::get('/admin/security/flagged-reporters', [BlockedReporterController::class, 'flagged'])->middleware('perm:security.manage');
    Route::delete('/admin/blocked-reporters/{blockedReporter}', [BlockedReporterController::class, 'destroy'])->middleware('perm:security.manage');
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);

    // ---- Donor & volunteer impact (gamification) ----
    Route::get('/impact/me', [ImpactController::class, 'me']);

    // ---- Messaging (member ⇄ the desk: staff and Desk Admins) ----
    Route::get('/conversations', [MessageController::class, 'index']);
    Route::post('/conversations', [MessageController::class, 'store']);
    Route::get('/conversations/{conversation}', [MessageController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'reply']);
    Route::get('/admin/conversations', [MessageController::class, 'adminIndex'])->middleware('perm:desk.operate');

    // ---- Notifications (Phase 8) ----
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // ---- Visitation scheduling ----
    Route::post('/visitations', [VisitationController::class, 'store']);
    Route::get('/visitations', [VisitationController::class, 'index']);
    Route::get('/admin/visitations', [VisitationController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::put('/admin/visitations/{visitation}', [VisitationController::class, 'adminUpdate'])->middleware('perm:desk.operate');
    Route::post('/admin/visitations/{visitation}/read', [VisitationController::class, 'adminMarkRead'])->middleware('perm:desk.operate');

    // ---- Health reminders (vaccination boosters, follow-ups) ----
    Route::get('/admin/reminders', [ReminderController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::put('/admin/reminders/{reminder}', [ReminderController::class, 'adminUpdate'])->middleware('perm:desk.operate');

    // ---- Animal management (Phase 6) ----
    Route::get('/admin/animals', [AnimalController::class, 'adminIndex'])->middleware('perm:desk.operate');
    // Must precede /admin/animals/{animal} so "stats" isn't bound as an animal id.
    Route::get('/admin/animals/stats', [AnimalController::class, 'adminStats'])->middleware('perm:desk.operate');
    // ---- Bulk import from Excel ---- Data Admin; preview checks every row and saves nothing.
    // Kept above /admin/animals/{animal} alongside "stats", for the same reason.
    // Bulk photo update (Desk Admin): match file names to animals; the photos then go through /animals/{animal}/photos.
    Route::post('/admin/animals/photos/match', [AnimalPhotoMatchController::class, 'match'])->middleware(['perm:desk.supervise', 'throttle:30,1']);
    Route::get('/admin/animals/import/template', [AnimalImportController::class, 'template'])->middleware('perm:master_data.manage');
    Route::post('/admin/animals/import/preview', [AnimalImportController::class, 'preview'])->middleware(['perm:master_data.manage', 'throttle:20,1']);
    Route::post('/admin/animals/import', [AnimalImportController::class, 'store'])->middleware(['perm:master_data.manage', 'throttle:20,1']);
    Route::get('/admin/animals/{animal}', [AnimalController::class, 'adminShow'])->middleware('perm:desk.operate');
    Route::post('/animals', [AnimalController::class, 'store'])->middleware('perm:desk.operate');
    Route::put('/animals/{animal}', [AnimalController::class, 'update'])->middleware('perm:desk.operate');
    Route::post('/animals/{animal}/archive', [AnimalController::class, 'archive'])->middleware('perm:desk.operate');
    // ---- In-shelter location (QR-based) ---- the desk moves animals between areas; the Data Admin manages the areas.
    Route::post('/animals/{animal}/location', [AnimalController::class, 'move'])->middleware('perm:desk.operate');
    Route::get('/admin/shelter-locations', [ShelterLocationController::class, 'index'])->middleware('perm:desk.operate,master_data.manage');
    Route::post('/admin/shelter-locations', [ShelterLocationController::class, 'store'])->middleware('perm:master_data.manage');
    Route::put('/admin/shelter-locations/{location}', [ShelterLocationController::class, 'update'])->middleware('perm:master_data.manage');
    Route::delete('/admin/shelter-locations/{location}', [ShelterLocationController::class, 'destroy'])->middleware('perm:master_data.manage');

    // ---- Categories (the dropdown lists: breeds, behavioral issues, medical record types, ID types, …) ----
    // Reading them is public (GET /api/categories); changing them is the Data Admin's, like shelter areas.
    $categoryTypes = array_keys(CategoryOption::TYPES);
    Route::get('/admin/categories/{type}', [CategoryOptionController::class, 'adminIndex'])->middleware('perm:master_data.manage')->whereIn('type', $categoryTypes);
    Route::post('/admin/categories/{type}', [CategoryOptionController::class, 'store'])->middleware('perm:master_data.manage')->whereIn('type', $categoryTypes);
    Route::put('/admin/categories/{type}/{option}', [CategoryOptionController::class, 'update'])->middleware('perm:master_data.manage')->whereIn('type', $categoryTypes);
    Route::delete('/admin/categories/{type}/{option}', [CategoryOptionController::class, 'destroy'])->middleware('perm:master_data.manage')->whereIn('type', $categoryTypes);
    Route::post('/animals/{animal}/photos', [AnimalController::class, 'addPhotos'])->middleware('perm:desk.operate');
    Route::delete('/animals/{animal}/photos/{photo}', [AnimalController::class, 'destroyPhoto'])->middleware('perm:desk.operate');

    // ---- Medical records & vaccinations (Phase 6) ----
    Route::post('/animals/{animal}/medical-records', [MedicalRecordController::class, 'store'])->middleware('perm:desk.operate');
    Route::put('/medical-records/{record}', [MedicalRecordController::class, 'update'])->middleware('perm:desk.operate');
    Route::delete('/medical-records/{record}', [MedicalRecordController::class, 'destroy'])->middleware('perm:desk.operate');
    Route::post('/animals/{animal}/vaccinations', [VaccinationController::class, 'store'])->middleware('perm:desk.operate');
    Route::put('/vaccinations/{vaccination}', [VaccinationController::class, 'update'])->middleware('perm:desk.operate');
    Route::delete('/vaccinations/{vaccination}', [VaccinationController::class, 'destroy'])->middleware('perm:desk.operate');

    // ---- User management (Phase 6) ---- System Admin. "summary" precedes {user} so it isn't bound as an id.
    Route::get('/admin/users', [UserController::class, 'adminIndex'])->middleware('perm:users.manage');
    Route::get('/admin/users/summary', [UserController::class, 'summary'])->middleware('perm:users.manage');
    Route::put('/admin/users/{user}', [UserController::class, 'adminUpdate'])->middleware('perm:users.manage');

    // ---- Admin dashboard overview (Phase 6) ----
    Route::get('/admin/dashboard/overview', [DashboardController::class, 'adminOverview'])->middleware('perm:desk.operate');
    Route::get('/admin/dashboard/pending-counts', [DashboardController::class, 'pendingCounts'])->middleware('perm:desk.operate');

    // ---- Insights & analytics dashboard ----
    Route::get('/admin/analytics/overview', [AnalyticsController::class, 'overview'])->middleware('perm:reports.view');

    // ---- Volunteer sign-up (user-facing) ----
    Route::post('/volunteer-applications', [VolunteerApplicationController::class, 'store']);
    Route::get('/volunteer-applications', [VolunteerApplicationController::class, 'index']);
    Route::get('/volunteer/me', [VolunteerController::class, 'me']);
    Route::post('/volunteer/tasks', [VolunteerController::class, 'requestTask']);
    // The volunteer's own task — the controller checks it belongs to them, since any signed-in
    // user could otherwise put a photo against someone else's work.
    Route::post('/volunteer/tasks/{task}/proof', [VolunteerController::class, 'submitTaskProof']);
    Route::get('/admin/volunteer-applications', [VolunteerApplicationController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::put('/admin/volunteer-applications/{application}', [VolunteerApplicationController::class, 'adminUpdate'])->middleware('perm:desk.operate');
    Route::post('/admin/volunteer-applications/{application}/read', [VolunteerApplicationController::class, 'adminMarkRead'])->middleware('perm:desk.operate');

    // ---- Volunteer management (Phase 6) ----
    Route::get('/admin/volunteers', [VolunteerController::class, 'adminIndex'])->middleware('perm:desk.operate');
    // Who can be added (plain user accounts) — the desk picks from this, not from Users.
    Route::get('/admin/volunteers/candidates', [VolunteerController::class, 'candidates'])->middleware('perm:desk.operate');
    Route::post('/admin/volunteers', [VolunteerController::class, 'adminStore'])->middleware('perm:desk.operate');
    Route::put('/admin/volunteers/{volunteer}', [VolunteerController::class, 'adminUpdate'])->middleware('perm:desk.operate');
    Route::delete('/admin/volunteers/{volunteer}', [VolunteerController::class, 'adminDestroy'])->middleware('perm:desk.operate');
    Route::post('/admin/volunteers/{volunteer}/tasks', [VolunteerController::class, 'storeTask'])->middleware('perm:desk.operate');
    Route::put('/admin/volunteer-tasks/{task}', [VolunteerController::class, 'updateTask'])->middleware('perm:desk.operate');
    Route::delete('/admin/volunteer-tasks/{task}', [VolunteerController::class, 'destroyTask'])->middleware('perm:desk.operate');

    // ---- Teams (Personnel → Teams) ---- staff run personnel, so they run teams too.
    Route::get('/admin/teams', [TeamController::class, 'index'])->middleware('perm:desk.operate');
    Route::post('/admin/teams', [TeamController::class, 'store'])->middleware('perm:desk.operate');
    Route::get('/admin/teams/{team}', [TeamController::class, 'show'])->middleware('perm:desk.operate');
    Route::put('/admin/teams/{team}', [TeamController::class, 'update'])->middleware('perm:desk.operate');
    Route::post('/admin/teams/{team}/members', [TeamController::class, 'addMember'])->middleware('perm:desk.operate');
    Route::delete('/admin/teams/{team}/members/{volunteer}', [TeamController::class, 'removeMember'])->middleware('perm:desk.operate');
    Route::post('/admin/teams/{team}/tasks', [TeamController::class, 'assignTask'])->middleware('perm:desk.operate');

    // ---- Attendance ---- people clock themselves in/out (the controller checks they have a
    // personnel record); staff see the log; only Desk Admins add, correct or delete records.
    Route::get('/volunteer/attendance', [AttendanceController::class, 'me']);
    Route::post('/volunteer/attendance/clock-in', [AttendanceController::class, 'clockIn'])->middleware('throttle:10,1');
    Route::post('/volunteer/attendance/clock-out', [AttendanceController::class, 'clockOut'])->middleware('throttle:10,1');
    Route::get('/admin/attendance', [AttendanceController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::post('/admin/volunteers/{volunteer}/attendance', [AttendanceController::class, 'adminStore'])->middleware('perm:desk.supervise');
    Route::put('/admin/attendance/{attendance}', [AttendanceController::class, 'adminUpdate'])->middleware('perm:desk.supervise');
    Route::post('/admin/attendance/{attendance}/time-out', [AttendanceController::class, 'adminTimeOut'])->middleware('perm:desk.supervise');
    Route::delete('/admin/attendance/{attendance}', [AttendanceController::class, 'adminDestroy'])->middleware('perm:desk.supervise');

    // ---- Intake management (Phase 6) ----
    Route::get('/admin/intakes', [IntakeController::class, 'adminIndex'])->middleware('perm:desk.operate');
    Route::post('/admin/intakes', [IntakeController::class, 'adminStore'])->middleware('perm:desk.operate');
    Route::get('/admin/intakes/{intake}', [IntakeController::class, 'adminShow'])->middleware('perm:desk.operate');
    Route::put('/admin/intakes/{intake}', [IntakeController::class, 'adminUpdate'])->middleware('perm:desk.operate');
    Route::delete('/admin/intakes/{intake}', [IntakeController::class, 'adminDestroy'])->middleware('perm:desk.operate');
    Route::post('/admin/intakes/{intake}/convert', [IntakeController::class, 'adminConvert'])->middleware('perm:desk.operate');
    Route::post('/admin/intakes/{intake}/documents', [IntakeController::class, 'addDocuments'])->middleware('perm:desk.operate');
    Route::delete('/admin/intakes/{intake}/documents/{document}', [IntakeController::class, 'destroyDocument'])->middleware('perm:desk.operate');

    // ---- Site settings ---- System Admin
    Route::get('/admin/settings', [SettingController::class, 'adminIndex'])->middleware('perm:settings.manage');
    Route::put('/admin/settings', [SettingController::class, 'adminUpdate'])->middleware('perm:settings.manage');
    Route::post('/admin/settings/image', [SettingController::class, 'adminUploadImage'])->middleware('perm:settings.manage');

    // ---- Reports & exports (Phase 7) ----
    // Operational reports are for staff and the Data Admin; the financial reports (donations,
    // expenses) are the Data Admin's alone. The export endpoints are open to reports.view, but
    // ReportController blocks the financial types without reports.financial (see resolveData()).
    Route::get('/admin/reports/adoption', [ReportController::class, 'adoption'])->middleware('perm:reports.view');
    Route::get('/admin/reports/animals', [ReportController::class, 'animals'])->middleware('perm:reports.view');
    Route::get('/admin/reports/medical', [ReportController::class, 'medical'])->middleware('perm:reports.view');
    Route::get('/admin/reports/donations', [ReportController::class, 'donations'])->middleware('perm:reports.financial');
    Route::get('/admin/reports/expenses', [ReportController::class, 'expenses'])->middleware('perm:reports.financial');
    Route::get('/admin/reports/volunteers', [ReportController::class, 'volunteers'])->middleware('perm:reports.view');
    Route::get('/admin/reports/staff', [ReportController::class, 'staff'])->middleware('perm:reports.view');
    Route::get('/admin/reports/attendance', [ReportController::class, 'attendance'])->middleware('perm:reports.view');
    Route::get('/admin/reports/rescue', [ReportController::class, 'rescue'])->middleware('perm:reports.view');
    Route::get('/admin/reports/export/csv', [ReportController::class, 'exportCsv'])->middleware('perm:reports.view');
    Route::get('/admin/reports/export/pdf', [ReportController::class, 'exportPdf'])->middleware('perm:reports.view');
});
