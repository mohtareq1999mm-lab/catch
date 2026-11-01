<?php

namespace Tests\Unit;

use App\Services\Payment\TamaraWebhookVerifier;
use Firebase\JWT\JWT;
use Tests\TestCase;

/**
 * TamaraWebhookVerifier unit tests (firebase/php-jwt v6, vendored).
 *
 * Tamara's documented webhook authentication: the delivery carries a
 * `tamaraToken` (query string AND Authorization: Bearer header) that is an
 * HS256 JWT signed with the merchant's Notification Token — sample claims
 * {exp, iat, iss: "Tamara"}. Verification is the cryptographic check.
 *
 * Every case here is clock/algo-faithful: tokens are ENCODED with a real
 * signing step (no mocks of the verifier internals); expired tokens use
 * past exp; cross-algorithm tokens are genuinely RS256-signed with a
 * generated key pair (no short-cuts), because the config pins the HS256
 * decode key.
 */
class TamaraWebhookVerifierTest extends TestCase
{
    private const SECRET = 'unit_tamara_notification_token_secret';

    /**
     * Test-only 2048-bit RSA private key (PKCS#8 PEM), generated once as a
     * fixture. NEVER a production credential — used solely to produce a
     * genuinely RS256-signed token that must still be rejected by the
     * HS256-pinned verifier. A static PEM is deliberate: runtime RSA
     * generation (openssl_pkey_new) depends on an openssl.cnf that this
     * Windows host lacks, and the fixture keeps the suite portable
     * (Linux CI / local Windows) with zero environment tuning.
     */
    private const RS256_FIXTURE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCsYu3jb5eyh7pY
+1YS/YdwbjRliyMnWAc+T9tLPrROU4kecBNzN9P7bAJhtYNxWDoTWDL0lmLhtW9u
hXwpsguNCP2vPk0/LKfbIg/tK3qAOBRMLeKXotLEPmhJcZyXcwEd8spIGXLtlXpg
euzU4j2WM+a/cK78L1NyDayHUQL5hRX9lePJ9FCPN1/3duWlB+ex0MO8iFlxr1ZG
hnFOqK135tbR6UUc2KlTbLkkpADeiwywQfzEnSaju1iYpjc2+T52sMW47CtRilSO
5MGRCdsF6xSE6uHRH9xjDZQ542Qj2Iia4g9VR4sPtsPPqDpf75iUmvxwXU2ssWRs
ajECxrCZAgMBAAECggEAHayr+a5AhU2DAQVG9F7rPSnjYCV66C9pk8roW4QQQrlz
jadKWdee6JVXtZ0q4syGtsTEhMEMhYEXjQVycbUB8wW8sbl+T6UuwfSTnEaTQFYm
/FQbwPGY6Yo6poXlqFZ2N0MPmiizVX2ZQtl2QlEtu9j0XDkYDjZRXX1IGUUTR4Ne
ANSrY3CXFoze+Bh+fWMStxaVl30KKgZzx8tHq899cMqWkY3BznJdh6zSYfmp8kSP
gamwjYpK40XN/EAGjRkt1hUaAappVOAFGCkvTdXfAcgLh4NTj2L1AmOXTkcVv5vI
wS6a9iO1cA2/lQUl2WZL2IqVcT7EX1MLlWxNuXZAmQKBgQDtQClP5EVJPVHrAopg
D0qFoywZWLChCu8xfJihkWjyGWQsN4sHsBE0q5XU2DR5JFqTrAF0t70oo+UQpmYr
R52t1R4H3hP1hyhR0SpvowtpVKmoOB418XzXDWym2hVAAz8+cbnitMI2wedvhzzP
Kz6uqqOmXXraXXQBUKb+XnvZ9QKBgQC6An6lngAG8TqwCUG9YD3uVel8hDW38jC8
DFX1EMk0r4gXzhwhQm6SUxOl+sQa6MahhEx2adMyDanYHmiaDWKld8O7TqqfNg9S
WhqAPl11i+0O9H8tLeDkmtB2bPgWM46mdhju8c+k1+L3UaZo3hKEkzdW5QIGEW25
JV0gMs9hlQKBgQCjVAFcw5xDQFitvhD+8mfuOhf41wq5wypJ2inFSLKDwKEqo44t
27LsnJdIcRMmtrU4ZjiWXot1d+cRIEzDzMirTEUhS2xYIhTKxoE9W3RWrfciHIgH
wNidv9slvorUh6e5WFeMz73ILpMiP2rIgE/ltQl2VXJm8+ERqrFb+jWvJQKBgQCF
0felVQd19ySesvotsUi85+gDoeY3wTDYiRNqLLpkgggqAClysCV2dfhDyCY/qcoF
N3X/FuFPOE9B4Zalhg27N2+EmPLdefoJ6DL6omOAhrt0r+ey7DfIn6xsFrdg2ZS+
I2aKuUMGuB5cicxr06CE6pi8liMflHhY5A2cdZGHBQKBgCQ/RTM6hQKewOPK2zA2
M5t9scT+gpi/H0c1od8RlZY19taDOqXiHPGmG5PgKPviqDkVrUBx1roSS52/AY3K
7qnVMmE3Traycma9XP9c8Atlf+wPWK/XHM3gnWtH8V6QjzY8Uph259dZOho0ygzP
Id1Q4MV7/zJUueeKhqRXMVwb
-----END PRIVATE KEY-----
PEM;

