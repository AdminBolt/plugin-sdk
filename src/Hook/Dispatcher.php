<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Hook;

use AdminBolt\Plugin\Exception\PluginException;
use AdminBolt\Plugin\Logging\Logger;
use AdminBolt\Plugin\Logging\NullLogger;

/**
 * Routes a delivery to the handlers registered for its hook.
 *
 * More than one handler may listen to the same hook. They run in registration
 * order and the results combine with the safe rule: the first rejection wins
 * and stops the chain, and mutations from the handlers that did run are
 * merged. A handler that throws does not take the others down; it is logged
 * and turned into an error response, which the panel resolves with the
 * plugin's failure policy.
 */
final class Dispatcher
{
    /** @var array<string, list<callable(HookRequest): HookResponse>> */
    private array $handlers = [];

    /** @var list<callable(HookRequest, HookResponse): void> */
    private array $observers = [];

    public function __construct(private readonly Logger $logger = new NullLogger())
    {
    }

    /**
     * @param callable(HookRequest): HookResponse $handler
     */
    public function on(string $hook, callable $handler): self
    {
        if (!Hook::isKnown($hook)) {
            throw new PluginException(sprintf(
                'Unknown hook "%s". The panel never dispatches it, so this handler would be dead code. Known hooks: %s.',
                $hook,
                implode(', ', Hook::all())
            ));
        }

        $this->handlers[$hook][] = $handler;

        return $this;
    }

    public function register(HookHandler $handler): self
    {
        foreach ($handler->hooks() as $hook) {
            $this->on($hook, $handler->handle(...));
        }

        return $this;
    }

    /**
     * Runs for every delivery, after the handlers. For logging and metrics
     * only: an observer cannot change the outcome.
     *
     * @param callable(HookRequest, HookResponse): void $observer
     */
    public function observe(callable $observer): self
    {
        $this->observers[] = $observer;

        return $this;
    }

    public function handles(string $hook): bool
    {
        return ($this->handlers[$hook] ?? []) !== [];
    }

    /** @return list<string> */
    public function registeredHooks(): array
    {
        return array_keys(array_filter($this->handlers, static fn (array $h): bool => $h !== []));
    }

    public function dispatch(HookRequest $request): HookResponse
    {
        $handlers = $this->handlers[$request->hook] ?? [];

        // Not subscribing to a hook is not an error. The panel may deliver one
        // the plugin declared in an earlier version, or an operator may have
        // enabled it by hand; acknowledging is the correct answer.
        $response = $handlers === []
            ? HookResponse::ok(['handled' => false])
            : $this->runChain($request, $handlers);

        foreach ($this->observers as $observer) {
            try {
                $observer($request, $response);
            } catch (\Throwable $e) {
                $this->logger->warning('Hook observer failed', [
                    'hook' => $request->hook,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $response;
    }

    /**
     * @param list<callable(HookRequest): HookResponse> $handlers
     */
    private function runChain(HookRequest $request, array $handlers): HookResponse
    {
        $mutations = [];
        $data = [];
        $errors = [];

        foreach ($handlers as $handler) {
            try {
                $result = $handler($request);
            } catch (\Throwable $e) {
                $this->logger->error('Hook handler threw', [
                    'hook' => $request->hook,
                    'delivery_id' => $request->deliveryId,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                $errors[] = $e->getMessage();
                continue;
            }

            if (!$result instanceof HookResponse) {
                throw new PluginException(sprintf(
                    'Handler for "%s" returned %s; it must return a HookResponse.',
                    $request->hook,
                    get_debug_type($result)
                ));
            }

            if ($result->isRejection()) {
                // A veto is final. Handlers after this one do not run, because
                // the operation they would be reacting to is not going to happen.
                return $result;
            }

            $mutations = [...$mutations, ...$result->mutations];
            $data = [...$data, ...$result->data];
        }

        if ($errors !== [] && $mutations === []) {
            return HookResponse::error(implode('; ', $errors));
        }

        return HookResponse::mutate($mutations, null, $data);
    }
}
