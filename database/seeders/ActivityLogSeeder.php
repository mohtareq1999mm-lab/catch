<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        $admins = User::where('type', 'admin')->get();
        $target = User::where('type', 'user')->first();

        if ($admins->isEmpty()) {
            $this->command->warn('No admin users found. Skipping activity log seeding.');

            return;
        }

        $admin = $admins->first();
        $targetName = $target?->name ?? 'Test User';
        $targetEmail = $target?->email ?? 'test@example.com';
        $targetId = $target?->id ?? 2;

        $logs = [
            [
                'log_name' => 'users',
                'description' => 'User created',
                'event' => 'created',
                'subject_id' => $targetId,
                'subject_type' => User::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['role' => 'customer', 'created_by' => $admin->name],
            ],
            [
                'log_name' => 'users',
                'description' => "User {$targetName} banned",
                'event' => 'banned',
                'subject_id' => $targetId,
                'subject_type' => User::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['previous_status' => 'active', 'new_status' => 'banned'],
            ],
            [
                'log_name' => 'users',
                'description' => "User {$targetName} activated",
                'event' => 'activated',
                'subject_id' => $targetId,
                'subject_type' => User::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['previous_status' => 'banned', 'new_status' => 'active'],
            ],
            [
                'log_name' => 'users',
                'description' => "User {$targetName} updated",
                'event' => 'updated',
                'subject_id' => $targetId,
                'subject_type' => User::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => [
                    'old' => ['name' => 'Old Name', 'email' => 'old@example.com'],
                    'new' => ['name' => $targetName, 'email' => $targetEmail],
                ],
            ],
            [
                'log_name' => 'users',
                'description' => "User {$targetName} deleted",
                'event' => 'deleted',
                'subject_id' => $targetId,
                'subject_type' => User::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['deleted_by' => $admin->name],
            ],
            [
                'log_name' => 'users',
                'description' => "User {$targetName} restored",
                'event' => 'restored',
                'subject_id' => $targetId,
                'subject_type' => User::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['restored_by' => $admin->name],
            ],
            [
                'log_name' => 'users',
                'description' => "User {$targetName} force deleted",
                'event' => 'forceDeleted',
                'subject_id' => $targetId,
                'subject_type' => User::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['permanently_deleted_by' => $admin->name],
            ],
            [
                'log_name' => 'products',
                'description' => 'Product created',
                'event' => 'created',
                'subject_id' => 1,
                'subject_type' => Product::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['product_name' => 'Sample Product'],
            ],
            [
                'log_name' => 'orders',
                'description' => 'Order status changed',
                'event' => 'statusChanged',
                'subject_id' => 1,
                'subject_type' => Order::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['previous_status' => 'pending', 'new_status' => 'processing'],
            ],
            [
                'log_name' => 'settings',
                'description' => 'Settings updated',
                'event' => 'updated',
                'subject_id' => 1,
                'subject_type' => Settings::class,
                'causer_id' => $admin->id,
                'causer_type' => User::class,
                'properties' => ['updated_fields' => ['currency', 'language']],
            ],
        ];

        foreach ($logs as $log) {
            Activity::create($log);
        }

        $this->command->info('Seeded ' . count($logs) . ' activity log entries.');
    }
}
