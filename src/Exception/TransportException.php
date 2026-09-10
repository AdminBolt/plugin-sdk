<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Exception;

/**
 * The request never produced an HTTP response: DNS failure, refused
 * connection, TLS rejection or timeout. Distinct from ApiException, which
 * means the panel answered and said no.
 */
class TransportException extends PluginException
{
}
