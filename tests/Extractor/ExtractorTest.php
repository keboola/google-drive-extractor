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
        $api = RestApi::createWithOAuth(
            (string) getenv('CLIENT_ID'),
            (string) getenv('CLIENT_SECRET'),
            (string) getenv('ACCESS_TOKEN'),
            (string) getenv('REFRESH_TOKEN'),
        );
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

    public function testParseCell(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseCell');
        $method->setAccessible(true);

        // Test column only
        $result = $method->invoke($this->extractor, 'A');
        $this->assertEquals([1, null], $result);

        // Test column with row
        $result = $method->invoke($this->extractor, 'A10');
        $this->assertEquals([1, 10], $result);

        // Test multi-letter column
        $result = $method->invoke($this->extractor, 'AA100');
        $this->assertEquals([27, 100], $result);

        // Test case insensitive
        $result = $method->invoke($this->extractor, 'ab5');
        $this->assertEquals([28, 5], $result);
    }

    public function testParseCellInvalid(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseCell');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessage('Invalid cell reference: "123"');
        $method->invoke($this->extractor, '123');
    }

    public function testParseRangeColumnOnly(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $result = $method->invoke($this->extractor, 'A:E', 1000, 26, 'Sheet1');
        $this->assertEquals([1, 5, 1, null], $result);
    }

    public function testParseRangeBounded(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $result = $method->invoke($this->extractor, 'A1:E10', 1000, 26, 'Sheet1');
        $this->assertEquals([1, 5, 1, 10], $result);
    }

    public function testParseRangePartialStart(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $result = $method->invoke($this->extractor, 'A10:E', 1000, 26, 'Sheet1');
        $this->assertEquals([1, 5, 10, null], $result);
    }

    public function testParseRangePartialEnd(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $result = $method->invoke($this->extractor, 'A:E10', 1000, 26, 'Sheet1');
        $this->assertEquals([1, 5, 1, 10], $result);
    }

    public function testParseRangeSingleCell(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $result = $method->invoke($this->extractor, 'A1:A1', 1000, 26, 'Sheet1');
        $this->assertEquals([1, 1, 1, 1], $result);
    }

    public function testParseRangeBoundaryCapping(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        // Cap columns: Sheet has 10 columns, but range requests A:Z (26 columns)
        $result = $method->invoke($this->extractor, 'A:Z', 1000, 10, 'Sheet1');
        $this->assertEquals([1, 10, 1, null], $result);

        // Cap rows: Sheet has 50 rows, but range requests A1:E100
        $result = $method->invoke($this->extractor, 'A1:E100', 50, 26, 'Sheet1');
        $this->assertEquals([1, 5, 1, 50], $result);
    }

    public function testParseRangeInvalidColumnOrder(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessage('start column "E" must be ≤ end column "A"');
        $method->invoke($this->extractor, 'E:A', 1000, 26, 'Sheet1');
    }

    public function testParseRangeInvalidRowOrder(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessage('start row 20 must be ≤ end row 10');
        $method->invoke($this->extractor, 'A20:E10', 1000, 26, 'Sheet1');
    }

    public function testGetBoundedRange(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('getBoundedRange');
        $method->setAccessible(true);

        $result = $method->invoke($this->extractor, 'Sheet1', 1, 5, 1, 10);
        $this->assertEquals('Sheet1!A1:E10', $result);

        // Test with multi-letter columns
        $result = $method->invoke($this->extractor, 'Sheet1', 27, 30, 5, 20);
        $this->assertEquals('Sheet1!AA5:AD20', $result);

        // Test with special characters in title
        $result = $method->invoke($this->extractor, 'My Sheet #1', 1, 5, 1, 10);
        $this->assertEquals('My+Sheet+%231!A1:E10', $result);
    }

    public function testParseCellEmptyString(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseCell');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessage('Invalid cell reference: ""');
        $method->invoke($this->extractor, '');
    }

    public function testParseCellSpecialCharacters(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseCell');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessage('Invalid cell reference: "A@10"');
        $method->invoke($this->extractor, 'A@10');
    }

    public function testParseCellWithSpaces(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseCell');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessage('Invalid cell reference: "A 10"');
        $method->invoke($this->extractor, 'A 10');
    }

    public function testParseRangeInvalidFormat(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessageMatches('/Expected format/');
        $method->invoke($this->extractor, 'A-E', 1000, 26, 'Sheet1');
    }

    public function testParseRangeMultipleColons(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessageMatches('/Expected format/');
        $method->invoke($this->extractor, 'A:B:C', 1000, 26, 'Sheet1');
    }

    public function testParseRangeMissingColon(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessageMatches('/Expected format/');
        $method->invoke($this->extractor, 'A1E10', 1000, 26, 'Sheet1');
    }

    public function testParseRangeRowZero(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessage('rows must be ≥ 1');
        $method->invoke($this->extractor, 'A0:E10', 1000, 26, 'Sheet1');
    }

    public function testParseRangeBothBoundariesExceeded(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        // Sheet has 5 columns and 10 rows, but range requests 26 columns and 100 rows
        $result = $method->invoke($this->extractor, 'A1:Z100', 10, 5, 'Sheet1');
        // Should cap both: columns to 5 (A-E) and rows to 10
        $this->assertEquals([1, 5, 1, 10], $result);
    }

    public function testParseRangeStartRowExceedsEndRow(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessage('start row 100 must be ≤ end row 10');
        $method->invoke($this->extractor, 'A100:E10', 1000, 26, 'Sheet1');
    }

    public function testParseRangeSameColumnAndRow(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        // Valid edge case: single cell with same column and row
        $result = $method->invoke($this->extractor, 'B5:B5', 1000, 26, 'Sheet1');
        $this->assertEquals([2, 2, 5, 5], $result);
    }

    public function testParseRangeVeryLargeRowNumbers(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessageMatches('/Start row \d+ in range .* exceeds sheet .* row count/');
        $method->invoke($this->extractor, 'A999999:E', 1000, 26, 'Sheet1');
    }

    public function testParseRangeStartRowExceedsSheet(): void
    {
        $reflection = new \ReflectionClass($this->extractor);
        $method = $reflection->getMethod('parseRange');
        $method->setAccessible(true);

        $this->expectException(\Keboola\GoogleDriveExtractor\Exception\UserException::class);
        $this->expectExceptionMessageMatches('/Start row \d+ in range .* exceeds sheet .* row count/');
        $method->invoke($this->extractor, 'A500:E', 100, 26, 'Sheet1');
    }
}
