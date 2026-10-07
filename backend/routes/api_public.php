<?php

use App\Http\Controllers\AnimalController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ImpactController;
use App\Http\Controllers\MatchmakerController;
use App\Http\Controllers\PaymongoWebhookController;
use App\Http\Controllers\PublicHomeController;
use App\Http\Controllers\RescueReportController;
use App\Http\Controllers\SettingController;
use App\Support\Captcha;
use Illuminate\Support\Facades\Route;

// Token-guarded backup trigger, driven by an external scheduler (GitHub Actions cron) so
// nightly backups still run on a sleeping Render Free service. Auth is the X-Backup-Token
// header (checked in the controller), not Sanctum — hence its place among the public routes.
Route::post('/internal/backup', [BackupController::class, 'run']);

// PayMongo payment confirmations. Server-to-server, so no Sanctum session: the request is
// authenticated by its Paymongo-Signature header, checked in the controller.
Route::post('/webhooks/paymongo', PaymongoWebhookController::class)->middleware('throttle:120,1');

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
// Anonymous write that creates rows and accepts a 5 MB upload — throttle per IP so a bot can't
// flood the triage queue or fill storage (a 429 is returned past the cap), then the honeypot +
// CAPTCHA check ('human', App\Http\Middleware\VerifyHuman).
Route::post('/rescue-reports', [RescueReportController::class, 'store'])->middleware(['throttle:5,1', 'human']);

// The public half of the CAPTCHA key pair, so the frontend can draw the "Verify you are human"
// box; null while the check is switched off. Served from here so both keys live in one place.
Route::get('/captcha', fn () => response()->json(['site_key' => Captcha::siteKey()]));

// ---- Smart adoption matchmaker (lifestyle quiz -> ranked animals) ----
Route::post('/matchmaker', [MatchmakerController::class, 'match']);

// ---- Public impact leaderboards (gamification) ----
Route::get('/impact/leaderboard', [ImpactController::class, 'leaderboard']);

// ---- Public Home API (DB-backed analytics; see PublicHomeController) ----
Route::get('/home/stats', [PublicHomeController::class, 'stats']);
Route::get('/home/impact', [PublicHomeController::class, 'impact']);
Route::get('/home/transparency', [PublicHomeController::class, 'transparency']);
Route::get('/home/featured-animals', [PublicHomeController::class, 'featuredAnimals']);
