<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::AUTHOR,
            'remember_token' => Str::random(10),
        ];
    }

    public function author(): static
    {
        return $this->state(['role' => UserRole::AUTHOR]);
    }

    public function reviewer(): static
    {
        return $this->state(['role' => UserRole::REVIEWER]);
    }

    public function approver(): static
    {
        return $this->state(['role' => UserRole::APPROVER]);
    }

    public function publisher(): static
    {
        return $this->state(['role' => UserRole::PUBLISHER]);
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::ADMIN]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
