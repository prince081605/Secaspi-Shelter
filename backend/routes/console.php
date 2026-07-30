<?php

use App\Contracts\PaymentGateway;
use App\Models\PaymentSession;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDue;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Create an admin, or promote and unlock an existing account. The way back in when a
 * database has no admin and you would rather not redeploy to change ADMIN_* env vars.
 *
 * The password is prompted for, never passed as an argument — arguments end up in shell
 * history and in the process list, where other users on the host can read them.
 *
 * Usage: php artisan secaspi:make-admin you@example.com
 */
Artisan::command('secaspi:make-admin {email} {--name=Shelter Admin}', function (string $email) {
    $password = $this->secret('New password (min 8 characters, input hidden)');

    if (strlen((string) $password) < 8) {
        $this->error('Password must be at least 8 characters.');
        return 1;
    }

    if ($password !== $this->secret('Confirm password')) {
        $this->error('Passwords do not match.');
        return 1;
    }

    $user = User::where('email', $email)->first();

    if ($user) {
        // forceFill because role and status are deliberately not mass-assignable on the
        // User model — the same escalation guard UserController::adminUpdate works around.
        $user->forceFill([
            'password' => Hash::make($password),
            'role' => 'admin',
            'status' => 'active',
            'suspension_reason' => null,
            'email_verified' => 1,
        ])->save();

        $user->tokens()->delete();   // old sessions should not survive a password reset

        $this->info("Promoted {$email} to admin, reset the password, and revoked existing sessions.");

        return 0;
    }

    $name = $this->option('name');

    User::insert([[
        'full_name' => $name,
        'username' => User::generateUsername($name),
        'email' => $email,
        'password' => Hash::make($password),
        'role' => 'admin',
        'status' => 'active',
        'email_verified' => 1,
        'created_at' => now(),
    ]]);

    $this->info("Created admin {$email}.");

    return 0;
})->purpose('Create an admin account, or promote and unlock an existing one');

/**
 * Prove the MAIL_* settings actually work, without having to trigger a real password
 * reset or donation to find out. Prints the effective config first, so a failure tells
 * you *which* setting is wrong rather than just that something is.
 *
 * Usage: php artisan secaspi:mail-test you@example.com
 */
Artisan::command('secaspi:mail-test {email}', function (string $email) {
    $this->line('Mailer:   '.config('mail.default'));
    $this->line('Host:     '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
    $this->line('Username: '.(config('mail.mailers.smtp.username') ?: '(blank)'));
    $this->line('Password: '.(config('mail.mailers.smtp.password') ? 'set ('.strlen(config('mail.mailers.smtp.password')).' chars)' : '(BLANK — this is why Gmail returns 535)'));
    $this->line('From:     '.config('mail.from.address'));
    $this->newLine();

    try {
        Mail::raw(
            "This is a test from SECASPI Shelter.\n\nIf you are reading this, MAIL_* is configured correctly.",
            fn ($m) => $m->to($email)->subject('SECASPI Shelter — mail test')
        );
    } catch (\Throwable $e) {
        $this->error('FAILED: '.$e->getMessage());
        return 1;
    }

    $this->info(config('mail.default') === 'log'
        ? "Written to storage/logs/laravel.log (MAIL_MAILER=log sends nothing)."
        : "Sent to {$email}. Check the inbox, and the spam folder.");

    return 0;
})->purpose('Send a test email to verify the MAIL_* settings');

/**
 * Notify admins of health reminders whose due date has arrived, then mark them sent
 * so they aren't re-notified daily. Idempotent and safe to run repeatedly.
 *
 * Note: the admin dashboard (GET /admin/reminders) is the always-on surface and does
 * not depend on this command running. This adds proactive email/in-app pings *if* a
 * scheduler (`php artisan schedule:work` or cron) is configured in the environment.
 */
Artisan::command('reminders:dispatch', function () {
    $due = Reminder::where('status', 'pending')
        ->whereDate('reminder_date', '<=', now())
        ->get();

    if ($due->isEmpty()) {
        $this->info('No reminders due.');
        return;
    }

    $admins = User::where('role', 'admin')->get();

    foreach ($due as $reminder) {
        foreach ($admins as $admin) {
            (new ReminderDue($reminder))->sendTo($admin);
        }
        $reminder->update(['status' => 'sent']);
    }

    $this->info("Dispatched {$due->count()} reminder(s) to {$admins->count()} admin(s).");
})->purpose('Notify admins of due health reminders');

Schedule::command('reminders:dispatch')->dailyAt('08:00');

/**
 * Database backups (spatie/laravel-backup). Prune old backups first, then take a fresh
 * dump and upload it to the configured destination disk (R2/S3 in production). Runs nightly
 * via the same scheduler that powers reminders:dispatch. Take a manual backup anytime with
 * `php artisan backup:run`; inspect existing backups with `php artisan backup:list`.
 */
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run')->dailyAt('01:30');

/**
 * Close out checkout links nobody finished. The donation goes back to 'cancelled' so it
 * stops sitting in the donor's history as payable and never reaches the admin queue.
 *
 * Like reminders:dispatch this is a convenience, not a dependency: the checkout endpoint
 * expires a stale session lazily the moment anyone opens it, so the flow is correct even
 * in an environment with no scheduler running.
 */
Artisan::command('payments:expire-sessions', function (PaymentGateway $gateway) {
    $stale = PaymentSession::whereIn('status', PaymentSession::LIVE_STATUSES)
        ->where('expires_at', '<', now())
        ->get();

    foreach ($stale as $session) {
        $gateway->expire($session);
    }

    $this->info("Expired {$stale->count()} payment session(s).");
})->purpose('Expire abandoned checkout sessions');

Schedule::command('payments:expire-sessions')->hourly();
