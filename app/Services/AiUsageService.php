<?php

namespace App\Services;

/**
 * Logging pemakaian token AI ke tabel ai_usage (best-effort).
 */
class AiUsageService
{
    protected $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * @param array|null $usage {prompt_tokens, completion_tokens, total_tokens}
     */
    public function log(?int $workflowId, ?int $executionId, ?string $model, ?array $usage): void
    {
        try {
            if ($usage === null || ! isset($usage['total_tokens'])) {
                return;
            }

            $this->db->table('ai_usage')->insert([
                'workflow_id'      => $workflowId,
                'execution_id'     => $executionId,
                'model'            => mb_substr((string) ($model ?? ''), 0, 100),
                'input_tokens'     => (int) ($usage['prompt_tokens'] ?? 0),
                'output_tokens'    => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens'     => (int) $usage['total_tokens'],
                'cost'             => 0,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[AiUsage] Gagal log: ' . $e->getMessage());
        }
    }

    /**
     * Pemakaian token AI bulan ini untuk satu workspace.
     */
    public function monthUsage(int $workspaceId): int
    {
        $row = $this->db->table('ai_usage u')
            ->selectSum('u.total_tokens', 't')
            ->join('workflows w', 'w.id = u.workflow_id')
            ->where('w.workspace_id', $workspaceId)
            ->where('u.created_at >=', date('Y-m-01 00:00:00'))
            ->get()
            ->getRow();

        return (int) ($row->t ?? 0);
    }

    /**
     * Baca konfigurasi budget (setting global).
     */
    public function budgetConfig(): array
    {
        $s = new SettingService();

        return [
            'limit'  => max(0, (int) ($s->get('ai_monthly_token_limit', 0) ?? 0)),
            'action' => ($s->get('ai_action_on_exceed', 'warn') ?? 'warn') === 'block' ? 'block' : 'warn',
        ];
    }

    /**
     * Cek budget utk workspace: lewat batas + mode block → throw.
     * Mode warn → kembalikan pesan peringatan (node boleh menampilkannya).
     *
     * @return array {used:int, limit:int, action:string, exceeded:bool, warning:?string}
     */
    public function guard(int $workspaceId): array
    {
        $cfg  = $this->budgetConfig();
        $used = $this->monthUsage($workspaceId);
        $exceeded = $cfg['limit'] > 0 && $used >= $cfg['limit'];
        $warning = null;

        if ($exceeded && $cfg['action'] === 'block') {
            throw new \RuntimeException(
                'Kuota AI bulanan tercapai (' . number_format($used) . '/' . number_format($cfg['limit'])
                . ' token). Naikkan limit di Pengaturan → AI Budget atau tunggu bulan depan.'
            );
        }
        if ($exceeded) {
            $warning = 'Kuota AI bulanan terlampaui (' . number_format($used) . '/' . number_format($cfg['limit']) . ' token)';
            log_message('warning', '[AiBudget] ' . $warning . ' ws=' . $workspaceId);
        }

        return [
            'used'     => $used,
            'limit'    => $cfg['limit'],
            'action'   => $cfg['action'],
            'exceeded' => $exceeded,
            'warning'  => $warning,
        ];
    }
}
