<?php

namespace Database\Factories;

use App\Enums\SiteReviewStatus;
use App\Models\SiteReview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Marvel\Database\Models\User;

class SiteReviewFactory extends Factory
{
    protected $model = SiteReview::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'rating' => $this->faker->numberBetween(1, 5),
            'title' => $this->faker->sentence(3),
            'comment' => $this->faker->paragraph,
            'status' => SiteReviewStatus::PENDING,
            'moderated_by' => null,
            'moderated_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteReviewStatus::PENDING,
            'moderated_by' => null,
            'moderated_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteReviewStatus::APPROVED,
            'moderated_by' => User::factory()->admin(),
            'moderated_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteReviewStatus::REJECTED,
            'moderated_by' => User::factory()->admin(),
            'moderated_at' => now(),
        ]);
    }
}
