<?php

namespace App\Listeners;

use Marvel\Enums\Role;

use App\Notifications\AdminQueueJobFailedNotification;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Phase 0: failed jobs must be observable. failed_jobs persistence is handled
 * by the framework; this listener adds structured logging plus a super-admin
 * alert using the existing notification conventions. Alert payload contains
 * no exception traces or secrets.
 */
class HandleFailedQueueJob
{
    public function handle(JobFailed $event): void
    {
        $jobName = $event->job::class;
        $queue = $event->job->getQueue() ?? 'default';
        $message = $event->exception->getMessage();

        Log::error('Queue job failed after final attempt', [
            'job' => $jobName,
            'queue' => $queue,
            'error' => $message,
        ]);

        try {
            $admins = \Marvel\Database\Models\User::role(\Marvel\Enums\Role::SUPER_ADMIN)->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new AdminQueueJobFailedNotification($jobName, $queue, $message));
            }
        } catch (\Throwable $e) {
            // Alerting must never mask the original failure.
            Log::warning('Failed to deliver queue-failure admin alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
