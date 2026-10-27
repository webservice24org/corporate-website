<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

final class AccountingCanonicalPayload
{
    /**
     * Normalize exact decimal strings before calling; numeric semantics are never guessed.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->normalize($payload, 0), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > 12) {
            throw new \InvalidArgumentException('Accounting payload nesting exceeds the contract.');
        }
        if (is_array($value)) {
            if (count($value) > 1000) {
                throw new \InvalidArgumentException('Accounting payload is too wide.');
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalize($item, $depth + 1);
            }

            return $value;
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        throw new \InvalidArgumentException('Accounting evidence permits exact scalars and arrays only; floats and objects are forbidden.');
    }
}
