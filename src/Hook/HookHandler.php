<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Hook;

/**
 * An alternative to closures: a class that declares which hooks it wants.
 *
 * Register it with Plugin::register() and the dispatcher subscribes it to
 * every hook it names.
 */
interface HookHandler
{
    /**
     * Hook names this handler answers, from the {@see Hook} constants.
     *
     * @return list<string>
     */
    public function hooks(): array;

    public function handle(HookRequest $request): HookResponse;
}
