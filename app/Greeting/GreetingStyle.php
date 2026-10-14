<?php

declare(strict_types=1);

namespace App\Greeting;

enum GreetingStyle: string
{
    case Friendly = 'friendly';
    case Formal = 'formal';

    public function greet(string $name): string
    {
        $prefix = match ($this) {
            self::Friendly => 'Hello',
            self::Formal => 'Welcome',
        };

        return $prefix . ', ' . $name;
    }
}