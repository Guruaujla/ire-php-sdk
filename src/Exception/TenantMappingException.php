<?php

declare(strict_types=1);

namespace Ezee\IntegrationRuntime\Exception;

final class TenantMappingException extends \RuntimeException
{
    public function __construct(public readonly string $problemCode, public readonly ?int $httpStatus = null)
    {
        parent::__construct('Customer mapping request failed (' . $problemCode . ')');
    }
}
