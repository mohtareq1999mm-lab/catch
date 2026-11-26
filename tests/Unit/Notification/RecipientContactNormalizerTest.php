<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Services\Notification\RecipientContactNormalizer;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * #186: normalize email/phone only with reliable context, and keep email and
 * phone identities separate and non-reversible.
 */
final class RecipientContactNormalizerTest extends TestCase
{
    private Repository $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Repository([
            'recipient_identity' => [
                'fingerprint_secret' => 'test-only-recipient-fingerprint-secret',
                'key_version' => 'v1',
                'country_dial_codes' => [
                    'EG' => '+20',
                    'SA' => '+966',
                    'US' => '+1',
                ],
            ],
        ]);
    }

    public function test_email_is_trimmed_and_canonicalized_to_lowercase(): void
    {
        $normalizer = $this->normalizer();

        $this->assertSame('user@example.com', $normalizer->normalizeEmail('  User@Example.COM '));
        $this->assertSame('user@example.com', $normalizer->normalizeEmail('USER@EXAMPLE.COM'));
    }

    public function test_empty_email_is_null(): void
    {
        $normalizer = $this->normalizer();

        $this->assertNull($normalizer->normalizeEmail(null));
        $this->assertNull($normalizer->normalizeEmail('   '));
    }

    public function test_invalid_email_is_rejected(): void
    {
        $normalizer = $this->normalizer();

        foreach (['not-an-email', 'a b@example.com', 'user@', '@example.com', 'user@example'] as $invalid) {
            try {
                $normalizer->normalizeEmail($invalid);
                $this->fail("Email '{$invalid}' must be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid email address.', $e->getMessage());
            }
        }
    }

    public function test_international_phone_is_normalized_to_e164(): void
    {
        $normalizer = $this->normalizer();

        $this->assertSame('+201012345678', $normalizer->normalizePhone('+20 101 234 5678'));
        $this->assertSame('+201012345678', $normalizer->normalizePhone('+20-101-234-5678'));
        $this->assertSame('+201012345678', $normalizer->normalizePhone('00201012345678'));
    }

    public function test_national_phone_requires_reliable_country_context(): void
    {
        $normalizer = $this->normalizer();

        $this->assertSame('+201012345678', $normalizer->normalizePhone('01012345678', 'EG'));
        $this->assertSame('+201012345678', $normalizer->normalizePhone('1012345678', 'eg'));

        try {
            $normalizer->normalizePhone('01012345678');
            $this->fail('A national phone without country context must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Phone requires reliable country context.', $e->getMessage());
        }
    }

    public function test_unknown_or_invalid_country_context_is_rejected(): void
    {
        $normalizer = $this->normalizer();

        foreach (['ZZ', 'Egypt', 'E'] as $context) {
            try {
                $normalizer->normalizePhone('01012345678', $context);
                $this->fail("Country context '{$context}' must be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertContains($e->getMessage(), [
                    'Invalid country context.',
                    'Unknown country context.',
                ]);
            }
        }
    }

    public function test_ambiguous_or_malformed_phone_is_rejected(): void
    {
        $normalizer = $this->normalizer();

        foreach (['+20123', '+20abc123456', '01012345678 ext 4', '+0 1012345678'] as $invalid) {
            try {
                $normalizer->normalizePhone($invalid, 'EG');
                $this->fail("Phone '{$invalid}' must be rejected.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('phone', strtolower($e->getMessage()));
            }
        }
    }

    public function test_fingerprint_is_deterministic_and_hex_sha256(): void
    {
        $normalizer = $this->normalizer();

        $first = $normalizer->fingerprintEmail('User@Example.com');
        $second = $normalizer->fingerprintEmail(' user@example.com ');

        $this->assertSame($first, $second);
        $this->assertSame('v1', $first['key_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['fingerprint_hash']);
    }

    public function test_email_and_phone_fingerprints_are_channel_separated(): void
    {
        $normalizer = $this->normalizer();

        $email = $normalizer->fingerprint('email', '201012345678');
        $phone = $normalizer->fingerprint('phone', '201012345678');

        $this->assertNotSame($email['fingerprint_hash'], $phone['fingerprint_hash']);
    }

    public function test_fingerprint_never_contains_plaintext_contact(): void
    {
        $normalizer = $this->normalizer();

        $email = $normalizer->fingerprintEmail('user@example.com');
        $phone = $normalizer->fingerprintPhone('01012345678', 'EG');

        $this->assertStringNotContainsString('user', $email['fingerprint_hash']);
        $this->assertStringNotContainsString('example', $email['fingerprint_hash']);
        $this->assertStringNotContainsString('01012345678', $phone['fingerprint_hash']);
        $this->assertStringNotContainsString('201012345678', $phone['fingerprint_hash']);
    }

    public function test_custom_key_version_is_preserved(): void
    {
        $normalizer = $this->normalizer();

        $fingerprint = $normalizer->fingerprintEmail('user@example.com', 'v2');

        $this->assertSame('v2', $fingerprint['key_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fingerprint['fingerprint_hash']);
        $this->assertSame($fingerprint, $normalizer->fingerprintEmail('USER@example.com', 'v2'));
    }

    public function test_invalid_key_version_is_rejected(): void
    {
        $normalizer = $this->normalizer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fingerprint key version.');

        $normalizer->fingerprintEmail('user@example.com', 'v1 with spaces');
    }

    public function test_missing_fingerprint_secret_fails_closed(): void
    {
        $this->config->set('recipient_identity.fingerprint_secret', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Recipient fingerprint secret is not configured.');

        $this->normalizer()->fingerprintEmail('user@example.com');
    }

    public function test_unsupported_channel_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported recipient channel.');

        $this->normalizer()->fingerprint('sms', '201012345678');
    }

    private function normalizer(): RecipientContactNormalizer
    {
        return new RecipientContactNormalizer($this->config);
    }
}
