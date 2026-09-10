<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Services\Firebase\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendFcmNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(
        public string $title,
        public string $body,
        public array $data,
        public ?int $notifiableUserId = null,
    ) {
        $this->onQueue(config('frontend.queue', \App\Enums\QueueName::MEDIUM->value));
    }

    public function handle(FcmService $fcm): void
    {
        // Security fix: push must target ONLY the intended notifiable's tokens.
        // A missing user id is a programming error — never fall back to a
        // device-table-wide broadcast.
        if ($this->notifiableUserId === null) {
            Log::warning('FCM job skipped: no target user resolved', [
                'title' => $this->title,
            ]);
            return;
        }

        DeviceToken::query()
            ->where('user_id', $this->notifiableUserId)
            ->orderBy('id')
            ->chunk(500, function ($devices) use ($fcm) {
                foreach ($devices->groupBy('client') as $client => $group) {
                    $invalid = $fcm->sendToClient($client, $this->title, $this->body, $this->data, $group->pluck('token'));

                    if ($invalid) {
                        DeviceToken::whereIn('token', $invalid)->delete();
                        Log::info('FCM invalid tokens removed', ['client' => $client, 'count' => count($invalid)]);
                    }
                }
            });
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendFcmNotificationJob failed permanently', ['error' => $e->getMessage()]);
    }
}