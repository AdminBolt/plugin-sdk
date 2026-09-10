<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Api\Resource;

use AdminBolt\Plugin\Cli\CommandJob;
use AdminBolt\Plugin\Cli\CommandResult;
use AdminBolt\Plugin\Support\Arr;

/**
 * Running the commands this plugin declared — client API.
 *
 * A plugin does not send a command. It sends the name of one from its own
 * manifest, which an administrator approved, plus values for the parameters
 * that command declares. The panel builds the command line from its own copy
 * of the template, resolves the program itself, and runs it as the account
 * user in a directory inside that account's home.
 *
 * Which means there is nothing to escape here, and no way to write a plugin
 * that runs something it did not declare. If a command is missing, the fix is
 * a manifest change and an approval, not a cleverer argument.
 *
 *     $cli = $plugin->clientFor($request)->cli();
 *
 *     $result = $cli->run('artisan', ['command' => 'migrate'], cwd: 'shop');
 *     $result->ok();
 *
 *     $job = $cli->start('deploy', cwd: 'shop');
 *     $cli->job($job->id)->isRunning();
 */
final class Cli extends Resource
{
    protected function path(): string
    {
        return 'cli';
    }

    public function find(int|string $id): array
    {
        $this->unsupported('find', 'commands are addressed by name. Use commands() or run().');
    }

    public function create(array $attributes): array
    {
        $this->unsupported('create', 'a command is declared in plugin.json, not created over the API.');
    }

    public function update(int|string $id, array $attributes): array
    {
        $this->unsupported('update', 'a command is changed in plugin.json and approved again.');
    }

    public function delete(int|string $id): array
    {
        $this->unsupported('delete', 'a command is removed from plugin.json.');
    }

    /**
     * The catalogue as the panel has it: what was approved, which may be less
     * than the manifest currently asks for.
     *
     * Worth calling once on a page that offers commands. A version that added
     * a command nobody has approved yet should not draw a button that will
     * come back 404.
     *
     * @return array<mixed>
     */
    public function commands(): array
    {
        $response = $this->client->get('cli/commands');

        return $response['commands'] ?? [];
    }

    /**
     * Run a command and wait for it.
     *
     * For anything that finishes while somebody is looking at the page. A
     * command the manifest marked async, or one with a long timeout, is
     * refused here and wants start() instead.
     *
     * @param array<string, mixed> $params
     */
    public function run(
        string $command,
        array $params = [],
        ?string $cwd = null,
        int|string|null $domainId = null,
    ): CommandResult {
        return CommandResult::fromArray($this->client->post('cli/run', Arr::withoutNulls([
            'command' => $command,
            'params' => $params !== [] ? $params : null,
            'cwd' => $cwd,
            // Which PHP an application's console runs under follows the
            // domain serving it, so a page that knows the domain says so.
            'domain_id' => $domainId,
        ])));
    }

    /**
     * Start a command in the background and get something to poll.
     *
     * One at a time per account: the panel refuses a second while the first
     * is in flight, because two composer installs in one vendor directory
     * corrupt it.
     *
     * @param array<string, mixed> $params
     */
    public function start(
        string $command,
        array $params = [],
        ?string $cwd = null,
        int|string|null $domainId = null,
    ): CommandJob {
        return CommandJob::fromArray($this->client->post('cli/jobs', Arr::withoutNulls([
            'command' => $command,
            'params' => $params !== [] ? $params : null,
            'cwd' => $cwd,
            'domain_id' => $domainId,
        ])));
    }

    public function job(string $id): CommandJob
    {
        return CommandJob::fromArray($this->client->get('cli/jobs/' . rawurlencode($id)));
    }

    /**
     * Recent runs for this account, most recent first.
     *
     * @return list<CommandJob>
     */
    public function jobs(int $limit = 20): array
    {
        $response = $this->client->get('cli/jobs', ['limit' => $limit]);

        return array_map(
            static fn (array $job): CommandJob => CommandJob::fromArray($job),
            $response['jobs'] ?? []
        );
    }

    /**
     * The job still in flight for this account, if there is one.
     *
     * A page that polls needs this after a reload: the id it was given lives
     * in a request that has since ended.
     */
    public function runningJob(): ?CommandJob
    {
        foreach ($this->jobs(5) as $job) {
            if ($job->isRunning()) {
                return $job;
            }
        }

        return null;
    }
}
