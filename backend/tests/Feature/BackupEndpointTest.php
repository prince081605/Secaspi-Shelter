<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackupEndpointTest extends TestCase
{
    public function test_it_rejects_a_request_with_no_token(): void
    {
        config(['backup.trigger_token' => 'secret-token']);

        $this->postJson('/api/internal/backup')->assertUnauthorized();
    }

    public function test_it_rejects_a_request_with_the_wrong_token(): void
    {
        config(['backup.trigger_token' => 'secret-token']);

        $this->postJson('/api/internal/backup', [], ['X-Backup-Token' => 'nope'])
            ->assertUnauthorized();
    }

    public function test_the_endpoint_is_disabled_when_no_token_is_configured(): void
    {
        config(['backup.trigger_token' => null]);

        // Even a matching-looking request must fail when the feature isn't configured.
        $this->postJson('/api/internal/backup', [], ['X-Backup-Token' => ''])
            ->assertUnauthorized();
    }

    public function test_a_valid_token_runs_the_backup_and_returns_200(): void
    {
        config(['backup.trigger_token' => 'secret-token']);

        // Don't shell out to a real dumper in the test — assert the controller drives the
        // right commands and reports success on a zero exit code.
        Artisan::shouldReceive('call')->once()->with('backup:clean')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('backup:run')->andReturn(0);
        Artisan::shouldReceive('output')->andReturn('Backup completed!');

        $this->postJson('/api/internal/backup', [], ['X-Backup-Token' => 'secret-token'])
            ->assertOk()
            ->assertJson(['message' => 'Backup completed.']);
    }

    public function test_a_failed_backup_returns_500(): void
    {
        config(['backup.trigger_token' => 'secret-token']);

        Artisan::shouldReceive('call')->once()->with('backup:clean')->andReturn(0);
        Artisan::shouldReceive('call')->once()->with('backup:run')->andReturn(1);
        Artisan::shouldReceive('output')->andReturn('Backup failed because: ...');

        $this->postJson('/api/internal/backup', [], ['X-Backup-Token' => 'secret-token'])
            ->assertStatus(500)
            ->assertJson(['message' => 'Backup failed.']);
    }
}
