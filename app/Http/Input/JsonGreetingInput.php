<?php

declare(strict_types=1);

namespace App\Http\Input;

use App\Greeting\GreetingStyle;
use LogicException;
use stdClass;

final readonly class JsonGreetingInput
{
    private function __construct(
        public string $name,
        public GreetingStyle $style,
    ) {
    }

    public static function fromObject(stdClass $document): self
    {
        $fields = get_object_vars($document);

        $name = $fields['name'] ?? null;

        $styleValue = array_key_exists('style', $fields)
            ? $fields['style']
            : GreetingStyle::Friendly->value;

        $style = is_string($styleValue)
            ? GreetingStyle::tryFrom($styleValue)
            : null;

        $invalidFields = [];

        if (
            !is_string($name)
            || trim($name) === ''
            || strlen($name) > 100
        ) {
            $invalidFields[] = 'name';
        }

        if ($style === null) {
            $invalidFields[] = 'style';
        }

        foreach ($fields as $field => $_value) {
            if ($field !== 'name' && $field !== 'style') {
                $invalidFields[] = (string) $field;
            }
        }

        if ($invalidFields !== []) {
            throw new InvalidGreetingInput($invalidFields);
        }

        if (!is_string($name) || $style === null) {
            throw new LogicException(
                'Validated greeting input has inconsistent types.',
            );
        }

        return new self($name, $style);
    }
}
