<?php

declare(strict_types=1);

namespace App\Product;

final class ProductPricing
{
    public static function normalize(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/^\d{1,8}(?:\.\d{1,6})?$/D', $value)) {
            throw new \InvalidArgumentException('Saisissez un prix positif avec six décimales au maximum.');
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (string) (int) $integer.'.'.str_pad($fraction, 6, '0');
    }

    public static function parseTaxRate(string $value): int
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $value)) {
            throw new \InvalidArgumentException('Le taux de TVA doit être compris entre 0 et 100 %, avec deux décimales au maximum.');
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $rate = (int) $integer * 100 + (int) str_pad($fraction, 2, '0');
        if ($rate > 10000) {
            throw new \InvalidArgumentException('Le taux de TVA ne peut pas dépasser 100 %.');
        }

        return $rate;
    }

    public static function excludingTax(string $includingTax, int $rate): string
    {
        return self::decimal(self::ratio(self::micros($includingTax), 10000, 10000 + $rate));
    }

    public static function includingTax(string $excludingTax, int $rate): string
    {
        return self::decimal(self::ratio(self::micros($excludingTax), 10000 + $rate, 10000));
    }

    public static function amounts(string $excludingTax, int $rate, ?int $pcb): array
    {
        $micros = self::micros($excludingTax);
        $carton = null === $pcb ? null : self::ratio($micros * $pcb, 1, 10000);
        $tax = null === $carton ? null : self::ratio($carton, $rate, 10000);

        return [
            'unitPriceCents' => self::ratio($micros, 1, 10000),
            'unitPriceInclTaxCents' => self::ratio($micros, 10000 + $rate, 100000000),
            'cartonPriceCents' => $carton,
            'cartonTaxCents' => $tax,
            'cartonPriceInclTaxCents' => null === $carton ? null : $carton + $tax,
        ];
    }

    private static function micros(string $value): int
    {
        return (int) str_replace('.', '', self::normalize($value));
    }

    private static function decimal(int $micros): string
    {
        return intdiv($micros, 1000000).'.'.str_pad((string) ($micros % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function ratio(int $amount, int $numerator, int $denominator): int
    {
        return intdiv($amount, $denominator) * $numerator
            + intdiv(($amount % $denominator) * $numerator + intdiv($denominator, 2), $denominator);
    }
}
