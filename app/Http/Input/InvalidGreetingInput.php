<?php

declare(strict_types=1);

namespace App\Http\Input;

use InvalidArgumentException;

final class InvalidGreetingInput extends InvalidArgumentException
{
    /**
     * @param non-empty-list<string> $fields
     */
    public function __construct(
        private readonly array $fields,
    ) {
        parent::__construct('The greeting input contains invalid fields.');
    }

    /**
     * @return non-empty-list<string>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
