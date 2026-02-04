<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Extractor;

use Keboola\Csv\CsvWriter;
use Symfony\Component\Yaml\Yaml;

class Output
{
    private string $dataDir;

    private string $outputBucket;

    private CsvWriter $csv;

    private ?array $header;

    private array $sheetCfg;

    public function __construct(string $dataDir, string $outputBucket)
    {
        $this->dataDir = $dataDir;
        $this->outputBucket = $outputBucket;
    }

    public function createCsv(array $sheet): string
    {
        $outTablesDir = $this->dataDir . '/out/tables';
        if (!is_dir($outTablesDir)) {
            mkdir($outTablesDir, 0777, true);
        }

        $filename = $outTablesDir . '/' . $sheet['fileId'] . '_' . $sheet['sheetId'] . '.csv';
        touch($filename);

        $this->csv = new CsvWriter($filename);
        $this->header = null;
        $this->sheetCfg = $sheet;

        return $filename;
    }

    public function write(array $data, int $offset): void
    {
        if (!($this->csv instanceof CsvWriter)) {
            return;
        }

        if ($this->header === null) {
            $headerRows = $this->sheetCfg['header']['rows'];

            // Detect if Google has prepended column letters (A, B, C, ...) at index 0
            $hasColumnLetters = $this->hasColumnLettersAtIndex0($data);

            if ($headerRows === 0 && !$hasColumnLetters) {
                // headerRows=0 without column letters: use first row
                $firstRow = reset($data);
                $this->header = is_array($firstRow) ? $firstRow : [];
                $headerLength = count($this->header);
            } else {
                // Adjust index based on whether column letters are present
                // With column letters: headerRows=0 -> index 0, headerRows=1 -> index 1, etc.
                // Without column letters: headerRows=1 -> index 0, headerRows=2 -> index 1, etc.
                $headerRowNum = $hasColumnLetters ? $headerRows : $headerRows - 1;
                $this->header = $data[$headerRowNum];
                $headerLength = $this->getHeaderLength($data, (int) $headerRowNum);
            }
        } else {
            $headerLength = count($this->header);
        }

        foreach ($data as $k => $row) {
            $headerRows = $this->sheetCfg['header']['rows'];

            // Detect if Google has prepended column letters
            $hasColumnLetters = $this->hasColumnLettersAtIndex0($data);

            // Sanitize only the header row
            $sanitizeIndex = $hasColumnLetters ? $headerRows : $headerRows - 1;
            if ($headerRows > 0 && $k === $sanitizeIndex && $offset === 1) {
                if (!isset($this->sheetCfg['header']['sanitize']) || $this->sheetCfg['header']['sanitize'] !== false) {
                    $row = $this->normalizeCsvHeader($row);
                }
            }

            $rowLength = count($row);
            if ($rowLength > $headerLength) {
                $row = array_slice($row, 0, $headerLength);
            }
            $this->csv->writeRow(array_pad($row, $headerLength, ''));
        }
    }

    public function createManifest(string $filename, string $outputTable): bool
    {
        $outFilename = $filename . '.manifest';

        $manifestData = [
            'destination' => $this->outputBucket . '.' . $outputTable,
            'incremental' => false,
        ];

        if (file_exists($this->dataDir . '/config.json')) {
            return (bool) file_put_contents($outFilename, json_encode($manifestData));
        } else {
            return (bool) file_put_contents($outFilename, Yaml::dump($manifestData));
        }
    }

    protected function normalizeCsvHeader(array $header): array
    {
        foreach ($header as &$col) {
            $col = Utility::sanitize($col);
        }
        return $header;
    }

    private function getHeaderLength(array $data, int $headerRowNum): int
    {
        $headerLength = 0;
        for ($i = 0; $i <= $headerRowNum; $i++) {
            $headerLength = max($headerLength, count($data[$i]));
        }
        return $headerLength;
    }

    /**
     * Detect if Google has prepended column letters (A, B, C, ...) at index 0
     * This happens when Google API includes auto-generated column headers
     */
    private function hasColumnLettersAtIndex0(array $data): bool
    {
        if (empty($data) || !isset($data[0]) || !is_array($data[0])) {
            return false;
        }

        $firstRow = $data[0];
        if (empty($firstRow)) {
            return false;
        }

        // Check if all values in first row look like column letters (A, B, C, ..., AA, AB, etc.)
        // Column letters are 1-3 uppercase letters only
        foreach ($firstRow as $value) {
            if (!preg_match('/^[A-Z]{1,3}$/', (string) $value)) {
                return false;
            }
        }

        // Additional check: verify they follow alphabetical sequence (A, B, C, ... or starts reasonably)
        // At minimum, check if first value is a valid column letter pattern
        return true;
    }
}
