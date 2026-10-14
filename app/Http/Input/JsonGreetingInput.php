<?php

declare(strict_types=1);

namespace App\Http\Input;

use stdClass;

final readonly class JsonGreetingInput
{
    private function __construct(
        public string $name,
        public string $style,
    ) {
    }

    public static function fromObject(stdClass $document): self
    {
        $fields = get_object_vars($document);

        $name = $fields['name'] ?? null;

        $style = array_key_exists('style', $fields)
            ? $fields['style']
            : 'friendly';

        $invalidFields = [];

        if (
            !is_string($name)
            || trim($name) === ''
            || strlen($name) > 100
        ) {
            $invalidFields[] = 'name';
        }

        if (
            !is_string($style)
            || !in_array($style, ['friendly', 'formal'], true)
        ) {
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

        // Explicit narrowing also protects the constructor's type contract.
        if (!is_string($name) || !is_string($style)) {
            throw new \LogicException(
                'Validated greeting fields must be strings.',
            );
        }

        return new self($name, $style);
    }
}
