<?php

namespace App\Nodes;

/**
 * Midtrans Webhook Verify — validasi signature_key webhook Midtrans.
 * Input item harus berisi: order_id, status_code, gross_amount, signature_key
 * (persis seperti payload webhook Midtrans).
 */
class MidtransVerifyNode extends AbstractNode
{
    public function getType(): string
    {
        return 'midtrans_verify';
    }

    public function getName(): string
    {
        return 'Midtrans Verify';
    }

    public function getCategory(): string
    {
        return 'Payments';
    }

    public function getColor(): string
    {
        return '#0d47a1';
    }

    public function getIcon(): string
    {
        return 'shield-check';
    }

    public function getDescription(): string
    {
        return 'Validasi signature webhook Midtrans (sha512 order_id+status_code+gross_amount+serverKey). Pasang setelah Webhook Trigger.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'credential', 'label' => 'Credential Midtrans', 'type' => 'credentials', 'credentialType' => 'midtrans'],
            ['key' => 'on_invalid', 'label' => 'Jika Signature Salah', 'type' => 'select',
             'options' => [
                 ['value' => 'fail', 'label' => 'Gagal (hentikan alur)'],
                 ['value' => 'pass', 'label' => 'Lanjutkan dengan valid=false'],
             ],
             'default' => 'fail'],
        ];
    }

    public function getOutputs(): array
    {
        return ['main'];
    }

    public function isTrigger(): bool
    {
        return false;
    }

    public function getExamples(): array
    {
        return [[
            'title'  => 'Contoh: verifikasi notifikasi pembayaran',
            'input'  => [
                'order_id'     => 'ORDER-101',
                'status_code'  => '200',
                'gross_amount' => '150000.00',
                'signature_key'=> 'abc123...',
                'transaction_status' => 'settlement',
            ],
            'params' => ['on_invalid' => 'fail'],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'valid'       => true,
            'order_id'    => 'ORDER-101',
            'paid'        => true,
            'status'      => 'settlement',
            'gross_amount' => '150000.00',
        ]]]];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $credential = $context->parameters['credential'] ?? null;
        if (! is_array($credential) || empty($credential['server_key'])) {
            throw new \Exception('Pilih credential Midtrans (server key wajib).');
        }
        $serverKey = (string) $credential['server_key'];

        $items = [];
        foreach ($inputItems as $item) {
            $json = is_array($item) && array_key_exists('json', $item)
                ? $item['json']
                : (is_array($item) ? $item : []);

            $orderId     = (string) ($json['order_id'] ?? '');
            $statusCode  = (string) ($json['status_code'] ?? '');
            $grossAmount = (string) ($json['gross_amount'] ?? '');
            $givenSig    = (string) ($json['signature_key'] ?? '');

            if ($orderId === '' && $statusCode === '') {
                throw new \Exception('Payload webhook Midtrans tidak terbaca — pastikan node ini menerima body mentah dari webhook.');
            }

            $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
            $valid = hash_equals($expected, $givenSig);

            if (! $valid && (($params['on_invalid'] ?? 'fail') === 'fail')) {
                throw new \Exception("Signature Midtrans TIDAK VALID untuk order {$orderId}. Kemungkinan percobaan pemalsuan.");
            }

            $transactionStatus = (string) ($json['transaction_status'] ?? '');
            $fraudStatus = (string) ($json['fraud_status'] ?? '');
            $paid = in_array($transactionStatus, ['capture', 'settlement'], true)
                && ($fraudStatus === '' || $fraudStatus === 'accept');

            // Dedup: callback berulang dari Midtrans tidak diproses dua kali.
            $isNew = (new \App\Services\PaymentEventService())
                ->markIfNew('midtrans', $orderId . ':' . $transactionStatus, $statusCode);

            $out = $json;
            $out['valid']     = $valid;
            $out['paid']      = $paid;
            $out['duplicate'] = ! $isNew;

            $items[] = ['json' => $out];
        }

        return ['main' => $items];
    }
}
