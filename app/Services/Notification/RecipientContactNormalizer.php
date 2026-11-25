<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use RuntimeException;

/**
 * #186: normalize recipient contacts only with reliable context and derive
 * stable, channel-separated fingerprints.
 *
 * Rules:
 *  - Email is trimmed and canonicalized to lowercase, then validated.
 *  - Phone becomes E.164 only when it is already international or the caller
 *    supplies a reliable country context. Ambiguous local numbers are
 *    rejected; a country code is never guessed.
 *  - Email and phone remain separate channels: the channel is part of the
 *    HMAC input, so the same digits/characters cannot collide across channels.
 *  - Fingerprints are HMAC-SHA256 with a dedicated secret. The plaintext
 *    contact never appears in the returned value.
 */
final class RecipientContactNormalizer
{
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_PHONE = 'phone';

    public function __construct(private readonly Repository $config) {}

    public function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);
        if ($email === '') {
            return null;
        }

        if (strlen($email) > 254 || preg_match('/\s/', $email) === 1) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        $email = mb_strtolower($email, 'UTF-8');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email address.');
        }

        return $email;
    }

    public function normalizePhone(?string $phone, ?string $countryIso2 = null): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        $compact = preg_replace('/[\s\-().]+/', '', $phone);
        if (!is_string($compact) || $compact === '') {
            throw new InvalidArgumentException('Invalid phone number.');
        }

        if (str_starts_with($compact, '00')) {
            $compact = '+' . substr($compact, 2);
        }

        if (str_starts_with($compact, '+')) {
            return $this->assertE164('+' . substr($compact, 1));
        }

        if (preg_match('/^\d+$/', $compact) !== 1) {
            throw new InvalidArgumentException('Invalid phone number.');
        }

        // A national-format number is ambiguous without explicit country
        // context. Never guess a country code or reinterpret the digits.
        $iso = $countryIso2 === null ? '' : strtoupper(trim($countryIso2));
        if ($iso === '') {
            throw new InvalidArgumentException('Phone requires reliable country context.');
        }
        if (preg_match('/^[A-Z]{2}$/', $iso) !== 1) {
            throw new InvalidArgumentException('Invalid country context.');
        }

        $dialCode = $this->config->get("recipient_identity.country_dial_codes.{$iso}");
        if (!is_string($dialCode) || preg_match('/^\+[1-9]\d{0,3}$/', $dialCode) !== 1) {
            throw new InvalidArgumentException('Unknown country context.');
        }

        // Strip the national trunk prefix only after context is established.
        $national = ltrim($compact, '0');
        if (preg_match('/^\d{6,14}$/', $national) !== 1) {
            throw new InvalidArgumentException('Invalid phone number.');
        }

        return $this->assertE164($dialCode . $national);
    }

    /**
     * @return array{key_version:string,fingerprint_hash:string}
     */
    public function fingerprintEmail(string $email, ?string $keyVersion = null): array
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === null) {
            throw new InvalidArgumentException('Email is required.');
        }

        return $this->fingerprint(self::CHANNEL_EMAIL, $normalized, $keyVersion);
    }

    /**
     * @return array{key_version:string,fingerprint_hash:string}
     */
    public function fingerprintPhone(string $phone, ?string $countryIso2 = null, ?string $keyVersion = null): array
    {
        $normalized = $this->normalizePhone($phone, $countryIso2);
        if ($normalized === null) {
            throw new InvalidArgumentException('Phone is required.');
        }

        return $this->fingerprint(self::CHANNEL_PHONE, $normalized, $keyVersion);
    }

    /**
     * @return array{key_version:string,fingerprint_hash:string}
     */
    public function fingerprint(string $channel, string $normalizedContact, ?string $keyVersion = null): array
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, [self::CHANNEL_EMAIL, self::CHANNEL_PHONE], true)) {
            throw new InvalidArgumentException('Unsupported recipient channel.');
        }

        $contact = trim($normalizedContact);
        if ($contact === '') {
            throw new InvalidArgumentException('Normalized contact is required.');
        }

        $secret = $this->config->get('recipient_identity.fingerprint_secret');
        if (!is_string($secret) || trim($secret) === '') {
            // Fail closed: a predictable fallback would let an attacker
            // enumerate contacts from stored fingerprints.
            throw new RuntimeException('Recipient fingerprint secret is not configured.');
        }

        $version = $keyVersion ?? (string) $this->config->get('recipient_identity.key_version', 'v1');
        $version = trim($version);
        if (preg_match('/^[A-Za-z0-9_.:-]{1,32}$/', $version) !== 1) {
            throw new InvalidArgumentException('Invalid fingerprint key version.');
        }

        return [
            'key_version' => $version,
            'fingerprint_hash' => hash_hmac('sha256', $channel . '|' . $contact, $secret),
        ];
    }

    private function assertE164(string $candidate): string
    {
        $digits = substr($candidate, 1);
        if (preg_match('/^[1-9]\d{7,14}$/', $digits) !== 1) {
            throw new InvalidArgumentException('Phone is not a valid E.164 number.');
        }

        return '+' . $digits;
    }
}
