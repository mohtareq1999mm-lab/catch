<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use InvalidArgumentException;

/**
 * #175: validation library for carrier-label bytes. No runtime label-content
 * ingestion/storage boundary exists yet; callers must invoke this explicitly.
 */
final class LabelContentValidator
{
    public const DEFAULT_MAX_BYTES = 10485760; // 10 MiB

    /** @var list<string> */
    public const ALLOWED_MIMES_DEFAULT = ['application/pdf', 'image/png'];

    /**
     * @param  list<string>  $allowedMimeTypes
     * @return array{valid:bool,detected_mime:?string,reason:?string}
     */
    public function validate(
        string $content,
        string $declaredMimeType,
        array $allowedMimeTypes = self::ALLOWED_MIMES_DEFAULT,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): array {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('maxBytes must be positive.');
        }

        $declared = strtolower(trim($declaredMimeType));
        $allowed = $this->normalizeAllowed($allowedMimeTypes);

        if ($content === '' || strlen($content) > $maxBytes) {
            return $this->failure($content === '' ? 'empty_content' : 'content_too_large');
        }
        if ($declared === '' || !in_array($declared, $allowed, true)) {
            return $this->failure('declared_mime_not_allowed');
        }

        return $this->validateBytes($content, $declared);
    }

    /**
     * @param  list<string>  $allowedMimeTypes
     * @return array{valid:true,detected_mime:string,reason:null}
     */
    public function assertValid(
        string $content,
        string $declaredMimeType,
        array $allowedMimeTypes = self::ALLOWED_MIMES_DEFAULT,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): array {
        $result = $this->validate($content, $declaredMimeType, $allowedMimeTypes, $maxBytes);
        if ($result['valid'] !== true) {
            throw new InvalidArgumentException('Invalid label content: '.($result['reason'] ?? 'unknown'));
        }

        /** @var array{valid:true,detected_mime:string,reason:null} $result */
        return $result;
    }

    /**
     * @return array{valid:bool,detected_mime:?string,reason:?string}
     */
    private function validateBytes(string $content, string $declaredMime): array
    {
        $detected = $this->detectMime($content);
        if ($detected === null) {
            return $this->failure('unknown_format');
        }
        if ($declaredMime !== $detected) {
            return $this->failure('mime_mismatch', $detected);
        }
        if (!$this->passesStructuralChecks($content, $detected)) {
            return $this->failure('malformed_content', $detected);
        }

        return ['valid' => true, 'detected_mime' => $detected, 'reason' => null];
    }

    /**
     * @return array{valid:false,detected_mime:?string,reason:string}
     */
    private function failure(string $reason, ?string $detectedMime = null): array
    {
        return ['valid' => false, 'detected_mime' => $detectedMime, 'reason' => $reason];
    }

    /**
     * @param  list<string>  $allowedMimeTypes
     * @return list<string>
     */
    private function normalizeAllowed(array $allowedMimeTypes): array
    {
        $normalized = [];
        foreach ($allowedMimeTypes as $mime) {
            if (!is_string($mime)) {
                continue;
            }
            $value = strtolower(trim($mime));
            if ($value !== '' && in_array($value, self::ALLOWED_MIMES_DEFAULT, true)) {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    private function detectMime(string $content): ?string
    {
        return match (true) {
            str_starts_with($content, '%PDF-') => 'application/pdf',
            str_starts_with($content, "\x89PNG\r\n\x1a\n") => 'image/png',
            default => null,
        };
    }

    private function passesStructuralChecks(string $content, string $detectedMime): bool
    {
        return match ($detectedMime) {
            'application/pdf' => $this->looksLikePdf($content),
            'image/png' => $this->looksLikePng($content),
            default => false,
        };
    }

    private function looksLikePdf(string $content): bool
    {
        if (!str_starts_with($content, '%PDF-')) {
            return false;
        }
        if (preg_match('/\A1\.[0-7]\z/', substr($content, 5, 3)) !== 1) {
            return false;
        }
        // The prefix rules out HTML/script preambles; still require an EOF marker
        // and at least one object so a truncated burst is not accepted.
        if (strpos(substr($content, -1024), '%%EOF') === false) {
            return false;
        }

        return strpos($content, 'obj') !== false && strpos($content, 'endobj') !== false;
    }

    private function looksLikePng(string $content): bool
    {
        if (!str_starts_with($content, "\x89PNG\r\n\x1a\n") || strlen($content) < 33) {
            return false;
        }
        // IHDR must be the first chunk and must carry its fixed 13-byte payload.
        if (substr($content, 8, 4) !== pack('N', 13) || substr($content, 12, 4) !== 'IHDR') {
            return false;
        }

        // IEND is a zero-length final chunk; its CRC bytes are not security-relevant here.
        $tail = substr($content, -12);

        return strlen($tail) === 12 && substr($tail, 0, 8) === "\x00\x00\x00\x00IEND";
    }
}
