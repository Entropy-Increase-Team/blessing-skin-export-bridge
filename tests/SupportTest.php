<?php

require_once __DIR__.'/../src/Support/IpMatcher.php';
require_once __DIR__.'/../src/Support/TextureReader.php';

use SkinLoomBridge\Support\IpMatcher;
use SkinLoomBridge\Support\TextureReader;

function assertSameValue($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL [%s]: expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true)));
        exit(1);
    }
}

// 1. IpMatcher::parseList & validateList
assertSameValue(['203.0.113.10', '10.0.0.0/8', '2001:db8::/32'], IpMatcher::parseList("203.0.113.10, 10.0.0.0/8\n2001:db8::/32;"), 'parseList');
assertSameValue(null, IpMatcher::validateList('127.0.0.1, 10.0.0.0/8, 192.168.1.0/24, ::1, 2001:db8::/32'), 'validateList valid');
assertSameValue('10.0.0.0/33', IpMatcher::validateList('127.0.0.1, 10.0.0.0/33'), 'validateList invalid IPv4 CIDR');
assertSameValue('2001:db8::/129', IpMatcher::validateList('2001:db8::/129'), 'validateList invalid IPv6 CIDR');
assertSameValue('not-an-ip', IpMatcher::validateList('not-an-ip'), 'validateList invalid IP');

// 2. IpMatcher::allowed
assertSameValue(true, IpMatcher::allowed('198.51.100.7', ''), 'empty allowlist allows all');
assertSameValue(true, IpMatcher::allowed('10.42.5.9', '192.168.1.1, 10.0.0.0/8'), 'IPv4 /8 match');
assertSameValue(false, IpMatcher::allowed('172.16.0.1', '192.168.1.1, 10.0.0.0/8'), 'IPv4 mismatch');
assertSameValue(true, IpMatcher::allowed('192.168.1.129', '192.168.1.128/25'), 'IPv4 /25 non-byte-boundary match');
assertSameValue(false, IpMatcher::allowed('192.168.1.127', '192.168.1.128/25'), 'IPv4 /25 non-byte-boundary mismatch');
assertSameValue(true, IpMatcher::allowed('2001:db8:abcd::1', '2001:db8::/32'), 'IPv6 /32 match');
assertSameValue(false, IpMatcher::allowed('2001:db9::1', '2001:db8::/32'), 'IPv6 /32 mismatch');
assertSameValue(false, IpMatcher::allowed('', '10.0.0.0/8'), 'empty client IP rejected when allowlist non-empty');

// 3. TextureReader::parsePngDimensions & isValidHash
$validHeader = "\x89PNG\r\n\x1a\n"
    .pack('N', 13)
    .'IHDR'
    .pack('NN', 64, 32)
    ."\x08\x06\x00\x00\x00\x00\x00\x00";
assertSameValue([64, 32], TextureReader::parsePngDimensions($validHeader), 'parsePngDimensions 64x32');
assertSameValue(null, TextureReader::parsePngDimensions('not-a-png'), 'parsePngDimensions invalid');
assertSameValue(true, TextureReader::isValidHash(str_repeat('a', 64)), 'isValidHash valid 64-hex');
assertSameValue(false, TextureReader::isValidHash('../'.str_repeat('a', 61)), 'isValidHash rejects traversal');
assertSameValue(false, TextureReader::isValidHash(str_repeat('A', 64)), 'isValidHash rejects uppercase');

echo "All blessing-skin-export-bridge unit tests passed.\n";
