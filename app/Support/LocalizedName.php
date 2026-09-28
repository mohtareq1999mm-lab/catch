<?php

namespace App\Support;

/**
 * Bilingual {en, ar} projection for Spatie-translatable attributes.
 *
 * Graceful fallback: legacy plain-string rows (pre-translatable
 * migration) surface as {en: <string>, ar: null} instead of breaking
 * the API contract.
 */
class LocalizedName
{
    /**
     * @return array{en: ?string, ar: ?string}
     */
    public static function for(object $model, string $attribute): array
    {
        try {
            if (method_exists($model, 'getTranslations')) {
                $translations = $model->getTranslations($attribute);

                if (is_array($translations)) {
                    return [
                        'en' => isset($translations['en']) ? (string) $translations['en'] : null,
                        'ar' => isset($translations['ar']) ? (string) $translations['ar'] : null,
                    ];
                }
            }
        } catch (\Throwable) {
        }

        $raw = null;
        try {
            $raw = $model->getAttributes()[$attribute] ?? null;
        } catch (\Throwable) {
        }

        if (is_array($raw)) {
            return ['en' => $raw['en'] ?? null, 'ar' => $raw['ar'] ?? null];
        }

        return ['en' => $raw !== null ? (string) $raw : null, 'ar' => null];
    }
}
