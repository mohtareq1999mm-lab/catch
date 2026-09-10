<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Marvel\Database\Models\User;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $admins = User::where('type', 'admin')->get();

        if ($admins->isEmpty()) {
            $this->command->warn('No admin users found. Skipping notification seeding.');

            return;
        }

        $orderTitles = ['New Order', 'Order Shipped', 'Order Delivered', 'Order Cancelled'];
        $orderNumbers = ['ORD-000001', 'ORD-000002', 'ORD-000003', 'ORD-000004', 'ORD-000005'];

        $contactNames = ['Ahmed Hassan', 'Sara Ali', 'Mohamed Ibrahim', 'Nora Khalid'];

        $adminNames = $admins->pluck('name')->toArray();

        for ($i = 0; $i < 30; $i++) {
            $admin = $admins->random();
            $type = $i % 3;

            $notificationData = match ($type) {
                0 => [
                    'type' => 'App\Notifications\NewOrderNotification',
                    'data' => [
                        'title' => 'New Order',
                        'message' => "New Order #{$orderNumbers[array_rand($orderNumbers)]} has been placed.",
                        'icon' => 'shopping-cart',
                        'resource_type' => 'order',
                        'resource_id' => rand(1, 100),
                        'action_url' => '/admin/orders/' . rand(1, 100),
                        'order_id' => rand(1, 100),
                        'order_number' => $orderNumbers[array_rand($orderNumbers)],
                        'customer_name' => 'Customer ' . rand(1, 20),
                        'total_amount' => rand(50, 500) + (rand(0, 99) / 100),
                        'payment_status' => ['pending', 'completed', 'failed'][array_rand(['pending', 'completed', 'failed'])],
                        'order_status' => ['pending', 'processing', 'completed'][array_rand(['pending', 'processing', 'completed'])],
                    ],
                ],
                1 => [
                    'type' => 'App\Notifications\NewContactMessageNotification',
                    'data' => [
                        'title' => 'New Contact Message',
                        'message' => "New Contact Us message received from {$contactNames[array_rand($contactNames)]}.",
                        'icon' => 'mail',
                        'resource_type' => 'contact',
                        'resource_id' => rand(1, 50),
                        'action_url' => '/admin/contacts/' . rand(1, 50),
                        'contact_id' => rand(1, 50),
                        'customer_name' => $contactNames[array_rand($contactNames)],
                        'customer_email' => 'customer' . rand(1, 20) . '@example.com',
                        'subject' => ['Order inquiry', 'Product question', 'Return request', 'Payment issue'][array_rand(['Order inquiry', 'Product question', 'Return request', 'Payment issue'])],
                    ],
                ],
                2 => [
                    'type' => 'App\Notifications\AdminLoggedInNotification',
                    'data' => [
                        'title' => 'Admin Login',
                        'message' => "{$adminNames[array_rand($adminNames)]} logged in.",
                        'icon' => 'log-in',
                        'resource_type' => 'admin',
                        'resource_id' => $admins->random()->id,
                        'action_url' => '/admin/admins',
                        'admin_id' => $admins->random()->id,
                        'admin_name' => $adminNames[array_rand($adminNames)],
                        'admin_email' => 'admin@demo.com',
                        'login_time' => now()->subHours(rand(1, 168))->toIso8601String(),
                        'login_ip' => '192.168.' . rand(0, 255) . '.' . rand(1, 254),
                        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ],
                ],
            };

            DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => $notificationData['type'],
                'notifiable_type' => User::class,
                'notifiable_id' => $admin->id,
                'data' => $notificationData['data'],
                'read_at' => $i % 4 === 0 ? now()->subHours(rand(1, 72)) : null,
                'created_at' => now()->subHours(rand(1, 168)),
                'updated_at' => now()->subHours(rand(1, 168)),
            ]);
        }

        $this->command->info('Seeded 30 notifications across admin users.');
    }
}
