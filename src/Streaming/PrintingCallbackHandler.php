<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * A callback handler that prints text events to stdout and errors to stderr.
 */
class PrintingCallbackHandler extends StreamCallbackHandler
{
    protected function onText(StreamEvent $event): void
    {
        echo $event->text;
    }

    protected function onComplete(StreamEvent $event): void
    {
        echo PHP_EOL;
    }

    protected function onError(StreamEvent $event): void
    {
        fwrite(STDERR, "Error [{$event->errorCode}]: {$event->errorMessage}" . PHP_EOL);
    }
}
