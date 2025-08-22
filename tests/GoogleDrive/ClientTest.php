<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Tests\GoogleDrive;

use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleDriveExtractor\GoogleDrive\Client;
use Keboola\GoogleDriveExtractor\Http\OAuthRestApiAdapter;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private function makeClient(): Client
    {
        $rest = new RestApi(
            (string) getenv('CLIENT_ID'),
            (string) getenv('CLIENT_SECRET'),
            (string) getenv('ACCESS_TOKEN'),
            (string) getenv('REFRESH_TOKEN')
        );

        return new Client(new OAuthRestApiAdapter($rest));
    }

    public function testGetFile(): void
    {
        $client = $this->makeClient();
        $this->assertTrue(method_exists($client, 'getFile'));
    }

    public function testGetSpreadsheet(): void
    {
        $client = $this->makeClient();
        $this->assertTrue(method_exists($client, 'getSpreadsheet'));
    }

    public function testGetSpreadsheetValues(): void
    {
        $client = $this->makeClient();
        $this->assertTrue(method_exists($client, 'getSpreadsheetValues'));
    }
}