    private function verifier(): TamaraWebhookVerifier
    {
        return app(TamaraWebhookVerifier::class);
    }

    private function hs256Token(array $claims = [], array $headers = []): string
    {
        $now = time();

        return JWT::encode(
            array_merge([
                'exp' => $now + 300,
                'iat' => $now,
                'iss' => 'Tamara',
            ], $claims),
            self::SECRET,
            'HS256',
            null,
            $headers,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment.gateways.tamara.notification_token' => self::SECRET]);
    }

    /** @test */
    public function accepts_valid_hs256_token_from_either_channel(): void
    {
        $token = $this->hs256Token();

        $this->assertTrue($this->verifier()->verify($token, null));
        $this->assertTrue($this->verifier()->verify(null, $token));
        $this->assertTrue($this->verifier()->verify($token, $token));
    }

    /** @test */
    public function rejects_conflicting_dual_channel_tokens(): void
    {
        $this->assertFalse($this->verifier()->verify(
            $this->hs256Token(['jti' => 'a']),
            $this->hs256Token(['jti' => 'b']),
        ));
    }

    /** @test */
    public function rejects_missing_token_on_both_channels(): void
    {
        $this->assertFalse($this->verifier()->verify(null, null));
        $this->assertFalse($this->verifier()->verify('', ''));
    }

    /** @test */
    public function rejects_tampered_signature(): void
    {
        $token = $this->hs256Token();
        [$head, $body, $sig] = explode('.', $token);

        // Flip a byte inside the signature payload.
        $tamperedSig = $sig;
        $tamperedSig[0] = $tamperedSig[0] === 'A' ? 'B' : 'A';

        $this->assertFalse($this->verifier()->verify($head . '.' . $body . '.' . $tamperedSig, null));
    }

    /** @test */
    public function rejects_expired_token(): void
    {
        $expired = $this->hs256Token(['exp' => time() - 1]);

        $this->assertFalse($this->verifier()->verify($expired, null));
    }

    /** @test */
    public function rejects_token_signed_with_a_different_secret(): void
    {
        $foreign = JWT::encode(
            ['exp' => time() + 300, 'iat' => time(), 'iss' => 'Tamara'],
            'attacker_hovered_key_material',
            'HS256',
        );

        $this->assertFalse($this->verifier()->verify($foreign, null));
    }

    /** @test */
    public function rejects_genuinely_rs256_signed_token_against_hs256_key(): void
    {
        // Genuinely RS256-signed with the fixture RSA key: the signature is
        // valid under its own key, but the config pins the HS256 decode
        // key — a valid-but-different-algorithm delivery must still be
        // rejected.
        $rsToken = JWT::encode(
            ['exp' => time() + 300, 'iat' => time(), 'iss' => 'Tamara'],
            self::RS256_FIXTURE_PEM,
            'RS256',
        );

        $this->assertFalse($this->verifier()->verify($rsToken, null));
    }

    /** @test */
    public function rejects_malformed_token_strings(): void
    {
        $this->assertFalse($this->verifier()->verify('not-a-jwt', null));
        $this->assertFalse($this->verifier()->verify('a.b', null));
        $this->assertFalse($this->verifier()->verify('a.b.c.d', null));
    }

    /** @test */
    public function fails_closed_without_a_configured_secret(): void
    {
        config(['payment.gateways.tamara.notification_token' => '']);

        $this->assertFalse($this->verifier()->verify($this->hs256Token(), null));
    }

    /** @test */
    public function rejects_token_signed_with_a_rotated_secret(): void
    {
        // The configured Notification Token no longer matches the one that
        // signed this delivery (key rotation / wrong environment secret).
        config(['payment.gateways.tamara.notification_token' => 'rotated_secret_that_is_not_the_issuers']);

        $this->assertFalse($this->verifier()->verify($this->hs256Token(), null));
    }
}
