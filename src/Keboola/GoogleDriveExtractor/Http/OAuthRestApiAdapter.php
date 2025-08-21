<?php
declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Http;

use Keboola\Google\ClientBundle\Google\RestApi as KeboolaRestApi;
use Psr\Http\Message\ResponseInterface;

class OAuthRestApiAdapter implements ApiClientInterface
{
    private KeboolaRestApi $api;

    public function __construct(KeboolaRestApi $api)
    {
        $this->api = $api;
    }

    /** @param array<string,string> $headers @param array<string,mixed> $options */
    public function request(string $uri, string $method = 'GET', array $headers = [], array $options = []): ResponseInterface
    {
        return $this->api->request($uri, $method, $headers, $options);
    }

    public function setBackoffsCount(int $count): void
    {
        if (method_exists($this->api, 'setBackoffsCount')) {
            $this->api->setBackoffsCount($count);
        }
    }

    public function setBackoffCallback403(callable $callback): void
    {
        if (method_exists($this->api, 'setBackoffCallback403')) {
            $this->api->setBackoffCallback403($callback);
        }
    }

    public function setRefreshTokenCallback(callable $callback): void
    {
        if (method_exists($this->api, 'setRefreshTokenCallback')) {
            $this->api->setRefreshTokenCallback($callback);
        }
    }
}
