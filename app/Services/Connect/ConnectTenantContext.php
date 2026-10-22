<?php

namespace App\Services\Connect;

use Config\Connect as ConnectConfig;

/**
 * Humming Bird Connect — authenticated tenant context.
 *
 * Produced ONLY by ConnectAuthenticationService after a request has been
 * verified. The tenant_id here comes from the API credential, never from a
 * request body — controllers must always use this object for authorization.
 */
class ConnectTenantContext
{
    public function __construct(
        private int $tenantId,
        private int $credentialDbId,
        private string $credentialId,
        private string $environment,
        private string $requestId,
        private array $scopes = [],
        private ?string $name = null,
        private ?string $expiresAt = null,
        private string $role = ConnectConfig::CREDENTIAL_ROLE_API,
        private ?array $allowedTenantIds = null,
    ) {
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function credentialDbId(): int
    {
        return $this->credentialDbId;
    }

    public function credentialId(): string
    {
        return $this->credentialId;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function scopes(): array
    {
        return $this->scopes;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function expiresAt(): ?string
    {
        return $this->expiresAt;
    }

    /**
     * Credential role ('api' | 'admin'). Admin scope routes additionally
     * require role === 'admin' — enforced by ConnectApiAuthFilter.
     */
    public function role(): string
    {
        return $this->role;
    }

    /**
     * Tenant ids an admin credential may view, or null meaning ALL tenants.
     * Plain ('api') credentials always see null here too — the value only
     * matters for the role gate + admin queries.
     *
     * @return int[]|null
     */
    public function allowedTenantIds(): ?array
    {
        return $this->allowedTenantIds;
    }

    public function isAdmin(): bool
    {
        return $this->role === ConnectConfig::CREDENTIAL_ROLE_ADMIN;
    }

    /**
     * True when the credential carries the given capability scope.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function toArray(): array
    {
        return [
            'tenant_id'     => $this->tenantId,
            'credential_id' => $this->credentialId,
            'environment'   => $this->environment,
            'request_id'    => $this->requestId,
            'scopes'        => $this->scopes,
        ];
    }
}