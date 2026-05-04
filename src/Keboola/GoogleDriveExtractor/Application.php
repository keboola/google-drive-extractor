<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleDriveExtractor\Configuration\ConfigDefinition;
use Keboola\GoogleDriveExtractor\Configuration\QueryConfigDefinition;
use Keboola\GoogleDriveExtractor\Exception\ApplicationException;
use Keboola\GoogleDriveExtractor\Exception\UserException;
use Keboola\GoogleDriveExtractor\Extractor\Extractor;
use Keboola\GoogleDriveExtractor\Extractor\Output;
use Keboola\GoogleDriveExtractor\GoogleDrive\Client;
use Monolog\Handler\NullHandler;
use Pimple\Container;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class Application
{
    private Container $container;

    public function __construct(array $config)
    {
        $container = new Container();
        $container['action'] = isset($config['action'])?$config['action']:'run';
        $container['parameters'] = $this->validateParameters(
            $config['parameters'],
            $this->configDefinitionForAction((string) $container['action']),
        );
        $container['logger'] = function ($c) {
            $logger = new Logger('ex-google-drive');
            if ($c['action'] !== 'run') {
                $logger->setHandlers([new NullHandler(Logger::INFO)]);
            }
            return $logger;
        };

        // Check for service account authentication first
        if (isset($config['parameters']['#serviceAccount']) && !empty($config['parameters']['#serviceAccount'])) {
            $serviceAccountJson = json_decode($config['parameters']['#serviceAccount'], true);
            if (!is_array($serviceAccountJson)) {
                throw new UserException('Invalid service account JSON');
            }
            $scopes = [
                'https://www.googleapis.com/auth/drive',
                'https://www.googleapis.com/auth/spreadsheets',
            ];

            $container['google_client'] = function ($container) use ($serviceAccountJson, $scopes) {
                return RestApi::createWithServiceAccount(
                    $serviceAccountJson,
                    $scopes,
                    $container['logger'],
                );
            };
        } elseif (isset($config['authorization']['oauth_api']['credentials']['#data'])) {
            // Fall back to OAuth authentication
            $tokenData = json_decode($config['authorization']['oauth_api']['credentials']['#data'], true);
            $container['google_client'] = function () use ($config, $tokenData) {
                return RestApi::createWithOAuth(
                    $config['authorization']['oauth_api']['credentials']['appKey'],
                    $config['authorization']['oauth_api']['credentials']['#appSecret'],
                    $tokenData['access_token'],
                    $tokenData['refresh_token'],
                );
            };
        } else {
            throw new UserException('Missing authorization data (OAuth or Service Account)');
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
            );
        };

        $this->container = $container;
    }

    public function run(): array
    {
        $actionMethod = $this->container['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this->container['action']));
        }

        try {
            return $this->$actionMethod();
        } catch (RequestException $e) {
            /** @var Response $response */
            $response = $e->getResponse();

            if ($e->getCode() === 401) {
                throw new UserException('Expired or wrong credentials, please reauthorize.', $e->getCode(), $e);
            }
            if ($e->getCode() === 403) {
                if (strtolower($response->getReasonPhrase()) === 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Drive resource.");
                    return [];
                }
                throw new UserException('Reason: ' . $response->getReasonPhrase(), $e->getCode(), $e);
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
                    'response' => $response->getBody()->getContents(),
                ],
            );
        }
    }

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
     * @return array<string, mixed>
     */
    private function queryAction(): array
    {
        /** @var array<string, mixed> $parameters */
        $parameters = $this->container['parameters'];
        $fileId = (string) $parameters['fileId'];
        $query = isset($parameters['query']) ? (string) $parameters['query'] : '';

        /** @var Client $client */
        $client = $this->container['google_drive_client'];

        if ($query === '') {
            $spreadsheet = $client->getSpreadsheet($fileId);
            $sheets = [];
            foreach ($spreadsheet['sheets'] ?? [] as $sheet) {
                $properties = $sheet['properties'] ?? [];
                $grid = $properties['gridProperties'] ?? [];
                $sheets[] = [
                    'sheetId' => $properties['sheetId'] ?? null,
                    'title' => $properties['title'] ?? null,
                    'rowCount' => $grid['rowCount'] ?? null,
                    'columnCount' => $grid['columnCount'] ?? null,
                ];
            }

            return [
                'status' => 'success',
                'spreadsheet' => [
                    'spreadsheetId' => $spreadsheet['spreadsheetId'] ?? $fileId,
                    'title' => $spreadsheet['properties']['title'] ?? null,
                    'sheets' => $sheets,
                ],
            ];
        }

        $response = $client->getSpreadsheetValues($fileId, $query);

        return [
            'status' => 'success',
            'range' => $response['range'] ?? $query,
            'values' => $response['values'] ?? [],
        ];
    }

    private function configDefinitionForAction(string $action): ConfigurationInterface
    {
        if ($action === 'query') {
            return new QueryConfigDefinition();
        }
        return new ConfigDefinition();
    }

    private function validateParameters(array $parameters, ConfigurationInterface $definition): array
    {
        try {
            $processor = new Processor();
            return $processor->processConfiguration(
                $definition,
                [$parameters],
            );
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), 400, $e);
        }
    }
}
