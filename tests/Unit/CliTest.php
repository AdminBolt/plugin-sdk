<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Tests\Unit;

use AdminBolt\Plugin\Api\ApiClient;
use AdminBolt\Plugin\Api\Resource\Cli;
use AdminBolt\Plugin\Cli\CommandJob;
use AdminBolt\Plugin\Cli\CommandResult;
use AdminBolt\Plugin\Http\HttpResponse;
use AdminBolt\Plugin\Manifest;
use AdminBolt\Plugin\Tests\Fixtures\FakeHttpClient;
use AdminBolt\Plugin\Tests\TestCase;

/**
 * Running commands a plugin declared.
 *
 * The SDK's part of this is small on purpose: it names a command and passes
 * values. Everything that decides what actually runs lives in the panel,
 * because a rule enforced in the SDK is a rule a plugin can skip by not using
 * the SDK.
 */
final class CliTest extends TestCase
{
    private function cli(FakeHttpClient $http): Cli
    {
        return new Cli(new ApiClient('https://panel.test:2087/api/client', 'k', 's', $http));
    }

    public function test_a_run_sends_the_name_and_the_values_and_nothing_else(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, [
            'status' => 'succeeded',
            'exit_code' => 0,
            'output' => 'Nothing to migrate.',
            'steps_done' => 1,
            'steps_total' => 1,
        ]);

        $result = $this->cli($http)->run('artisan', ['command' => 'migrate'], cwd: 'shop');

        $sent = json_decode((string) $http->lastRequest()['body'], true);

        self::assertSame('https://panel.test:2087/api/client/cli/run', $http->lastRequest()['url']);
        self::assertSame(['command' => 'artisan', 'params' => ['command' => 'migrate'], 'cwd' => 'shop'], $sent);
        self::assertTrue($result->ok());
        self::assertSame('Nothing to migrate.', $result->output());
    }

    public function test_a_non_zero_exit_is_a_result_and_not_an_exception(): void
    {
        // A migration that fails has succeeded in telling the customer
        // something, and the something is the output. A plugin should not
        // have to catch an exception to show it.
        $http = (new FakeHttpClient())->queueJson(200, [
            'status' => 'failed',
            'exit_code' => 1,
            'output' => "Migrating: 2026_01_01_add_column\n   SQLSTATE[42S21]: Column already exists",
        ]);

        $result = $this->cli($http)->run('artisan', ['command' => 'migrate'], cwd: 'shop');

        self::assertFalse($result->ok());
        self::assertTrue($result->ran());
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('Column already exists', $result->output());
        self::assertSame('SQLSTATE[42S21]: Column already exists', $result->lastLine());
    }

    public function test_a_program_the_server_does_not_have_reads_as_not_run(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, [
            'status' => 'unavailable',
            'message' => 'composer is not installed on this server.',
            'output' => '',
        ]);

        $result = $this->cli($http)->run('composer-install', cwd: 'shop');

        self::assertFalse($result->ran());
        self::assertSame('composer is not installed on this server.', $result->message);
    }

    public function test_a_job_reports_its_progress_while_it_runs(): void
    {
        $http = (new FakeHttpClient())
            ->queue(new HttpResponse(202, json_encode([
                'id' => '17',
                'command' => 'deploy',
                'status' => 'queued',
                'finished' => false,
                'steps_done' => 0,
                'steps_total' => 4,
            ], JSON_THROW_ON_ERROR)))
            ->queueJson(200, [
                'id' => '17',
                'command' => 'deploy',
                'status' => 'running',
                'finished' => false,
                'steps_done' => 2,
                'steps_total' => 4,
                'output' => 'Already up to date.',
            ]);

        $cli = $this->cli($http);
        $job = $cli->start('deploy', cwd: 'shop');

        self::assertSame('17', $job->id);
        self::assertTrue($job->isRunning());

        $polled = $cli->job($job->id);

        self::assertSame('2/4', $polled->progress());
        self::assertTrue($polled->isRunning());
        self::assertFalse($polled->succeeded());
    }

    public function test_a_finished_job_says_whether_it_worked(): void
    {
        $succeeded = CommandJob::fromArray([
            'id' => '1', 'status' => CommandResult::STATUS_SUCCEEDED, 'finished' => true, 'exit_code' => 0,
        ]);

        $failed = CommandJob::fromArray([
            'id' => '2', 'status' => CommandResult::STATUS_FAILED, 'finished' => true, 'exit_code' => 1,
        ]);

        self::assertTrue($succeeded->succeeded());
        self::assertTrue($failed->failed());
        self::assertFalse($failed->isRunning());
    }

    public function test_the_running_job_is_the_one_a_reloaded_page_polls(): void
    {
        // The id the page was given lives in a request that has ended, so a
        // page that comes back has to ask what is in flight.
        $http = (new FakeHttpClient())->queueJson(200, [
            'jobs' => [
                ['id' => '9', 'status' => 'running', 'finished' => false],
                ['id' => '8', 'status' => 'succeeded', 'finished' => true],
            ],
        ]);

        $running = $this->cli($http)->runningJob();

        self::assertNotNull($running);
        self::assertSame('9', $running->id);
    }

    public function test_a_manifest_declaring_commands_without_the_scope_is_refused(): void
    {
        $errors = Manifest::validate([
            'id' => 'laravel-toolkit',
            'name' => 'Laravel Toolkit',
            'version' => '1.0.0',
            'runtime' => ['entrypoint' => 'public/index.php'],
            'commands' => [
                ['name' => 'artisan', 'program' => 'php', 'args' => ['artisan', 'about']],
            ],
        ]);

        self::assertStringContainsString('client:cli:execute', implode("\n", $errors));
    }

    public function test_a_manifest_using_an_undeclared_placeholder_is_refused(): void
    {
        $errors = Manifest::validate($this->manifestWith([
            'name' => 'artisan',
            'program' => 'php',
            'args' => ['artisan', '{command}'],
        ]));

        self::assertStringContainsString('{command}', implode("\n", $errors));
    }

    public function test_a_manifest_asking_for_a_free_string_parameter_is_refused(): void
    {
        $errors = Manifest::validate($this->manifestWith([
            'name' => 'artisan',
            'program' => 'php',
            'args' => ['artisan', '{command}'],
            'params' => ['command' => ['type' => 'string']],
        ]));

        self::assertStringContainsString('no free string type', implode("\n", $errors));
    }

    public function test_a_well_formed_command_validates(): void
    {
        $errors = Manifest::validate($this->manifestWith([
            'name' => 'deploy',
            'label' => 'Deploy',
            'steps' => [
                ['program' => 'git', 'args' => ['pull', '--ff-only']],
                ['program' => 'php', 'args' => ['artisan', 'migrate', '--force']],
            ],
            'timeout' => 900,
            'async' => true,
        ]));

        self::assertSame([], $errors);
    }

    public function test_a_manifest_exposes_what_it_declared(): void
    {
        $manifest = Manifest::fromArray($this->manifestWith([
            'name' => 'artisan',
            'program' => 'php',
            'args' => ['artisan', 'about'],
        ]));

        self::assertSame(['artisan'], $manifest->commandNames());
        self::assertTrue($manifest->declaresCommand('artisan'));
        self::assertFalse($manifest->declaresCommand('deploy'));
    }

    /**
     * @param  array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function manifestWith(array $command): array
    {
        return [
            'id' => 'laravel-toolkit',
            'name' => 'Laravel Toolkit',
            'version' => '1.0.0',
            'runtime' => ['entrypoint' => 'public/index.php'],
            'api' => ['scopes' => ['client:cli:execute']],
            'commands' => [$command],
        ];
    }
}
