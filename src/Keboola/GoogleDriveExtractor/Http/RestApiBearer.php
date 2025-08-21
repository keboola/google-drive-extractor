<?php
declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Http;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\ResponseInterface;

class RestApiBearer implements ApiClientInterface
{
    private GuzzleClient $http;
    private int $backoffsCount = 0;
    /** @var null|callable */
    private $callback403 = null;
    /** @var null|callable function():string */
    private $refreshCallback = null;

    /** @var array<string,string> */
    private array $defaultHeaders;

    public function __construct(string $accessToken)
    {
        $this->defaultHeaders = ['Authorization' => 'Bearer ' . $accessToken];
        $this->http = new GuzzleClient([
            'http_errors' => false,
            'timeout'     => 60,
        ]);
    }

    /** @param array<string,string> $headers @param array<string,mixed> $options */
    public function request(string $uri, string $method = 'GET', array $headers = [], array $options = []): ResponseInterface
    {
        // Merge headers (so we can change token later without recreating client)
        $options['headers'] = array_replace($this->defaultHeaders, $options['headers'] ?? [], $headers);

        $attempts = $this->backoffsCount + 1;
        $last = null;

        for ($i = 0; $i < $attempts; $i++) {
            $resp = $this->http->request($method, $uri, $options);
            $code = $resp->getStatusCode();

            // 401: try refresh ONCE via callback (if provided), then retry immediately
            if ($code === 401 && $this->refreshCallback) {
                $newToken = (string) call_user_func($this->refreshCallback);
                if ($newToken !== '') {
                    $this->defaultHeaders['Authorization'] = 'Bearer ' . $newToken;
                    // retry the same attempt after refresh (no backoff)
                    $options['headers'] = array_replace($this->defaultHeaders, $options['headers'] ?? []);
                    $resp = $this->http->request($method, $uri, $options);
                    if ($resp->getStatusCode() !== 401) {
                        return $resp;
                    }
                }
                // still 401 → return
                return $resp;
            }

            // 403: notify
            if ($code === 403) {
                if ($this->callback403) {
                    ($this->callback403)($resp);
                }
                return $resp;
            }

            // 429 or 5xx → backoff + retry
            if ($code === 429 || ($code >= 500 && $code < 600)) {
                $last = $resp;
                if ($i === $attempts - 1) {
                    return $last;
                }
                $base = (int) (100000 * (2 ** $i)); // 0.1s, 0.2s, 0.4s, ...
                $jitter = random_int(0, (int) ($base * 0.2));
                usleep(min($base + $jitter, 2_000_000));
                continue;
            }

            // success or other non-retriable status
            return $resp;
        }

        return $last ?? $this->http->request($method, $uri, $options);
    }

    public function setBackoffsCount(int $count): void
    {
        $this->backoffsCount = max(0, $count);
    }

    public function setBackoffCallback403(callable $callback): void
    {
        $this->callback403 = $callback;
    }

    public function setRefreshTokenCallback(callable $callback): void
    {
        $this->refreshCallback = $callback;
    }
}
