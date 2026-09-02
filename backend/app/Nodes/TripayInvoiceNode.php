<?php

namespace App\Nodes;

/**
 * Tripay Invoice — buat transaksi pembayaran via API Tripay.
 * Output: checkout_url, reference, merchant_ref, expired.
 */
class TripayInvoiceNode extends AbstractNode
{
    public function getType(): string
    {
        return 'tripay_invoice';
    }

    public function getName(): string
    {
        return 'Tripay Invoice';
    }

    public function getCategory(): string
    {
        return 'Payments';
    }

    public function getColor(): string
    {
        return '#0d9488';
    }

    public function getIcon(): string
    {
        return 'receipt';
    }

    public function getDescription(): string
    {
        return 'Buat tagihan/invoice pembayaran via Tripay (QRIS, VA, e-wallet, dll). Output berisi checkout_url untuk dikirim ke pelanggan.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'credential', 'label' => 'Credential Tripay', 'type' => 'credentials', 'credentialType' => 'tripay'],
            ['key' => 'method', 'label' => 'Metode Bayar', 'type' => 'text', 'required' => true,
             'default' => 'BRIVA', 'placeholder' => 'BRIVA / QRIS / ALFAMART / dll'],
            ['key' => 'amount', 'label' => 'Nominal (Rp)', 'type' => 'text', 'required' => true,
             'placeholder' => '{{$json.amount}}'],
            ['key' => 'merchant_ref', 'label' => 'Nomor Invoice (opsional)', 'type' => 'text',
             'placeholder' => 'INV-{{$json.orderId}} — kosongkan untuk auto'],
            ['key' => 'customer_name', 'label' => 'Nama Pelanggan', 'type' => 'text', 'required' => true,
             'placeholder' => '{{$json.nama}}'],
            ['key' => 'customer_email', 'label' => 'Email Pelanggan', 'type' => 'text',
             'placeholder' => '{{$json.email}}'],
            ['key' => 'item_name', 'label' => 'Nama Item', 'type' => 'text', 'required' => true,
             'default' => 'Pembayaran pesanan'],
            ['key' => 'expired_hours', 'label' => 'Kedaluwarsa (jam)', 'type' => 'number', 'default' => 24],
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
            'title'  => 'Contoh: buat invoice BRIVA untuk order',
            'input'  => ['orderId' => 101, 'amount' => 150000, 'nama' => 'Budi', 'email' => 'budi@mail.com'],
            'params' => [
                'method'         => 'BRIVA',
                'amount'         => '{{$json.amount}}',
                'merchant_ref'   => 'INV-{{$json.orderId}}',
                'customer_name'  => '{{$json.nama}}',
                'customer_email' => '{{$json.email}}',
                'item_name'      => 'Pembayaran order #{{$json.orderId}}',
                'expired_hours'  => 24,
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'success'      => true,
            'merchant_ref' => 'INV-101',
            'reference'    => 'TRX-ABC123',
            'checkout_url' => 'https://tripay.co.id/payment/TRX-ABC123',
            'pay_code'     => '8808990012345678',
            'expired'      => '2026-08-25 15:00:00',
        ]]]];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $credential = $context->parameters['credential'] ?? null;
        if (! is_array($credential)
            || empty($credential['api_key'])
            || empty($credential['private_key'])
            || empty($credential['merchant_code'])) {
            throw new \Exception('Credential Tripay butuh API Key, Private Key, dan Kode Merchant.');
        }
        $mode = ($credential['mode'] ?? 'sandbox') === 'production'
            ? 'https://tripay.co.id/api'
            : 'https://tripay.co.id/api-sandbox';

        $items = [];
        foreach ($inputItems as $item) {
            $json = is_array($item) && array_key_exists('json', $item) ? $item['json'] : $item;

            $amount = (int) round((float) $context->resolve((string) ($params['amount'] ?? '0'), $item));
            if ($amount <= 0) {
                throw new \Exception('Nominal invoice harus lebih dari 0.');
            }

            $refInput = trim((string) $context->resolve((string) ($params['merchant_ref'] ?? ''), $item));
            $merchantRef = $refInput !== '' ? $refInput : ('INV-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))));

            $itemName = trim((string) $context->resolve((string) ($params['item_name'] ?? 'Pembayaran'), $item));
            if ($itemName === '') {
                $itemName = 'Pembayaran';
            }

            $payload = [
                'method'         => (string) $context->resolve((string) ($params['method'] ?? 'BRIVA'), $item),
                'merchant_ref'   => $merchantRef,
                'amount'         => $amount,
                'customer_name'  => (string) $context->resolve((string) ($params['customer_name'] ?? 'Pelanggan'), $item),
                'customer_email' => (string) $context->resolve((string) ($params['customer_email'] ?? ''), $item),
                'order_items'    => [[
                    'sku'         => $merchantRef,
                    'name'        => mb_substr($itemName, 0, 120),
                    'price'       => $amount,
                    'quantity'    => 1,
                ]],
                'expired_time'   => time() + max(1, (int) ($params['expired_hours'] ?? 24)) * 3600,
                'signature'      => hash_hmac('sha256', $credential['merchant_code'] . $merchantRef . $amount, (string) $credential['private_key']),
            ];

            $resp = json_decode($this->httpPostJson(
                $mode . '/transaction/create',
                $payload,
                (string) $credential['api_key']
            ), true);

            if (! is_array($resp) || ($resp['success'] ?? false) !== true) {
                throw new \Exception('Tripay error: ' . substr(json_encode($resp['message'] ?? $resp, JSON_UNESCAPED_UNICODE), 0, 300));
            }

            $data = $resp['data'] ?? [];

            $base = is_array($json) ? $json : [];
            $items[] = ['json' => array_merge($base, [
                'success'      => true,
                'merchant_ref' => $merchantRef,
                'reference'    => $data['reference'] ?? null,
                'checkout_url' => $data['checkout_url'] ?? null,
                'pay_code'     => $data['pay_code'] ?? null,
                'expired'      => $data['expired_time'] ?? null,
            ])];
        }

        return ['main' => $items];
    }

    protected function httpPostJson(string $url, array $payload, string $apiKey): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $apiKey,
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($err || $code >= 500) {
            throw new \RuntimeException('Tripay API error (' . $code . '): ' . $err);
        }

        return (string) $resp;
    }
}
