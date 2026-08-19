<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception this package throws.
 *
 * Catch this to handle any failure originating from toxiproxy-php without
 * catching unrelated RuntimeException instances from the rest of your app.
 */
interface ToxiproxyException extends Throwable
{
}
