<?php

declare(strict_types=1);

namespace Psl\DNS;

use Psl\Async\CancellationTokenInterface;
use Psl\Async\NullCancellationToken;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;

use function strtolower;

/**
 * DNS resolver backed by an in-memory record map.
 *
 * No network I/O is performed. Useful for testing, fixtures, and
 * deterministic environments where queries should return known data.
 *
 * @api
 */
final readonly class StaticResolver implements ResolverInterface
{
    use ResolverConvenienceMethodsTrait;

    /**
     * @var array<string, array<int, list<RecordInterface>>> $records
     *  Map of lowercased domain name -> record kind value -> record list.
     */
    private array $records;

    /**
     * @param array<string, array<int, list<RecordInterface>>> $records
     *  Map of domain name -> record kind value -> record list.
     */
    public function __construct(array $records)
    {
        $normalized = [];
        foreach ($records as $name => $kinds) {
            $normalized[strtolower($name)] = $kinds;
        }

        $this->records = $normalized;
    }

    /**
     * {@inheritDoc}
     */
    public function query(
        string $name,
        RecordType $type,
        CancellationTokenInterface $cancellation = new NullCancellationToken(),
        array $ednsOptions = [],
    ): Response {
        $normalizedName = strtolower($name);

        if (!isset($this->records[$normalizedName])) {
            return new Response(0, ResponseCode::NonExistentDomain, [], [], []);
        }

        $answers = $this->records[$normalizedName][$type->value] ?? [];

        return new Response(0, ResponseCode::NoError, $answers, [], []);
    }
}
