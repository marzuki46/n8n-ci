<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Tag workflow (setara n8n): CRUD + assign ke workflow.
 * Scoping: tag global sederhana; hanya anggota workspace yang boleh mengelola.
 */
class TagController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $db = \Config\Database::connect();

        $tags = $db->table('tags t')
            ->select('t.id, t.name, t.color, COUNT(wt.workflow_id) AS usage_count')
            ->join('workflow_tags wt', 'wt.tag_id = t.id', 'left')
            ->groupBy('t.id, t.name, t.color')
            ->orderBy('t.name', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($tags as &$tag) {
            $tag['usage_count'] = (int) $tag['usage_count'];
        }

        return $this->success($tags);
    }

    public function create(): ResponseInterface
    {
        $input = $this->input();
        $name  = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return $this->fail('Nama tag wajib diisi.');
        }

        $db = \Config\Database::connect();

        // Hindari duplikat nama (case-insensitive).
        $existing = $db->table('tags')->where('name', $name)->get()->getRowArray();
        if ($existing) {
            return $this->success($existing, 'Tag sudah ada');
        }

        $color = (string) ($input['color'] ?? '#2b6de3');
        if (! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#2b6de3';
        }

        $db->table('tags')->insert([
            'name'       => mb_substr($name, 0, 191),
            'color'      => $color,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->success([
            'id'    => (int) $db->insertID(),
            'name'  => $name,
            'color' => $color,
        ], 'Tag dibuat', 201);
    }

    public function delete(int $id): ResponseInterface
    {
        $db = \Config\Database::connect();

        $db->table('workflow_tags')->where('tag_id', $id)->delete();
        $deleted = $db->table('tags')->where('id', $id)->delete();

        if (! $deleted) {
            return $this->fail('Tag tidak ditemukan.', 404);
        }

        return $this->success(null, 'Tag dihapus');
    }

    /**
     * Assign satu tag ke satu workflow.
     */
    public function attach(int $workflowId, int $tagId): ResponseInterface
    {
        if (! $this->hasAccessToWorkflow($workflowId)) {
            return $this->fail('Tidak punya akses.', 403);
        }

        $db = \Config\Database::connect();
        if (! $db->table('tags')->where('id', $tagId)->countAllResults()) {
            return $this->fail('Tag tidak ditemukan.', 404);
        }

        $exists = $db->table('workflow_tags')
            ->where('workflow_id', $workflowId)->where('tag_id', $tagId)
            ->countAllResults();
        if (! $exists) {
            $db->table('workflow_tags')->insert([
                'workflow_id' => $workflowId,
                'tag_id'      => $tagId,
            ]);
        }

        return $this->success(null, 'Tag dipasang');
    }

    public function detach(int $workflowId, int $tagId): ResponseInterface
    {
        if (! $this->hasAccessToWorkflow($workflowId)) {
            return $this->fail('Tidak punya akses.', 403);
        }

        \Config\Database::connect()
            ->table('workflow_tags')
            ->where('workflow_id', $workflowId)->where('tag_id', $tagId)
            ->delete();

        return $this->success(null, 'Tag dilepas');
    }
}
