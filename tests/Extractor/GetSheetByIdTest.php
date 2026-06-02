<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Tests\Extractor;

use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleDriveExtractor\Exception\UserException;
use Keboola\GoogleDriveExtractor\Extractor\Extractor;
use Keboola\GoogleDriveExtractor\Extractor\Output;
use Keboola\GoogleDriveExtractor\GoogleDrive\Client;
use Keboola\GoogleDriveExtractor\Logger;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GetSheetByIdTest extends TestCase
{
    private Extractor $extractor;

    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $api = $this->createMock(RestApi::class);
        $googleDriveClient = new Client($api);
        $output = new Output('/tmp', 'in.c-test');
        $logger = new Logger('tests');
        $this->logHandler = new TestHandler();
        $logger->pushHandler($this->logHandler);
        $this->extractor = new Extractor($googleDriveClient, $output, $logger);
    }

    /**
     * @param list<array{
     *     properties: array{
     *         sheetId: int,
     *         title: string,
     *         gridProperties: array{rowCount: int, columnCount: int},
     *     },
     * }> $sheets
     * @return array{
     *     properties: array{
     *         sheetId: int,
     *         title: string,
     *         gridProperties: array{rowCount: int, columnCount: int},
     *     },
     * }
     */
    private function invokeGetSheetById(array $sheets, string $id, string $expectedTitle = ''): array
    {
        $reflection = new ReflectionClass($this->extractor);
        $method = $reflection->getMethod('getSheetById');
        $method->setAccessible(true);

        /** @var array{properties: array{sheetId: int, title: string, gridProperties: array{rowCount: int, columnCount: int}}} $result */
        $result = $method->invoke($this->extractor, $sheets, $id, $expectedTitle);
        return $result;
    }

    /**
     * @return array{
     *     properties: array{
     *         sheetId: int,
     *         title: string,
     *         gridProperties: array{rowCount: int, columnCount: int},
     *     },
     * }
     */
    private function buildSheet(int $sheetId, string $title, int $rowCount = 1000, int $columnCount = 26): array
    {
        return [
            'properties' => [
                'sheetId' => $sheetId,
                'title' => $title,
                'gridProperties' => [
                    'rowCount' => $rowCount,
                    'columnCount' => $columnCount,
                ],
            ],
        ];
    }

    // =========================================================================
    // BACKWARD COMPATIBILITY: Primary lookup by sheetId (existing behavior)
    // =========================================================================

    public function testMatchByIdReturnsCorrectSheet(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
            $this->buildSheet(888438393, 'Parameters', 10, 6),
        ];

        $result = $this->invokeGetSheetById($sheets, '1264381652');

        $this->assertSame('Calendar', $result['properties']['title']);
        $this->assertSame(10000, $result['properties']['gridProperties']['rowCount']);
        $this->assertFalse($this->logHandler->hasWarningRecords());
    }

    public function testMatchByIdZeroReturnsFirstSheet(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
        ];

        $result = $this->invokeGetSheetById($sheets, '0');

        $this->assertSame('Countries', $result['properties']['title']);
        $this->assertSame(21, $result['properties']['gridProperties']['rowCount']);
        $this->assertFalse($this->logHandler->hasWarningRecords());
    }

    public function testMatchByIdWithExpectedTitleStillUsesIdWhenFound(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
        ];

        $result = $this->invokeGetSheetById($sheets, '1264381652', 'Calendar');

        $this->assertSame('Calendar', $result['properties']['title']);
        $this->assertSame(1264381652, $result['properties']['sheetId']);
        $this->assertFalse($this->logHandler->hasWarningRecords());
    }

    public function testMatchByIdTakesPriorityOverTitle(): void
    {
        // If sheetId matches a sheet whose title is DIFFERENT from expectedTitle,
        // the ID match wins. This preserves backward compatibility for configs
        // where the title may have been updated in Google Sheets after config creation.
        $sheets = [
            $this->buildSheet(0, 'RenamedSheet', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
        ];

        $result = $this->invokeGetSheetById($sheets, '0', 'Countries');

        $this->assertSame('RenamedSheet', $result['properties']['title']);
        $this->assertSame(0, $result['properties']['sheetId']);
        $this->assertFalse($this->logHandler->hasWarningRecords());
    }

    // =========================================================================
    // FALLBACK: Match by title when sheetId is not found
    // =========================================================================

    public function testFallbackByTitleWhenIdNotFound(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
            $this->buildSheet(888438393, 'Parameters', 10, 6),
        ];

        $result = $this->invokeGetSheetById($sheets, '999', 'Calendar');

        $this->assertSame('Calendar', $result['properties']['title']);
        $this->assertSame(1264381652, $result['properties']['sheetId']);
        $this->assertSame(10000, $result['properties']['gridProperties']['rowCount']);
    }

    public function testFallbackByTitleLogsWarning(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
        ];

        $this->invokeGetSheetById($sheets, '999', 'Calendar');

        $this->assertTrue($this->logHandler->hasWarningRecords());
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('Sheet with id "999" not found'),
        );
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('Matched by title "Calendar"'),
        );
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('actual sheetId: 1264381652'),
        );
        $this->assertTrue(
            $this->logHandler->hasWarningThatContains('Please update the configuration'),
        );
    }

    public function testFallbackByTitleIsCaseSensitive(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Calendar', 10000, 14),
            $this->buildSheet(1, 'calendar', 500, 10),
        ];

        $result = $this->invokeGetSheetById($sheets, '999', 'calendar');

        $this->assertSame('calendar', $result['properties']['title']);
        $this->assertSame(1, $result['properties']['sheetId']);
    }

    public function testFallbackByTitleReturnsFirstMatchWhenDuplicateTitles(): void
    {
        $sheets = [
            $this->buildSheet(100, 'Duplicate', 500, 10),
            $this->buildSheet(200, 'Duplicate', 1000, 10),
        ];

        $result = $this->invokeGetSheetById($sheets, '999', 'Duplicate');

        $this->assertSame(100, $result['properties']['sheetId']);
    }

    // =========================================================================
    // ERROR: Neither ID nor title match
    // =========================================================================

    public function testThrowsExceptionWhenNeitherIdNorTitleMatch(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
        ];

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Sheet id "999" not found (title "NonExistent" also not found)');

        $this->invokeGetSheetById($sheets, '999', 'NonExistent');
    }

    public function testThrowsExceptionWhenIdNotFoundAndNoTitleProvided(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
        ];

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Sheet id "999" not found');

        $this->invokeGetSheetById($sheets, '999');
    }

    public function testThrowsExceptionWithEmptySheetsList(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Sheet id "0" not found');

        $this->invokeGetSheetById([], '0');
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    public function testNoTitleFallbackWhenExpectedTitleIsEmpty(): void
    {
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
        ];

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Sheet id "999" not found');

        $this->invokeGetSheetById($sheets, '999', '');
    }

    // =========================================================================
    // REAL-WORLD SCENARIO: The exact bug that caused this issue
    // =========================================================================

    public function testRealWorldScenarioWrongSheetIdPointsToExistingSheet(): void
    {
        // When sheetId=0 exists in the spreadsheet, ID match wins (backward compat).
        // The fallback does NOT override a valid ID match even if title doesn't match.
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
            $this->buildSheet(888438393, 'Parameters', 10, 6),
        ];

        $result = $this->invokeGetSheetById($sheets, '0', 'Calendar');

        $this->assertSame('Countries', $result['properties']['title']);
        $this->assertSame(0, $result['properties']['sheetId']);
    }

    public function testRealWorldScenarioSheetIdDoesNotExistInSpreadsheet(): void
    {
        // Config has a sheetId that doesn't exist → fallback finds by title
        $sheets = [
            $this->buildSheet(0, 'Countries', 21, 11),
            $this->buildSheet(1264381652, 'Calendar', 10000, 14),
        ];

        $result = $this->invokeGetSheetById($sheets, '99999', 'Calendar');

        $this->assertSame('Calendar', $result['properties']['title']);
        $this->assertSame(1264381652, $result['properties']['sheetId']);
        $this->assertSame(10000, $result['properties']['gridProperties']['rowCount']);
    }
}
