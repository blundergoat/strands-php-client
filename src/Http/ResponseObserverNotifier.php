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
 * StrandsClient builds this from the app's middleware and observer lists, so a
 * class registered as both (common under Symfony auto-configuration) still hears
 * each result exactly once. An observer that throws is logged and skipped —
 * telemetry problems never break the user's request.
 */
final class ResponseObserverNotifier implements ResponseObserver
{
    /** @var list<ResponseObserver> */
    private readonly array $responseObservers;

    /**
     * Collect every observer to notify, including observers registered as middleware.
     *
     * @param list<RequestMiddleware> $middleware        Request middleware the app registered; entries that also observe responses are auto-detected.
     * @param list<ResponseObserver>  $responseObservers Observers the app registered explicitly; empty when it relies on middleware auto-detection alone.
     * @param LoggerInterface         $logger            Logger that records observer failures without interrupting the user's request.
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

        // Dedupe by object identity. A class implementing both RequestMiddleware
        // and ResponseObserver is auto-tagged into both lists under Symfony's
        // registerForAutoconfiguration, so without dedup each afterInvoke /
        // afterStream / afterResponse would fire twice for the same observer.
        $seen = [];

        return array_values(array_filter(
            [...$observerMiddleware, ...$responseObservers],
            static function (ResponseObserver $responseObserver) use (&$seen): bool {
                $id = spl_object_id($responseObserver);
                $isNew = !isset($seen[$id]);
                $seen[$id] = true;

                return $isNew;
            },
        ));
    }

    /**
     * Runs observer callbacks without breaking the app call.
     *
     * @param callable(ResponseObserver): void $notify Observer callback run after an app-facing hook.
     * @param string $hook Observer hook name used in warning logs.
     * @return void No returned value; updates client or observer state.
     */
    private function notifyResponseObservers(callable $notify, string $hook): void
    {
        // Fan out to each observer; one that throws is logged, never breaking the user's call.
        foreach ($this->responseObservers as $observer) {
            try {
                $notify($observer);
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('Response observer %s threw an exception', $hook), [
                    'observer' => $observer::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
