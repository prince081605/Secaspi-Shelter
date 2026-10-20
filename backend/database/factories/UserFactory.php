<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    /**
     * Matches the actual users schema (full_name, role, status, email_verified — no `name`,
     * `email_verified_at`, or `remember_token`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'phone' => fake()->numerify('09#########'),
            'role' => 'user',
            'status' => 'active',
            'email_verified' => true,
        ];
    }

    // The three admin types (App\Support\Permissions). There is no all-powerful admin state on
    // purpose: a test acts as whichever admin type owns the action it exercises.
    public function deskAdmin(): static
    {
        return $this->state(fn () => ['role' => 'desk_admin']);
    }

    public function dataAdmin(): static
    {
        return $this->state(fn () => ['role' => 'data_admin']);
    }

    public function systemAdmin(): static
    {
        return $this->state(fn () => ['role' => 'system_admin']);
    }

    public function staff(): static
    {
        return $this->state(fn () => ['role' => 'staff']);
    }

    public function volunteer(): static
    {
        return $this->state(fn () => ['role' => 'volunteer']);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => 'suspended',
            'suspension_reason' => 'Suspended pending review of reported activity.',
        ]);
    }

    /**
     * The User model guards role/status/email_verified against mass assignment (the
     * privilege-escalation defense). Factories are trusted fixtures, so force-fill everything.
     */
    public function newModel(array $attributes = [])
    {
        $model = $this->modelName();

        return (new $model)->forceFill($attributes);
    }
}
