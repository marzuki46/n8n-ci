<?php

namespace App\Nodes;

/**
 * Memory node: simpan / muat / hapus riwayat percakapan per memory_key.
 * Tabel yang sama dipakai AI Agent (ai_memories), jadi agent dan workflow
 * bisa berbagi konteks percakapan.
 */
class MemoryNode extends AbstractNode
{
    public function getType(): string
    {
        return 'memory';
    }

    public function getName(): string
    {
        return 'Memory';
    }

    public function getCategory(): string
    {
        return 'AI';
    }

    public function getColor(): string
    {
        return '#a06bff';
    }

    public function getIcon(): string
    {
        return 'brain';
    }

    public function getDescription(): string
    {
        return 'Simpan, muat, atau hapus riwayat percakapan per memory_key. Bisa berbagi memori dengan node AI Agent.';
    }

    public function getParameters(): array
    {
        return [
            ['key' => 'operation', 'label' => 'Operasi', 'type' => 'select',
             'options' => [
                 ['value' => 'load', 'label' => 'Load: muat riwayat'],
                 ['value' => 'save', 'label' => 'Save: simpan pesan'],
                 ['value' => 'clear', 'label' => 'Clear: hapus semua'],
             ],
             'default' => 'load'],
            ['key' => 'memory_key', 'label' => 'Memory Key', 'type' => 'text', 'required' => true,
             'placeholder' => 'chat-user-123'],
            ['key' => 'content_field', 'label' => 'Field Isi Pesan (untuk save)', 'type' => 'text', 'default' => 'text'],
            ['key' => 'role', 'label' => 'Role Pesan (untuk save)', 'type' => 'select',
             'options' => [['value' => 'user'], ['value' => 'assistant'], ['value' => 'tool']],
             'default' => 'user'],
            ['key' => 'limit', 'label' => 'Maks Pesan Dimuat', 'type' => 'number', 'default' => 20],
            ['key' => 'as_field', 'label' => 'Field Output Hasil Load', 'type' => 'text', 'default' => 'history'],
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
            'title'  => 'Contoh: muat riwayat chat sebelum diproses AI',
            'input'  => ['memory_key' => 'chat-user-123'],
            'params' => [
                'operation'   => 'load',
                'memory_key'  => '{{$json.memory_key}}',
                'limit'       => 20,
                'as_field'    => 'history',
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [['json' => [
            'history' => [
                ['role' => 'user', 'content' => 'Halo, order saya belum masuk'],
                ['role' => 'assistant', 'content' => 'Baik, saya bantu cek ya.'],
            ],
            'count' => 2,
        ]]]];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $db = \Config\Database::connect();
        $op = (string) ($params['operation'] ?? 'load');

        $items = [];
        foreach ($inputItems as $item) {
            $json = is_array($item) && array_key_exists('json', $item)
                ? $item['json']
                : (is_array($item) ? $item : []);

            $key = trim($context->resolve((string) ($params['memory_key'] ?? ''), is_array($item) ? $item : []));
            if ($key === '') {
                throw new \Exception('Memory key wajib diisi.');
            }
            $key = mb_substr($key, 0, 191);

            if ($op === 'save') {
                $field = (string) ($params['content_field'] ?? 'text');
                $content = trim(is_array($json) ? (string) ($json[$field] ?? '') : '');
                if ($content === '') {
                    throw new \Exception('Isi pesan kosong — cek field "' . $field . '".');
                }
                $role = in_array(($params['role'] ?? 'user'), ['user', 'assistant', 'tool'], true)
                    ? $params['role'] : 'user';

                $db->table('ai_memories')->insert([
                    'memory_key' => $key,
                    'role'       => $role,
                    'content'    => mb_substr($content, 0, 60000),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $json['saved'] = true;
            } elseif ($op === 'clear') {
                $deleted = $db->table('ai_memories')->where('memory_key', $key)->delete();
                $json['cleared'] = true;
                $json['cleared_count'] = $deleted;
            } else {
                $limit = max(1, (int) ($params['limit'] ?? 20));
                $rows = $db->table('ai_memories')
                    ->select('role, content')
                    ->where('memory_key', $key)
                    ->orderBy('id', 'DESC')
                    ->limit($limit)
                    ->get()
                    ->getResultArray();
                $asField = (string) ($params['as_field'] ?? 'history');
                $json[$asField] = array_reverse($rows);
                $json['count'] = count($rows);
            }

            $items[] = ['json' => $json];
        }

        return ['main' => $items];
    }
}
