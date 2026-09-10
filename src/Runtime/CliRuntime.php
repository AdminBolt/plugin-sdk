<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Runtime;

use AdminBolt\Plugin\Config;
use AdminBolt\Plugin\Exception\SignatureException;
use AdminBolt\Plugin\Hook\Dispatcher;
use AdminBolt\Plugin\Hook\HookRequest;
use AdminBolt\Plugin\Hook\HookResponse;
use AdminBolt\Plugin\Hook\Signature;
use AdminBolt\Plugin\Logging\Logger;
use AdminBolt\Plugin\Logging\NullLogger;
use AdminBolt\Plugin\Support\Json;

/**
 * The alternative transport: the panel executes the plugin once per delivery.
 *
 * Chosen with "runtime": {"transport": "cli"} in plugin.json. It costs a
 * process spawn per hook, so it is the wrong choice for a busy notification
 * hook, but it needs no long-running listener and no port, which suits a
 * plugin that runs rarely or that an operator would rather not leave resident.
 *
 * The envelope arrives on stdin. The signature travels in the environment
 * rather than in argv, because argv is world-readable in the process list.
 * The response goes to stdout as JSON; exit code 0 means the plugin answered,
 * and any other code means it did not, which the panel treats as a delivery
 * failure and resolves with the plugin's failure policy.
 */
final class CliRuntime
{
    public const ENV_SIGNATURE = 'BOLT_HOOK_SIGNATURE';
    public const ENV_TIMESTAMP = 'BOLT_HOOK_TIMESTAMP';

    public function __construct(
        private readonly Config $config,
        private readonly Dispatcher $dispatcher,
        private readonly Logger $logger = new NullLogger(),
    ) {
    }

    /**
     * Reads stdin, writes stdout, and exits with the right code.
     */
    public function run(): never
    {
        $body = (string) file_get_contents('php://stdin');

        $result = $this->handle(
            $body,
            (string) (getenv(self::ENV_SIGNATURE) ?: ''),
            (int) (getenv(self::ENV_TIMESTAMP) ?: 0),
        );

        fwrite(STDOUT, Json::encode($result->jsonSerialize()) . "\n");

        exit($result->status === HookResponse::STATUS_ERROR ? 1 : 0);
    }

    /**
     * Testable core: envelope in, decision out.
     */
    public function handle(string $body, string $signature, int $timestamp): HookResponse
    {
        try {
            if ($signature === '') {
                throw SignatureException::missingHeader(self::ENV_SIGNATURE);
            }

            Signature::verify(
                $this->config->hookSecret,
                $signature,
                $timestamp,
                $body,
                $this->config->signatureTolerance,
            );
        } catch (SignatureException $e) {
            $this->logger->error('Rejected an unauthenticated hook delivery on stdin', [
                'error' => $e->getMessage(),
            ]);

            return HookResponse::error('Invalid signature.');
        }

        try {
            $request = HookRequest::fromJson($body);
        } catch (\Throwable $e) {
            $this->logger->error('Could not read hook envelope from stdin', ['error' => $e->getMessage()]);

            return HookResponse::error('Malformed hook envelope.');
        }

        return $this->dispatcher->dispatch($request);
    }
}
