<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class BackupController extends Controller
{
    /**
     * Trigger a database backup over HTTP.
     *
     * This exists so an external scheduler (a free GitHub Actions cron) can drive nightly
     * backups: on Render's Free plan the web service sleeps when idle, which kills the
     * in-process `schedule:work` scheduler. The incoming request wakes the service, then we
     * run the same spatie/laravel-backup commands the scheduler would have (clean old
     * backups, then take a fresh one to the configured R2/S3 disk).
     *
     * Guarded by a shared secret in the X-Backup-Token header (config: backup.trigger_token),
     * compared in constant time. If no token is configured the endpoint is disabled.
     */
    public function run(Request $request)
    {
        $expected = config('backup.trigger_token');
        $provided = (string) $request->header('X-Backup-Token', '');

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        // Prune per the retention policy first, then take a fresh backup. Wrap both so an
        // unexpected error (e.g. a notification failing to send) returns a clean JSON status
        // instead of leaking a stack trace.
        try {
            Artisan::call('backup:clean');
            $output = Artisan::output();

            $exitCode = Artisan::call('backup:run');
            $output .= Artisan::output();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Backup trigger errored.',
                'error' => $e->getMessage(),
            ], 500);
        }

        // backup:run returns a non-zero exit code when the dump/upload fails. Report that
        // honestly so the calling cron (GitHub Actions, curl -f) goes red and alerts us,
        // rather than silently claiming success.
        if ($exitCode !== 0) {
            return response()->json([
                'message' => 'Backup failed.',
                'output' => $output,
            ], 500);
        }

        return response()->json([
            'message' => 'Backup completed.',
            'output' => $output,
        ]);
    }
}
