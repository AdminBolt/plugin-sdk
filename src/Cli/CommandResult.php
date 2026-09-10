<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Cli;

/**
 * What running a declared command produced.
 *
 * A non-zero exit is a result, not an exception. `artisan migrate` failing is
 * something to show the customer, and what to show them is on stdout; a
 * plugin that had to catch an exception to find that out would report "the
 * command failed" and throw away the reason.
 */
final readonly class CommandResult
{
    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $status,
        public ?int $exitCode,
        public string $output,
        public int $stepsDone = 1,
        public int $stepsTotal = 1,
        public ?string $message = null,
        public int $durationMs = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: is_string($data['status'] ?? null) ? $data['status'] : self::STATUS_UNAVAILABLE,
            exitCode: isset($data['exit_code']) ? (int) $data['exit_code'] : null,
            output: (string) ($data['output'] ?? ''),
            stepsDone: (int) ($data['steps_done'] ?? 0),
            stepsTotal: (int) ($data['steps_total'] ?? 1),
            message: is_string($data['message'] ?? null) ? $data['message'] : null,
            durationMs: (int) ($data['duration_ms'] ?? 0),
        );
    }

    public function ok(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function failed(): bool
    {
        return !$this->ok();
    }

    /**
     * Whether the command ran at all. A program the server does not have and
     * a migration that exited 1 are both "not ok" and want different words.
     */
    public function ran(): bool
    {
        return $this->status !== self::STATUS_UNAVAILABLE;
    }

    /**
     * The output with nothing else, for a page that shows it.
     */
    public function output(): string
    {
        return trim($this->output);
    }

    /**
     * The last line that says something, which is usually the one worth
     * putting in a toast.
     */
    public function lastLine(): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $this->output)),
            static fn (string $line): bool => $line !== ''
        ));

        return $lines === [] ? '' : (string) end($lines);
    }
}
