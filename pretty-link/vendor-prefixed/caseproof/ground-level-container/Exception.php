<?php

declare(strict_types=1);

namespace Prli\GroundLevel\Container;

use Prli\Psr\Container\ContainerExceptionInterface;
use Exception as BaseException;

class Exception extends BaseException implements ContainerExceptionInterface
{
}
