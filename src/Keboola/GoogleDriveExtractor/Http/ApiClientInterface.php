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
    public function request(
        string $uri,
        string $method = 'GET',
        array $headers = [],
        array $options = [],
    ): ResponseInterface;

    public function setBackoffsCount(int $count): void;

    /** @param callable(\Psr\Http\Message\ResponseInterface):void $callback */
    public function setBackoffCallback403(callable $callback): void;

    /** @param callable():string $callback */
    public function setRefreshTokenCallback(callable $callback): void;
}
