<?php

namespace App\Nodes;

/**
 * WhatsApp Send — kirim pesan via gateway lokal Fonnte.
 * (Wablas/Whacenter menyusul sebagai provider tambahan.)
 *
 * API Fonnte: POST https://api.fonnte.com/send
 * Header: Authorization: <device token>
 * Body form: target=<nomor|groupid>, message=<isi>
 */
class WhatsAppSendNode extends AbstractNode
{
    public function getType(): string
    {
        return 'whatsapp_send';
    }

    public function getName(): string
    {
        return 'WhatsApp';
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function getColor(): string
    {
        return '#25d366';
    }

    public function getIcon(): string
    {
        return 'message-circle';
    }

    public function getDescription(): string
    {
        return 'Kirim pesan WhatsApp via gateway Fonnte. Target bisa nomor pribadi (628xx) atau ID group.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'credential', 'label' => 'Credential Fonnte', 'type' => 'credentials', 'credentialType' => 'fonnte'],
            ['key' => 'provider', 'label' => 'Provider', 'type' => 'select',
             'options' => [['value' => 'fonnte', 'label' => 'Fonnte']],
             'default' => 'fonnte'],
            ['key' => 'target', 'label' => 'Target', 'type' => 'text', 'required' => true,
             'placeholder' => '62812xxxxxxx atau 1203630xxxxx@g.us'],
            ['key' => 'message', 'label' => 'Pesan', 'type' => 'textarea', 'required' => true,
             'placeholder' => 'Halo {{$json.nama}}, pesanan Anda sedang diproses.'],
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
            'title'  => 'Contoh: notifikasi order ke admin',
            'input'  => ['orderId' => 999, 'total' => 'Rp1.500.000'],
            'params' => [
                'provider' => 'fonnte',
                'target'   => '6281234567890',
                'message'  => '📦 Order baru #{{$json.orderId}}' . "\n" . 'Total: {{$json.total}}',
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'sent'  => true,
            'id'    => 'msg-abc123',
            'target' => '6281234567890',
        ]]]];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $credential = $context->parameters['credential'] ?? null;
        if (! is_array($credential) || empty($credential['token'])) {
            throw new \Exception('Pilih credential Fonnte pada node ini (token device wajib).');
        }

        $items = [];
        foreach ($inputItems as $item) {
            $target  = trim((string) $context->resolve((string) ($params['target'] ?? ''), $item));
            $message = (string) $context->resolve((string) ($params['message'] ?? ''), $item);

            if ($target === '') {
                throw new \Exception('Target WhatsApp kosong.');
            }
            if ($message === '') {
                throw new \Exception('Pesan WhatsApp kosong.');
            }

            $resp = $this->sendFonnte((string) $credential['token'], $target, $message);

            $decoded = json_decode($resp, true);
            $ok = is_array($decoded) && ($decoded['status'] ?? false) === true;

            if (! $ok) {
                $reason = is_array($decoded) ? ($decoded['reason'] ?? substr($resp, 0, 200)) : substr($resp, 0, 200);
                throw new \Exception('Fonnte gagal mengirim: ' . $reason);
            }

            $base = is_array($item) && array_key_exists('json', $item) ? $item['json'] : [];
            $items[] = ['json' => array_merge($base, [
                'wa_sent' => true,
                'wa_id'   => $decoded['id'] ?? null,
                'wa_target' => $target,
            ])];
        }

        return ['main' => $items];
    }

    /**
     * Panggil API Fonnte. Protected agar test bisa stub.
     */
    protected function sendFonnte(string $token, string $target, string $message): string
    {
        $ch = curl_init('https://api.fonnte.com/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $token,
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'target'  => $target,
                'message' => $message,
            ]),
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($err || $code >= 500) {
            throw new \RuntimeException('Fonnte API error (' . $code . '): ' . $err);
        }

        return (string) $resp;
    }
}
