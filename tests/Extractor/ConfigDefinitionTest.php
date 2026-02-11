<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Tests\Extractor;

use Generator;
use Keboola\GoogleDriveExtractor\Configuration\ConfigDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class ConfigDefinitionTest extends TestCase
{
    /** @dataProvider validColumnRangeProvider */
    public function testColumnRangeValidation(string $columnRange): void
    {
        $processor = new Processor();
        $definition = new ConfigDefinition();

        $config = [
            'data_dir' => '/data',
            'sheets' => [
                [
                    'id' => 0,
                    'fileId' => 'test-file-id',
                    'fileTitle' => 'Test File',
                    'sheetId' => '0',
                    'sheetTitle' => 'Sheet1',
                    'outputTable' => 'test-table',
                    'enabled' => true,
                    'columnRange' => $columnRange,
                ],
            ],
        ];

        $processedConfig = $processor->processConfiguration($definition, [$config]);

        $this->assertEquals($columnRange, $processedConfig['sheets'][0]['columnRange']);
    }

    public function validColumnRangeProvider(): Generator
    {
        yield 'column-only' => ['A:E'];
        yield 'bounded' => ['A1:E10'];
        yield 'partial-start' => ['A10:E'];
        yield 'partial-end' => ['A:E10'];
        yield 'single-cell' => ['A1:A1'];
        yield 'multi-letter-columns' => ['AA:ZZ'];
        yield 'case-insensitive' => ['a:e'];
        yield 'mixed-case' => ['A1:e10'];
    }

    /** @dataProvider invalidColumnRangeProvider */
    public function testColumnRangeValidationInvalid(string $columnRange): void
    {
        $processor = new Processor();
        $definition = new ConfigDefinition();

        $config = [
            'data_dir' => '/data',
            'sheets' => [
                [
                    'id' => 0,
                    'fileId' => 'test-file-id',
                    'fileTitle' => 'Test File',
                    'sheetId' => '0',
                    'sheetTitle' => 'Sheet1',
                    'outputTable' => 'test-table',
                    'enabled' => true,
                    'columnRange' => $columnRange,
                ],
            ],
        ];

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Column range must be in format/');

        $processor->processConfiguration($definition, [$config]);
    }

    public function invalidColumnRangeProvider(): Generator
    {
        yield 'row-only' => ['1:100'];
        yield 'single-column' => ['A'];
        yield 'single-cell-no-colon' => ['A1'];
        yield 'no-letters' => ['1:2'];
        yield 'invalid-format' => ['A-E'];
        yield 'multiple-colons' => ['A:B:C'];
        yield 'missing-colon' => ['A1E10'];
        yield 'special-chars' => ['A@:E#'];
        yield 'with-spaces' => ['A 1:E 10'];
        yield 'empty-start' => [':E10'];
        yield 'empty-end' => ['A1:'];
    }

    public function testEmptyColumnRangeAllowed(): void
    {
        $processor = new Processor();
        $definition = new ConfigDefinition();

        $config = [
            'data_dir' => '/data',
            'sheets' => [
                [
                    'id' => 0,
                    'fileId' => 'test-file-id',
                    'fileTitle' => 'Test File',
                    'sheetId' => '0',
                    'sheetTitle' => 'Sheet1',
                    'outputTable' => 'test-table',
                    'enabled' => true,
                    'columnRange' => '',
                ],
            ],
        ];

        $processedConfig = $processor->processConfiguration($definition, [$config]);

        $this->assertEquals('', $processedConfig['sheets'][0]['columnRange']);
    }
}
