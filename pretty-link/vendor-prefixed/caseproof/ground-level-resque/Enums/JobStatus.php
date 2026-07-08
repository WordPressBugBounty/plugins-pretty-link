<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Resque\Enums;

use PrettyLinks\GroundLevel\Support\Enum;

/**
 * Relationship type enum.
 *
 * @method static JobStatus PENDING() Returns the {@see JobStatus::PENDING} enum case.
 * @method static JobStatus WORKING() Returns the {@see JobStatus::WORKING} enum case.
 * @method static JobStatus COMPLETE() Returns the {@see JobStatus::COMPLETE} enum case.
 * @method static JobStatus FAILED() Returns the {@see JobStatus::FAILED} enum case.
 */
class JobStatus extends Enum
{
    /**
     * Type: Pending.
     */
    public const PENDING = 'pending';

    /**
     * Type: Working.
     */
    public const WORKING = 'working';

    /**
     * Type: Completed.
     */
    public const COMPLETE = 'complete';

    /**
     * Type: Failed.
     */
    public const FAILED = 'failed';
}
