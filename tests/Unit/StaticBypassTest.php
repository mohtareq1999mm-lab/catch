<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Log;
use Marvel\Otp\StaticBypass;
use Tests\TestCase;

/**
 * #74: the ONE universal static-bypass check (ADR 0003).
 *
 * The check owns the decision for every OTP consumer (login today, the
 * Cancellation OTP service next): hard-blocked in production regardless of
 * configuration, gated only on the Local OTP switch, master value as a
 * value override of the local code (unset master = disabled), timing-safe
 * comparison, and one audit line per successful acceptance carrying the
 * actor identifier only — never the code or any credential.
 */
class StaticBypassTest extends TestCase
{
    private StaticBypass $bypass;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bypass = new StaticBypass();

        // Deterministic non-production base state; each test overrides what
        // it needs. master_otp = null models the shipped default (unset).
        config([
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '123456',
            'auth.master_otp' => null,
        ]);
        $this->app['env'] = 'testing';
    }

    /** @test */
    public function it_accepts_the_master_code_when_the_local_path_is_enabled()
    {
        config(['auth.master_otp' => '999888']);

        $this->assertTrue($this->bypass->accepts('999888', 'actor@example.test'));
        // Master is a value override of the local code: the default local
        // code is no longer accepted while a master value is configured.
        $this->assertFalse($this->bypass->accepts('123456', 'actor@example.test'));
    }

    /** @test */
    public function it_falls_back_to_the_local_code_when_no_master_value_is_set()
    {
        config(['auth.master_otp' => null]);

        $this->assertTrue($this->bypass->accepts('123456', 'actor@example.test'));
        $this->assertFalse($this->bypass->accepts('999888', 'actor@example.test'));
    }

    /** @test */
    public function it_treats_an_empty_master_value_as_disabled()
    {
        // Master set to '' (explicitly emptied) must not become the accepted
        // value; resolution falls through to the local code.
        config(['auth.master_otp' => '']);

        $this->assertTrue($this->bypass->accepts('123456', 'actor@example.test'));
        $this->assertFalse($this->bypass->accepts('', 'actor@example.test'));
    }

    /** @test */
    public function it_rejects_when_the_local_path_is_disabled_even_with_a_master_value()
    {
        // ADR 0003: the Local OTP switch is the ONLY gate for the bypass;
        // a configured master value never becomes its own enable switch.
        config([
            'auth.local_otp_enabled' => false,
            'auth.master_otp' => '999888',
        ]);

        $this->assertFalse($this->bypass->accepts('999888', 'actor@example.test'));
        $this->assertFalse($this->bypass->accepts('123456', 'actor@example.test'));
    }

    /** @test */
    public function it_rejects_empty_codes()
    {
        config(['auth.master_otp' => '999888']);

        $this->assertFalse($this->bypass->accepts('', 'actor@example.test'));
        $this->assertFalse($this->bypass->accepts(null, 'actor@example.test'));
    }

    /** @test */
    public function it_never_accepts_any_static_code_in_production()
    {
        $this->app['env'] = 'production';

        // Misconfigured on purpose: master set AND the local switch on —
        // production must still reject every static code.
        config([
            'auth.master_otp' => '123456',
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '123456',
        ]);

        Log::spy();

        $this->assertFalse($this->bypass->accepts('123456', 'actor@example.test'));
        $this->assertFalse($this->bypass->accepts('999888', 'actor@example.test'));

        // A production rejection is silent: no audit line either.
        Log::shouldNotHaveReceived('warning');
    }

    /** @test */
    public function the_audit_line_carries_the_actor_identifier_only()
    {
        config(['auth.master_otp' => '999888']);

        Log::shouldReceive('debug')->andReturnUsing(fn () => null);
        Log::shouldReceive('info')->andReturnUsing(fn () => null);
        Log::shouldReceive('notice')->andReturnUsing(fn () => null);
        Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$records) {
            $records[] = ['message' => $message, 'context' => $context];
        });
        Log::shouldReceive('error')->andReturnUsing(fn () => null);
        Log::shouldReceive('critical')->andReturnUsing(fn () => null);
        Log::shouldReceive('alert')->andReturnUsing(fn () => null);
        Log::shouldReceive('emergency')->andReturnUsing(fn () => null);
        $records = [];

        $this->assertTrue($this->bypass->accepts('999888', 'customer@example.test'));

        // Exactly one audit line, on the warning level, identifying the actor.
        $auditLines = array_values(array_filter($records, fn ($record) => str_contains(
            (string) $record['message'],
            'bypass'
        )));
        $this->assertCount(1, $auditLines, 'One audit line per successful acceptance.');
        $this->assertSame(
            'customer@example.test',
            $auditLines[0]['context']['actor'],
            'The audit line identifies the actor.'
        );

        // Negative sweep across EVERY logged record: the accepted code and
        // any credential must never appear in a message or a log context.
        $serialized = json_encode($records);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('999888', $serialized, 'The accepted code never lands in any log.');
        $this->assertStringNotContainsString('123456', $serialized, 'The local code never lands in any log.');
    }

    /** @test */
    public function rejections_write_no_audit_line()
    {
        config(['auth.master_otp' => '999888']);

        Log::spy();

        $this->assertFalse($this->bypass->accepts('000000', 'customer@example.test'));

        Log::shouldNotHaveReceived('warning');
    }
}
