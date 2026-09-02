<?php

namespace App\Controllers\Api;

use App\Services\RbacService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Kelola anggota workspace (sharing antar user). Hanya owner.
 */
class MemberController extends BaseApiController
{
    public function index(int $workspaceId): ResponseInterface
    {
        $db = \Config\Database::connect();

        if (! $this->hasAccessToWorkspace($workspaceId)) {
            return $this->fail('Tidak punya akses.', 403);
        }

        $members = $db->table('workspace_users')
            ->select('workspace_users.*, users.name, users.email, users.role AS user_role')
            ->join('users', 'users.id = workspace_users.user_id', 'inner')
            ->where('workspace_users.workspace_id', $workspaceId)
            ->orderBy('workspace_users.id', 'ASC')
            ->get()
            ->getResultArray();

        return $this->success($members);
    }

    /**
     * Tambah anggota baru ke workspace (share) lewat email. Hanya owner.
     */
    public function add(int $workspaceId): ResponseInterface
    {
        $denied = $this->requirePermission('members:manage', $workspaceId);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $role = (string) ($input['role'] ?? 'member');

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Email anggota tidak valid.');
        }
        if (! in_array($role, ['owner', 'admin', 'member'], true)) {
            return $this->fail('Role tidak valid.', 422);
        }

        $db = \Config\Database::connect();
        $user = $db->table('users')->where('email', $email)->get()->getRowArray();
        if (! $user) {
            return $this->fail('User dengan email tersebut tidak ditemukan.', 404);
        }
        if ((int) $user['id'] === $this->userId()) {
            return $this->fail('Kamu sudah menjadi anggota workspace ini.');
        }

        $exists = $db->table('workspace_users')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user['id'])
            ->get()
            ->getRowArray();

        if ($exists) {
            return $this->fail('User sudah menjadi anggota.', 409);
        }

        $db->table('workspace_users')->insert([
            'workspace_id' => $workspaceId,
            'user_id'      => $user['id'],
            'role'         => $role,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return $this->success(['user_id' => (int) $user['id'], 'role' => $role], 'Anggota ditambahkan.', 201);
    }

    /**
     * Ubah peran anggota. Hanya owner.
     */
    public function updateRole(int $workspaceId, int $userId): ResponseInterface
    {
        $denied = $this->requirePermission('members:manage', $workspaceId);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $role = (string) ($input['role'] ?? '');
        if (! in_array($role, ['owner', 'admin', 'member'], true)) {
            return $this->fail('Role tidak valid.', 422);
        }

        $db = \Config\Database::connect();
        $membership = $db->table('workspace_users')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        if (! $membership) {
            return $this->fail('Anggota tidak ditemukan.', 404);
        }

        // Cegah demote owner terakhir
        if ($membership['role'] === 'owner') {
            $ownerCount = (int) $db->table('workspace_users')
                ->where('workspace_id', $workspaceId)
                ->where('role', 'owner')
                ->countAllResults();
            if ($ownerCount <= 1) {
                return $this->fail('Tidak bisa menurunkan owner terakhir.', 422);
            }
        }

        $db->table('workspace_users')->where('id', $membership['id'])->update([
            'role' => $role,
        ]);

        return $this->success(['user_id' => $userId, 'role' => $role], 'Peran diperbarui.');
    }

    /**
     * Hapus anggota dari workspace. Hanya owner.
     */
    public function remove(int $workspaceId, int $userId): ResponseInterface
    {
        $denied = $this->requirePermission('members:manage', $workspaceId);
        if ($denied) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $membership = $db->table('workspace_users')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->get()
            ->getRowArray();

        if (! $membership) {
            return $this->fail('Anggota tidak ditemukan.', 404);
        }

        if ($membership['role'] === 'owner') {
            $ownerCount = (int) $db->table('workspace_users')
                ->where('workspace_id', $workspaceId)
                ->where('role', 'owner')
                ->countAllResults();
            if ($ownerCount <= 1) {
                return $this->fail('Tidak bisa menghapus owner terakhir.', 422);
            }
        }

        $db->table('workspace_users')->where('id', $membership['id'])->delete();

        return $this->success(null, 'Anggota dihapus.');
    }

    /**
     * Daftar peran yang tersedia (untuk dropdown UI).
     */
    public function roles(): ResponseInterface
    {
        return $this->success([
            'owner'  => 'Pemilik (akses penuh + kelola anggota)',
            'admin'  => 'Admin (kelola workflow & asset)',
            'member' => 'Member (baca & eksekusi)',
        ]);
    }
}
