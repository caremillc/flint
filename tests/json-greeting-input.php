<?php

declare(strict_types=1);

use App\Greeting\GreetingStyle;
use App\Http\Input\InvalidGreetingInput;
use App\Http\Input\JsonGreetingInput;
use Flint\Http\JsonObjectDecoder;

require_once dirname(__DIR__) . '/vendor/autoload.php';

function jsonGreetingInputCheck(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Omitted style becomes a typed enum value.
$document = JsonObjectDecoder::decode('{"name":"Ada"}');
$input = JsonGreetingInput::fromObject($document);

jsonGreetingInputCheck(
    $input->name === 'Ada'
        && $input->style === GreetingStyle::Friendly,
    'Valid input must contain the name and default style enum.',
);

jsonGreetingInputCheck(
    !property_exists($document, 'style'),
    'Validation must not insert defaults into the source object.',
);

// Changing the source must not change previously validated input.
$document->name = 'Grace';
$document->style = 'formal';

$second = JsonGreetingInput::fromObject($document);

jsonGreetingInputCheck(
    $input->name === 'Ada'
        && $input->style === GreetingStyle::Friendly,
    'Source changes must not affect previously validated input.',
);

jsonGreetingInputCheck(
    $second->name === 'Grace'
        && $second->style === GreetingStyle::Formal,
    'A later conversion must use the updated source values.',
);

jsonGreetingInputCheck(
    $input->style->greet($input->name) === 'Hello, Ada'
        && $second->style->greet($second->name) === 'Welcome, Grace',
    'Each input must retain its own greeting behavior.',
);

// Report known fields first, then unknown fields in input order.
// Numeric JSON property names must remain strings in diagnostics.
$invalidDocument = JsonObjectDecoder::decode(
    '{"extra":"private-value","0":true,"style":null,"name":42}',
);

$before = get_object_vars($invalidDocument);
$failure = null;

try {
    JsonGreetingInput::fromObject($invalidDocument);
} catch (InvalidGreetingInput $exception) {
    $failure = $exception;
}

jsonGreetingInputCheck(
    $failure instanceof InvalidGreetingInput,
    'Invalid fields must throw InvalidGreetingInput.',
);

jsonGreetingInputCheck(
    $failure->fields() === ['name', 'style', 'extra', '0'],
    'Invalid field names or their order are incorrect.',
);

jsonGreetingInputCheck(
    $failure->getMessage()
        === 'The greeting input contains invalid fields.',
    'The exception message must not contain submitted values.',
);

jsonGreetingInputCheck(
    get_object_vars($invalidDocument) === $before,
    'Failed validation must leave the source object unchanged.',
);

// Repair the same object and validate it again.
$invalidDocument->name = 'Lin';
$invalidDocument->style = 'friendly';

unset($invalidDocument->extra, $invalidDocument->{'0'});

$recovered = JsonGreetingInput::fromObject($invalidDocument);

jsonGreetingInputCheck(
    $recovered->name === 'Lin'
        && $recovered->style === GreetingStyle::Friendly,
    'Validation must succeed after the source is corrected.',
);

jsonGreetingInputCheck(
    $failure->fields() === ['name', 'style', 'extra', '0'],
    'Later validation must not change an earlier exception.',
);

echo 'PASS: Typed greeting input, source isolation, field diagnostics, and recovery.'
    . PHP_EOL;
