<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Review;
use Marvel\Database\Models\User;

class ReviewSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::query()->get();
        $users = User::query()->get();

        if ($products->isEmpty()) {
            $this->command?->warn('No products found. Seed products before reviews.');
            return;
        }

        if ($users->isEmpty()) {
            $this->command?->warn('No users found. Seed users before reviews.');
            return;
        }

        $positiveComments = [
            'Amazing coverage and lasts all day without creasing.',
            'Love the texture and how smoothly it applies.',
            'Perfect shade match and feels lightweight on the skin.',
            'Great pigmentation and blends like a dream.',
            'My new holy grail product! Highly recommend.',
            'Beautiful finish that looks natural and flawless.',
            'Exceeded my expectations. Will definitely repurchase.',
            'Very comfortable to wear and does not clog pores.',
        ];

        $neutralComments = [
            'Decent product but the shade range could be better.',
            'Works fine but nothing extraordinary for the price.',
            'Good quality but the packaging feels cheap.',
            'It does the job but takes a bit more effort to blend.',
            'Average product. Might work better on different skin types.',
            'Not bad but I have used better formulas.',
        ];

        $negativeComments = [
            'Caused irritation and breakouts on my sensitive skin.',
            'The shade was completely different from the online swatch.',
            'Fades after just a few hours. Disappointing longevity.',
            'Formula is too thick and difficult to work with.',
            'Strong chemical smell that I did not like.',
            'Arrived damaged and the consistency was off.',
            'Not worth the price. Drugstore alternatives perform better.',
        ];

        foreach ($products as $product) {
            $reviewCount = random_int(1, 3);

            for ($i = 0; $i < $reviewCount; $i++) {
                $rating = $this->randomRating();
                $reviewer = $users->random();

                Review::create([
                    'user_id' => $reviewer->id,
                    'product_id' => $product->id,
                    'comment' => $this->commentForRating($rating, $positiveComments, $neutralComments, $negativeComments),
                    'rating' => $rating,
                    'approved' => $this->randomBool(80),
                ]);
            }
        }

        $this->command?->info('ReviewSeeder completed successfully. Created product reviews.');
    }

    private function randomRating(): int
    {
        $ratings = [5, 5, 5, 5, 4, 4, 4, 3, 3, 2, 1];

        return $ratings[array_rand($ratings)];
    }

    private function commentForRating(int $rating, array $positiveComments, array $neutralComments, array $negativeComments): string
    {
        if ($rating >= 4) {
            return $positiveComments[array_rand($positiveComments)];
        }

        if ($rating === 3) {
            return $neutralComments[array_rand($neutralComments)];
        }

        return $negativeComments[array_rand($negativeComments)];
    }

    private function randomBool(int $truePercent): bool
    {
        return random_int(1, 100) <= $truePercent;
    }
}
