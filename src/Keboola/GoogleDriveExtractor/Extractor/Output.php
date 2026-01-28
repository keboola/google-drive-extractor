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

    /**
     * @var array<mixed>|null
     */
    private ?array $header;

    /**
     * @var array<mixed>
     */
    private array $sheetCfg;

    public function __construct(string $dataDir, string $outputBucket)
    {
        $this->dataDir = $dataDir;
        $this->outputBucket = $outputBucket;
    }

    /**
     * @param array<mixed> $sheet
     */
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

    /**
     * @param array<mixed> $data
     */
    public function write(array $data, int $offset): void
    {
        if (!($this->csv instanceof CsvWriter)) {
            return;
        }

        if ($this->header === null) {
            $headerRows = $this->sheetCfg['header']['rows'];

            if ($headerRows === 0) {
                // No header in data - generate column letters (A, B, C, ...)
                $firstRow = reset($data);
                $columnCount = is_array($firstRow) ? count($firstRow) : 0;
                $this->header = [];

                // Use actual column positions if columnRange was specified
                $startColumn = $this->sheetCfg['_startColumn'] ?? 1;
                for ($i = 0; $i < $columnCount; $i++) {
                    $this->header[] = $this->columnToLetter($startColumn + $i);
                }
                $headerLength = $columnCount;
                // Write the generated header as first row
                if ($offset === 1) {
                    $this->csv->writeRow($this->header);
                }
            } else {
                // Standard behavior - use specified row as header
                $headerRowNum = $headerRows - 1;
                $this->header = $data[$headerRowNum];
                $headerLength = $this->getHeaderLength($data, (int) $headerRowNum);
            }
        } else {
            $headerLength = count($this->header);
        }

        foreach ($data as $k => $row) {
            // Skip header row if rows > 0 and it's the header row
            if ($this->sheetCfg['header']['rows'] > 0 && $k === 0 && $offset === 1) {
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

    /**
     * @param array<mixed> $header
     * @return array<mixed>
     */
    protected function normalizeCsvHeader(array $header): array
    {
        foreach ($header as &$col) {
            $col = Utility::sanitize($col);
        }
        return $header;
    }

    /**
     * @param array<mixed> $data
     */
    private function getHeaderLength(array $data, int $headerRowNum): int
    {
        $headerLength = 0;
        for ($i = 0; $i <= $headerRowNum; $i++) {
            $headerLength = max($headerLength, count($data[$i]));
        }
        return $headerLength;
    }

    private function columnToLetter(int $column): string
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
}
