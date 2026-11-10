<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Psl\Type;
use Psl\Type\Exception\CoercionException;

$party = Type\discriminated_union('partyType', [
    'company' => Type\shape([
        'partyType' => Type\literal_scalar('company'),
        'name' => Type\non_empty_string(),
    ]),
    'person' => Type\shape([
        'partyType' => Type\literal_scalar('person'),
        'firstName' => Type\non_empty_string(),
        'lastName' => Type\non_empty_string(),
    ]),
]);

$parties = Type\vec($party);

$parties->coerce([
    ['partyType' => 'company', 'name' => 'Acme'],
    ['partyType' => 'person', 'firstName' => 'Ada', 'lastName' => 'Lovelace'],
]);

// Only the "person" variant is checked, so the error points at the failing field:
// ... at path "1.firstName".
try {
    $parties->coerce([
        ['partyType' => 'company', 'name' => 'Acme'],
        ['partyType' => 'person', 'firstName' => '', 'lastName' => 'Lovelace'],
    ]);
} catch (CoercionException $e) {
    echo $e->getMessage() . "\n";
}
