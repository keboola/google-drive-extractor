<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Tests\Extractor;

use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleDriveExtractor\Extractor\Extractor;
use Keboola\GoogleDriveExtractor\Extractor\Output;
use Keboola\GoogleDriveExtractor\GoogleDrive\Client;
use Keboola\GoogleDriveExtractor\Logger;
use PHPUnit\Framework\TestCase;

class ExtractorTest extends TestCase
{
    private Client $googleDriveClient;

    private Extractor $extractor;

    public function setUp(): void
    {
        $api = new RestApi((string) getenv('CLIENT_ID'), (string) getenv('CLIENT_SECRET'));
        $api->setCredentials((string) getenv('ACCESS_TOKEN'), (string) getenv('REFRESH_TOKEN'));
        $this->googleDriveClient = new Client($api);
        $output = new Output('/data', 'in.c-ex-google-drive');
        $logger = new Logger('tests');
        $this->extractor = new Extractor($this->googleDriveClient, $output, $logger);
    }

    public function testColumnToLetter(): void
    {
        $notation = $this->extractor->columnToLetter(76);
        $this->assertEquals('BX', $notation);

        $notation = $this->extractor->columnToLetter(1);
        $this->assertEquals('A', $notation);

        $notation = $this->extractor->columnToLetter(26);
        $this->assertEquals('Z', $notation);

        $notation = $this->extractor->columnToLetter(27);
        $this->assertEquals('AA', $notation);
    }

    public function testLetterToColumn(): void
    {
        $column = $this->extractor->letterToColumn('A');
        $this->assertEquals(1, $column);

        $column = $this->extractor->letterToColumn('Z');
        $this->assertEquals(26, $column);

        $column = $this->extractor->letterToColumn('AA');
        $this->assertEquals(27, $column);

        $column = $this->extractor->letterToColumn('BX');
        $this->assertEquals(76, $column);

        $column = $this->extractor->letterToColumn('ZZ');
        $this->assertEquals(702, $column);
    }

    public function testGetRangeDefault(): void
    {
        $range = $this->extractor->getRange('Sheet1', 10, 1, 1000);
        $this->assertEquals('Sheet1!A1:J1000', $range);
    }

    public function testGetRangeWithCustomColumns(): void
    {
        // Test A:E range
        $range = $this->extractor->getRange('Sheet1', 26, 1, 1000, 1, 5);
        $this->assertEquals('Sheet1!A1:E1000', $range);

        // Test C:K range
        $range = $this->extractor->getRange('Sheet1', 26, 1, 1000, 3, 11);
        $this->assertEquals('Sheet1!C1:K1000', $range);

        // Test single column
        $range = $this->extractor->getRange('Sheet1', 26, 1, 1000, 5, 5);
        $this->assertEquals('Sheet1!E1:E1000', $range);

        // Test with pagination offset
        $range = $this->extractor->getRange('Sheet1', 26, 1001, 1000, 1, 5);
        $this->assertEquals('Sheet1!A1001:E2000', $range);
    }

    public function testGetRangeWithSpecialCharactersInSheetTitle(): void
    {
        $range = $this->extractor->getRange('My Sheet #1', 10, 1, 1000, 1, 5);
        $this->assertEquals('My+Sheet+%231!A1:E1000', $range);
    }
}
