<?php

declare(strict_types=1);

namespace PokemonGoLingen\PogoAPI\Logger;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

use function count;
use function date;
use function json_encode;
use function printf;
use function trim;

use const PHP_EOL;

class PrintLogger implements LoggerInterface
{
    use LoggerTrait;

    /**
     * @param mixed               $level
     * @param string|mixed        $message
     * @param array<mixed, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        printf(
            trim('[%s] %s %s') . PHP_EOL,
            date('H:i:s'),
            $message,
            count($context) !== 0 ? '-> ' . json_encode($context) : ''
        );
    }
}
