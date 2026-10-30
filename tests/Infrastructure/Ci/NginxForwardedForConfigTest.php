<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Ci;

use PHPUnit\Framework\TestCase;

/**
 * review-13: IP-keyed limits are only as good as the client address they key
 * on. Symfony is configured with `trusted_proxies: '127.0.0.1,REMOTE_ADDR'`,
 * which trusts every client, so a client-supplied X-Forwarded-For is honoured.
 * nginx must therefore overwrite the header instead of forwarding it.
 *
 * The guard reads the config file, because the behaviour it protects lives
 * outside PHP: no in-kernel test can prove what nginx does to a request.
 */
final class NginxForwardedForConfigTest extends TestCase
{
    private const string CONFIG = __DIR__.'/../../../docker/nginx/default.conf';

    public function testItOverwritesForwardedForWithTheRealPeerAddress(): void
    {
        $config = (string) \file_get_contents(self::CONFIG);

        self::assertMatchesRegularExpression(
            '/fastcgi_param\s+HTTP_X_FORWARDED_FOR\s+\$remote_addr\s*;/',
            $config,
            'nginx must set X-Forwarded-For to $remote_addr: the header otherwise reaches PHP untrusted-and-untouched, making IP rate limits bypassable.',
        );
    }

    public function testItDoesNotAppendTheClientSuppliedValue(): void
    {
        $config = (string) \file_get_contents(self::CONFIG);

        self::assertStringNotContainsString(
            '$proxy_add_x_forwarded_for',
            $config,
            '$proxy_add_x_forwarded_for appends the client-supplied X-Forwarded-For, which is exactly the spoofable value.',
        );
    }
}
