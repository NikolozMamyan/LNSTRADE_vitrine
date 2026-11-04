<?php

declare(strict_types=1);

namespace App\Tests\Product;

use App\Product\ProductPricing;
use PHPUnit\Framework\TestCase;

final class ProductPricingTest extends TestCase
{
    public function testTaxIsRoundedOnTheCartonRatherThanMultiplyingRoundedUnitPrices(): void
    {
        $amounts = ProductPricing::amounts('1.410000', 550, 24);
        self::assertSame(141, $amounts['unitPriceCents']);
        self::assertSame(149, $amounts['unitPriceInclTaxCents']);
        self::assertSame(3384, $amounts['cartonPriceCents']);
        self::assertSame(186, $amounts['cartonTaxCents']);
        self::assertSame(3570, $amounts['cartonPriceInclTaxCents']);
        self::assertNotSame(149 * 24, $amounts['cartonPriceInclTaxCents']);
    }

    public function testSixDecimalPricesAndHalfCentsArePreservedUntilCartonRounding(): void
    {
        $amounts = ProductPricing::amounts('12.345678', 2000, 3);
        self::assertSame(3704, $amounts['cartonPriceCents']);
        self::assertSame(741, $amounts['cartonTaxCents']);
        self::assertSame(4445, $amounts['cartonPriceInclTaxCents']);
        self::assertSame(302, ProductPricing::amounts('1.005000', 0, 3)['cartonPriceCents']);
        self::assertSame(123, ProductPricing::amounts('1.170616', 550, 1)['unitPriceInclTaxCents']);
        self::assertSame('1.234560', ProductPricing::normalize('01,23456'));
    }

    public function testTtcInputCanBeConvertedWithoutLosingThePricePrecision(): void
    {
        self::assertSame('1.412322', ProductPricing::excludingTax('1.49', 550));
        self::assertSame('1.490000', ProductPricing::includingTax('1.412322', 550));
        self::assertSame(550, ProductPricing::parseTaxRate('5,50'));
        self::assertSame(0, ProductPricing::parseTaxRate('0'));
        self::assertNull(ProductPricing::amounts('1.000000', 550, null)['cartonPriceCents']);
    }

    public function testNegativePriceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductPricing::normalize('-1.00');
    }

    public function testTaxAboveOneHundredPercentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductPricing::parseTaxRate('100.01');
    }
}
