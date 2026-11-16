<?php

namespace Marvel\Traits;

// One notice-key translator (round-2 ticket #50): see Marvel\Support\NoticeTranslator.

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
        // One notice-key translator (round-2 ticket #50): the API response
        // trait and the exception handler share the implementation, with the
        // fail-closed rule — an unresolved KEY-LIKE input never reaches a
        // client.
        return app(\Marvel\Support\NoticeTranslator::class)->translate($key, $replace);
    }
}
