<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Keboola\Google\ClientBundle\Google\RestApi as KeboolaRestApi;
use Keboola\GoogleDriveExtractor\Auth\ServiceAccountTokenFactory;
use Keboola\GoogleDriveExtractor\Configuration\ConfigDefinition;
use Keboola\GoogleDriveExtractor\Exception\ApplicationException;
use Keboola\GoogleDriveExtractor\Exception\UserException;
use Keboola\GoogleDriveExtractor\Extractor\Extractor;
use Keboola\GoogleDriveExtractor\Extractor\Output;
use Keboola\GoogleDriveExtractor\GoogleDrive\Client;
use Keboola\GoogleDriveExtractor\Http\OAuthRestApiAdapter;
use Keboola\GoogleDriveExtractor\Http\RestApiBearer;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Pimple\Container;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class Application
{
    private Container $container;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $container = new Container();

        $container['action'] = isset($config['action']) ? $config['action'] : 'run';
        $container['parameters'] = $this->validateParameters($config['parameters']);

        $container['logger'] = function ($c) {
            $logger = new Logger('ex-google-drive');
            if ($c['action'] !== 'run') {
                $logger->setHandlers([new NullHandler(Logger::INFO)]);
            }
            return $logger;
        };

        $saRaw = $config['parameters']['#serviceAccountJson']
            ?? null;
        $hasSa = !empty($saRaw);
        $hasOauth = isset($config['authorization']['oauth_api']['credentials']['#data']);

        if (!$hasSa && !$hasOauth) {
            $msg = 'Missing authorization: provide either parameters.#serviceAccountJson'
                . ' or authorization.oauth_api.credentials.#data';
            throw new UserException($msg);
        }

        if ($hasSa) {
            $sa = is_string($saRaw) ? json_decode($saRaw, true) : $saRaw;
            if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
                throw new UserException('Invalid Service Account JSON in parameters.#serviceAccountJson');
            }

            $scopes = [
                'https://www.googleapis.com/auth/drive.file',
                'https://www.googleapis.com/auth/spreadsheets',
            ];

            $tokenFactory = new ServiceAccountTokenFactory();
            $accessToken = $tokenFactory->getAccessToken($sa, $scopes);

            $container['google_client'] = function () use ($accessToken) {
                return new RestApiBearer($accessToken);
            };
        } else {
            $creds = $config['authorization']['oauth_api']['credentials'] ?? [];
            if (!isset($creds['#data'])) {
                throw new UserException('Missing authorization data');
            }
            $tokenData = json_decode($creds['#data'], true);
            if (!$tokenData || empty($tokenData['refresh_token'])) {
                throw new UserException('OAuth credentials are invalid: missing refresh_token.');
            }

            $container['google_client'] = function () use ($creds, $tokenData) {
                $rest = new KeboolaRestApi(
                    $creds['appKey'],
                    $creds['#appSecret'],
                    $tokenData['access_token'] ?? '',
                    $tokenData['refresh_token'],
                );
                return new OAuthRestApiAdapter($rest);
            };
        }

        $container['google_drive_client'] = function ($c) {
            return new Client($c['google_client']);
        };

        $container['output'] = function ($c) {
            return new Output($c['parameters']['data_dir'], $c['parameters']['outputBucket']);
        };

        $container['extractor'] = function ($c) {
            return new Extractor(
                $c['google_drive_client'],
                $c['output'],
                $c['logger'],
            ); // ← trailing comma added
        };

        $this->container = $container;
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $actionMethod = $this->container['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this->container['action']));
        }

        try {
            return $this->$actionMethod();
        } catch (RequestException $e) {
            /** @var Response|null $response */
            $response = $e->getResponse();

            if ($e->getCode() === 401) {
                throw new UserException('Expired or wrong credentials, please reauthorize.', $e->getCode(), $e);
            }
            if ($e->getCode() === 403) {
                if ($response && strtolower($response->getReasonPhrase()) === 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Drive resource.");
                    return [];
                }
                $reason = $response ? $response->getReasonPhrase() : 'Forbidden';
                throw new UserException('Reason: ' . $reason, $e->getCode(), $e);
            }
            if ($e->getCode() === 400) {
                throw new UserException($e->getMessage());
            }
            if ($e->getCode() === 503) {
                throw new UserException('Google API error: ' . $e->getMessage(), $e->getCode(), $e);
            }
            throw new ApplicationException(
                $e->getMessage(),
                500,
                $e,
                [
                    'response' => $response ? $response->getBody()->getContents() : null,
                ],
            ); // ← trailing comma added
        }
    }

    /**
     * @return array<string,mixed>
     */
    // phpcs:disable SlevomatCodingStandard.Classes.UnusedPrivateElements.UnusedMethod
    private function runAction(): array
    {
        /** @var Extractor $extractor */
        $extractor = $this->container['extractor'];
        $extracted = $extractor->run($this->container['parameters']['sheets']);

        return [
            'status' => 'ok',
            'extracted' => $extracted,
        ];
    }

    /**
     * @param array<string,mixed> $parameters
     * @return array<string,mixed>
     */
    private function validateParameters(array $parameters): array
    {
        try {
            $processor = new Processor();
            return $processor->processConfiguration(
                new ConfigDefinition(),
                [$parameters],
            ); // ← trailing comma added
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), 400, $e);
        }
    }
}
