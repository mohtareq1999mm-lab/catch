<?php

namespace App\Services\Digital;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * SSRF-safe static validation for EXTERNAL URL digital assets.
 *
 * SECURITY MODEL (Workstream 5): the application never fetches, proxies or
 * follows a stored URL. Validation is therefore static + one-time DNS
 * resolution at creation time:
 *
 *   - scheme restricted by config (https-only by default)
 *   - userinfo (user:pass@host) rejected
 *   - hostname deny-list / optional allowlist
 *   - literal IPv4/IPv6 hosts must be public
 *   - hostnames resolved once; EVERY A/AAAA record must be public
 *   - IPv4-mapped IPv6 (::ffff:a.b.c.d) unpacked and re-checked
 *
 * Known limitation (documented): DNS TOCTOU/rebinding cannot be eliminated
 * in a no-fetch model because no connection is ever made by this app; a
 * hostile host may rotate DNS after creation. The customer's own browser
 * performs the eventual connection — this application is never the SSRF
 * client.
 */
class ExternalUrlValidator
{
    public function validate(string $url): string
    {
        $config = config('digital.external_urls');
        $url = trim($url);

        if ($url === '' || strlen($url) > (int) ($config['max_length'] ?? 2048)) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_URL'));
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_URL'));
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, array_map('strtolower', (array) ($config['allowed_schemes'] ?? ['https'])), true)) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_URL'));
        }

        if (!empty($parts['user']) && !($config['allow_userinfo'] ?? false)) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_URL'));
        }

        if (isset($parts['port']) && (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535)) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_INVALID_URL'));
        }

        $host = strtolower($parts['host']);

        // Literal IPv6 arrives bracketed from parse_url.
        $bareHost = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;

        $this->assertHostnameAllowed($bareHost, $config);

        if (filter_var($bareHost, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($bareHost);
        } else {
            $this->assertPublicDns($bareHost);
        }

        return $this->normalize($scheme, $host, $parts);
    }

    private function assertHostnameAllowed(string $host, array $config): void
    {
        foreach ((array) ($config['blocked_suffixes'] ?? []) as $suffix) {
            if (str_ends_with($host, strtolower((string) $suffix))) {
                throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
            }
        }

        foreach ((array) ($config['blocked_hostnames'] ?? []) as $blocked) {
            if (hash_equals(strtolower((string) $blocked), $host)) {
                throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
            }
        }

        $allowlist = array_map('strtolower', (array) ($config['allowed_hostnames'] ?? []));

        if ($allowlist !== [] && !in_array($host, $allowlist, true)) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
        }
    }

    /** Every record the name resolves to must be publicly routable. */
    private function assertPublicDns(string $host): void
    {
        $ips = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($ips === false || $ips === []) {
            // Unresolvable at creation time = not a usable resource.
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_UNRESOLVABLE'));
        }

        foreach ($ips as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip) && $ip !== '') {
                $this->assertPublicIp($ip);
            }
        }
    }

    public function assertPublicIp(string $ip): void
    {
        // Unpack IPv4-mapped IPv6 first (::ffff:10.0.0.1 bypass attempt).
        $packed = @inet_pton($ip);

        if ($packed !== false && strlen($packed) === 16 && str_starts_with($bin = bin2hex($packed), '00000000000000000000ffff')) {
            $v4 = long2ip((int) hexdec(substr($bin, 24)));
            $this->rejectNonPublicIpv4($v4);

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->rejectNonPublicIpv4($ip);

            return;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->rejectNonPublicIpv6(strtolower($ip));

            return;
        }

        throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
    }

    private function rejectNonPublicIpv4(string $ip): void
    {
        // Belt & braces beyond PHP filters (covers reserved/documentation/
        // carrier-NAT/broadcast ranges the flags miss).
        $blocked = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
            '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
            '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/5', '255.255.255.255/32',
        ];

        $long = ip2long($ip);

        if ($long === false) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
        }

        foreach ($blocked as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);

            if (($long & $mask) === (ip2long($net) & $mask)) {
                throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
            }
        }
    }

    private function rejectNonPublicIpv6(string $ip): void
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
        }

        // Expanded form: 32 lowercase hex chars — exact prefix matching.
        $hex = bin2hex($packed);

        if ($hex === str_repeat('0', 32)) {                       // ::
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
        }

        if ($hex === str_repeat('0', 31) . '1') {                 // ::1 loopback
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
        }

        if (str_starts_with($hex, '00000000000000000000ffff')) {  // v4-mapped
            $this->rejectNonPublicIpv4(long2ip((int) hexdec(substr($hex, 24))));

            return;
        }

        foreach (
            [
                'fe8', 'fe9', 'fea', 'feb',           // fe80::/10 link-local
                'fec', 'fed', 'fee', 'fef',           // fec0::/10 site-local
                'fc', 'fd',                           // fc00::/7 unique local
                'ff',                                 // ff00::/8 multicast
            ] as $prefix
        ) {
            if (str_starts_with($hex, $prefix)) {
                throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
            }
        }

        if (str_starts_with($hex, '20010db8')) {                  // documentation
            throw new HttpException(422, __('message.ERROR.DIGITAL_ASSET_URL_BLOCKED'));
        }
    }

    private function normalize(string $scheme, string $bracketedHost, array $parts): string
    {
        $url = $scheme . '://' . $bracketedHost;

        if (isset($parts['port'])) {
            $default = $scheme === 'https' ? 443 : 80;
            if ((int) $parts['port'] !== $default) {
                $url .= ':' . $parts['port'];
            }
        }

        $url .= $parts['path'] ?? '/';
        $url .= isset($parts['query']) ? '?' . $parts['query'] : '';

        return $url;
    }
}
