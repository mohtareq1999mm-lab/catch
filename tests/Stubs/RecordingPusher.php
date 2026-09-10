<?php

declare(strict_types=1);

namespace Tests\Stubs;

use Pusher\Pusher;

/**
 * A real Pusher SDK instance whose network calls are replaced by an in-memory
 * recording layer. It is swapped into the real \Pusher\Pusher behind the real
 * PusherBroadcaster so the full broadcast pipeline (event -> broadcaster ->
 * channel/event/data payload construction) executes untouched while the
 * external HTTP hop to api-*.pusher.com is captured instead of performed.
 */
class RecordingPusher extends Pusher
{
    /**
     * Every trigger() invocation made through the real broadcaster.
     *
     * @var array<int, array{channels: array<int, string>, event: string, data: mixed}>
     */
    public array $broadcasts = [];

    /**
     * Authorization responses issued for channel auth requests.
     *
     * @var array<int, array{channel: string, socket_id: string}>
     */
    public array $authorizations = [];

    public function __construct()
    {
        parent::__construct('recording-key', 'recording-secret', 'recording-app-id', [
            'cluster' => 'mt1',
            'useTLS' => false,
        ]);
    }

    public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
    {
        $this->broadcasts[] = [
            'channels' => is_array($channels) ? array_values($channels) : [$channels],
            'event' => $event,
            'data' => $data,
            'params' => $params,
        ];

        return new \stdClass();
    }

    public function authorizeChannel(string $channel, string $socket_id, string $custom_data = null): string
    {
        $this->authorizations[] = [
            'channel' => $channel,
            'socket_id' => $socket_id,
        ];

        return json_encode(['auth' => 'recording-auth-signature']);
    }

    public function socket_auth(string $channel, string $socket_id, string $custom_data = null): string
    {
        return $this->authorizeChannel($channel, $socket_id);
    }

    public function authorizePresenceChannel(string $channel, string $socket_id, string $user_id, $user_info = null): string
    {
        $this->authorizations[] = [
            'channel' => $channel,
            'socket_id' => $socket_id,
        ];

        return json_encode(['auth' => 'recording-auth-signature']);
    }

    public function getBroadcasts(): array
    {
        return $this->broadcasts;
    }

    public function reset(): void
    {
        $this->broadcasts = [];
        $this->authorizations = [];
    }
}
