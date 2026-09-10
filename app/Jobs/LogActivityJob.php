<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\User;

class LogActivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public ?int $causerId,
        public string $event,
        public string $logName,
        public ?string $description,
        public array $properties = [],
    ) {
        $this->onQueue('meem-medium');
    }

    public function handle(): void
    {
        $subject = app($this->subjectType)::find($this->subjectId);
        if (!$subject) return;

        $causer = $this->causerId ? User::find($this->causerId) : null;

        $log = activity($this->logName)
            ->performedOn($subject)
            ->withProperties($this->properties)
            ->event($this->event);

        if ($causer) {
            $log->causedBy($causer);
        }

        $log->log($this->description ?? $this->event);
    }
}
