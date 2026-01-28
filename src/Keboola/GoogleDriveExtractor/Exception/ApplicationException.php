<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Exception;

use Exception;
use Throwable;

class ApplicationException extends Exception
{
    /**
     * @var array<mixed>
     */
    protected array $data;

    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null, ?array $data = [])
    {
        $this->setData((array) $data);
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param array<mixed> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * @return array<mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }
}
