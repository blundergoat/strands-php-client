<?php

declare(strict_types=1);

namespace StrandsPhpClient\Streaming;

/**
 * A ready-made stream handler that prints the answer as it arrives.
 *
 * Drop this into StrandsClient::stream() to echo streamed text to stdout (and
 * errors to stderr) — handy for CLI tools and quick demos. Pass custom writers
 * to redirect output elsewhere, such as a log file or a test buffer.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- onComplete() keeps the parent's $event name for named-arg callers.
 */
class PrintingCallbackHandler extends StreamCallbackHandler
{
    /** @var (\Closure(string): void)|null */
    private readonly ?\Closure $outputWriter;

    /** @var (\Closure(string): void)|null */
    private readonly ?\Closure $errorWriter;

    /**
     * Choose where streamed text and errors are written (defaults to stdout/stderr).
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
     * @param StreamEvent $event Completed stream event; ignored because the closing newline is unconditional.
     * @return bool|null False cancels the stream; null continues it.
     */
    protected function onComplete(StreamEvent $event): ?bool
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
        // Use the app's custom writer when one was supplied; otherwise print to stdout.
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
        // Use the app's custom error writer when one was supplied; otherwise use stderr.
        if ($this->errorWriter !== null) {
            ($this->errorWriter)($message);

            return;
        }

        file_put_contents('php://stderr', $message, FILE_APPEND);
    }
}
