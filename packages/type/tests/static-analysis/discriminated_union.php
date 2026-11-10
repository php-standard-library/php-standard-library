<?php

declare(strict_types=1);

namespace Psl\Type\Tests\StaticAnalysis;

use Psl\Type;

/**
 * @param array{'type': 'company', 'name': string}|array{'type': 'person', 'age': int} $_
 */
function takes_party(array $_): void {}

/**
 * @throws Type\Exception\AssertException
 */
function test_discriminated_union(mixed $input): void
{
    $party = Type\discriminated_union('type', [
        'company' => Type\shape(['type' => Type\literal_scalar('company'), 'name' => Type\string()]),
        'person' => Type\shape(['type' => Type\literal_scalar('person'), 'age' => Type\int()]),
    ]);

    namespace\takes_party($party->assert($input));
}
