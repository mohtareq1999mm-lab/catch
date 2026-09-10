<?php

namespace App\Services\Firebase;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;

class FcmService
{
    /** Runtime-cached messaging clients keyed by client identifier. */
    private array $clients = [];

    public function __construct(private FirebaseProjectResolver $resolver) {}

    public function sendToClient(string $client, string $title, string $body, array $data, Collection $tokens): array
    {
        if ($tokens->isEmpty()) {
            return [];
        }

        $invalid = [];

        try {
            $messaging = $this->messagingFor($client);

            $message = $messaging->newMessage()
                ->withNotification(new \Kreait\Firebase\Messaging\Notification($title, $body))
                ->withHighestPossiblePriority()
                ->withData($data);

            $report = $messaging->sendMulticast($message, $tokens->all());

            foreach ($report->getItems() as $item) {
                $target = $item->getTarget()?->value();
                if ($item->isSuccess() || !$target) {
                    continue;
                }
                if ($item->error() instanceof NotFound
                    || str_contains($item->error()->getMessage(), 'not a valid FCM registration token')) {
                    $invalid[] = $target;
                } else {
                    Log::warning('FCM transient failure', [
                        'client' => $client, 'reason' => $item->error()->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // FCM must never break business flows.
            Log::error('FCM send failed', ['client' => $client, 'error' => $e->getMessage()]);
        }

        return $invalid;
    }

    private function messagingFor(string $client): Messaging
    {
        if (isset($this->clients[$client])) {
            return $this->clients[$client];
        }

        $credentials = $this->resolver->credentialsPath($client);

        if (!is_file($credentials)) {
            throw new \RuntimeException("Firebase credentials file missing for client [{$client}].");
        }

        // Strict per-client factory: no fallback, no shared default project.
        return $this->clients[$client] = (new Factory)
            ->withServiceAccount($credentials)
            ->createMessaging();
    }
}