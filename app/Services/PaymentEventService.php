<?php

namespace App\Services;

/**
 * Deduplikasi callback payment: satu kombinasi provider+reference+status
 * hanya diproses sekali (UNIQUE index di DB).
 */
class PaymentEventService
{
    protected $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    /**
     * Coba tandai event sebagai diproses.
     *
     * @return bool true = pertama kali (proses); false = duplikat (skip)
     */
    public function markIfNew(string $provider, string $reference, string $status, ?int $executionId = null): bool
    {
        try {
            $this->db->table('payment_events')->insert([
                'provider'     => mb_substr($provider, 0, 32),
                'reference'    => mb_substr($reference, 0, 191),
                'status'       => mb_substr($status, 0, 32),
                'execution_id' => $executionId,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable $e) {
            // Duplicate entry (kode 1062 MySQL) atau error lain → anggap sudah diproses.
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1062') !== false) {
                return false;
            }

            throw $e;
        }
    }
}
