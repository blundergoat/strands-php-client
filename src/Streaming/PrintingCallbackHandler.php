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
     * Supports the __construct step in the app-facing flow.
     *
     * @param callable(string): void|null $outputWriter Writer used to show streamed text to the user.
     * @param callable(string): void|null $errorWriter Writer used to show stream errors to the user.
     */
    public function __construct(?callable $outputWriter = null, ?callable $errorWriter = null)
    {
        $this->outputWriter = $outputWriter !== null ? \Closure::fromCallable($outputWriter) : null;
        $this->errorWriter = $errorWriter !== null ? \Closure::fromCallable($errorWriter) : null;
    }

    /**
     * Write text event content to the configured output stream.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onText(StreamEvent $event): ?bool
    {
        $this->writeOutput($event->text ?? '');

        return null;
    }

    /**
     * Terminate printed stream output when the stream completes.
     *
     * @param StreamEvent $_event Unused stream event kept for override signature
     * compatibility.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onComplete(StreamEvent $_event): ?bool
    {
        $this->writeOutput(PHP_EOL);

        return null;
    }

    /**
     * Write formatted stream errors to the configured error stream.
     *
     * @param StreamEvent $event Stream event being handled.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onError(StreamEvent $event): ?bool
    {
        $code = $event->errorCode ?? 'unknown';
        $message = $event->errorMessage ?? '(no message)';
        $this->writeError("Error [{$code}]: {$message}" . PHP_EOL);

        return null;
    }

    /**
     * Write output text through the injected writer or stdout.
     *
     * @param string $message Message text to write.
     * @return void
     */
    private function writeOutput(string $message): void
    {
        if ($this->outputWriter !== null) {
            ($this->outputWriter)($message);

            return;
        }

        echo $message;
    }

    /**
     * Write error text through the injected writer or stderr.
     *
     * @param string $message Message text to write.
     * @return void
     */
    private function writeError(string $message): void
    {
        if ($this->errorWriter !== null) {
            ($this->errorWriter)($message);

            return;
        }

        file_put_contents('php://stderr', $message, FILE_APPEND);
    }
}
