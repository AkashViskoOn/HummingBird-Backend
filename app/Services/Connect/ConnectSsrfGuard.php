<?php

namespace App\Services\Connect;

/**
 * Humming Bird Connect — SSRF guard for outbound webhook URLs (Phase 7).
 *
 * Rejects URLs that would let a tenant bounce the backend's HTTP client off
 * internal infrastructure:
 *
 *   - scheme must be https/http
 *   - hostname must not be empty / must not be an IP literal we cannot parse
 *   - DNS-free checks only (no network calls), so validation is cheap and can
 *     run synchronously at webhook create/update time
 *   - blocked: loopback (127.0.0.0/8, ::1, localhost, *.localhost), link-local
 *     (169.254.0.0/16, fe80::/10), reserved/private ranges, any *.local /
 *     .internal / bare single-label hostnames, and non-zero port literals are
 *     allowed only when the URL scheme is http/https and the port is a
 *     conventional web port (80/443) — anything else is treated as
 *     infrastructure-targeting.
 *
 * The checks are intentionally conservative: a tenant can always point the
 * webhook at a public HTTPS URL. Blocking private ranges prevents the classic
 * SSRF-to-metadata / SSRF-to-internal-service attacks on shared infrastructure
 * (cloud metadata endpoints, internal dashboards, the DB host, the socket
 * server, etc.).
 */
class ConnectSsrfGuard
{
    /**
     * @return string|null null when the URL is allowed, or a human-readable
     *                     reason when it must be rejected
     */
    public function check(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048) {
            return 'Webhook URL is required and must be under 2048 characters.';
        }

        $parsed = parse_url($url);
        if ($parsed === false) {
            return 'Webhook URL is not parseable.';
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'Webhook URL scheme must be http or https.';
        }

        $host = strtolower(trim((string) ($parsed['host'] ?? '')));
        if ($host === '') {
            return 'Webhook URL is missing a host.';
        }

        if ($this->looksLikeIp($host)) {
            if ($this->isBlockedIp($host)) {
                return 'Webhook URL host must be a public IP address.';
            }
        } else {
            $check = $this->checkHostname($host);
            if ($check !== null) {
                return $check;
            }
        }

        // Port policy: only standard web ports are acceptable. A tenant
        // pointing at an internal service on a random port is a red flag.
        $port = $parsed['port'] ?? null;
        if ($port !== null && ! in_array((int) $port, [80, 443], true)) {
            return 'Webhook URL port must be 80 or 443.';
        }

        $userinfo = ($parsed['user'] ?? '') || ($parsed['pass'] ?? '');
        if ($userinfo) {
            return 'Webhook URL must not embed credentials.';
        }

        return null;
    }

    /**
     * Delivery-time re-validation (DNS rebinding defense). Registration checks
     * the literal hostname DNS-free; the webhook worker calls this right before
     * POSTing so a host that now resolves to loopback/private/link-local/CGNAT
     * address space is refused. Literal IPs and unresolvable hosts (possibly a
     * transient DNS failure — the transport layer retries) pass through.
     *
     * @return string|null null when delivery may proceed, else a reject reason
     */
    public function checkResolved(string $host): ?string
    {
        $host = strtolower(trim($host));
        if ($host === '' || $this->looksLikeIp($host)) {
            return null;
        }

        $records = @gethostbynamel($host);
        if ($records === false || $records === []) {
            return null;
        }

        foreach ($records as $ip) {
            if ($this->isBlockedIp($ip)) {
                return 'Webhook URL host resolves to a non-public address.';
            }
        }

        return null;
    }

    private function looksLikeIp(string $host): bool
    {
        return strpos($host, ':') !== false
            || preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host) === 1
            || preg_match('/^[0-9a-f]{0,4}(:[0-9a-f]{0,4}){7}$/i', $host) === 1;
    }

    private function isBlockedIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = (int) ip2long($host);
            $blocks = [
                [0x00000000, 0x00FFFFFF],       // 0.0.0.0/8 "this network"
                [0x64400000, 0x647FFFFF],       // 100.64.0.0/10 CGNAT
                [0x0A000000, 0x0AFFFFFF],       // 10.0.0.0/8
                [0x7F000000, 0x7FFFFFFF],       // 127.0.0.0/8 loopback
                [0xA9FE0000, 0xA9FEFFFF],       // 169.254.0.0/16 link-local
                [0xAC100000, 0xAC1FFFFF],       // 172.16.0.0/12
                [0xC0000000, 0xC00000FF],       // 192.0.0.0/24 (IETF protocol assignment)
                [0xC0A80000, 0xC0A8FFFF],       // 192.168.0.0/16
                [0xC6120000, 0xC613FFFF],       // 198.18.0.0/15 (benchmarking)
                [0xE0000000, 0xFFFFFFFF],       // 224.0.0.0/4 + broadcast
            ];
            foreach ($blocks as [$min, $max]) {
                if ($long >= $min && $long <= $max) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // ::1 (loopback), :: (unspecified), fc00::/7 (ULA), fe80::/10
            // (link-local), and the IPv4-mapped/embedded forms are all blocked.
            $lower = strtolower($host);
            if (in_array($lower, ['::1', '::'], true) || strpos($lower, ':') === 0) {
                return true;
            }

            $firstGroup = $this->firstIpv6Group($lower);
            if ($firstGroup !== '' && (preg_match('/^f[cd]/', $firstGroup) || preg_match('/^fe[89ab]/', $firstGroup))) {
                return true;
            }

            return false;
        }

        // Not a recognizable IP — treat as blocked (refuse to resolve).
        return true;
    }

    private function firstIpv6Group(string $ipv6): string
    {
        $parts = explode(':', $ipv6);

        return $parts[0] ?? '';
    }

    private function checkHostname(string $host): ?string
    {
        // Unambiguous internal single-label names and wildcard suffixes.
        if ($host === 'localhost' || strpos($host, '.') === false) {
            return 'Webhook URL host must be a fully-qualified public hostname.';
        }

        foreach (['.localhost', '.local', '.internal', '.lan', '.home.arpa', '.test', '.example'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return 'Webhook URL host uses a non-routable domain suffix.';
            }
        }

        // cloud metadata hostnames — the classic SSRF target.
        foreach (['metadata.google.internal', '169.254.169.254.nip.io', '169.254.170.2', 'metadata'] as $target) {
            if ($host === $target) {
                return 'Webhook URL host is a cloud metadata endpoint.';
            }
        }

        return null;
    }
}