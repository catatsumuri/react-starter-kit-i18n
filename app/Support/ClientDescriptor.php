<?php

namespace App\Support;

class ClientDescriptor
{
    /** @return array{browser: string, platform: string|null} */
    public function describe(?string $userAgent): array
    {
        $userAgent = $this->sanitizeUserAgent($userAgent);

        if ($userAgent === null || $userAgent === '') {
            return ['browser' => 'Unknown browser', 'platform' => null];
        }

        return [
            'browser' => match (true) {
                str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
                str_contains($userAgent, 'Firefox/') => 'Firefox',
                str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
                str_contains($userAgent, 'Safari/') => 'Safari',
                default => 'Unknown browser',
            },
            'platform' => match (true) {
                str_contains($userAgent, 'Android') => 'Android',
                str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
                str_contains($userAgent, 'Windows') => 'Windows',
                str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS X') => 'macOS',
                str_contains($userAgent, 'Linux') => 'Linux',
                default => null,
            },
        ];
    }

    public function maskIpAddress(?string $ipAddress): string
    {
        if ($ipAddress === null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return 'Unknown';
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ipAddress);
            $parts[3] = 'x';

            return implode('.', $parts);
        }

        $packedAddress = inet_pton($ipAddress);
        $networkAddress = $packedAddress === false
            ? false
            : inet_ntop(substr($packedAddress, 0, 8).str_repeat("\0", 8));

        return $networkAddress === false ? 'Unknown' : $networkAddress.'/64';
    }

    public function sanitizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $userAgent);

        return mb_substr(trim($sanitized ?? ''), 0, 512);
    }
}
