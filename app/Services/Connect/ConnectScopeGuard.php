<?php

namespace App\Services\Connect;

use CodeIgniter\Model;

/**
 * Humming Bird Connect — reusable tenant-scoped query guard.
 *
 * The Connect data model stores `tenant_id` on every row. Instead of
 * re-implementing `WHERE tenant_id = X` checks in every controller, use
 * these helpers so a cross-tenant read is structurally impossible: the
 * tenant filter is AND'ed with the row lookup.
 */
class ConnectScopeGuard
{
    /**
     * Fetch a row owned by $tenantId, or null. A foreign (cross-tenant) id
     * returns null — it never leaks another tenant's record.
     *
     * @param int|string $id row primary key
     */
    public static function owned(Model $model, int $tenantId, $id): ?array
    {
        $row = $model->where('tenant_id', $tenantId)->find($id);

        return $row !== null && $row !== [] ? $row : null;
    }

    /**
     * Fetch all rows owned by $tenantId.
     */
    public static function ownedList(Model $model, int $tenantId, array $extra = []): array
    {
        $rows = $model->where('tenant_id', $tenantId);
        foreach ($extra as $column => $value) {
            $rows->where($column, $value);
        }

        return $rows->findAll();
    }
}