<?php

declare(strict_types=1);

namespace StrandsPhpClient\Http;

use Psr\Log\LoggerInterface;
use StrandsPhpClient\Response\AgentResponse;
use StrandsPhpClient\Streaming\StreamResult;
use StrandsPhpClient\Streaming\StreamSseSummary;

/**
 * Fans one parsed agent result out to every registered response observer.
 *
 * It deduplicates app observers registered directly or through middleware, then notifies each once.
 * Observer failures are logged and skipped so telemetry never replaces the caller's result with an error.
 */
final class ResponseObserverNotifier implements ResponseObserver
{
    /** @var list<ResponseObserver> */
    private readonly array $responseObservers;

    /**
     * Collect every observer to notify, including observers registered as middleware.
     *
     * @param list<RequestMiddleware> $middleware        Request middleware the app registered; entries that also observe responses are auto-detected.
     * @param list<ResponseObserver> $responseObservers Explicit observers; empty means middleware supplies every observer.
     * @param LoggerInterface         $logger            Logger that records observer failures without interrupting the request.
     */
    public function __construct(
        array $middleware,
        array $responseObservers,
        private readonly LoggerInterface $logger,
    ) {
        $this->responseObservers = self::normaliseResponseObservers($middleware, $responseObservers);
    }

    /**
     * Tell each observer an invoke() call finished, with the parsed response.
     *
     * @param string $url Request URL being observed.
     * @param AgentResponse $response Parsed response data for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
     */
    public function afterInvoke(string $url, AgentResponse $response, float $durationMs): void
    {
        $this->notifyResponseObservers(
            static function (ResponseObserver $responseObserver) use ($url, $response, $durationMs): void {
                $responseObserver->afterInvoke($url, $response, $durationMs);
            },
            'afterInvoke',
        );
    }

    /**
     * Tell each observer a typed stream() call finished, with the parsed result.
     *
     * @param string $url Request URL being observed.
     * @param StreamResult $result Parsed stream result for the operation.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
     */
    public function afterStream(string $url, StreamResult $result, float $durationMs): void
    {
        $this->notifyResponseObservers(
            static function (ResponseObserver $responseObserver) use ($url, $result, $durationMs): void {
                $responseObserver->afterStream($url, $result, $durationMs);
            },
            'afterStream',
        );
    }

    /**
     * Tell each observer a custom postJson() call finished, with the raw response.
     *
     * @param array<string, mixed> $response parsed agent result returned to the app.
     * @param string $url agent endpoint the app is calling.
     * @param float $durationMs elapsed time reported to app telemetry.
     * @return void No returned value; updates client or observer state.
     */
    public function afterPostJson(string $url, array $response, float $durationMs): void
    {
        $this->notifyResponseObservers(
            static function (ResponseObserver $responseObserver) use ($url, $response, $durationMs): void {
                $responseObserver->afterPostJson($url, $response, $durationMs);
            },
            'afterPostJson',
        );
    }

    /**
     * Tell each observer a raw streamSse() call finished, with the sanitized summary.
     *
     * @param string $url Request URL being observed.
     * @param StreamSseSummary $summary Sanitized raw SSE stream summary.
     * @param float $durationMs Operation duration in milliseconds.
     * @return void
     */
    public function afterStreamSse(string $url, StreamSseSummary $summary, float $durationMs): void
    {
        $this->notifyResponseObservers(
            static function (ResponseObserver $responseObserver) use ($url, $summary, $durationMs): void {
                $responseObserver->afterStreamSse($url, $summary, $durationMs);
            },
            'afterStreamSse',
        );
    }

    /**
     * Collects observers that receive parsed app results.
     *
     * @param list<RequestMiddleware> $middleware request hooks applied before the agent call.
     * @param list<ResponseObserver> $responseObservers observers that receive parsed caller results.
     *
     * @return list<ResponseObserver> Deduplicated observers to notify; empty when the app registered none.
     */
    private static function normaliseResponseObservers(array $middleware, array $responseObservers): array
    {
        /** @var list<ResponseObserver> $observerMiddleware validated before app code uses it. */
        $observerMiddleware = array_values(array_filter(
            $middleware,
            static fn (RequestMiddleware $requestMiddleware): bool => $requestMiddleware instanceof ResponseObserver,
        ));

        // Symfony can register one class as middleware and observer, so deduplicate by object identity.
        // This keeps each completed request from producing the same metric or trace twice.
        $seenObserverIds = [];

        return array_values(array_filter(
            [...$observerMiddleware, ...$responseObservers],
            static function (ResponseObserver $responseObserver) use (&$seenObserverIds): bool {
                $observerId = spl_object_id($responseObserver);
                $isNewObserver = !isset($seenObserverIds[$observerId]);
                $seenObserverIds[$observerId] = true;

                return $isNewObserver;
            },
        ));
    }

    /**
     * Runs observer callbacks without breaking the app call.
     *
     * @param callable(ResponseObserver): void $notifyObserver Observer callback run after an app-facing hook.
     * @param string $hookName Observer hook name used in warning logs.
     * @return void No returned value; updates client or observer state.
     */
    private function notifyResponseObservers(callable $notifyObserver, string $hookName): void
    {
        // Fan out to each observer; one that throws is logged without breaking the caller's operation.
        foreach ($this->responseObservers as $responseObserver) {
            try {
                $notifyObserver($responseObserver);
            } catch (\Throwable $observerException) {
                // A metrics exporter can fail after a valid answer arrives; log it without replacing the caller's result.
                $this->logger->warning(sprintf('Response observer %s threw an exception', $hookName), [
                    'observer' => $responseObserver::class,
                    'error' => $observerException->getMessage(),
                ]);
            }
        }
    }
}
