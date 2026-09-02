<?php

namespace App\Nodes;

class EmailNode extends AbstractNode
{
    public function getType(): string
    {
        return 'email';
    }

    public function getName(): string
    {
        return 'Email (SMTP)';
    }

    public function getCategory(): string
    {
        return 'Apps';
    }

    public function getColor(): string
    {
        return '#3aa0e0';
    }

    public function getIcon(): string
    {
        return 'email';
    }

    public function getDescription(): string
    {
        return 'Kirim email via server SMTP.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'             => 'credential',
                'label'           => 'Credential SMTP',
                'type'            => 'credentials',
                'credentialType'  => 'smtp',
                'description'     => 'Pakai credential SMTP tersimpan. Field SMTP Host/Port/User di bawah bisa menimpa.',
            ],
            [
                'key'         => 'smtpHost',
                'label'       => 'SMTP Host (opsional)',
                'type'        => 'text',
                'placeholder' => 'smtp.gmail.com',
            ],
            [
                'key'     => 'smtpPort',
                'label'   => 'Port',
                'type'    => 'number',
                'default' => 587,
            ],
            [
                'key'     => 'secure',
                'label'   => 'Enkripsi',
                'type'    => 'select',
                'options' => [
                    ['value' => 'tls', 'label' => 'STARTTLS (587)'],
                    ['value' => 'ssl', 'label' => 'SSL (465)'],
                    ['value' => 'none', 'label' => 'Tanpa enkripsi'],
                ],
                'default' => 'tls',
            ],
            [
                'key'         => 'username',
                'label'       => 'Username (opsional)',
                'type'        => 'text',
            ],
            [
                'key'         => 'password',
                'label'       => 'Password',
                'type'        => 'password',
            ],
            [
                'key'         => 'fromEmail',
                'label'       => 'Dari (Email)',
                'type'        => 'text',
                'required'    => true,
            ],
            [
                'key'         => 'fromName',
                'label'       => 'Dari (Nama)',
                'type'        => 'text',
            ],
            [
                'key'         => 'toEmail',
                'label'       => 'Ke (Email)',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'a@contoh.com, b@contoh.com',
            ],
            [
                'key'         => 'subject',
                'label'       => 'Subjek',
                'type'        => 'text',
                'required'    => true,
            ],
            [
                'key'      => 'body',
                'label'    => 'Isi Email',
                'type'     => 'textarea',
                'required' => true,
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $cred = $context->parameters['credential'] ?? [];

        $items = [];
        foreach ($inputItems as $item) {
            $cfg = [
                'host'      => (string) ($cred['host'] ?? ''),
                'port'      => (int) ($cred['port'] ?? 587),
                'secure'    => (string) ($cred['secure'] ?? 'tls'),
                'username'  => (string) ($cred['user'] ?? ''),
                'password'  => (string) ($cred['password'] ?? ''),
                'fromEmail' => '',
                'fromName'  => '',
                'to'        => '',
                'subject'   => '',
                'body'      => '',
            ];

            $inline = [
                'host'      => (string) $context->resolve($params['smtpHost'] ?? '', $item),
                'port'      => (int) ($params['smtpPort'] ?? 0),
                'secure'    => (string) ($params['secure'] ?? ''),
                'username'  => (string) $context->resolve($params['username'] ?? '', $item),
                'password'  => (string) $context->resolve($params['password'] ?? '', $item),
                'fromEmail' => (string) $context->resolve($params['fromEmail'] ?? '', $item),
                'fromName'  => (string) $context->resolve($params['fromName'] ?? '', $item),
                'to'        => (string) $context->resolve($params['toEmail'] ?? '', $item),
                'subject'   => (string) $context->resolve($params['subject'] ?? '', $item),
                'body'      => (string) $context->resolve($params['body'] ?? '', $item),
            ];

            foreach ($inline as $key => $value) {
                if ($key === 'port') {
                    if ($value > 0) {
                        $cfg['port'] = $value;
                    }
                } elseif ($value !== '') {
                    $cfg[$key] = $value;
                }
            }

            if ($cfg['host'] === '' || $cfg['fromEmail'] === '' || $cfg['to'] === '') {
                throw new \Exception('Email: SMTP host (atau credential), dari, dan ke wajib diisi.');
            }

            $recipients = array_values(array_filter(array_map('trim', explode(',', $cfg['to'])), static function ($r) {
                return $r !== '';
            }));

            $this->send($cfg, $recipients);

            $items[] = [
                'json'   => [
                    'from'     => $cfg['fromEmail'],
                    'to'       => $recipients,
                    'subject'  => $cfg['subject'],
                    'sent_at'  => date('Y-m-d H:i:s'),
                ],
                'status' => 250,
                'error'  => null,
            ];
        }

        return ['main' => $items];
    }

