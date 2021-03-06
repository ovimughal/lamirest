<?php

namespace Lamirest\Implement;

interface TenantProviderInterface {
    public function getTenantInfoById(?int $organizationId, ?array $tenantInfo = null);
    public function getTenantConnectionInfoById(?int $organizationId, ?array $tenantInfo = null);
}