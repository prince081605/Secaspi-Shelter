<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\AdoptionApplicationController;
use App\Http\Controllers\FosterApplicationController;
use App\Http\Controllers\RescueReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\AnimalController;
use App\Http\Controllers\MedicalRecordController;
use App\Http\Controllers\VaccinationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\VolunteerController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\NotificationController;


// Public routes
Route::post('/login',           [AuthController::class, 'login']);
Route::post('/register',        [AuthController::class, 'register']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password',  [AuthController::class, 'resetPassword']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Current user is exposed as GET /api/user (see routes/api.php).
    Route::post('/logout',      [AuthController::class, 'logout']);
    Route::get('/donations',                 [DonationController::class, 'index']);
    Route::post('/donations',                [DonationController::class, 'store']);
    Route::get('/donations/{donation}',      [DonationController::class, 'show']);
    Route::post('/donations/{donation}/verify', [DonationController::class, 'verify'])->middleware('admin');
    Route::get('/admin/donations',              [DonationController::class, 'adminIndex'])->middleware('admin');
    Route::get('/admin/donations/stats',         [DonationController::class, 'adminStats'])->middleware('admin');
    Route::post('/animals/{animal}/adopt',  [AdoptionApplicationController::class, 'store']);
    Route::post('/animals/{animal}/foster', [FosterApplicationController::class, 'store']);
    Route::get('/adoption-applications',    [AdoptionApplicationController::class, 'index']);
    Route::get('/foster-applications',      [FosterApplicationController::class, 'index']);
    Route::get('/admin/adoption-applications',        [AdoptionApplicationController::class, 'adminIndex'])->middleware('admin');
    Route::put('/admin/adoption-applications/{application}', [AdoptionApplicationController::class, 'adminUpdate'])->middleware('admin');
    Route::get('/admin/foster-applications',          [FosterApplicationController::class, 'adminIndex'])->middleware('admin');
    Route::put('/admin/foster-applications/{application}',   [FosterApplicationController::class, 'adminUpdate'])->middleware('admin');
    Route::get('/rescue-reports',                    [RescueReportController::class, 'index'])->middleware('admin');
    Route::post('/rescue-reports/{report}/status',   [RescueReportController::class, 'updateStatus'])->middleware('admin');
    Route::put('/profile',                           [ProfileController::class, 'update']);
    Route::post('/profile/change-password',          [ProfileController::class, 'changePassword']);

    // ---- Notifications (Phase 8) ----
    Route::get('/notifications',                     [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all',           [NotificationController::class, 'markAllRead']);

    // ---- Animal management (Phase 6) ----
    Route::get('/admin/animals',                     [AnimalController::class, 'adminIndex'])->middleware('admin');
    Route::get('/admin/animals/{animal}',            [AnimalController::class, 'adminShow'])->middleware('admin');
    Route::post('/animals',                          [AnimalController::class, 'store'])->middleware('admin');
    Route::put('/animals/{animal}',                  [AnimalController::class, 'update'])->middleware('admin');
    Route::post('/animals/{animal}/archive',         [AnimalController::class, 'archive'])->middleware('admin');
    Route::delete('/animals/{animal}',               [AnimalController::class, 'destroy'])->middleware('admin');
    Route::post('/animals/{animal}/photos',          [AnimalController::class, 'addPhotos'])->middleware('admin');
    Route::delete('/animals/{animal}/photos/{photo}', [AnimalController::class, 'destroyPhoto'])->middleware('admin');

    // ---- Medical records & vaccinations (Phase 6) ----
    Route::post('/animals/{animal}/medical-records', [MedicalRecordController::class, 'store'])->middleware('admin');
    Route::put('/medical-records/{record}',           [MedicalRecordController::class, 'update'])->middleware('admin');
    Route::delete('/medical-records/{record}',        [MedicalRecordController::class, 'destroy'])->middleware('admin');
    Route::post('/animals/{animal}/vaccinations',      [VaccinationController::class, 'store'])->middleware('admin');
    Route::put('/vaccinations/{vaccination}',          [VaccinationController::class, 'update'])->middleware('admin');
    Route::delete('/vaccinations/{vaccination}',       [VaccinationController::class, 'destroy'])->middleware('admin');

    // ---- User management (Phase 6) ----
    Route::get('/admin/users',           [UserController::class, 'adminIndex'])->middleware('admin');
    Route::put('/admin/users/{user}',    [UserController::class, 'adminUpdate'])->middleware('admin');

    // ---- Admin dashboard overview (Phase 6) ----
    Route::get('/admin/dashboard/overview', [DashboardController::class, 'adminOverview'])->middleware('admin');

    // ---- Volunteer management (Phase 6) ----
    Route::get('/admin/volunteers',                 [VolunteerController::class, 'adminIndex'])->middleware('admin');
    Route::post('/admin/volunteers',                [VolunteerController::class, 'adminStore'])->middleware('admin');
    Route::put('/admin/volunteers/{volunteer}',     [VolunteerController::class, 'adminUpdate'])->middleware('admin');
    Route::delete('/admin/volunteers/{volunteer}',  [VolunteerController::class, 'adminDestroy'])->middleware('admin');
    Route::post('/admin/volunteers/{volunteer}/tasks',     [VolunteerController::class, 'storeTask'])->middleware('admin');
    Route::put('/admin/volunteer-tasks/{task}',            [VolunteerController::class, 'updateTask'])->middleware('admin');
    Route::delete('/admin/volunteer-tasks/{task}',         [VolunteerController::class, 'destroyTask'])->middleware('admin');

    // ---- Intake management (Phase 6) ----
    Route::get('/admin/intakes',                     [IntakeController::class, 'adminIndex'])->middleware('admin');
    Route::post('/admin/intakes',                    [IntakeController::class, 'adminStore'])->middleware('admin');
    Route::get('/admin/intakes/{intake}',             [IntakeController::class, 'adminShow'])->middleware('admin');
    Route::put('/admin/intakes/{intake}',             [IntakeController::class, 'adminUpdate'])->middleware('admin');
    Route::delete('/admin/intakes/{intake}',          [IntakeController::class, 'adminDestroy'])->middleware('admin');
    Route::post('/admin/intakes/{intake}/convert',    [IntakeController::class, 'adminConvert'])->middleware('admin');
    Route::post('/admin/intakes/{intake}/documents',  [IntakeController::class, 'addDocuments'])->middleware('admin');
    Route::delete('/admin/intakes/{intake}/documents/{document}', [IntakeController::class, 'destroyDocument'])->middleware('admin');

    // ---- Site settings ----
    Route::get('/admin/settings',           [SettingController::class, 'adminIndex'])->middleware('admin');
    Route::put('/admin/settings',           [SettingController::class, 'adminUpdate'])->middleware('admin');
    Route::post('/admin/settings/image',    [SettingController::class, 'adminUploadImage'])->middleware('admin');

    // ---- Reports & exports (Phase 7) ----
    Route::get('/admin/reports/adoption',    [ReportController::class, 'adoption'])->middleware('admin');
    Route::get('/admin/reports/animals',     [ReportController::class, 'animals'])->middleware('admin');
    Route::get('/admin/reports/medical',     [ReportController::class, 'medical'])->middleware('admin');
    Route::get('/admin/reports/donations',   [ReportController::class, 'donations'])->middleware('admin');
    Route::get('/admin/reports/volunteers',  [ReportController::class, 'volunteers'])->middleware('admin');
    Route::get('/admin/reports/rescue',      [ReportController::class, 'rescue'])->middleware('admin');
    Route::get('/admin/reports/export/csv',  [ReportController::class, 'exportCsv'])->middleware('admin');
    Route::get('/admin/reports/export/pdf',  [ReportController::class, 'exportPdf'])->middleware('admin');
});