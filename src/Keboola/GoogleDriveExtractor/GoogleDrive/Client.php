<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\GoogleDrive;

use Keboola\GoogleDriveExtractor\Exception\UserException;
use Keboola\GoogleDriveExtractor\Http\ApiClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function GuzzleHttp\Psr7\stream_for;

class Client
{
    protected const DRIVE_FILES  = 'https://www.googleapis.com/drive/v3/files';
    protected const DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
    protected const SPREADSHEETS = 'https://sheets.googleapis.com/v4/spreadsheets/';

    protected ApiClientInterface $api;

    public function __construct(ApiClientInterface $api)
    {
        $this->api = $api;
    }

    public function getApi(): ApiClientInterface
    {
        return $this->api;
    }

    public function getFile(string $id): array
    {
        $response = $this->api->request(
            sprintf('%s/%s?supportsAllDrives=true', self::DRIVE_FILES, $id),
            'GET'
        );
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code >= 400) {
            throw new UserException("Drive API error ($code) when getting file $id: " . $body);
        }
        /** @var array<string,mixed> $json */
        $json = json_decode($body, true);
        return $json;
    }

    public function createFile(string $pathname, string $title): array
    {
        // 1) Create a spreadsheet file (metadata)
        $response = $this->api->request(
            self::DRIVE_FILES . '?supportsAllDrives=true',
            'POST',
            ['Content-Type' => 'application/json'],
            [
                'json' => [
                    'name' => $title,
                    'mimeType' => 'application/vnd.google-apps.spreadsheet',
                ],
            ]
        );

        /** @var array{id:string} $meta */
        $meta = json_decode((string) $response->getBody()->getContents(), true);

        // 2) Upload CSV content into that file
        $mediaUrl = sprintf('%s/%s?uploadType=media&supportsAllDrives=true', self::DRIVE_UPLOAD, $meta['id']);

        $size = filesize($pathname);
        if ($size === false) {
            throw new RuntimeException('Cannot read file size for: ' . $pathname);
        }

        $response = $this->api->request(
            $mediaUrl,
            'PATCH',
            [
                'Content-Type'   => 'text/csv',
                'Content-Length' => (string) $size,
            ],
            [
                'body' => stream_for(fopen($pathname, 'r')),
            ]
        );

        /** @var array<string,mixed> $json */
        $json = json_decode((string) $response->getBody()->getContents(), true);
        return $json;
    }

    public function deleteFile(string $id): ResponseInterface
    {
        return $this->api->request(
            sprintf('%s/%s?supportsAllDrives=true', self::DRIVE_FILES, $id),
            'DELETE'
        );
    }

    public function getSpreadsheet(string $fileId): array
    {
        $fields = [
            'spreadsheetId',
            'properties.title',
            'sheets.properties.gridProperties',
            'sheets.properties.sheetId',
            'sheets.properties.title',
        ];
        $response = $this->api->request(
            $this->addFields(self::SPREADSHEETS . $fileId, $fields),
            'GET',
            ['Accept' => 'application/json']
        );
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code >= 400) {
            throw new UserException("Sheets API error ($code) for spreadsheet $fileId: " . $body);
        }
        /** @var array<string,mixed> $json */
        $json = json_decode($body, true);
        return $json;
    }

    public function getSpreadsheetValues(string $spreadsheetId, string $range): array
    {
        $response = $this->api->request(
            sprintf('%s%s/values/%s', self::SPREADSHEETS, $spreadsheetId, $range),
            'GET',
            ['Accept' => 'application/json']
        );
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($code >= 400) {
            throw new UserException("Sheets API error ($code) for values $spreadsheetId:$range: " . $body);
        }
        /** @var array<string,mixed> $json */
        $json = json_decode($body, true);
        return $json;
    }

    /**
     * @param list<string> $fields
     */
    protected function addFields(string $uri, array $fields = []): string
    {
        if ($fields === []) {
            return $uri;
        }
        $delimiter = (strpos($uri, '?') === false) ? '?' : '&';
        return $uri . sprintf('%sfields=%s', $delimiter, implode(',', $fields));
    }
}
