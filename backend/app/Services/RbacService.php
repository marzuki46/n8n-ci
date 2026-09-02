<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * RBAC berbasis peran workspace (owner/admin/member).
 *
 * Matriks peran:
 *   owner  - akses penuh termasuk kelola anggota & hapus workspace
 *   admin  - kelola workflow, credential, API key, schedule (tidak bisa
 *            kelola anggota / hapus workspace)
 *   member - hanya baca + eksekusi; tidak bisa ubah/ubah/hapus aset
 */
class RbacService
{
    protected $db;

    protected const PERMISSIONS = [
        'workflows:read'     => ['owner', 'admin', 'member'],
        'workflows:write'    => ['owner', 'admin'],
        'workflows:delete'   => ['owner', 'admin'],
        'workflows:execute'  => ['owner', 'admin', 'member'],
        'credentials:read'   => ['owner', 'admin', 'member'],
        'credentials:write'  => ['owner', 'admin'],
        'apikeys:manage'     => ['owner', 'admin'],
        'schedules:read'     => ['owner', 'admin', 'member'],
        'schedules:write'    => ['owner', 'admin'],
        'workspaces:update'  => ['owner'],
        'workspaces:delete'  => ['owner'],
        'members:manage'     => ['owner'],
    ];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Peran user di sebuah workspace. Non-anggota => 'none'.
     */
    public function roleInWorkspace(int $userId, int $workspaceId): string
    {
        $row = $this->db->table('workspace_users')
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->get()
            ->getRowArray();

        return $row['role'] ?? 'none';
    }

    public function can(string $permission, int $userId, int $workspaceId): bool
    {
        if (! isset(self::PERMISSIONS[$permission])) {
            return false;
        }

        $role = $this->roleInWorkspace($userId, $workspaceId);
        if ($role === 'none') {
            return false;
        }

        return in_array($role, self::PERMISSIONS[$permission], true);
    }

    /**
     * Daftar peran yang boleh mengelola workspace (untuk UI/API).
     */
    public static function permissions(): array
    {
        return self::PERMISSIONS;
    }
}
