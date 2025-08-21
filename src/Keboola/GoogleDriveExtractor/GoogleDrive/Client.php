<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\GoogleDrive;

use Keboola\GoogleDriveExtractor\Http\ApiClientInterface;
use Psr\Http\Message\ResponseInterface;
use Keboola\GoogleDriveExtractor\Exception\UserException;

class Client
{
    protected const DRIVE_FILES  = 'https://www.googleapis.com/drive/v3/files';
    protected const DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
    protected const SPREADSHEETS = 'https://sheets.googleapis.com/v4/spreadsheets/';

    protected ApiClientInterface $api;  // ← single property, via our interface

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
            // Drive error (permissions/not found)
            throw new UserException("Drive API error ($code) when getting file $id: " . $body);
        }
        return json_decode($body, true);
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

        $meta = json_decode((string) $response->getBody()->getContents(), true);

        // 2) (Optional) Upload CSV content into that file
        $mediaUrl = sprintf('%s/%s?uploadType=media&supportsAllDrives=true', self::DRIVE_UPLOAD, $meta['id']);

        $response = $this->api->request(
            $mediaUrl,
            'PATCH',
            [
                'Content-Type'   => 'text/csv',
                'Content-Length' => filesize($pathname),
            ],
            [
                'body' => \GuzzleHttp\Psr7\stream_for(fopen($pathname, 'r')),
            ]
        );

        return json_decode((string) $response->getBody()->getContents(), true);
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
        return json_decode($body, true);
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
        return json_decode($body, true);
    }

    protected function addFields(string $uri, array $fields = []): string
    {
        if (empty($fields)) {
            return $uri;
        }
        $delimiter = (strpos($uri, '?') === false) ? '?' : '&';
        return $uri . sprintf('%sfields=%s', $delimiter, implode(',', $fields));
    }
}
