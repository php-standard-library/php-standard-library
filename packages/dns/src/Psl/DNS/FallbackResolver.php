<?php

declare(strict_types=1);

namespace Psl\DNS;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DNS\Record\RecordType;

/**
 * A resolver that tries multiple resolvers in order, falling back to the next
 * one when the current resolver fails or returns SERVFAIL or REFUSED.
 * NXDOMAIN is a final answer. Invalid input is not retried.
 *
 * @api
 */
final readonly class FallbackResolver implements ResolverInterface
{
    use ResolverConvenienceMethodsTrait;

    /**
     * @param non-empty-list<ResolverInterface> $resolvers Resolvers to try in order.
     */
    public function __construct(
        private array $resolvers,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function query(
        string $name,
        RecordType $type,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        array $ednsOptions = [],
    ): Response {
        $lastError = null;

        foreach ($this->resolvers as $resolver) {
            try {
                $response = $resolver->query($name, $type, $cancellation, $ednsOptions);

                if (
                    $response->code === ResponseCode::ServerFailure
                    || $response->code === ResponseCode::ServerRefused
                ) {
                    $lastError = Exception\RuntimeException::forServerError($response->code->name);
                    continue;
                }

                return $response;
            } catch (Exception\RuntimeException $e) {
                $lastError = $e;
                continue;
            }
        }

        throw Exception\RuntimeException::forAllResolversFailed('fallback', $lastError);
    }
}
