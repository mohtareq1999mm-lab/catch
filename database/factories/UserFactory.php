<?php

namespace Database\Factories;

use Marvel\Database\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition()
    {
        return [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'remember_token' => Str::random(10),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => $this->faker->unique()->phoneNumber,
        ];
    }

    public function admin()
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'admin',
        ]);
    }

    public function customer()
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'user',
        ]);
    }

    public function withoutEmail()
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'email_verified_at' => null,
        ]);
    }
}
