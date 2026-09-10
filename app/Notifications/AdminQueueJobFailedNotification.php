<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 0: alerts super admins when a queued job exhausts its attempts.
 * Fired from HandleFailedQueueJob (Illuminate\Queue\Events\JobFailed).
 * Payload intentionally excludes exception traces/secrets — identification
 * fields only; full context lives in the structured log + failed_jobs table.
 */
class AdminQueueJobFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $jobName,
        public string $queue,
        public string $errorMessage,
    ) {
        $this->onQueue(\App\Enums\QueueName::MEDIUM->value);
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => [
                'en' => 'Background job failed',
                'ar' => "\u{0641}\u{0634}\u{0644} \u{062A}\u{0646}\u{0641}\u{064A}\u{0630} \u{0645}\u{0647}\u{0645}\u{0629} \u{062E}\u{0644}\u{0641}\u{064A}\u{0629}",
            ],
            'message' => [
                'en' => "Job [{$this->jobName}] on queue [{$this->queue}] failed after all retries: {$this->errorMessage}",
                'ar' => "\u{0641}\u{0634}\u{0644}\u{062A} \u{0627}\u{0644}\u{0645}\u{0647}\u{0645}\u{0629} [{$this->jobName}] \u{0639}\u{0644}\u{0649} \u{0627}\u{0644}\u{0642}\u{0627\u{0626}\u{0645}\u{0629}} [{$this->queue}]",
            ],
            'icon' => 'alert-triangle',
            'resource_type' => 'queue_job',
            'job' => $this->jobName,
            'queue' => $this->queue,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue(\App\Enums\QueueName::MEDIUM->value);
    }

    public function broadcastType(): string
    {
        return 'queue.job_failed';
    }
}
