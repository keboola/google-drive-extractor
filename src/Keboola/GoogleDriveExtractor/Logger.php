<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger as MonologLogger;

class Logger extends MonologLogger
{
    public function __construct(string $name = '', bool $debug = false)
    {
        $options = getopt('', ['debug']);
        if (isset($options['debug'])) {
            // Default format with all the info for dev debug
            $formatter = new LineFormatter();
            $debug = true;
        } elseif (!empty($debug)) {
            // Set user debug mode
            $formatter = new LineFormatter("%level_name%: %message% %context% %extra%\n");
        } else {
            // Simple message
            $formatter = new LineFormatter("%message%\n");
        }

        $errHandler = new StreamHandler('php://stderr', MonologLogger::NOTICE, false);
        $level = $debug ? MonologLogger::DEBUG : MonologLogger::INFO;
        $handler = new StreamHandler('php://stdout', $level);
        $handler->setFormatter($formatter);

        parent::__construct($name, [$errHandler, $handler]);
    }
}
