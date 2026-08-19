<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use InvalidArgumentException as BaseInvalidArgumentException;

class InvalidArgumentException extends BaseInvalidArgumentException implements ToxiproxyException
{
}
