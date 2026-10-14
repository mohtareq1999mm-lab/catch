<?php

namespace Marvel\Traits;

use Illuminate\Support\Facades\Lang;

trait ApiResponse
{
    public function apiResponse($message, $status, $success = true, $data = [])
    {
        if (is_string($message)) {
            $message = $this->translateNotice($message);
        }
        $result = [
            'status' => $status,
            'message' => $message,
            'success' => $success,
        ];
        if (!empty($data))
            $result['data'] = $data;
        return response()->json($result, $status);
    }

    private function translateNotice(string $key, array $replace = []): string
    {
        $normalizedKey = $this->stripNoticeDomain($key);
        $messageKey = 'message.' . $normalizedKey;

        if (Lang::has($messageKey)) {
            return __($messageKey, $replace);
        }

        $translated = __($normalizedKey, $replace);

        if ($translated === $normalizedKey && $normalizedKey !== $key) {
            $translated = __($key, $replace);
        }

        return $translated;
    }

    private function stripNoticeDomain(string $key): string
    {
        $prefix = config('shop.app_notice_domain');

        if ($prefix && str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }
}
