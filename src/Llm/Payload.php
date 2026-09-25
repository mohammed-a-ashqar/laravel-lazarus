<?php

declare(strict_types=1);

namespace Alashqar\Lazarus\Llm;

/**
 * Field validation for decoded model output, with error messages written for the model.
 */
final class Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function string(array $data, string $field, bool $allowEmpty = false): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || (! $allowEmpty && trim($value) === '')) {
            throw new InvalidLlmResponse(sprintf('field "%s" must be a %sstring.', $field, $allowEmpty ? '' : 'non-empty '));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function number(array $data, string $field, float $min, float $max): float
    {
        $value = $data[$field] ?? null;

        if (! is_int($value) && ! is_float($value) || $value < $min || $value > $max) {
            throw new InvalidLlmResponse(sprintf('field "%s" must be a number between %s and %s.', $field, $min, $max));
        }

        return (float) $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public static function objects(array $data, string $field): array
    {
        $value = $data[$field] ?? null;

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new InvalidLlmResponse(sprintf('field "%s" must be a non-empty array.', $field));
        }

        $objects = [];

        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidLlmResponse(sprintf('item %d of "%s" must be an object.', $index, $field));
            }

            $objects[] = $item;
        }

        return $objects;
    }
}
