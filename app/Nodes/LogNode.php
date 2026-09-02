<?php

namespace App\Nodes;

/**
 * Catat input ke file log (per workflow) lalu teruskan data tanpa perubahan.
 */
class LogNode extends AbstractNode
{
    public function getType(): string
    {
        return 'log';
    }

    public function getName(): string
    {
        return 'Log';
    }

    public function getCategory(): string
    {
        return 'Core';
    }

    public function getColor(): string
    {
        return '#8b5cf6';
    }

    public function getIcon(): string
    {
        return 'log';
    }

    public function getDescription(): string
    {
        return 'Tulis data ke file log di storage/logs/workflows/.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'      => 'level',
                'label'    => 'Level',
                'type'     => 'select',
                'options'  => ['info', 'debug', 'warning', 'error'],
                'default'  => 'info',
            ],
            [
                'key'      => 'message',
                'label'    => 'Pesan / Field yang Dicatat',
                'type'     => 'textarea',
                'default'  => '{{$json}}',
                'placeholder' => '{{$json.id}} - {{$json.name}}',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $level = (string) ($params['level'] ?? 'info');
        $messageTemplate = (string) ($params['message'] ?? '{{$json}}');

        $lines = [];
        foreach ($inputItems as $item) {
            $message = $context->resolve($messageTemplate, is_array($item) ? $item : ['json' => $item]);
            $lines[] = $this->formatMessage($level, $message);
        }

        if ($lines !== []) {
            $this->writeLog($context->workflow['id'] ?? null, $level, implode("\n", $lines));
        }

        return ['main' => $inputItems];
    }

    protected function formatMessage(string $level, $message): string
    {
        $time = date('Y-m-d H:i:s');
        $payload = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return "[{$time}] [{$level}] {$payload}";
    }

    protected function writeLog(?int $workflowId, string $level, string $content): void
    {
        $dir = WRITEPATH . 'logs/workflows';
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'workflow-' . ($workflowId ?? 'global') . '.log';
        @file_put_contents($file, $content . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: catat proses ke log eksekusi',
    'input' => 
    array (
      'orderId' => 999,
    ),
    'params' => 
    array (
      'level' => 'info',
      'message' => 'Order {{$json.orderId}} sedang diproses',
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
        'logged' => true,
      ),
    ),
  ),
);
    }
}
