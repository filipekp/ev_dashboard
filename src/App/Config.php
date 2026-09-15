<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /** @var array<string,mixed> */
    private $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /** @return mixed */
    public function get(string $key, $default = null)
    {
        $value = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
