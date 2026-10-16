<?php

declare(strict_types=1);

namespace App\Tests\Prospect;

use App\Prospect\ProspectAttribution;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProspectAttributionTest extends TestCase
{
    public function testCampaignUrlsStillWorkWhenTheVisitorLookupIsUnavailable(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willThrowException(new class('Visitor statistics unavailable.') extends \RuntimeException implements Exception {});
        $attribution = new ProspectAttribution($connection, 'test-secret', new NullLogger());
        self::assertSame(['source' => 'google', 'medium' => 'cpc', 'campaign' => 'lnstrade_hors_europe'], $attribution->resolve(
            'https://lnstrade.fr/en/?gclid=example&utm_campaign=lnstrade_hors_europe', '198.51.100.20', 'Chrome',
        ));
    }

    public function testMissingDataAndArrayParametersDoNotInventADirectSource(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');
        $attribution = new ProspectAttribution($connection, 'test-secret', new NullLogger());
        foreach (['', 'https://lnstrade.fr/fr/', 'https://lnstrade.fr/fr/?utm_source[]=forged&utm_medium[]=forged&utm_campaign[]=forged'] as $url) {
            self::assertSame(['source' => null, 'medium' => null, 'campaign' => null], $attribution->resolve($url, null, 'Chrome'));
        }
    }
}
