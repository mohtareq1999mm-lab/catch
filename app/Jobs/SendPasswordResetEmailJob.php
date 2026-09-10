<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Marvel\Mail\ForgetPassword;

class SendPasswordResetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];
    public int $timeout = 60;

    public function __construct(
        public string $email,
        public string $token,
    ) {
        $this->onQueue('meem-high');
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(new ForgetPassword($this->token));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Failed to send password reset email', [
            'email' => $this->email,
            'error' => $e->getMessage(),
        ]);
    }
}
