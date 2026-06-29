<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AnimalController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ImpactController;
use App\Http\Controllers\MatchmakerController;
use App\Http\Controllers\PublicHomeController;
use App\Http\Controllers\RescueReportController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

// Token-guarded backup trigger, driven by an external scheduler (GitHub Actions cron) so
// nightly backups still run on a sleeping Render Free service. Auth is the X-Backup-Token
// header (checked in the controller), not Sanctum — hence its place among the public routes.
Route::post('/internal/backup', [BackupController::class, 'run']);

Route::get('/home/settings', [SettingController::class, 'publicIndex']);

Route::get('/test', function () {
    return response()->json([
        'message' => 'Laravel API is working fine',
    ]);
});

// ---- Animal browse + detail (Phase 2) ----
Route::get('/animals', [AnimalController::class, 'index']);
Route::get('/animals/{animal}', [AnimalController::class, 'show']);

// ---- Rescue report submission (Phase 4) ----
Route::post('/rescue-reports', [RescueReportController::class, 'store']);

// ---- Smart adoption matchmaker (lifestyle quiz -> ranked animals) ----
Route::post('/matchmaker', [MatchmakerController::class, 'match']);

// ---- AI shelter assistant (FAQ-first, cost-capped) ----
Route::post('/assistant/chat', [AiAssistantController::class, 'chat']);

// ---- Public impact leaderboards (gamification) ----
Route::get('/impact/leaderboard', [ImpactController::class, 'leaderboard']);

// ---- Public Home API (DB-backed analytics; see PublicHomeController) ----
Route::get('/home/stats', [PublicHomeController::class, 'stats']);
Route::get('/home/impact', [PublicHomeController::class, 'impact']);
Route::get('/home/transparency', [PublicHomeController::class, 'transparency']);
Route::get('/home/featured-animals', [PublicHomeController::class, 'featuredAnimals']);
