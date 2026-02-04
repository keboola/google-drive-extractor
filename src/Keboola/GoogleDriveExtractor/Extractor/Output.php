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

            if ($headerRows === 0) {
                // Use first row as header (Google provides A, B, C, ... as the 0th row)
                $firstRow = reset($data);
                $this->header = is_array($firstRow) ? $firstRow : [];
                $headerLength = count($this->header);
            } else {
                // Standard behavior - use specified row as header
                // Since Google provides column letters at index 0, row N is at index N
                $headerRowNum = $headerRows;
                $this->header = $data[$headerRowNum];
                $headerLength = $this->getHeaderLength($data, (int) $headerRowNum);
            }
        } else {
            $headerLength = count($this->header);
        }

        foreach ($data as $k => $row) {
            $headerRows = $this->sheetCfg['header']['rows'];

            // Sanitize only the header row (row at index headerRows, since index 0 is Google's column letters)
            if ($headerRows > 0 && $k === $headerRows && $offset === 1) {
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
}
