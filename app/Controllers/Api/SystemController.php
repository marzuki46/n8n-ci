<?php

namespace App\Controllers\Api;

use App\Services\RuntimeService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Status engine/runtime untuk halaman Pengaturan.
 */
class SystemController extends BaseApiController
{
    public function runtimes(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $service = new RuntimeService();

        return $this->success([
            'runtimes'   => $service->statuses(),
            'os'         => PHP_OS_FAMILY,
            'checked_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * GET api/system/ai-budget — konfigurasi + pemakaian bulan ini.
     */
    public function aiBudget(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $svc = new \App\Services\AiUsageService();
        $cfg = $svc->budgetConfig();

        return $this->success([
            'limit'  => $cfg['limit'],
            'action' => $cfg['action'],
            'used'   => $svc->monthUsage((int) session()->get('workspace_id')),
        ]);
    }

    /**
     * PUT api/system/ai-budget — simpan limit & aksi (owner).
     */
    public function saveAiBudget(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }
        if ((string) session()->get('user_role') !== 'owner') {
            return $this->fail('Hanya owner yang boleh mengubah pengaturan ini.', 403);
        }

        $input = $this->input();
        $s = new \App\Services\SettingService();
        $s->set('ai_monthly_token_limit', (string) max(0, (int) ($input['limit'] ?? 0)));
        $action = (($input['action'] ?? 'warn') === 'block') ? 'block' : 'warn';
        $s->set('ai_action_on_exceed', $action);

        return $this->success(['limit' => (int) $s->get('ai_monthly_token_limit', 0), 'action' => $action], 'AI Budget disimpan');
    }

    /**
     * GET api/vectors/summary — daftar namespace knowledge base + jumlah vektor.
     */
    public function vectorSummary(): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $ws = (int) session()->get('workspace_id');
        $rows = \Config\Database::connect()->table('ai_vectors')
            ->select('namespace, COUNT(*) AS vectors, MIN(created_at) AS first_at, MAX(created_at) AS last_at')
            ->groupStart()
                ->where('workspace_id', $ws)
                ->orWhere('workspace_id', null)
            ->groupEnd()
            ->groupBy('namespace')
            ->orderBy('namespace', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$r) {
            $r['vectors'] = (int) $r['vectors'];
        }

        return $this->success($rows);
    }

    /**
     * DELETE api/vectors/namespace/(:segment) — hapus semua vektor namespace
     * milik workspace aktif.
     */
    public function vectorDelete(string $namespace): ResponseInterface
    {
        if (! $this->userId()) {
            return $this->fail('Tidak terautentikasi.', 401);
        }

        $ns = trim($namespace);
        if ($ns === '') {
            return $this->fail('Namespace kosong.');
        }

        $ws = (int) session()->get('workspace_id');
        $deleted = \Config\Database::connect()->table('ai_vectors')
            ->where('namespace', $ns)
            ->groupStart()
                ->where('workspace_id', $ws)
                ->orWhere('workspace_id', null)
            ->groupEnd()
            ->delete();

        if (! $deleted) {
            return $this->fail('Namespace tidak ditemukan.', 404);
        }

        return $this->success(['namespace' => $ns], 'Knowledge base dihapus');
    }
}
