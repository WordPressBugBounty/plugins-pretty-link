<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Container;

use PrettyLinks\Psr\Container\ContainerExceptionInterface;
use Exception as BaseException;

class Exception extends BaseException implements ContainerExceptionInterface
{
}
