<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Error alert / notifikasi gagal workflow.
 *
 * Workflow yang gagal (status error) memicu notifikasi bila konfigurasi
 * alert aktif (workflow_alerts). Notifikasi dicatat ke alert_logs dan
 * (opsional) dikirim email ke penerima. Throttle mencegah spam email
 * saat workflow error beruntun.
 */
class AlertService
{
    protected $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Ambil konfigurasi alert sebuah workflow (null bila belum di-set).
     */
    public function getConfig(int $workflowId): ?array
    {
        $row = $this->db->table('workflow_alerts')->where('workflow_id', $workflowId)->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Simpan / perbarui konfigurasi alert.
     */
    public function saveConfig(int $workflowId, array $data): void
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->db->table('workflow_alerts')->where('workflow_id', $workflowId)->get()->getRowArray();

        $fields = [
            'email_to'         => $data['email_to'] ?? null,
            'enabled'          => ! empty($data['enabled']) ? 1 : 0,
            'throttle_minutes' => max(0, (int) ($data['throttle_minutes'] ?? 60)),
            'updated_at'       => $now,
        ];

        if ($row) {
            $this->db->table('workflow_alerts')->where('id', $row['id'])->update($fields);

            return;
        }

        $this->db->table('workflow_alerts')->insert(array_merge($fields, [
            'workflow_id' => $workflowId,
            'created_at'  => $now,
        ]));
    }

    /**
     * Laporkan kegagalan eksekusi. Catat log + kirim email (bila aktif &
     * lewat throttle). Amankan: kegagalan kirim email tidak melempar.
     */
    public function notifyFailure(int $workflowId, int $executionId, string $message): void
    {
        $config = $this->getConfig($workflowId);
        if (! $config || ! (int) $config['enabled']) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $throttle = (int) $config['throttle_minutes'];
        $lastSent = $config['last_sent_at'];

        if ($throttle > 0 && $lastSent && (strtotime($now) - strtotime($lastSent)) < $throttle * 60) {
            // Catat log tanpa kirim email (throttle).
            $this->log($workflowId, $executionId, $message, null, false);

            return;
        }

        $recipients = $this->parseRecipients((string) $config['email_to']);

        $emailSent = false;
        if ($recipients !== []) {
            $emailSent = $this->sendEmail($recipients, $workflowId, $executionId, $message);
        }

        $this->log($workflowId, $executionId, $message, $config['email_to'], $emailSent);

        $this->db->table('workflow_alerts')->where('id', $config['id'])->update([
            'last_sent_at' => $now,
        ]);
    }

    /**
     * Riwayat alert terbaru untuk dashboard.
     */
    public function recent(int $limit = 20): array
    {
        return $this->db->table('alert_logs l')
            ->select('l.*, w.name AS workflow_name')
            ->join('workflows w', 'w.id = l.workflow_id', 'left')
            ->orderBy('l.id', 'DESC')
            ->limit((int) $limit)
            ->get()
            ->getResultArray();
    }

    public function recentForWorkspace(int $workspaceId, int $limit = 20): array
    {
        return $this->db->table('alert_logs l')
            ->select('l.*, w.name AS workflow_name')
            ->join('workflows w', 'w.id = l.workflow_id', 'inner')
            ->where('w.workspace_id', $workspaceId)
            ->orderBy('l.id', 'DESC')
            ->limit((int) $limit)
            ->get()
            ->getResultArray();
    }

    public function countUnreadForWorkspace(int $workspaceId): int
    {
        return (int) $this->db->table('alert_logs l')
            ->join('workflows w', 'w.id = l.workflow_id', 'inner')
            ->where('w.workspace_id', $workspaceId)
            ->countAllResults();
    }

    protected function log(int $workflowId, int $executionId, string $message, ?string $recipient, bool $emailSent): void
    {
        $this->db->table('alert_logs')->insert([
            'workflow_id'  => $workflowId,
            'execution_id' => $executionId,
            'alert_type'   => 'workflow_error',
            'message'      => mb_substr($message, 0, 60000),
            'recipient'    => $recipient,
            'email_sent'   => $emailSent ? 1 : 0,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    protected function parseRecipients(string $emailTo): array
    {
        $emails = array_values(array_filter(array_map('trim', explode(',', $emailTo)), static function ($e) {
            return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
        }));

        return array_slice(array_unique($emails), 0, 10);
    }

    /**
     * Kirim email via Email library CodeIgniter. Gunakan konfigurasi default
     * (mail) bila SMTP belum diisi; kegagalan tidak dilempar.
     */
    protected function sendEmail(array $recipients, int $workflowId, int $executionId, string $message): bool
    {
        try {
            $email = \Config\Services::email();

            $workflow = $this->db->table('workflows')->where('id', $workflowId)->get()->getRowArray();
            $name = $workflow['name'] ?? "Workflow #{$workflowId}";

            $email->setTo($recipients[0]);
            foreach (array_slice($recipients, 1) as $rcpt) {
                $email->setCC($rcpt);
            }

            $email->setSubject('[FlowForge] Workflow gagal: ' . $name);
            $email->setMessage(
                "Workflow berikut gagal dijalankan:\n\n" .
                "- Nama: {$name}\n" .
                "- Eksekusi: #{$executionId}\n" .
                "- Waktu: " . date('Y-m-d H:i:s') . "\n\n" .
                "Pesan error:\n{$message}\n\n" .
                'Log eksekusi tersedia di dashboard.'
            );

            return $email->send();
        } catch (\Throwable $e) {
            log_message('error', '[AlertService] Gagal kirim email: ' . $e->getMessage());

            return false;
        }
    }
}
