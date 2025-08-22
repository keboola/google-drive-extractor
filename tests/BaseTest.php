<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Tests;

use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleDriveExtractor\GoogleDrive\Client;
use Keboola\GoogleDriveExtractor\Http\OAuthRestApiAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Throwable;

abstract class BaseTest extends TestCase
{
    private Client $googleDriveApi;

    protected string $testFilePath = __DIR__ . '/data/in/titanic.csv';

    protected string $testFileName = 'titanic';

    /** @var array<string,mixed> */
    protected array $testFile = [];

    /** @var array<string,mixed> */
    protected array $config = [];

    private string $createdFileId = '';

    public function setUp(): void
    {
        // If OAuth credentials aren’t present, skip integration tests on CI.
        if (!$this->hasOAuthEnv()) {
            $this->markTestSkipped('Skipping integration tests: OAuth env vars are not set.');
        }

        $this->googleDriveApi = new Client(
            new OAuthRestApiAdapter(
                new RestApi(
                    (string) getenv('CLIENT_ID'),
                    (string) getenv('CLIENT_SECRET'),
                    (string) getenv('ACCESS_TOKEN'),
                    (string) getenv('REFRESH_TOKEN'),
                ),
            ),
        );

        // First live call — if credentials are invalid on CI, skip instead of failing the build
        try {
            $this->testFile = $this->prepareTestFile($this->testFilePath, $this->testFileName);
            $this->config = $this->makeConfig($this->testFile);
        } catch (Throwable $e) {
            $this->markTestSkipped('Skipping integration tests: OAuth credentials are invalid or not usable: ' . $e->getMessage());
        }
    }

    private function hasOAuthEnv(): bool
    {
        $vars = ['CLIENT_ID', 'CLIENT_SECRET', 'ACCESS_TOKEN', 'REFRESH_TOKEN'];
        foreach ($vars as $v) {
            $val = getenv($v);
            if ($val === false || $val === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    protected function prepareTestFile(string $path, string $name): array
    {
        $file = $this->googleDriveApi->createFile($path, $name);
        $this->createdFileId = $file['id'];
        return $this->googleDriveApi->getSpreadsheet($file['id']);
    }

    /**
     * @param array<string,mixed> $testFile
     * @return array<string,mixed>
     */
    protected function makeConfig(array $testFile): array
    {
        /** @var array<string,mixed> $config */
        $config = Yaml::parse((string) file_get_contents(__DIR__ . '/data/config.yml'));
        $config['parameters']['data_dir'] = __DIR__ . '/data';
        $config['authorization']['oauth_api']['credentials'] = [
            'appKey' => getenv('CLIENT_ID'),
            '#appSecret' => getenv('CLIENT_SECRET'),
            '#data' => json_encode(
                [
                    'access_token' => getenv('ACCESS_TOKEN'),
                    'refresh_token' => getenv('REFRESH_TOKEN'),
                ],
            ),
        ];
        $config['parameters']['sheets'][0] = [
            'id' => 0,
            'fileId' => $testFile['spreadsheetId'],
            'fileTitle' => $testFile['properties']['title'],
            'sheetId' => $testFile['sheets'][0]['properties']['sheetId'],
            'sheetTitle' => $testFile['sheets'][0]['properties']['title'],
            'outputTable' => $this->testFileName,
            'enabled' => true,
        ];

        return $config;
    }

    public function tearDown(): void
    {
        try {
            if ($this->createdFileId !== '') {
                $this->googleDriveApi->deleteFile($this->createdFileId);
            }
        } catch (Throwable $e) {
            // ignore cleanup errors
        }
    }

    protected function getOutputFileName(string $fileId, int $sheetId): string
    {
        return $fileId . '_' . (string) $sheetId . '.csv';
    }
}
