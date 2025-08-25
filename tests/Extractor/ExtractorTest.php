<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Tests\Extractor;

use GuzzleHttp\Psr7\Response;
use Keboola\GoogleDriveExtractor\Extractor\Extractor;
use Keboola\GoogleDriveExtractor\Extractor\Output;
use Keboola\GoogleDriveExtractor\GoogleDrive\Client;
use Keboola\GoogleDriveExtractor\Http\ApiClientInterface;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class ExtractorTest extends TestCase
{
    private function makeExtractor(): Extractor
    {
        $client = new Client($this->createDummyApi());
        $output = new Output(sys_get_temp_dir(), 'out.c-bucket');
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        return new Extractor($client, $output, $logger);
    }

    private function createDummyApi(): ApiClientInterface
    {
        // Anonymous class to avoid "multiple classes per file" sniff.
        return new class implements ApiClientInterface {
            /**
             * @param array<string, string> $headers
             * @param array<string, mixed>  $options
             */
            // phpcs:ignore Generic.Files.LineLength
            public function request(string $uri, string $method = 'GET', array $headers = [], array $options = []): ResponseInterface
            {
                // No real HTTP in unit test — return a 501 placeholder or throw as needed.
                if ($method === 'THROW') {
                    throw new RuntimeException('Not implemented');
                }

                return new Response(501, [], 'Not Implemented');
            }

            public function setBackoffsCount(int $count): void
            {
                // no-op for tests
            }

            public function setBackoffCallback403(callable $callback): void
            {
                // no-op for tests
            }

            public function setRefreshTokenCallback(callable $callback): void
            {
                // no-op for tests
            }
        };
    }

    public function testColumnToLetter(): void
    {
        $ex = $this->makeExtractor();

        $this->assertSame('A', $ex->columnToLetter(1));
        $this->assertSame('Z', $ex->columnToLetter(26));
        $this->assertSame('AA', $ex->columnToLetter(27));
        $this->assertSame('AZ', $ex->columnToLetter(52));
        $this->assertSame('BA', $ex->columnToLetter(53));
        $this->assertSame('ZZ', $ex->columnToLetter(702));
        $this->assertSame('AAA', $ex->columnToLetter(703));
    }

    public function testGetRange(): void
    {
        $ex = $this->makeExtractor();

        // getRange URL-encodes the sheet title.
        $this->assertSame('Sheet1!A1:C3', urldecode($ex->getRange('Sheet1', 3, 1, 3)));
        $this->assertSame('Sheet%201!A2:C4', $ex->getRange('Sheet 1', 3, 2, 3));
    }
}
