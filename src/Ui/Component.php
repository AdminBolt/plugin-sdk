<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * Something the panel can render.
 *
 * A plugin describes its interface; it never emits HTML. The panel renders
 * the description with its own native components, which is what makes a
 * plugin page look like the rest of the panel, respect the operator's theme,
 * work on a phone, and stay free of injection by construction: there is no
 * markup to escape.
 */
interface Component extends \JsonSerializable
{
    /**
     * The discriminator the panel switches on when rendering.
     */
    public function type(): string;
}
