<?php

namespace App\Enums;

/**
 * Canonical queue names — semantic roles, physical names are deployment config.
 *
 *   QueueName::HIGH->value   = neutral logical fallback (before config loads)
 *   QueueName::high()        = config('queue.queues.high') — runtime physical name
 *   QueueName::medium()      = config('queue.queues.medium')
 *   $enum->resolved()        = same, instance helper
 *
 * Application code must use ::high()/::medium() or config('queue.queues.*'),
 * never hard-coded strings. Supervisor workers consume the same env values.
 *
 * The enum values are neutral logical names (high/medium) so that `->value`
 * is a safe fallback when config is not yet booted (e.g. early service
 * providers). Physical names (meem-high, catch-high, ...) live only in each
 * deployment's QUEUE_HIGH / QUEUE_MEDIUM environment variables.
 */
enum QueueName: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';

    /**
     * Resolve this semantic role to its deployment-specific physical queue name.
     */
    public function resolved(): string
    {
        return match ($this) {
            self::HIGH => (string) config('queue.queues.high', $this->value),
            self::MEDIUM => (string) config('queue.queues.medium', $this->value),
        };
    }

    /**
     * Physical name for the high-priority queue.
     */
    public static function high(): string
    {
        return (string) config('queue.queues.high', self::HIGH->value);
    }

    /**
     * Physical name for the medium-priority queue.
     */
    public static function medium(): string
    {
        return (string) config('queue.queues.medium', self::MEDIUM->value);
    }
}
