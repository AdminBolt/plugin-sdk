<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Cli;

/**
 * A declared command running in the background.
 *
 * A composer install outlives an HTTP request, so the plugin gets one of
 * these back straight away and the page polls it. The output grows as the
 * command runs, which is what makes a live page worth having.
 */
final readonly class CommandJob
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public function __construct(
        public string $id,
        public string $command,
        public string $status,
        public bool $finished,
        public ?int $exitCode,
        public string $output,
        public int $stepsDone,
        public int $stepsTotal,
        public ?string $message = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            command: (string) ($data['command'] ?? ''),
            status: (string) ($data['status'] ?? self::STATUS_QUEUED),
            finished: (bool) ($data['finished'] ?? false),
            exitCode: isset($data['exit_code']) ? (int) $data['exit_code'] : null,
            output: (string) ($data['output'] ?? ''),
            stepsDone: (int) ($data['steps_done'] ?? 0),
            stepsTotal: (int) ($data['steps_total'] ?? 1),
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            startedAt: is_string($data['started_at'] ?? null) ? $data['started_at'] : null,
            finishedAt: is_string($data['finished_at'] ?? null) ? $data['finished_at'] : null,
        );
    }

    public function isRunning(): bool
    {
        return !$this->finished;
    }

    public function succeeded(): bool
    {
        return $this->finished && $this->status === CommandResult::STATUS_SUCCEEDED;
    }

    public function failed(): bool
    {
        return $this->finished && !$this->succeeded();
    }

    /**
     * What to put next to a progress indicator: "step 2 of 4".
     */
    public function progress(): string
    {
        if ($this->stepsTotal <= 1) {
            return $this->finished ? $this->status : 'running';
        }

        return sprintf('%d/%d', max(1, $this->stepsDone), $this->stepsTotal);
    }
}
