<?php

namespace Marvel\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The single notice-key translator (round-2 ticket #50).
 *
 * Resolution order for one input:
 *  1. strip the configured notice domain prefix;
 *  2. `message.<key>` in the catalogue;
 *  3. `<key>` in the catalogue;
 *  4. fail closed — a KEY-LIKE input that resolves to itself becomes the
 *     approved generic message and never reaches a client;
 *  5. already-translated prose (contains whitespace) passes through
 *     unchanged, so existing `apiResponse(__('...'))` call sites keep their
 *     localized text.
 *
 * Both the exception handler and the API response trait delegate here, so a
 * raw translation key can no longer leak from either path.
 */
final class NoticeTranslator
{
    public function translate(string $key, array $replace = []): string
    {
        $normalized = $this->stripNoticeDomain($key);
        $messageKey = 'message.' . $normalized;

        if (Lang::has($messageKey)) {
            return __($messageKey, $replace);
        }

        if (Lang::has($normalized)) {
            return __($normalized, $replace);
        }

        if ($this->isKeyLike($normalized)) {
            return __('message.ERROR.SOMETHING_WENT_WRONG', $replace);
        }

        return $key;
    }

    private function stripNoticeDomain(string $key): string
    {
        $prefix = (string) config('shop.app_notice_domain');

        if ($prefix !== '' && str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }

    /** Prose (any whitespace) is already-translated text, not a key. */
    private function isKeyLike(string $value): bool
    {
        return !preg_match('/\s/u', $value);
    }
}
