<?php

use App\Http\Controllers\AdoptionApplicationController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AnimalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\FosterApplicationController;
use App\Http\Controllers\ImpactController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\MedicalRecordController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RescueReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VaccinationController;
use App\Http\Controllers\VisitationController;
use App\Http\Controllers\VolunteerApplicationController;
use App\Http\Controllers\VolunteerController;
use Illuminate\Support\Facades\Route;

// Public routes.
// Sensitive auth endpoints are rate-limited per IP (the `throttle` middleware keys guests by IP
// and namespaces the counter per route) to blunt brute force, credential stuffing, and reset
// -email bombing — a 429 is returned past the cap. forgot-password is tighter since each hit can
// send an email. `username/suggest` is a live keystroke preview, so it stays unthrottled.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/username/suggest', [AuthController::class, 'suggestUsername']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

// Protected routes. `active` runs after `auth:sanctum` so a suspended user holding
// a still-valid token is rejected (with their suspension reason) on every endpoint.
//
// Role gating (see App\Http\Middleware\EnsureRole, hierarchy user<volunteer<staff<admin):
//   'role:staff' — day-to-day operations (animals, requests, rescues, donations view,
//                  personnel, reports, intakes). Staff and admin clear this.
//   'admin'      — sensitive/admin-only (user management, settings, donation verify,
//                  the financial donations report). Admin only.
// Routes with no role middleware are open to any authenticated user (their own data).
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Current user is exposed as GET /api/user (see routes/api.php).
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/donations', [DonationController::class, 'index']);
    Route::post('/donations', [DonationController::class, 'store']);
    Route::get('/donations/{donation}', [DonationController::class, 'show']);
    Route::post('/donations/{donation}/verify', [DonationController::class, 'verify'])->middleware('admin');
    Route::get('/admin/donations', [DonationController::class, 'adminIndex'])->middleware('role:staff');
    Route::get('/admin/donations/stats', [DonationController::class, 'adminStats'])->middleware('role:staff');
    Route::post('/animals/{animal}/adopt', [AdoptionApplicationController::class, 'store']);
    Route::post('/animals/{animal}/foster', [FosterApplicationController::class, 'store']);
    Route::get('/adoption-applications', [AdoptionApplicationController::class, 'index']);
    Route::get('/foster-applications', [FosterApplicationController::class, 'index']);
    Route::get('/admin/adoption-applications', [AdoptionApplicationController::class, 'adminIndex'])->middleware('role:staff');
    Route::put('/admin/adoption-applications/{application}', [AdoptionApplicationController::class, 'adminUpdate'])->middleware('role:staff');
    Route::post('/admin/adoption-applications/{application}/read', [AdoptionApplicationController::class, 'adminMarkRead'])->middleware('role:staff');
    Route::get('/admin/foster-applications', [FosterApplicationController::class, 'adminIndex'])->middleware('role:staff');
    Route::put('/admin/foster-applications/{application}', [FosterApplicationController::class, 'adminUpdate'])->middleware('role:staff');
    Route::get('/rescue-reports', [RescueReportController::class, 'index'])->middleware('role:staff');
    Route::post('/rescue-reports/{report}/status', [RescueReportController::class, 'updateStatus'])->middleware('role:staff');
    Route::post('/rescue-reports/{report}/read', [RescueReportController::class, 'adminMarkRead'])->middleware('role:staff');
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);

    // ---- Donor & volunteer impact (gamification) ----
    Route::get('/impact/me', [ImpactController::class, 'me']);

    // ---- Messaging (member ⇄ staff/admin) ----
    Route::get('/conversations', [MessageController::class, 'index']);
    Route::post('/conversations', [MessageController::class, 'store']);
    Route::get('/conversations/{conversation}', [MessageController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [MessageController::class, 'reply']);
    Route::get('/admin/conversations', [MessageController::class, 'adminIndex'])->middleware('role:staff');

    // ---- Notifications (Phase 8) ----
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    // ---- Visitation scheduling ----
    Route::post('/visitations', [VisitationController::class, 'store']);
    Route::get('/visitations', [VisitationController::class, 'index']);
    Route::get('/admin/visitations', [VisitationController::class, 'adminIndex'])->middleware('role:staff');
    Route::put('/admin/visitations/{visitation}', [VisitationController::class, 'adminUpdate'])->middleware('role:staff');
    Route::post('/admin/visitations/{visitation}/read', [VisitationController::class, 'adminMarkRead'])->middleware('role:staff');

    // ---- Health reminders (vaccination boosters, follow-ups) ----
    Route::get('/admin/reminders', [ReminderController::class, 'adminIndex'])->middleware('role:staff');
    Route::put('/admin/reminders/{reminder}', [ReminderController::class, 'adminUpdate'])->middleware('role:staff');

    // ---- Animal management (Phase 6) ----
    Route::get('/admin/animals', [AnimalController::class, 'adminIndex'])->middleware('role:staff');
    // Must precede /admin/animals/{animal} so "stats" isn't bound as an animal id.
    Route::get('/admin/animals/stats', [AnimalController::class, 'adminStats'])->middleware('role:staff');
    Route::get('/admin/animals/{animal}', [AnimalController::class, 'adminShow'])->middleware('role:staff');
    Route::post('/animals', [AnimalController::class, 'store'])->middleware('role:staff');
    Route::put('/animals/{animal}', [AnimalController::class, 'update'])->middleware('role:staff');
    Route::post('/animals/{animal}/archive', [AnimalController::class, 'archive'])->middleware('role:staff');
    Route::delete('/animals/{animal}', [AnimalController::class, 'destroy'])->middleware('role:staff');
    Route::post('/animals/{animal}/photos', [AnimalController::class, 'addPhotos'])->middleware('role:staff');
    Route::delete('/animals/{animal}/photos/{photo}', [AnimalController::class, 'destroyPhoto'])->middleware('role:staff');

    // ---- Medical records & vaccinations (Phase 6) ----
    Route::post('/animals/{animal}/medical-records', [MedicalRecordController::class, 'store'])->middleware('role:staff');
    Route::put('/medical-records/{record}', [MedicalRecordController::class, 'update'])->middleware('role:staff');
    Route::delete('/medical-records/{record}', [MedicalRecordController::class, 'destroy'])->middleware('role:staff');
    Route::post('/animals/{animal}/vaccinations', [VaccinationController::class, 'store'])->middleware('role:staff');
    Route::put('/vaccinations/{vaccination}', [VaccinationController::class, 'update'])->middleware('role:staff');
    Route::delete('/vaccinations/{vaccination}', [VaccinationController::class, 'destroy'])->middleware('role:staff');

    // ---- User management (Phase 6) ---- admin only
    Route::get('/admin/users', [UserController::class, 'adminIndex'])->middleware('admin');
    Route::put('/admin/users/{user}', [UserController::class, 'adminUpdate'])->middleware('admin');

    // ---- Admin dashboard overview (Phase 6) ----
    Route::get('/admin/dashboard/overview', [DashboardController::class, 'adminOverview'])->middleware('role:staff');
    Route::get('/admin/dashboard/pending-counts', [DashboardController::class, 'pendingCounts'])->middleware('role:staff');

    // ---- Insights & analytics dashboard ----
    Route::get('/admin/analytics/overview', [AnalyticsController::class, 'overview'])->middleware('role:staff');

    // ---- Volunteer sign-up (user-facing) ----
    Route::post('/volunteer-applications', [VolunteerApplicationController::class, 'store']);
    Route::get('/volunteer-applications', [VolunteerApplicationController::class, 'index']);
    Route::get('/volunteer/me', [VolunteerController::class, 'me']);
    Route::post('/volunteer/tasks', [VolunteerController::class, 'requestTask']);
    Route::get('/admin/volunteer-applications', [VolunteerApplicationController::class, 'adminIndex'])->middleware('role:staff');
    Route::put('/admin/volunteer-applications/{application}', [VolunteerApplicationController::class, 'adminUpdate'])->middleware('role:staff');
    Route::post('/admin/volunteer-applications/{application}/read', [VolunteerApplicationController::class, 'adminMarkRead'])->middleware('role:staff');

    // ---- Volunteer management (Phase 6) ----
    Route::get('/admin/volunteers', [VolunteerController::class, 'adminIndex'])->middleware('role:staff');
    Route::post('/admin/volunteers', [VolunteerController::class, 'adminStore'])->middleware('role:staff');
    Route::put('/admin/volunteers/{volunteer}', [VolunteerController::class, 'adminUpdate'])->middleware('role:staff');
    Route::delete('/admin/volunteers/{volunteer}', [VolunteerController::class, 'adminDestroy'])->middleware('role:staff');
    Route::post('/admin/volunteers/{volunteer}/tasks', [VolunteerController::class, 'storeTask'])->middleware('role:staff');
    Route::put('/admin/volunteer-tasks/{task}', [VolunteerController::class, 'updateTask'])->middleware('role:staff');
    Route::delete('/admin/volunteer-tasks/{task}', [VolunteerController::class, 'destroyTask'])->middleware('role:staff');

    // ---- Intake management (Phase 6) ----
    Route::get('/admin/intakes', [IntakeController::class, 'adminIndex'])->middleware('role:staff');
    Route::post('/admin/intakes', [IntakeController::class, 'adminStore'])->middleware('role:staff');
    Route::get('/admin/intakes/{intake}', [IntakeController::class, 'adminShow'])->middleware('role:staff');
    Route::put('/admin/intakes/{intake}', [IntakeController::class, 'adminUpdate'])->middleware('role:staff');
    Route::delete('/admin/intakes/{intake}', [IntakeController::class, 'adminDestroy'])->middleware('role:staff');
    Route::post('/admin/intakes/{intake}/convert', [IntakeController::class, 'adminConvert'])->middleware('role:staff');
    Route::post('/admin/intakes/{intake}/documents', [IntakeController::class, 'addDocuments'])->middleware('role:staff');
    Route::delete('/admin/intakes/{intake}/documents/{document}', [IntakeController::class, 'destroyDocument'])->middleware('role:staff');

    // ---- AI assistant FAQ knowledge base (training) ---- admin only
    Route::get('/admin/faqs', [FaqController::class, 'adminIndex'])->middleware('admin');
    Route::post('/admin/faqs', [FaqController::class, 'store'])->middleware('admin');
    Route::put('/admin/faqs/{faq}', [FaqController::class, 'update'])->middleware('admin');
    Route::delete('/admin/faqs/{faq}', [FaqController::class, 'destroy'])->middleware('admin');

    // ---- Site settings ---- admin only
    Route::get('/admin/settings', [SettingController::class, 'adminIndex'])->middleware('admin');
    Route::put('/admin/settings', [SettingController::class, 'adminUpdate'])->middleware('admin');
    Route::post('/admin/settings/image', [SettingController::class, 'adminUploadImage'])->middleware('admin');

    // ---- Reports & exports (Phase 7) ----
    // Operational reports are staff-accessible; the donations (financial) report stays
    // admin-only. The export endpoints are staff-accessible but ReportController blocks
    // type=donations for non-admins (see resolveData()).
    Route::get('/admin/reports/adoption', [ReportController::class, 'adoption'])->middleware('role:staff');
    Route::get('/admin/reports/animals', [ReportController::class, 'animals'])->middleware('role:staff');
    Route::get('/admin/reports/medical', [ReportController::class, 'medical'])->middleware('role:staff');
    Route::get('/admin/reports/donations', [ReportController::class, 'donations'])->middleware('admin');
    Route::get('/admin/reports/volunteers', [ReportController::class, 'volunteers'])->middleware('role:staff');
    Route::get('/admin/reports/rescue', [ReportController::class, 'rescue'])->middleware('role:staff');
    Route::get('/admin/reports/export/csv', [ReportController::class, 'exportCsv'])->middleware('role:staff');
    Route::get('/admin/reports/export/pdf', [ReportController::class, 'exportPdf'])->middleware('role:staff');
});
