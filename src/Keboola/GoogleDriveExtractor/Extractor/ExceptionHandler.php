<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Extractor;

use GuzzleHttp\Exception\RequestException;
use Keboola\GoogleDriveExtractor\Exception\ApplicationException;
use Keboola\GoogleDriveExtractor\Exception\UserException;
use Throwable;

class ExceptionHandler
{
    /**
     * @param array<mixed> $sheet
     */
    public function handleGetSpreadsheetException(Throwable $e, array $sheet): void
    {
        if (($e instanceof RequestException) && ($e->getResponse() !== null)) {
            if ($e->getResponse()->getStatusCode() === 404) {
                assert(is_string($sheet['sheetTitle']));
                throw new UserException(sprintf('File "%s" not found in Google Drive', $sheet['sheetTitle']), 404, $e);
            }

            $errorSpec = json_decode((string) $e->getResponse()->getBody()->getContents(), true);

            if (is_array($errorSpec) && array_key_exists('error', $errorSpec)) {
                if ($errorSpec['error'] === 'invalid_grant') {
                    assert(is_string($sheet['fileTitle']));
                    throw new UserException(
                        sprintf(
                            'Invalid OAuth grant when fetching "%s", try reauthenticating the extractor',
                            $sheet['fileTitle'],
                        ),
                        0,
                        $e,
                    );
                }

                if (is_array($errorSpec['error'])
                    && count($errorSpec['error']) > 1
                    && !isset($errorSpec['error_description'])
                ) {
                    assert(is_string($errorSpec['error']['message']));
                    assert(is_string($errorSpec['error']['status']));
                    assert(is_string($sheet['sheetTitle']));
                    throw new UserException(
                        sprintf(
                            '"%s" (%s) for "%s"',
                            $errorSpec['error']['message'],
                            $errorSpec['error']['status'],
                            $sheet['sheetTitle'],
                        ),
                        0,
                        $e,
                    );
                }

                if (isset($errorSpec['error_description'])) {
                    throw new UserException(
                        sprintf(
                            '"%s" (%s)',
                            $errorSpec['error_description'],
                            $errorSpec['error'],
                        ),
                        0,
                        $e,
                    );
                }
            }

            $userException = new UserException('Google Drive Error: ' . $e->getMessage(), 400, $e);
            $userException->setData(
                [
                'message' => $e->getMessage(),
                'reason' => $e->getResponse()->getReasonPhrase(),
                'sheet' => $sheet,
                ],
            );
            throw $userException;
        }
        throw new ApplicationException(
            $e->getMessage(),
            $e->getCode(),
            $e,
        );
    }

    /**
     * @param array<mixed> $sheet
     */
    public function handleExportException(Throwable $e, array $sheet): void
    {
        if (($e instanceof RequestException) && ($e->getResponse() !== null)) {
            assert(is_string($sheet['fileTitle']));
            assert(is_string($sheet['sheetTitle']));
            $userException = new UserException(
                sprintf(
                    "Error importing file - sheet: '%s - %s'",
                    $sheet['fileTitle'],
                    $sheet['sheetTitle'],
                ),
                400,
                $e,
            );
            $userException->setData(
                [
                'message' => $e->getMessage(),
                'reason'  => $e->getResponse()->getReasonPhrase(),
                'body'    => substr($e->getResponse()->getBody()->getContents(), 0, 300),
                'sheet'   => $sheet,
                ],
            );
            throw $userException;
        }
        throw new ApplicationException(
            $e->getMessage(),
            $e->getCode(),
            $e,
        );
    }
}
