<?php

namespace Marvel\Services\Import;

/**
 * The ONE SSRF guard for every imported image (candidate 5, architecture
 * review): scheme allowlist, host resolution (A + AAAA + fallback), and
 * the strictest of the two former per-path block policies — IPv4 ranges,
 * IPv6 forms including IPv4-mapped recursion, and the reserved-range
 * flags. A security invariant must not drift between image paths.
 *
 * Callers keep their own user-facing failure modes: this guard throws
 * UnsafeImageUrlException with an internal reason, and the caller
 * translates it.
 */
class SsrfGuard
{
    /**
     * Resolve the host and refuse any URL whose host does not resolve to a
     * routable public IP.
     *
     * @throws UnsafeImageUrlException
     */
    public function assertResolvablePublicHost(string $host): void
    {
        $host = trim($host, '[]');

        if ($host === '') {
            throw new UnsafeImageUrlException('Missing host');
        }

        $ips = $this->resolveHost($host);

        if (empty($ips)) {
            throw new UnsafeImageUrlException('Host does not resolve');
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp((string) $ip)) {
                throw new UnsafeImageUrlException('Host resolves to a private/reserved IP');
            }
        }
    }

    /**
     * The union DNS strategy: IP literals pass through, A + AAAA records
     * first (the product path's coverage — including IPv6), the IPv4-only
     * gethostbynamel as the fallback (the brand/category path's coverage).
     *
     * @return array<int, string>
     */
    public function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if (empty($ips)) {
            $resolved = gethostbynamel($host);

            if (is_array($resolved)) {
                $ips = $resolved;
            }
        }

        if (empty($ips)) {
            $ip = gethostbyname($host);

            if ($ip !== $host) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique(array_filter($ips)));
    }

    /**
     * The strictest of the two former policies: the brand/category path's
     * explicit IPv4 range table and IPv6 forms (unspecified, loopback,
     * IPv4-mapped recursion), with the product path's reserved-range flags
     * as the outer net.
     */
    public function isBlockedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isBlockedIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->isBlockedIpv6($ip);
        }

        return true;
    }

    private function isBlockedIpv4(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return true;
        }

        $unsigned = sprintf('%u', $long);

        $blocks = [
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['100.64.0.0', '100.127.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.0.0.0', '192.0.0.255'],
            ['192.0.2.0', '192.0.2.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['198.18.0.0', '198.19.255.255'],
            ['198.51.100.0', '198.51.100.255'],
            ['203.0.113.0', '203.0.113.255'],
            ['224.0.0.0', '239.255.255.255'],
            ['240.0.0.0', '255.255.255.255'],
        ];

        foreach ($blocks as [$start, $end]) {
            if ($unsigned >= sprintf('%u', ip2long($start)) && $unsigned <= sprintf('%u', ip2long($end))) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedIpv6(string $ip): bool
    {
        $bin = inet_pton($ip);

        if ($bin === false) {
            return true;
        }

        // Unspecified (::) and loopback (::1).
        if ($bin === str_repeat("\x00", 16) || $bin === str_repeat("\x00", 15) . "\x01") {
            return true;
        }

        // IPv4-mapped (::ffff:a.b.c.d): recurse into the embedded IPv4.
        if (substr($bin, 0, 10) === str_repeat("\x00", 10) && substr($bin, 10, 2) === "\xff\xff") {
            return $this->isBlockedIp(inet_ntop(substr($bin, 12, 4)));
        }

        // Unique-local (fc00::/7) and multicast (ff00::/8).
        if (str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'ff')) {
            return true;
        }

        return false;
    }
}
