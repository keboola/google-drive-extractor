<?php
declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Http;

use Psr\Http\Message\ResponseInterface;

interface ApiClientInterface
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $options
     */
    public function request(string $uri, string $method = 'GET', array $headers = [], array $options = []): ResponseInterface;

    /** Configure how many retries/backoffs to attempt on 429/5xx. */
    public function setBackoffsCount(int $count): void;

    /** Register a callback invoked when a 403 response is encountered. */
    public function setBackoffCallback403(callable $callback): void;

    /**
     * Register a refresh callback used on 401 responses.
     * The callable should return a NEW access token string.
     * Signature: function(): string
     */
    public function setRefreshTokenCallback(callable $callback): void;
}
