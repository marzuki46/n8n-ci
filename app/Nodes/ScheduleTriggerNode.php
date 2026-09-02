<?php

namespace App\Nodes;

class ScheduleTriggerNode extends AbstractNode
{
    public function getType(): string
    {
        return 'schedule_trigger';
    }

    public function getName(): string
    {
        return 'Schedule Trigger';
    }

    public function getCategory(): string
    {
        return 'Trigger';
    }

    public function getColor(): string
    {
        return '#ff6d5a';
    }

    public function getIcon(): string
    {
        return 'schedule';
    }

    public function getDescription(): string
    {
        return 'Jalankan workflow otomatis sesuai jadwal cron.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'cron',
                'label'       => 'Cron Expression',
                'type'        => 'text',
                'required'    => true,
                'default'     => '*/5 * * * *',
                'placeholder' => '*/5 * * * *',
                'description' => 'Format: menit jam hari-bulan bulan hari-minggu. Contoh: 0 9 * * 1 = setiap Senin 09:00.',
            ],
            [
                'key'     => 'timezone',
                'label'   => 'Timezone',
                'type'    => 'text',
                'default' => 'Asia/Jakarta',
            ],
        ];
    }

    public function isTrigger(): bool
    {
        return true;
    }

    public function getTriggerKinds(): array
    {
        return ['manual', 'schedule'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $scheduleData = $context->parameters['scheduleData'] ?? null;

        if (is_array($scheduleData)) {
            return ['main' => [$scheduleData]];
        }

        return ['main' => [[
            'cron'     => $params['cron'] ?? '',
            'timezone' => $params['timezone'] ?? 'UTC',
            'source'   => 'manual',
        ]]];
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: jalan tiap hari jam 08.00',
    'input' => 
    array (
    ),
    'params' => 
    array (
      'cron' => '0 8 * * *',
      'timezone' => 'Asia/Jakarta',
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
        'triggered_at' => '2026-08-24 08:00:00',
      ),
    ),
  ),
);
    }
}
