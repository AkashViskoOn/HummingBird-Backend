<?php

namespace App\Services\Connect;

use CodeIgniter\Database\BaseConnection;
use Config\Connect as ConnectConfig;
use Config\Database;
use InvalidArgumentException;
use App\Models\Connect\ConnectApiCredentialModel;
use App\Models\Connect\ConnectTenantModel;

/**
 * Humming Bird Connect — API credential management.
 *
 * Responsibilities (per Phase 2):
 *   - generate high-entropy credential_id / api_key / secret
 *   - store a SECURE representation of the secret (ConnectSecretVault, never
 *     plaintext in the database)
 *   - associate the credential with a tenant + environment
 *   - create / revoke / rotate / validate lifecycle
 *   - return the plaintext secret ONLY at creation time
 *
 * The secret is never logged, never returned twice, and never included in
 * errors, exceptions or audit rows.
 */
class ConnectCredentialService
{
    private ?BaseConnection $db = null;

    private function db(): BaseConnection
    {
        if ($this->db === null) {
            // defaultGroup is 'tests' under CI_ENVIRONMENT=testing and
            // 'default' in production — tests never touch the live RDS DB.
            $this->db = Database::connect(config(Database::class)->defaultGroup);
        }

        return $this->db;
    }

    private function credentialModel(): ConnectApiCredentialModel
    {
        return new ConnectApiCredentialModel($this->db());
    }

    private function tenantModel(): ConnectTenantModel
    {
        return new ConnectTenantModel($this->db());
    }

    /**
     * Internal-only token: NOT displayable, high entropy for the DB id column.
     */
    public function generateCredentialId(): string
    {
        return ConnectConfig::CREDENTIAL_ID_PREFIX
            . bin2hex(random_bytes(8));
    }

    /**
     * Public API key, environment-encoded: hb_test_/hb_live_<random>.
     */
    public function generateApiKey(string $environment): string
    {
        return ConnectConfig::keyPrefixFor($environment)
            . bin2hex(random_bytes(16));
    }

    /**
     * High-entropy secret shown to the client exactly once.
     */
    public function generateSecret(): string
    {
        return bin2hex(random_bytes(ConnectConfig::CREDENTIAL_MAX_SECRET_BYTES));
    }

    /**
     * Create a new API credential. Returns the secret ONCE.
     *
     * @return array{credential_id:string, api_key:string, api_secret:string}
     *
     * @throws InvalidArgumentException when the tenant does not exist
     */
    public function createCredential(
        int $tenantId,
        string $environment,
        array $scopes = [],
        ?string $name = null,
        ?string $expiresAt = null,
    ): array {
        $this->assertTenantExists($tenantId);

        $environment = $environment === ConnectConfig::TENANT_ENVIRONMENT_TEST
            ? ConnectConfig::TENANT_ENVIRONMENT_TEST
            : ConnectConfig::TENANT_ENVIRONMENT_PRODUCTION;

        $vault    = new ConnectSecretVault();
        $apiKey   = $this->generateApiKey($environment);
        $secret   = $this->generateSecret();
        $credId   = $this->generateCredentialId();
        $prefix   = ConnectConfig::keyPrefixFor($environment);

        $id = $this->credentialModel()->insert([
            'tenant_id'     => $tenantId,
            'credential_id' => $credId,
            'api_key'       => $apiKey,
            'key_prefix'    => $prefix,
            'secret_hash'   => $vault->seal($secret),
            'name'          => $name,
            'environment'   => $environment,
            'status'        => ConnectConfig::CREDENTIAL_STATUS_ACTIVE,
            'scopes'        => json_encode($scopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expires_at'    => $expiresAt,
        ]);

        if ($id === false) {
            throw new InvalidArgumentException('Could not create the Connect API credential.');
        }

        // The secret is intentionally produced here and nowhere else. It is
        // NOT stored or logged anywhere after this point.
        return [
            'credential_id' => $credId,
            'api_key'       => $apiKey,
            'api_secret'    => $secret,
        ];
    }

    /**
     * Immediately invalidate a credential by its db credential_id or api_key.
     */
    public function revokeCredential(string $identifier): bool
    {
        $row = $this->findByIdentifier($identifier);
        if ($row === null) {
            return false;
        }

        return $this->credentialModel()->update($row['id'], [
            'status'     => ConnectConfig::CREDENTIAL_STATUS_REVOKED,
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Rotate credential material without destroying tenants.
     *
     * Creates a brand-new credential row (new api_key/secret). With
     * $overlapSeconds > 0 the old credential stays ACTIVE so clients can
     * transition during a maintenance window; revoke it afterwards with
     * revokeCredential(). Default behaviour revokes the old one immediately.
     *
     * @return array{old_credential_id:string, old_status:string, new_credential_id:string, api_key:string, api_secret:string}
     */
    public function rotateCredential(
        string $oldIdentifier,
        int $overlapSeconds = 0,
    ): array {
        $old = $this->findByIdentifier($oldIdentifier);
        if ($old === null) {
            throw new InvalidArgumentException('Credential not found.');
        }

        $scopes = json_decode((string) ($old['scopes'] ?? '[]'), true);
        if (! is_array($scopes)) {
            $scopes = [];
        }

        $created = $this->createCredential(
            (int) $old['tenant_id'],
            (string) $old['environment'],
            $scopes,
            ($old['name'] ?? null) ? (string) $old['name'] : null,
        );

        $newStatus = ConnectConfig::CREDENTIAL_STATUS_ACTIVE;

        if ($overlapSeconds > 0) {
            // Old stays valid for the overlap window; caller revokes later.
            $newStatus = (string) $old['status'];
        } else {
            $this->credentialModel()->update($old['id'], [
                'status'     => ConnectConfig::CREDENTIAL_STATUS_ROTATED,
                'revoked_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'old_credential_id'  => (string) $old['credential_id'],
            'old_status'         => $newStatus,
            'new_credential_id'  => $created['credential_id'],
            'api_key'            => $created['api_key'],
            'api_secret'         => $created['api_secret'],
        ];
    }

    /**
     * Timing-safe verification of a candidate secret against the stored one.
     */
    public function validateSecret(string $identifier, string $candidateSecret): bool
    {
        $row = $this->findByIdentifier($identifier);
        if ($row === null || empty($row['secret_hash'])) {
            return false;
        }

        try {
            $stored = (new ConnectSecretVault())->open((string) $row['secret_hash']);
        } catch (\Throwable $e) {
            return false;
        }

        return hash_equals($stored, $candidateSecret);
    }

    /**
     * Lookup a credential row by api_key. Returns null when unknown so the
     * caller can return a generic INVALID_API_KEY (no user enumeration).
     */
    public function findByApiKey(string $apiKey): ?array
    {
        return $this->credentialModel()->where('api_key', $apiKey)->first();
    }

    /**
     * Lookup by either the db credential_id or the api_key.
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $row = $this->credentialModel()->where('credential_id', $identifier)->first();
        if ($row !== null) {
            return $row;
        }

        return $this->findByApiKey($identifier);
    }

    private function assertTenantExists(int $tenantId): void
    {
        if ($this->tenantModel()->find($tenantId) === null) {
            throw new InvalidArgumentException('Tenant does not exist.');
        }
    }
}