<?php

namespace ESN\Utils;

class AuthTenant
{
    public readonly TenantType $tenantType;
    public readonly string $userId;
    public readonly string $domainId;
    protected readonly Principal $principal;

    protected $prefixes = [
        TenantType::User->value => 'principals/users/',
        TenantType::Technical->value => 'principals/users/',
        TenantType::Resources->value => 'principals/resources/'
    ];

    public function __construct(
        string $userId,
        string $domainId,
        TenantType $tenantType = TenantType::User
    ) {
        $this->tenantType = $tenantType;
        $this->userId = $userId;
        $this->domainId = $domainId;
        $this->principal = new Principal($this->prefixes[$this->tenantType->value], $this->userId);
    }

    public function getPrincipal(): Principal {
        return $this->principal;
    }
}
