<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Auth throttling for login and registration (review-13).
 *
 * Fail-open: if the counter store is unavailable the request is allowed and a
 * warning is logged - a Redis outage must not become a login outage.
 */
final readonly class AuthThrottle
{
    public function __construct(
        #[Autowire(service: 'limiter.login_account')]
        private RateLimiterFactory $loginAccountLimiter,
        #[Autowire(service: 'limiter.login_ip')]
        private RateLimiterFactory $loginIpLimiter,
        #[Autowire(service: 'limiter.register_ip')]
        private RateLimiterFactory $registerIpLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException
     */
    public function checkLogin(?string $clientIp, string $email): void
    {
        // Case variants must share one budget: "Admin@Example.com" is the same account.
        $this->consume($this->loginAccountLimiter, 'account:'.$this->normalize($email));
        $this->consume($this->loginIpLimiter, 'ip:'.$this->ipKey($clientIp));
    }

    /**
     * @throws TooManyRequestsHttpException
     */
    public function checkRegistration(?string $clientIp): void
    {
        $this->consume($this->registerIpLimiter, 'ip:'.$this->ipKey($clientIp));
    }

    /**
     * @throws TooManyRequestsHttpException
     */
    private function consume(RateLimiterFactory $factory, string $key): void
    {
        try {
            $limit = $factory->create($key)->consume();
        } catch (\Throwable $throwable) {
            // Fail-open, see class docblock.
            $this->logger->warning('Rate limiter storage unavailable; request allowed.', [
                'exception' => $throwable,
            ]);

            return;
        }

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $this->retryAfterSeconds($limit),
                'Too many attempts. Try again later.',
            );
        }
    }

    private function retryAfterSeconds(RateLimit $limit): int
    {
        $retryAfter = $limit->getRetryAfter();
        $seconds = (int) \ceil((float) $retryAfter->format('U.u') - \microtime(true));

        return \max(1, $seconds);
    }

    private function normalize(string $email): string
    {
        return \mb_strtolower(\trim($email), 'UTF-8');
    }

    /**
     * getClientIp() is null only without REMOTE_ADDR (synthetic test request),
     * and one shared bucket throttles instead of exempting.
     */
    private function ipKey(?string $clientIp): string
    {
        return $clientIp ?? 'unknown';
    }
}
