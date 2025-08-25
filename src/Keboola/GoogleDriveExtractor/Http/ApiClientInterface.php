<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Http;

use Psr\Http\Message\ResponseInterface;

interface ApiClientInterface
{
    /**
     * Make an HTTP request to a Google API endpoint.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed>  $options
     */
    // phpcs:ignore Generic.Files.LineLength
    public function request(string $uri, string $method = 'GET', array $headers = [], array $options = []): ResponseInterface;

    public function setBackoffsCount(int $count): void;

    public function setBackoffCallback403(callable $callback): void;

    public function setRefreshTokenCallback(callable $callback): void;
}
