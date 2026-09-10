<?php

namespace App\Services\Firebase;

use InvalidArgumentException;

class FirebaseProjectResolver
{
    public function credentialsPath(string $client): string
    {
        $path = config("firebase.clients.{$client}.credentials");

        if (!$path) {
            throw new InvalidArgumentException("No Firebase credentials configured for client [{$client}].");
        }

        return storage_path('app/' . config('firebase.credential_storage_path') . '/' . basename($path));
    }

    public function projectId(string $client): ?string
    {
        return config("firebase.clients.{$client}.project_id");
    }
}