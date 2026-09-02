<?php

namespace App\Nodes;

class HttpRequestNode extends AbstractNode
{
    public function getType(): string
    {
        return 'http_request';
    }

    public function getName(): string
    {
        return 'HTTP Request';
    }

    public function getCategory(): string
    {
        return 'HTTP';
    }

    public function getColor(): string
    {
        return '#2b6de3';
    }

    public function getIcon(): string
    {
        return 'globe';
    }

    public function getDescription(): string
    {
        return 'Lakukan panggilan HTTP GET, POST, PUT, PATCH, atau DELETE.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'      => 'method',
                'label'    => 'Method',
                'type'     => 'select',
                'options'  => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                'default'  => 'GET',
                'required' => true,
            ],
            [
                'key'         => 'url',
                'label'       => 'URL',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'https://example.com/api',
            ],
            [
                'key'         => 'headers',
                'label'       => 'Headers (JSON)',
                'type'        => 'json',
                'default'     => '{"Content-Type": "application/json"}',
            ],
            [
                'key'         => 'body',
                'label'       => 'Body (JSON)',
                'type'        => 'json',
                'default'     => '{}',
            ],
            [
                'key'      => 'timeout',
                'label'    => 'Timeout (detik)',
                'type'     => 'number',
                'default'  => 15,
            ],
            [
                'key'      => 'onError',
                'label'    => 'Saat Terjadi Error',
                'type'     => 'select',
                'options'  => ['fail', 'continue'],
                'default'  => 'fail',
                'description' => 'fail: hentikan workflow; continue: lanjutkan dengan status error di output.',
            ],
            [
                'key'      => 'outputMode',
                'label'    => 'Output',
                'type'     => 'select',
                'options'  => ['replace', 'merge'],
                'default'  => 'replace',
                'description' => 'replace: ganti data item dengan respons; merge: gabung data item asli + respons.',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $items = [];
        foreach ($inputItems as $item) {
            $url        = $params['url'] ?? '';
            $method     = strtoupper($params['method'] ?? 'GET');
            $headers    = $this->asArray($params['headers'] ?? []);
            $body       = $params['body'] ?? null;
            $timeout    = (int) ($params['timeout'] ?? 15);
            $onError    = (string) ($params['onError'] ?? 'fail');
            $outputMode = (string) ($params['outputMode'] ?? 'replace');

            // Resolve ekspresi pada semua parameter per-item
            $url     = (string) $context->resolve($url, $item);
            $headers = $context->resolveDeep($headers, $item);
            $body    = $context->resolveDeep($body, $item);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $httpHeaders = [];
            foreach ($headers as $k => $v) {
                $httpHeaders[] = $k . ': ' . $v;
            }

            if ($body !== null && $body !== '' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                if (is_array($body) || is_object($body)) {
                    $body = json_encode($body);
                    if (! $this->hasHeader($httpHeaders, 'Content-Type')) {
                        $httpHeaders[] = 'Content-Type: application/json';
                    }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            if ($httpHeaders) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
            }

            $responseBody = curl_exec($ch);
            $httpCode     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error        = curl_error($ch);
            curl_close($ch);

            $failed = $error !== '' || $httpCode >= 400;
            if ($failed && $onError === 'fail') {
                $reason = $error !== '' ? $error : 'HTTP ' . $httpCode;
                throw new \Exception('HTTP Request gagal (' . $method . ' ' . $url . '): ' . $reason);
            }

            $decoded      = json_decode((string) $responseBody, true);
            $responseData = $decoded !== null ? $decoded : (string) $responseBody;

            $outData = $responseData;
            if ($outputMode === 'merge') {
                $inputData = $this->jsonData($item);
                $outData   = array_merge($inputData, is_array($responseData) ? $responseData : []);
            }

            $items[] = [
                'json'   => $outData,
                'status' => $httpCode,
                'error'  => $error ?: null,
                'url'    => $url,
                'method' => $method,
            ];
        }

        return ['main' => $items];
    }

    protected function asArray($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    protected function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return true;
            }
        }

        return false;
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: ambil data cuaca dari API publik',
    'input' => 
    array (
      'kota' => 'Jakarta',
    ),
    'params' => 
    array (
      'method' => 'GET',
      'url' => 'https://api.open-meteo.com/v1/forecast?latitude=-6.2&longitude=106.8&current_weather=true',
      'timeout' => 30,
    ),
  ),
  1 => 
  array (
    'title' => 'Contoh: kirim data ke API lain (POST)',
    'input' => 
    array (
      'nama' => 'Riski',
      'email' => 'riski@example.com',
    ),
    'params' => 
    array (
      'method' => 'POST',
      'url' => 'https://api.contoh.com/pelanggan',
      'headers' => '{"Content-Type":"application\\/json"}',
      'body' => '{"nama":"{{$json.nama}}","email":"{{$json.email}}"}',
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
        'temperature' => 31.2,
        'windspeed' => 9.4,
      ),
    ),
  ),
);
    }
}
