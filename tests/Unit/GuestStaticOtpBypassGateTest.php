<?php

namespace Tests\Unit;

use App\Services\Guest\StaticOtpBypassGate;
use Tests\TestCase;

/**
 * Contract for the static-bypass decision point (ADR-0003 + D4):
 *
 * - The accepted value is the Master OTP when non-empty, else the
 *   Local OTP code — a value override, never an enable switch.
 * - Acceptance is gated ONLY on the Local OTP flag, regardless of
 *   which gateway is selected.
 * - Production hard-blocks the bypass regardless of environment values.
 * - An empty resolved expected value refuses everything.
 * - Comparison is timing-safe.
 */
class GuestStaticOtpBypassGateTest extends TestCase
{
    private const MASTER_CODE = '998877';

    private const LOCAL_CODE = '123456';

    /** @test */
    public function the_static_bypass_accepts_the_resolved_value_end_to_end(): void
    {
        $this->assertMatrix([true, 'testing', 'master', true]);
    }

    /**
     * The full gating matrix: enabled/disabled x production/non-production
     * x Master/local/empty resolved value. Each row is a literal of the
     * documented policy, not a recomputation of the gate's logic.
     *
     * @return array<string, array{0: bool, 1: string, 2: string, 3: bool}>
     */
    public static function gatingMatrix(): array
    {
        return [
            // enabled | env          | resolved | expected
            'enabled, non-production, master resolved' => [true, 'testing', 'master', true],
            'enabled, non-production, local resolved' => [true, 'testing', 'local', true],
            'enabled, non-production, empty resolved' => [true, 'testing', 'empty', false],
            'enabled, production, master resolved' => [true, 'production', 'master', false],
            'enabled, production, local resolved' => [true, 'production', 'local', false],
            'enabled, production, empty resolved' => [true, 'production', 'empty', false],
            'disabled, non-production, master resolved' => [false, 'testing', 'master', false],
            'disabled, non-production, local resolved' => [false, 'testing', 'local', false],
            'disabled, non-production, empty resolved' => [false, 'testing', 'empty', false],
            'disabled, production, master resolved' => [false, 'production', 'master', false],
            'disabled, production, local resolved' => [false, 'production', 'local', false],
            'disabled, production, empty resolved' => [false, 'production', 'empty', false],
        ];
    }

    /** @dataProvider gatingMatrix */
    public function the_gate_resolves_the_gating_matrix(bool $enabled, string $environment, string $resolved, bool $shouldAccept): void
    {
        $this->assertMatrix([$enabled, $environment, $resolved, $shouldAccept]);
    }

    /** @test */
    public function the_master_code_overrides_the_local_code_as_the_accepted_value(): void
    {
        $gate = $this->matrixGate([true, 'testing', 'master']);

        $this->assertTrue($gate->accepts(self::MASTER_CODE));
        $this->assertFalse($gate->accepts(self::LOCAL_CODE));
    }

    /** @test */
    public function an_unset_master_otp_falls_back_to_the_local_code(): void
    {
        $gate = $this->matrixGate([true, 'testing', 'local']);

        $this->assertTrue($gate->accepts(self::LOCAL_CODE));
    }

    /** @test */
    public function an_empty_master_value_falls_back_to_the_local_code(): void
    {
        config([
            'app.env' => 'testing',
            'auth.local_otp_enabled' => true,
            'auth.master_otp' => '',
            'auth.local_otp_code' => self::LOCAL_CODE,
        ]);

        $this->assertTrue((new StaticOtpBypassGate)->accepts(self::LOCAL_CODE));
    }

    /** @test */
    public function a_wrong_code_is_refused_even_when_everything_else_allows_the_bypass(): void
    {
        config([
            'app.env' => 'testing',
            'auth.local_otp_enabled' => true,
            'auth.master_otp' => null,
            'auth.local_otp_code' => self::LOCAL_CODE,
        ]);

        $this->assertFalse((new StaticOtpBypassGate)->accepts('654321'));
    }

    /** @test */
    public function a_wrong_code_of_a_different_length_is_refused_without_error(): void
    {
        config([
            'app.env' => 'testing',
            'auth.local_otp_enabled' => true,
            'auth.master_otp' => null,
            'auth.local_otp_code' => self::LOCAL_CODE,
        ]);

        $gate = new StaticOtpBypassGate;

        $this->assertFalse($gate->accepts('1'));
        $this->assertFalse($gate->accepts(str_repeat('9', 64)));
    }

    /** @test */
    public function an_empty_provided_code_is_refused(): void
    {
        config([
            'app.env' => 'testing',
            'auth.local_otp_enabled' => true,
            'auth.master_otp' => null,
            'auth.local_otp_code' => self::LOCAL_CODE,
        ]);

        $this->assertFalse((new StaticOtpBypassGate)->accepts(''));
    }

    /** @test */
    public function acceptance_does_not_depend_on_the_selected_gateway(): void
    {
        config([
            'app.env' => 'testing',
            'auth.active_otp_gateway' => 'twilio',
            'auth.local_otp_enabled' => true,
            'auth.master_otp' => null,
            'auth.local_otp_code' => self::LOCAL_CODE,
        ]);

        $this->assertTrue((new StaticOtpBypassGate)->accepts(self::LOCAL_CODE));

        config(['auth.local_otp_enabled' => false]);

        $this->assertFalse((new StaticOtpBypassGate)->accepts(self::LOCAL_CODE));
    }

    /** @test */
    public function the_committed_marvel_config_defaults_master_otp_to_unset(): void
    {
        $marvelAuthConfig = (string) file_get_contents(base_path('packages/marvel/config/auth.php'));

        // Null default: "not configured" must stay distinguishable from an
        // explicitly configured value (design record, section 6).
        $this->assertMatchesRegularExpression(
            "/^\s*'master_otp'\s*=>\s*env\('MASTER_OTP'\),\s*$/m",
            $marvelAuthConfig
        );

        // Value-only, single home (ADR-0003): the app-level auth config
        // must not duplicate the OTP block.
        $this->assertStringNotContainsString(
            'master_otp',
            (string) file_get_contents(config_path('auth.php'))
        );
    }

    /**
     * Assert one row of the gating matrix through the public seam.
     *
     * @param  array{0: bool, 1: string, 2: string, 3: bool}  $row  [enabled, environment, resolved, shouldAccept]
     */
    private function assertMatrix(array $row): void
    {
        $accepted = $this->matrixGate($row)->accepts($this->codeForResolved($row[2]));

        $this->assertSame($row[3], $accepted, "Matrix row failed: ".json_encode($row));
    }

    /**
     * Build a gate against one configured matrix scenario.
     *
     * @param  array{0: bool, 1: string, 2: string}  $row  [enabled, environment, resolved]
     */
    private function matrixGate(array $row): StaticOtpBypassGate
    {
        [$enabled, $environment, $resolved] = $row;

        config([
            'app.env' => $environment,
            'auth.local_otp_enabled' => $enabled,
            'auth.local_otp_code' => $resolved === 'empty' ? '' : self::LOCAL_CODE,
            'auth.master_otp' => $resolved === 'master' ? self::MASTER_CODE : null,
        ]);

        return new StaticOtpBypassGate;
    }

    private function codeForResolved(string $resolved): string
    {
        return $resolved === 'master' ? self::MASTER_CODE : self::LOCAL_CODE;
    }
}
