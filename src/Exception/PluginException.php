<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Exception;

/**
 * Base class for every exception the SDK raises.
 *
 * Catching this one type is enough to keep a plugin from crashing the
 * request; the runtime already does so and turns it into an error response.
 */
class PluginException extends \RuntimeException
{
}