    protected function send(array $cfg, array $recipients): void
    {
        $conn = stream_socket_client(
            ($cfg['secure'] === 'ssl' ? 'ssl://' : '') . $cfg['host'] . ':' . $cfg['port'],
            $errno,
            $errstr,
            30
        );

        if (! $conn) {
            throw new \Exception('SMTP: tidak bisa konek ke ' . $cfg['host'] . ':' . $cfg['port'] . ' (' . $errstr . ')');
        }

        stream_set_timeout($conn, 30);

        $this->readReply($conn);

        $this->command($conn, 'EHLO flowforge.local');

        if ($cfg['secure'] === 'tls') {
            $this->command($conn, 'STARTTLS');
            $crypto = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (! $crypto) {
                fclose($conn);
                throw new \Exception('SMTP: gagal mengaktifkan TLS.');
            }
            $this->command($conn, 'EHLO flowforge.local');
        }

        if ($cfg['username'] !== '') {
            $this->command($conn, 'AUTH LOGIN', false);
            $this->command($conn, base64_encode($cfg['username']), false);
            $this->command($conn, base64_encode($cfg['password']), false);
        }

        $this->command($conn, 'MAIL FROM: <' . $cfg['fromEmail'] . '>');
        foreach ($recipients as $rcpt) {
            $this->command($conn, 'RCPT TO: <' . $rcpt . '>');
        }

        $this->command($conn, 'DATA');

        $headers  = 'From: ' . $this->encodeHeader($cfg['fromName'] ? $cfg['fromName'] . ' <' . $cfg['fromEmail'] . '>' : $cfg['fromEmail']) . "\r\n";
        $headers .= 'To: ' . implode(', ', $recipients) . "\r\n";
        $headers .= 'Subject: ' . $this->encodeHeader($cfg['subject']) . "\r\n";
        $headers .= 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";
        $headers .= 'Date: ' . date('r') . "\r\n";

        $this->write($conn, $headers . "\r\n" . $cfg['body']);
        $this->write($conn, '.');
        $this->readReply($conn);

        $this->command($conn, 'QUIT');
        fclose($conn);
    }

    protected function write($conn, string $data): void
    {
        fwrite($conn, $data . "\r\n");
    }

    protected function command($conn, string $cmd, bool $expect = true): void
    {
        $this->write($conn, $cmd);
        if ($expect) {
            $this->readReply($conn);
        }
    }

    /**
     * Baca balasan sampai baris non-kontinuasi (bukan "NNN-").
     */
    protected function readReply($conn): string
    {
        $line = '';
        do {
            $chunk = fgets($conn, 515);
            if ($chunk === false) {
                throw new \Exception('SMTP: koneksi terputus saat membaca balasan.');
            }
            $line = rtrim($chunk, "\r\n");
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($line, 0, 3);
        if ($code >= 400) {
            throw new \Exception('SMTP error: ' . $line);
        }

        return $line;
    }

    protected function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: email selamat datang otomatis',
    'input' => 
    array (
      'email' => 'pengguna@example.com',
      'nama' => 'Riski',
    ),
    'params' => 
    array (
      'smtpHost' => 'smtp.gmail.com',
      'smtpPort' => 587,
      'secure' => 'tls',
      'fromEmail' => 'noreply@domainku.id',
      'fromName' => 'Tim Kami',
      'toEmail' => '{{$json.email}}',
      'subject' => 'Selamat datang, {{$json.nama}}!',
      'body' => '<h3>Halo {{$json.nama}},</h3><p>Terima kasih telah mendaftar.</p>',
    ),
  ),
);
    }

    public function getExampleOutput(): array
    {
        return array (
  'main' => 
  array (
    0 => 
    array (
      'json' => 
      array (
        'sent' => true,
        'message' => 'Email terkirim.',
      ),
    ),
  ),
);
    }
}
