<?php

namespace SkinLoomBridge\Support;

use function count;
use function is_string;

class IpMatcher
{
    /**
     * Split raw allowlist text into normalized entries.
     *
     * @return array<int, string>
     */
    public static function parseList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
    }

    /**
     * Validate every IP or CIDR entry in raw allowlist text.
     */
    public static function validateList(string $raw): ?string
    {
        foreach (self::parseList($raw) as $entry) {
            if (!self::isValidEntry($entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Return true when allowlist is empty or clientIp matches at least one entry.
     */
    public static function allowed(?string $clientIp, string $rawAllowlist): bool
    {
        $entries = self::parseList($rawAllowlist);
        if (count($entries) === 0) {
            return true;
        }
        if (!is_string($clientIp) || $clientIp === '') {
            return false;
        }
        $ipBin = @inet_pton($clientIp);
        if ($ipBin === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if (self::matchesEntry($ipBin, $entry)) {
                return true;
            }
        }

        return false;
    }

    private static function isValidEntry(string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$subnet, $bitsRaw] = explode('/', $entry, 2);
        if (!ctype_digit($bitsRaw)) {
            return false;
        }
        $bits = (int) $bitsRaw;
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $bits >= 0 && $bits <= 32;
        }
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $bits >= 0 && $bits <= 128;
        }

        return false;
    }

    private static function matchesEntry(string $ipBin, string $entry): bool
    {
        if (!str_contains($entry, '/')) {
            $targetBin = @inet_pton($entry);

            return $targetBin !== false && hash_equals($ipBin, $targetBin);
        }

        [$subnet, $bitsRaw] = explode('/', $entry, 2);
        if (!ctype_digit($bitsRaw)) {
            return false;
        }
        $subnetBin = @inet_pton($subnet);
        if ($subnetBin === false || strlen($subnetBin) !== strlen($ipBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        $bits = (int) $bitsRaw;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }

        $mask = (~((1 << (8 - $remBits)) - 1)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
