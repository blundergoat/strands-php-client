<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * A callback handler that prints text events to stdout and errors to stderr.
 */
class PrintingCallbackHandler extends StreamCallbackHandler
{
    /** @var (\Closure(string): void)|null */
    private readonly ?\Closure $outputWriter;

    /** @var (\Closure(string): void)|null */
    private readonly ?\Closure $errorWriter;

    /**
     * @param callable(string): void|null $outputWriter
     * @param callable(string): void|null $errorWriter
     */
    public function __construct(?callable $outputWriter = null, ?callable $errorWriter = null)
    {
        $this->outputWriter = $outputWriter !== null ? \Closure::fromCallable($outputWriter) : null;
        $this->errorWriter = $errorWriter !== null ? \Closure::fromCallable($errorWriter) : null;
    }

    protected function onText(StreamEvent $event): ?bool
    {
        $this->writeOutput($event->text ?? '');

        return null;
    }

    protected function onComplete(StreamEvent $_event): ?bool
    {
        $this->writeOutput(PHP_EOL);

        return null;
    }

    protected function onError(StreamEvent $event): ?bool
    {
        $this->writeError("Error [{$event->errorCode}]: {$event->errorMessage}" . PHP_EOL);

        return null;
    }

    private function writeOutput(string $message): void
    {
        if ($this->outputWriter !== null) {
            ($this->outputWriter)($message);

            return;
        }

        echo $message;
    }

    private function writeError(string $message): void
    {
        if ($this->errorWriter !== null) {
            ($this->errorWriter)($message);

            return;
        }

        file_put_contents('php://stderr', $message, FILE_APPEND);
    }
}
