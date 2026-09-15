<?php

namespace App\Audit;

use Illuminate\Support\Facades\Auth;

/**
 * Resolves the business actor and executor for an audit event.
 *
 * "actor" is the business actor (a human user, or "system" for automation).
 * "executor" is the technical vehicle (queue job / command / scheduler).
 */
final class ActorResolver
{
    public static function resolve(?array $context = null): array
    {
        $context = $context ?? ActivityContext::capture();
        $user = Auth::user();

        $actor = $user
            ? ['type' => 'human', 'user_id' => $user->getKey(), 'user_type' => get_class($user)]
            : ['type' => 'system'];

        $executor = null;

        if (!empty($context['job'])) {
            $executor = ['type' => 'queue', 'job' => $context['job']];
        } elseif (!empty($context['command'])) {
            $executor = ['type' => 'command', 'command' => $context['command']];
        } elseif (($context['source'] ?? null) === 'scheduler') {
            $executor = ['type' => 'scheduler'];
        } elseif (($context['source'] ?? null) === 'system') {
            $executor = ['type' => 'system'];
        }

        return [
            'actor' => $actor,
            'executor' => $executor,
            'causer_type' => $user ? get_class($user) : null,
            'causer_id' => $user ? (int) $user->getKey() : null,
        ];
    }
}
