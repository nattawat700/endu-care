<?php

namespace App\Support;

class StaffTotp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function createSecret(): string
    {
        $bits = implode(array_map(fn (int $byte): string => sprintf('%08b', $byte), unpack('C*', random_bytes(20))));

        return implode(array_map(fn (string $chunk): string => self::ALPHABET[bindec($chunk)], str_split($bits, 5)));
    }

    public static function matchingStep(string $secret, string $code, int $timestamp, ?int $lastStep): ?int
    {
        if (! preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }

        $step = intdiv($timestamp, 30);
        foreach ([$step, $step - 1, $step + 1] as $candidate) {
            if ($candidate >= 0 && $candidate > ($lastStep ?? -1) && hash_equals(self::code($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function code(string $secret, int $step): string
    {
        $bits = implode(array_map(fn (string $letter): string => sprintf('%05b', strpos(self::ALPHABET, $letter)), str_split($secret)));
        $key = implode(array_map(fn (string $chunk): string => chr(bindec($chunk)), str_split($bits, 8)));
        $digest = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($digest[19]) & 15;
        $value = unpack('N', substr($digest, $offset, 4))[1] & 0x7FFFFFFF;

        return sprintf('%06d', $value % 1000000);
    }
}
