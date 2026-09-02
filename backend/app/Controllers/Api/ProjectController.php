<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

class ProjectController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $db = \Config\Database::connect();

        $projects = $db->table('workspaces w')
            ->select('w.*, wu.role AS my_role, COUNT(wf.id) as workflow_count')
            ->join('workspace_users wu', 'wu.workspace_id = w.id AND wu.user_id = ' . (int) $this->userId())
            ->join('workflows wf', 'wf.workspace_id = w.id', 'left')
            ->where('wu.user_id', $this->userId())
            ->groupBy('w.id')
            ->orderBy('w.id', 'ASC')
            ->get()
            ->getResultArray();

        return $this->success($projects);
    }

    public function show(int $id): ResponseInterface
    {
        if (! $this->hasAccessToWorkspace($id)) {
            return $this->fail('Tidak punya akses.', 403);
        }

        $db = \Config\Database::connect();
        $project = $db->table('workspaces')->where('id', $id)->get()->getRowArray();

        if (! $project) {
            return $this->fail('Projek tidak ditemukan.', 404);
        }

        return $this->success($project);
    }

    public function create(): ResponseInterface
    {
        $input = $this->input();
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return $this->fail('Nama projek wajib diisi.');
        }

        helper('text');
        $slug = url_title($name, '-', true) . '-' . random_string('alnum', 6);

        $db = \Config\Database::connect();

        $db->table('workspaces')->insert([
            'name'        => $name,
            'description' => $input['description'] ?? null,
            'slug'        => $slug,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], true);
        $workspaceId = (int) $db->insertID();

        $db->table('workspace_users')->insert([
            'workspace_id' => $workspaceId,
            'user_id'      => $this->userId(),
            'role'         => 'owner',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return $this->success(['id' => $workspaceId], 'Projek dibuat', 201);
    }

    public function update(int $id): ResponseInterface
    {
        $denied = $this->requirePermission('workspaces:update', $id);
        if ($denied) {
            return $denied;
        }

        $input = $this->input();
        $data = [];

        if (isset($input['name']) && trim((string) $input['name']) !== '') {
            $data['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('description', $input)) {
            $data['description'] = $input['description'];
        }
        $data['updated_at'] = date('Y-m-d H:i:s');

        if (empty($data)) {
            return $this->fail('Tidak ada data yang diubah.');
        }

        $db = \Config\Database::connect();
        $db->table('workspaces')->where('id', $id)->update($data);

        return $this->success(['id' => $id], 'Projek diperbarui');
    }

    public function delete(int $id): ResponseInterface
    {
        $denied = $this->requirePermission('workspaces:delete', $id);
        if ($denied) {
            return $denied;
        }

        $db = \Config\Database::connect();

        // Cek masih ada workflow?
        $count = $db->table('workflows')->where('workspace_id', $id)->countAllResults();
        if ($count > 0) {
            return $this->fail('Tidak bisa dihapus: projek masih memiliki ' . $count . ' workflow. Pindahkan/hapus dulu.');
        }

        $db->table('workspace_users')->where('workspace_id', $id)->delete();
        $db->table('workspaces')->where('id', $id)->delete();

        return $this->success(null, 'Projek dihapus');
    }
}
