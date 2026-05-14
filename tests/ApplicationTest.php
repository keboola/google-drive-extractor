<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Tests;

use Keboola\GoogleDriveExtractor\Application;
use Keboola\GoogleDriveExtractor\Exception\UserException;
use Symfony\Component\Yaml\Yaml;

class ApplicationTest extends BaseTest
{
    private Application $application;

    public function setUp(): void
    {
        parent::setUp();
        $this->application = new Application($this->config);
    }

    public function testAppRun(): void
    {
        $this->application->run();

        $outputPath = sprintf(
            '%s/data/out/tables/%s_%s.csv',
            __DIR__,
            $this->testFile['spreadsheetId'],
            $this->testFile['sheets'][0]['properties']['sheetId'],
        );

        $manifestPath = $outputPath . '.manifest';
        $manifest = Yaml::parse((string) file_get_contents($manifestPath));

        $this->assertArrayHasKey('destination', $manifest);
        $this->assertArrayHasKey('incremental', $manifest);
        $this->assertFalse($manifest['incremental']);

        $outputTableId = sprintf(
            '%s.%s',
            $this->config['parameters']['outputBucket'],
            $this->config['parameters']['sheets'][0]['outputTable'],
        );

        $this->assertEquals($outputTableId, $manifest['destination']);
    }

    public function testInvalidSpreadsheetId(): void
    {
        $this->testFile['sheets'][0]['properties']['sheetId'] = 18293729;
        $this->config = $this->makeConfig($this->testFile);
        $this->application = new Application($this->config);

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Sheet id "18293729" not found');
        $this->application->run();
    }

    public function testProbeActionMetadata(): void
    {
        $config = $this->makeProbeConfig($this->testFile);
        $app = new Application($config);

        $result = $app->run();

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('spreadsheet', $result);
        $this->assertSame($this->testFile['spreadsheetId'], $result['spreadsheet']['spreadsheetId']);
        $this->assertNotEmpty($result['spreadsheet']['sheets']);
        $firstSheet = $result['spreadsheet']['sheets'][0];
        $this->assertArrayHasKey('sheetId', $firstSheet);
        $this->assertArrayHasKey('title', $firstSheet);
        $this->assertArrayHasKey('rowCount', $firstSheet);
        $this->assertArrayHasKey('columnCount', $firstSheet);
    }

    public function testProbeActionRange(): void
    {
        $sheetTitle = $this->testFile['sheets'][0]['properties']['title'];
        $config = $this->makeProbeConfig($this->testFile, sprintf('%s!A1:E5', $sheetTitle));
        $app = new Application($config);

        $result = $app->run();

        $this->assertSame('success', $result['status']);
        $this->assertArrayHasKey('range', $result);
        $this->assertArrayHasKey('values', $result);
        $this->assertIsArray($result['values']);
    }

    public function testProbeActionMissingFileId(): void
    {
        $config = [
            'action' => 'probe',
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        'appKey' => getenv('CLIENT_ID'),
                        '#appSecret' => getenv('CLIENT_SECRET'),
                        '#data' => json_encode([
                            'access_token' => getenv('ACCESS_TOKEN'),
                            'refresh_token' => getenv('REFRESH_TOKEN'),
                        ]),
                    ],
                ],
            ],
            'parameters' => [
                'data_dir' => __DIR__ . '/data',
            ],
        ];

        $this->expectException(UserException::class);
        new Application($config);
    }

    /**
     * @param array<string, mixed> $testFile
     * @return array<string, mixed>
     */
    private function makeProbeConfig(array $testFile, ?string $probe = null): array
    {
        $config = [
            'action' => 'probe',
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        'appKey' => getenv('CLIENT_ID'),
                        '#appSecret' => getenv('CLIENT_SECRET'),
                        '#data' => json_encode([
                            'access_token' => getenv('ACCESS_TOKEN'),
                            'refresh_token' => getenv('REFRESH_TOKEN'),
                        ]),
                    ],
                ],
            ],
            'parameters' => [
                'data_dir' => __DIR__ . '/data',
                'fileId' => $testFile['spreadsheetId'],
            ],
        ];
        if ($probe !== null) {
            $config['parameters']['probe'] = $probe;
        }
        return $config;
    }
}
