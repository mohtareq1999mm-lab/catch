<?php

namespace Tests\Unit\Services;

use Marvel\Services\Import\SsrfGuard;
use Marvel\Services\Import\UnsafeImageUrlException;
use Tests\TestCase;

/**
 * The ONE SSRF policy for every imported image (architecture candidate 5):
 * the strictest of the two former per-path rules — explicit IPv4 ranges,
 * IPv6 forms (unspecified, loopback, IPv4-mapped recursion, unique-local,
 * multicast), the reserved-range flags, and IP-literal hosts — must not
 * drift between the product and brand/category image paths.
 *
 * Host-name resolution is DNS-dependent and covered by the import suites;
 * this file pins the pure policy.
 */
class SsrfGuardTest extends TestCase
{
    private SsrfGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new SsrfGuard();
    }

    /** @test */
    public function loopback_and_private_and_reserved_ipv4_is_blocked()
    {
        foreach (['127.0.0.1', '10.1.2.3', '192.168.0.5', '172.16.0.9', '169.254.1.1', '0.0.0.0', '100.64.0.1', '192.0.2.1', '198.51.100.7', '203.0.113.9', '224.0.0.1', '240.0.0.1', '255.255.255.255'] as $ip) {
            $this->assertTrue($this->guard->isBlockedIp($ip), "$ip must be blocked");
        }
    }

    /** @test */
    public function ipv6_special_forms_are_blocked()
    {
        foreach (['::1', '::', '::ffff:10.0.0.5', '::ffff:192.168.1.1', 'fc00::1', 'fd12:3456::1', 'ff02::1', 'fe80::1', 'FC00::1', 'FE80::A1', '2001:db8::1'] as $ip) {
            $this->assertTrue($this->guard->isBlockedIp($ip), "$ip must be blocked");
        }
    }

    /** @test */
    public function a_routable_public_ipv4_is_allowed()
    {
        $this->assertFalse($this->guard->isBlockedIp('8.8.8.8'));
        $this->assertFalse($this->guard->isBlockedIp('1.1.1.1'));
    }

    /** @test */
    public function a_routable_public_ipv6_is_allowed()
    {
        $this->assertFalse($this->guard->isBlockedIp('2606:4700:4700::1111'));
    }

    /** @test */
    public function non_ip_garbage_is_blocked()
    {
        $this->assertTrue($this->guard->isBlockedIp('not-an-ip'));
        $this->assertTrue($this->guard->isBlockedIp(''));
    }

    /** @test */
    public function ip_literal_hosts_are_evaluated_directly()
    {
        // An IP literal host: resolveHost passes it through; the policy
        // blocks the private one.
        $this->assertSame(['127.0.0.1'], $this->guard->resolveHost('127.0.0.1'));

        $this->expectException(UnsafeImageUrlException::class);
        $this->guard->assertResolvablePublicHost('127.0.0.1');
    }
}
