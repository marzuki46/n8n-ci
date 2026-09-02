<?php

namespace App\Nodes;

/**
 * Tripay Callback Verify — validasi signature callback Tripay.
 * Signature = HMAC-SHA256(raw body, private_key), dikirim via header
 * X-Callback-Signature. Node membaca raw body dari field item atau
 * otomatis dari data webhook trigger.
 */
class TripayVerifyNode extends AbstractNode
{
    public function getType(): string
    {
        return 'tripay_verify';
    }

    public function getName(): string
    {
        return 'Tripay Verify';
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
        return 'shield-check';
    }

    public function getDescription(): string
    {
        return 'Validasi signature callback Tripay (HMAC-SHA256 raw body + private key). Output ternormalisasi: merchant_ref, status, paid.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'credential', 'label' => 'Credential Tripay', 'type' => 'credentials', 'credentialType' => 'tripay'],
            ['key' => 'raw_body_field', 'label' => 'Field Raw Body', 'type' => 'text', 'default' => 'raw',
             'description' => 'Field berisi body mentah (bukan hasil decode JSON).'],
            ['key' => 'signature_field', 'label' => 'Field Signature', 'type' => 'text', 'default' => 'signature',
             'description' => 'Field berisi X-Callback-Signature (atau ambil dari header webhook).'],
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
            'title'  => 'Contoh: verifikasi callback pembayaran',
            'input'  => [
                'raw'       => '{"event":"payment_status","merchant_ref":"INV-101","status":"PAID"}',
                'signature' => 'a1b2c3...',
            ],
            'params' => [
                'raw_body_field'   => 'raw',
                'signature_field'  => 'signature',
                'on_invalid'       => 'fail',
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'valid'        => true,
            'merchant_ref' => 'INV-101',
            'status'       => 'PAID',
            'paid'         => true,
        ]]]];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $credential = $context->parameters['credential'] ?? null;
        if (! is_array($credential) || empty($credential['private_key'])) {
            throw new \Exception('Pilih credential Tripay (private key wajib).');
        }
        $privateKey = (string) $credential['private_key'];

        // Header webhook (fallback sumber signature) — keys lowercase.
        $webhookHeaders = [];
        if (! empty($context->parameters['webhookData']['headers'])) {
            foreach ((array) $context->parameters['webhookData']['headers'] as $k => $v) {
                $webhookHeaders[strtolower((string) $k)] = is_array($v) ? implode(',', $v) : (string) $v;
            }
        }

        $items = [];
        foreach ($inputItems as $item) {
            $json = is_array($item) && array_key_exists('json', $item)
                ? $item['json']
                : (is_array($item) ? $item : []);

            $rawField = (string) ($params['raw_body_field'] ?? 'raw');
            $sigField = (string) ($params['signature_field'] ?? 'signature');

            // Raw body: dari field item; bila kosong, pakai body webhook trigger.
            $rawBody = trim((string) ($json[$rawField] ?? ''));
            if ($rawBody === '' && isset($context->parameters['webhookData']['body'])) {
                $wb = $context->parameters['webhookData']['body'];
                $rawBody = is_string($wb) ? trim($wb) : json_encode($wb, JSON_UNESCAPED_UNICODE);
            }

            // Signature: dari field item; fallback header webhook.
            $givenSig = trim((string) ($json[$sigField] ?? ''))
                ?: ($webhookHeaders['x-callback-signature'] ?? '');

            if ($rawBody === '' || $givenSig === '') {
                throw new \Exception('Raw body atau signature callback tidak terbaca. Pastikan node menerima callback mentah.');
            }

            $expected = hash_hmac('sha256', $rawBody, $privateKey);
            $valid = hash_equals($expected, $givenSig);

            if (! $valid && (($params['on_invalid'] ?? 'fail') === 'fail')) {
                throw new \Exception('Signature callback Tripay TIDAK VALID — kemungkinan pemalsuan.');
            }

            $payload = json_decode($rawBody, true);
            if (! is_array($payload)) {
                $payload = [];
            }

            $status = strtoupper((string) ($payload['status'] ?? ''));

            // Dedup: callback berulang dari Tripay tidak diproses dua kali.
            $isNew = (new \App\Services\PaymentEventService())
                ->markIfNew('tripay', (string) ($payload['reference'] ?? ($payload['merchant_ref'] ?? '')), $status);

            $out = $json;
            $out['valid']        = $valid;
            $out['merchant_ref'] = (string) ($payload['merchant_ref'] ?? '');
            $out['reference']    = (string) ($payload['reference'] ?? '');
            $out['event']        = (string) ($payload['event'] ?? '');
            $out['status']       = $status;
            $out['paid']         = $status === 'PAID';
            $out['duplicate']    = ! $isNew;

            $items[] = ['json' => $out];
        }

        return ['main' => $items];
    }
}
