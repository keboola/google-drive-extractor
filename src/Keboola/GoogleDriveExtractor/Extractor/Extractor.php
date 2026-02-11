<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Extractor;

use Keboola\GoogleDriveExtractor\Exception\UserException;
use Keboola\GoogleDriveExtractor\GoogleDrive\Client;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class Extractor
{
    private Client $driveApi;

    private Output $output;

    private Logger $logger;

    public function __construct(Client $driveApi, Output $output, Logger $logger)
    {
        $this->driveApi = $driveApi;
        $this->logger = $logger;
        $this->output = $output;

        $this->driveApi->getApi()->setBackoffsCount(9);
        $this->driveApi->getApi()->setBackoffCallback403($this->getBackoffCallback403());
        $this->driveApi->getApi()->setRefreshTokenCallback([$this, 'refreshTokenCallback']);
    }

    public function getBackoffCallback403(): callable
    {
        return function ($response) {
            /** @var ResponseInterface $response */
            $reason = $response->getReasonPhrase();

            if ($reason === 'insufficientPermissions'
                || $reason === 'dailyLimitExceeded'
                || $reason === 'usageLimits.userRateLimitExceededUnreg'
            ) {
                return false;
            }

            return true;
        };
    }

    public function run(array $sheets): array
    {
        $status = [];
        $exceptionHandler = new ExceptionHandler();

        foreach ($sheets as $sheet) {
            if (!$sheet['enabled']) {
                continue;
            }

            try {
                $spreadsheet = $this->driveApi->getSpreadsheet($sheet['fileId']);
                $this->logger->info('Obtained spreadsheet metadata');

                try {
                    $this->logger->info('Extracting sheet ' . $sheet['sheetTitle']);
                    $this->export($spreadsheet, $sheet);
                } catch (UserException $e) {
                    throw new UserException($e->getMessage(), 0, $e);
                } catch (Throwable $e) {
                    $exceptionHandler->handleExportException($e, $sheet);
                }
            } catch (UserException $e) {
                throw new UserException($e->getMessage(), 0, $e);
            } catch (Throwable $e) {
                $exceptionHandler->handleGetSpreadsheetException($e, $sheet);
            }

            $status[$sheet['fileTitle']][$sheet['sheetTitle']] = 'success';
        }

        return $status;
    }

    private function export(array $spreadsheet, array $sheetCfg): void
    {
        $sheet = $this->getSheetById($spreadsheet['sheets'], (string) $sheetCfg['sheetId']);
        $sheetRowCount = $sheet['properties']['gridProperties']['rowCount'];
        $sheetColumnCount = $sheet['properties']['gridProperties']['columnCount'];

        // Parse range if specified
        $startColumn = 1;
        $endColumn = $sheetColumnCount;
        $startRow = 1;
        $endRow = null; // null = unbounded (paginate)

        if (!empty($sheetCfg['columnRange'])) {
            [$startColumn, $endColumn, $startRow, $endRow] = $this->parseRange(
                $sheetCfg['columnRange'],
                $sheetRowCount,
                $sheetColumnCount,
                $sheet['properties']['title'],
            );
        }

        // Branch based on range type
        if ($endRow !== null) {
            // Bounded range: single API call
            $this->exportBoundedRange(
                $spreadsheet,
                $sheet,
                $sheetCfg,
                $startColumn,
                $endColumn,
                $startRow,
                $endRow,
            );
        } else {
            // Unbounded range: pagination from startRow to end
            $this->exportUnboundedRange(
                $spreadsheet,
                $sheet,
                $sheetCfg,
                $startColumn,
                $endColumn,
                $startRow,
                $sheetRowCount,
            );
        }
    }

    /**
     * Export a bounded range (e.g., A1:E10) with a single API call
     *
     * @param array<mixed> $spreadsheet
     * @param array<mixed> $sheet
     * @param array<mixed> $sheetCfg
     */
    private function exportBoundedRange(
        array $spreadsheet,
        array $sheet,
        array $sheetCfg,
        int $startColumn,
        int $endColumn,
        int $startRow,
        int $endRow,
    ): void {
        $this->logger->info(sprintf(
            'Extracting bounded range: columns %s-%s, rows %d-%d',
            $this->columnToLetter($startColumn),
            $this->columnToLetter($endColumn),
            $startRow,
            $endRow,
        ));

        $range = $this->getBoundedRange(
            $sheet['properties']['title'],
            $startColumn,
            $endColumn,
            $startRow,
            $endRow,
        );

        $response = $this->driveApi->getSpreadsheetValues(
            $spreadsheet['spreadsheetId'],
            $range,
        );

        if (!empty($response['values'])) {
            $sheetCfgWithRange = $sheetCfg;
            $sheetCfgWithRange['_startColumn'] = $startColumn;
            $sheetCfgWithRange['_endColumn'] = $endColumn;
            $sheetCfgWithRange['_startRow'] = $startRow;
            $sheetCfgWithRange['_endRow'] = $endRow;

            $csvFilename = $this->output->createCsv($sheetCfgWithRange);
            $this->output->createManifest($csvFilename, $sheetCfg['outputTable']);
            $this->output->write($response['values'], $startRow);
        }
    }

    /**
     * Export an unbounded range (e.g., A:E or A10:E) with pagination
     *
     * @param array<mixed> $spreadsheet
     * @param array<mixed> $sheet
     * @param array<mixed> $sheetCfg
     */
    private function exportUnboundedRange(
        array $spreadsheet,
        array $sheet,
        array $sheetCfg,
        int $startColumn,
        int $endColumn,
        int $startRow,
        int $sheetRowCount,
    ): void {
        $offset = $startRow;
        $limit = 1000;
        $firstBatch = true;

        while ($offset <= $sheetRowCount) {
            $this->logger->info(sprintf(
                'Extracting rows %d to %d (columns %s-%s)',
                $offset,
                min($offset + $limit - 1, $sheetRowCount),
                $this->columnToLetter($startColumn),
                $this->columnToLetter($endColumn),
            ));

            $range = $this->getRange(
                $sheet['properties']['title'],
                $sheetRowCount,
                $offset,
                $limit,
                $startColumn,
                $endColumn,
            );

            $response = $this->driveApi->getSpreadsheetValues(
                $spreadsheet['spreadsheetId'],
                $range,
            );

            if (!empty($response['values'])) {
                if ($firstBatch) {
                    $sheetCfgWithRange = $sheetCfg;
                    $sheetCfgWithRange['_startColumn'] = $startColumn;
                    $sheetCfgWithRange['_endColumn'] = $endColumn;
                    $sheetCfgWithRange['_startRow'] = $startRow;
                    $sheetCfgWithRange['_endRow'] = null;

                    $csvFilename = $this->output->createCsv($sheetCfgWithRange);
                    $this->output->createManifest($csvFilename, $sheetCfg['outputTable']);
                    $firstBatch = false;
                }

                $this->output->write($response['values'], $offset);
            }

            $offset += $limit;
        }
    }

    private function getSheetById(array $sheets, string $id): array
    {
        foreach ($sheets as $sheet) {
            if ((string) $sheet['properties']['sheetId'] === $id) {
                return $sheet;
            }
        }

        throw new UserException(sprintf('Sheet id "%s" not found', $id));
    }

    public function getRange(
        string $sheetTitle,
        int $columnCount,
        int $rowOffset = 1,
        int $rowLimit = 1000,
        ?int $startColumn = null,
        ?int $endColumn = null,
    ): string {
        $firstColumn = $this->columnToLetter($startColumn ?? 1);
        $lastColumn = $this->columnToLetter($endColumn ?? $columnCount);

        $start = $firstColumn . $rowOffset;
        $end = $lastColumn . ($rowOffset + $rowLimit - 1);

        return urlencode($sheetTitle) . '!' . $start . ':' . $end;
    }

    /**
     * Build API range string for bounded ranges (e.g., "Sheet1!A1:E10")
     */
    private function getBoundedRange(
        string $sheetTitle,
        int $startColumn,
        int $endColumn,
        int $startRow,
        int $endRow,
    ): string {
        $firstColumn = $this->columnToLetter($startColumn);
        $lastColumn = $this->columnToLetter($endColumn);

        $start = $firstColumn . $startRow;
        $end = $lastColumn . $endRow;

        return urlencode($sheetTitle) . '!' . $start . ':' . $end;
    }

    public function columnToLetter(int $column): string
    {
        $alphas = range('A', 'Z');
        $letter = '';

        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $letter = $alphas[$remainder] . $letter;
            $column = ($column - $remainder - 1) / 26;
        }

        return $letter;
    }

    public function letterToColumn(string $letter): int
    {
        $column = 0;
        $length = strlen($letter);

        for ($i = 0; $i < $length; $i++) {
            $column = $column * 26 + (ord($letter[$i]) - ord('A') + 1);
        }

        return $column;
    }

    /**
     * Parse a cell reference like "A10" into [column, row]
     *
     * @param string $cell Cell reference (e.g., "A10", "AA", "Z100")
     * @return array{int, int|null} [column: int, row: int|null] 1-based indices
     */
    private function parseCell(string $cell): array
    {
        if (!preg_match('/^([A-Z]+)(\d*)$/i', $cell, $matches)) {
            throw new UserException(sprintf('Invalid cell reference: "%s"', $cell));
        }

        $letter = strtoupper($matches[1]);
        $row = $matches[2] !== '' ? (int) $matches[2] : null;

        $column = $this->letterToColumn($letter);

        return [$column, $row];
    }

    /**
     * Parse range string and validate against sheet dimensions
     *
     * Supports: "A:E", "A1:E10", "A10:E", "A:E10"
     *
     * @param string $range Range string
     * @param int $sheetRowCount Total rows in sheet
     * @param int $sheetColumnCount Total columns in sheet
     * @param string $sheetTitle Sheet title for error messages
     * @return array{int, int, int, int|null} [startColumn, endColumn, startRow, endRow|null] 1-based indices
     */
    private function parseRange(
        string $range,
        int $sheetRowCount,
        int $sheetColumnCount,
        string $sheetTitle,
    ): array {
        $parts = explode(':', $range);
        if (count($parts) !== 2) {
            throw new UserException(sprintf(
                'Invalid range "%s" for sheet "%s". Expected format: "A:E", "A1:E10", "A10:E", or "A:E10"',
                $range,
                $sheetTitle,
            ));
        }

        // Parse start and end cells
        [$startColumn, $startRow] = $this->parseCell($parts[0]);
        [$endColumn, $endRow] = $this->parseCell($parts[1]);

        // Validate column order
        if ($startColumn > $endColumn) {
            throw new UserException(sprintf(
                'Invalid range "%s" for sheet "%s": start column "%s" must be ≤ end column "%s"',
                $range,
                $sheetTitle,
                $parts[0],
                $parts[1],
            ));
        }

        // Validate row order (if both specified)
        if ($startRow !== null && $endRow !== null && $startRow > $endRow) {
            throw new UserException(sprintf(
                'Invalid range "%s" for sheet "%s": start row %d must be ≤ end row %d',
                $range,
                $sheetTitle,
                $startRow,
                $endRow,
            ));
        }

        // Validate minimum bounds
        if ($startColumn < 1 || $endColumn < 1 ||
            ($startRow !== null && $startRow < 1) ||
            ($endRow !== null && $endRow < 1)) {
            throw new UserException(sprintf(
                'Invalid range "%s" for sheet "%s": columns must be ≥ A, rows must be ≥ 1',
                $range,
                $sheetTitle,
            ));
        }

        // Cap to sheet boundaries (silent capping per user requirement)
        $cappedStartColumn = max(1, min($startColumn, $sheetColumnCount));
        $cappedEndColumn = max(1, min($endColumn, $sheetColumnCount));
        $cappedStartRow = $startRow !== null ? max(1, min($startRow, $sheetRowCount)) : 1;
        $cappedEndRow = $endRow !== null ? max(1, min($endRow, $sheetRowCount)) : null;

        // Log warning if capping occurred
        if ($cappedStartColumn !== $startColumn || $cappedEndColumn !== $endColumn ||
            ($startRow !== null && $cappedStartRow !== $startRow) ||
            ($endRow !== null && $cappedEndRow !== $endRow)) {
            $this->logger->warning(sprintf(
                'Range "%s" for sheet "%s" exceeded boundaries (rows: %d, cols: %d). Capped to available data.',
                $range,
                $sheetTitle,
                $sheetRowCount,
                $sheetColumnCount,
            ));
        }

        return [$cappedStartColumn, $cappedEndColumn, $cappedStartRow, $cappedEndRow];
    }

    public function refreshTokenCallback(string $accessToken, string $refreshToken): void
    {
    }
}
