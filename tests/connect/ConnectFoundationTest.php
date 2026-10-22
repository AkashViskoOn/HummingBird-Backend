<?php

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use App\Database\Migrations\CreateHummingBirdConnectFoundation;
use App\Models\Connect\ConnectApiCredentialModel;
use App\Models\Connect\ConnectExternalMessageModel;
use App\Models\Connect\ConnectExternalUserModel;
use App\Models\Connect\ConnectIdempotencyKeyModel;
use App\Models\Connect\ConnectTenantModel;

/**
 * Humming Bird Connect — Phase 1 database foundation tests.
 *
 * Runs ONLY the Connect migration (the legacy app migrations depend on
 * hand-created production tables and are therefore not part of this suite).
 *
 * Uses the SQLite `tests` connection; requires the PHP `sqlite3` extension.
 *
 * @internal
 */
final class ConnectFoundationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = false;
    protected $migrateOnce = false;
    protected $refresh     = false;
    protected $namespace   = null;

    private ?CreateHummingBirdConnectFoundation $migration = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Migration class name differs from the timestamped file name, so it
        // is not PSR-4 autoloadable — require it explicitly.
        require_once APPPATH
            . 'Database/Migrations/2026-09-08-000001_CreateHummingBirdConnectFoundation.php';

        $this->migration = new CreateHummingBirdConnectFoundation();
        // Clean slate, then build only the connect_* tables.
        $this->migration->down();
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        if ($this->migration !== null) {
            $this->migration->down();
        }
        parent::tearDown();
    }

    /**
     * Connect models declare $DBGroup = 'default' (they run against RDS in
     * production). Inject the SQLite `tests` connection at construction so
     * nothing ever touches the production database.
     */
    private function model(string $class)
    {
        return new $class(Database::connect('tests'));
    }

    private function createTenant(string $slug): int
    {
        $model = $this->model(ConnectTenantModel::class);
        $data  = [
            'uuid'        => uniqid('tenant_', true),
            'name'        => 'Tenant ' . $slug,
            'slug'        => $slug,
            'status'      => 'active',
            'environment' => 'test',
        ];
        return (int) $model->insert($data);
    }

    /**
     * Expects a duplicate-key rejection: either a DatabaseException, or the
     * model insert returning false (driver-dependent behaviour).
     */
    private function assertDuplicateRejected(callable $operation): void
    {
        try {
            $result = $operation();
            if ($result === false) {
                $this->assertTrue(true); // insert rejected cleanly
                return;
            }
            $this->fail('Expected the duplicate-key insert to be rejected, but it was accepted.');
        } catch (DatabaseException $e) {
            $this->assertTrue(true); // constraint violation raised
        }
    }

    public function testTenantUuidAndSlugAreUnique()
    {
        $tenantA = $this->createTenant('tenant-unique');

        $this->assertGreaterThan(0, $tenantA);

        // Duplicate slug must be rejected.
        $this->assertDuplicateRejected(fn () => $this->createTenant('tenant-unique'));

        // Duplicate uuid must be rejected.
        $model = $this->model(ConnectTenantModel::class);
        $this->assertDuplicateRejected(fn () => $model->insert([
            'uuid'        => $model->find($tenantA)['uuid'],
            'name'        => 'Another',
            'slug'        => 'different-slug',
            'status'      => 'active',
            'environment' => 'test',
        ]));
    }

    public function testExternalUserIdIsUniquePerTenantButSharedAcrossTenants()
    {
        $tenantA = $this->createTenant('tenant-exta');
        $tenantB = $this->createTenant('tenant-extb');
        $model   = $this->model(ConnectExternalUserModel::class);

        // Allowed: same external_user_id under two DIFFERENT tenants.
        $idA = (int) $model->insert([
            'tenant_id'        => $tenantA,
            'external_user_id' => 'USER-100',
            'status'           => 'active',
        ]);
        $idB = (int) $model->insert([
            'tenant_id'        => $tenantB,
            'external_user_id' => 'USER-100',
            'status'           => 'active',
        ]);
        $this->assertGreaterThan(0, $idA);
        $this->assertGreaterThan(0, $idB);

        // Rejected: same external_user_id under the SAME tenant.
        $this->assertDuplicateRejected(fn () => $model->insert([
            'tenant_id'        => $tenantA,
            'external_user_id' => 'USER-100',
            'status'           => 'active',
        ]));
    }

    public function testApiCredentialIdIsUnique()
    {
        $tenantA = $this->createTenant('tenant-cred');
        $model   = $this->model(ConnectApiCredentialModel::class);

        $data = [
            'tenant_id'     => $tenantA,
            'credential_id' => 'hb_cred_0001',
            'key_prefix'    => 'hb_live_',
            'secret_hash'   => str_repeat('a', 64),
            'status'        => 'active',
        ];

        $this->assertGreaterThan(0, (int) $model->insert($data));
        $this->assertDuplicateRejected(fn () => $model->insert($data));
    }

    public function testIdempotencyKeyIsUniquePerTenant()
    {
        $tenantA = $this->createTenant('tenant-idem-a');
        $tenantB = $this->createTenant('tenant-idem-b');
        $model   = $this->model(ConnectIdempotencyKeyModel::class);

        // Same key on two tenants is allowed.
        $this->assertGreaterThan(0, (int) $model->insert([
            'tenant_id'       => $tenantA,
            'idempotency_key' => 'INT-7788',
            'status'          => 'completed',
        ]));
        $this->assertGreaterThan(0, (int) $model->insert([
            'tenant_id'       => $tenantB,
            'idempotency_key' => 'INT-7788',
            'status'          => 'completed',
        ]));

        // Same key on the same tenant is rejected (retry must not duplicate).
        $this->assertDuplicateRejected(fn () => $model->insert([
            'tenant_id'       => $tenantA,
            'idempotency_key' => 'INT-7788',
            'status'          => 'completed',
        ]));
    }

    public function testTenantIsolationOnExternalMessages()
    {
        $tenantA = $this->createTenant('tenant-iso-a');
        $tenantB = $this->createTenant('tenant-iso-b');
        $model   = $this->model(ConnectExternalMessageModel::class);

        $id = (int) $model->insert([
            'tenant_id'    => $tenantA,
            'message_uuid' => uniqid('msg_', true),
            'message_type' => 'notification',
            'body'         => 'hello from A',
            'status'       => 'accepted',
        ]);
        $this->assertGreaterThan(0, $id);

        $scopedToB = $model->where('tenant_id', $tenantB)->findAll();
        $this->assertCount(0, $scopedToB, 'Tenant B must not see Tenant A records.');

        $scopedToA = $model->where('tenant_id', $tenantA)->findAll();
        $this->assertCount(1, $scopedToA);
    }
}