<?php

declare(strict_types=1);

namespace MunicipioClone\Support;

use WpService\WpService;

/**
 * Performs serialization-aware string replacement.
 */
class PlaceholderReplacer
{
    public function __construct(private WpService $wpService)
    {
    }

    public function replace(mixed $value, string $search, string $replace): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replace($item, $search, $replace);
            }

            return $value;
        }

        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $property => $item) {
                $value->{$property} = $this->replace($item, $search, $replace);
            }

            return $value;
        }

        if (is_object($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        if ($this->isSerialized($value)) {
            $unserialized = $this->wpService->maybeUnserialize($value);
            if ($unserialized !== $value) {
                $updated = $this->replace($unserialized, $search, $replace);

                return $this->wpService->maybeSerialize($updated);
            }
        }

        return str_replace($search, $replace, $value);
    }

    private function isSerialized(string $value): bool
    {
        if ($value === 'b:0;') {
            return true;
        }

        return preg_match('/^(a|O|s|i|d|b):/', $value) === 1;
    }
}
