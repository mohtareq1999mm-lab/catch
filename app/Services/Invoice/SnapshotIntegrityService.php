<?php

namespace App\Services\Invoice;

class SnapshotIntegrityService
{
    public function computeHash(array $data): string
    {
        $sorted = $this->sortRecursive($data);
        $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json);
    }

    public function verify(array $data, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->computeHash($data));
    }

    private function sortRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }
        return $data;
    }
}
