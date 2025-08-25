<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Exception;

use Exception;
use Throwable;

/** @phpstan-type ExtraData array<string, mixed> */
class ApplicationException extends Exception
{
    /** @var ExtraData */
    protected $data = [];

    /**
     * @param ExtraData $data
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null, array $data = [])
    {
        parent::__construct($message, $code, $previous);
        $this->data = $data;
    }

    /** @param ExtraData $data */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /** @return ExtraData */
    public function getData(): array
    {
        return $this->data;
    }
}
