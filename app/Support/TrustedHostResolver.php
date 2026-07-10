<?php

namespace App\Support;

class TrustedHostResolver
{
    /**
     * @return array<int, string>
     */
    public static function resolve(string $appUrl, ?string $trustedHosts = null): array
    {
        $hosts = [];

        if ($appUrl !== '') {
            $host = parse_url($appUrl, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        if (! empty($trustedHosts)) {
            foreach (explode(',', $trustedHosts) as $host) {
                $host = trim($host);

                if ($host === '') {
                    continue;
                }

                $host = preg_replace('#^https?://#', '', $host) ?? $host;
                $host = preg_replace('#/.*$#', '', $host) ?? $host;
                $host = preg_replace('#:\d+$#', '', $host) ?? $host;

                if ($host !== '') {
                    $hosts[] = $host;
                }
            }
        }

        return array_values(array_unique($hosts));
    }
}
