<?php

declare(strict_types=1);

namespace Psl\DNSSEC\Internal;

use Psl\DNS\Exception\InvalidArgumentException;
use Psl\DNS\Internal\Encoder;
use Psl\DNS\Record\CNAMERecord;
use Psl\DNS\Record\DNAMERecord;
use Psl\DNS\Record\RecordInterface;
use Psl\DNS\Record\RecordType;
use Psl\DNS\Record\RRSIGRecord;
use Psl\DNSSEC\Exception\InvalidProofException;

use function count;
use function rtrim;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Selects the answer RRsets that need DNSSEC validation.
 *
 * @internal
 */
final class AnswerRrsets
{
    /**
     * Select the query's RRsets and alias chain. Other answer RRsets are discarded.
     * All records use class IN; the wire decoder rejects other classes.
     *
     * @param list<RecordInterface> $answers
     *
     * @return array{array<string, non-empty-list<RecordInterface>>, array<string, true>, string, bool}
     *
     * @throws InvalidArgumentException If a DNAME substitution exceeds the DNS name limit.
     * @throws InvalidProofException If the alias chain is missing, loops, or disagrees with a DNAME.
     */
    public static function select(array $answers, string $queryName, RecordType $queryType): array
    {
        $rrsets = [];
        foreach ($answers as $record) {
            if ($record instanceof RRSIGRecord) {
                continue;
            }

            $rrsets[self::id($record->name, $record->kind)][] = $record;
        }

        $selected = [];
        $synthesized = [];
        $visited = [];
        $answered = false;
        $name = self::normalizeName($queryName);
        while (true) {
            if (isset($visited[$name]) || count($visited) > count($rrsets)) {
                throw InvalidProofException::forRRSIG($queryName);
            }

            $visited[$name] = true;

            $cnameId = self::id($name, RecordType::CNAME);
            $cname = $rrsets[$cnameId][0] ?? null;
            $dname = null;
            foreach ($rrsets as $rrset) {
                $candidate = $rrset[0];
                if (
                    $candidate instanceof DNAMERecord
                    && str_ends_with($name, '.' . self::normalizeName($candidate->name))
                    && ($dname === null || strlen($candidate->name) > strlen($dname->name))
                ) {
                    $dname = $candidate;
                }
            }

            if ($dname !== null) {
                $dnameId = self::id($dname->name, RecordType::DNAME);
                if (count($rrsets[$dnameId]) !== 1) {
                    throw InvalidProofException::forRRSIG($name);
                }

                $selected[$dnameId] = $rrsets[$dnameId];
                $target = self::normalizeName(
                    substr($name, 0, -strlen(self::normalizeName($dname->name))) . self::normalizeName($dname->target),
                );
                Encoder::encodeName($target);
                if ($cname instanceof CNAMERecord) {
                    if (count($rrsets[$cnameId]) !== 1 || self::normalizeName($cname->target) !== $target) {
                        throw InvalidProofException::forRRSIG($name);
                    }

                    $selected[$cnameId] = $rrsets[$cnameId];
                    $synthesized[$cnameId] = true;
                }

                if ($queryType === RecordType::CNAME && $cname instanceof CNAMERecord) {
                    $answered = true;
                    break;
                }

                $name = $target;
                continue;
            }

            $id = self::id($name, $queryType);
            if (isset($rrsets[$id])) {
                $selected[$id] = $rrsets[$id];
                $answered = true;
                break;
            }

            if (!$cname instanceof CNAMERecord) {
                break;
            }

            if (count($rrsets[$cnameId]) !== 1) {
                throw InvalidProofException::forRRSIG($name);
            }

            $selected[$cnameId] = $rrsets[$cnameId];
            $name = self::normalizeName($cname->target);
        }

        if ($selected === []) {
            throw InvalidProofException::forRRSIG($queryName);
        }

        return [$selected, $synthesized, $name, $answered];
    }

    private static function normalizeName(string $name): string
    {
        return rtrim(strtolower($name), '.');
    }

    public static function id(string $name, RecordType $type): string
    {
        return self::normalizeName($name) . '/' . $type->value;
    }
}
