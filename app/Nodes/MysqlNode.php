<?php

namespace App\Nodes;

class MysqlNode extends AbstractNode
{
    use SqlDatabaseTrait;

    public function getType(): string
    {
        return 'mysql';
    }

    public function getName(): string
    {
        return 'MySQL';
    }

    public function getCategory(): string
    {
        return 'Integrations';
    }

    public function getColor(): string
    {
        return '#00618a';
    }

    public function getIcon(): string
    {
        return 'database';
    }

    public function getDescription(): string
    {
        return 'Baca/tulis data dari database MySQL.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'credential',
                'label'       => 'Credential MySQL',
                'type'        => 'credentials',
                'credentialType' => 'mysql',
                'required'    => true,
            ],
            [
                'key'      => 'operation',
                'label'    => 'Operasi',
                'type'     => 'select',
                'options'  => ['select', 'insert', 'update', 'delete'],
                'default'  => 'select',
            ],
            [
                'key'      => 'query',
                'label'    => 'Query (select)',
                'type'     => 'code',
                'default'  => 'SELECT * FROM tabel',
                'description' => 'Gunakan :nama untuk binding, nilai dari {{$json}}.',
            ],
            [
                'key'      => 'table',
                'label'    => 'Nama Tabel (insert/update/delete)',
                'type'     => 'text',
                'placeholder' => 'pengguna',
            ],
            [
                'key'      => 'data',
                'label'    => 'Data (JSON)',
                'type'     => 'code',
                'default'  => '{{$json}}',
                'description' => 'Objek kolomâ†’nilai untuk insert/update.',
            ],
            [
                'key'      => 'where',
                'label'    => 'Kondisi WHERE (JSON)',
                'type'     => 'code',
                'default'  => '{"id": "{{$json.id}}"}',
            ],
            [
                'key'     => 'retryCount',
                'label'   => 'Retry Saat Gagal',
                'type'    => 'number',
                'default' => 0,
            ],

                ['key'     => 'retryBackoffMs',
                'label'   => 'Delay Dasar Retry (ms)',
                'type'    => 'number',
                'default' => 500,
                'description' => 'Delay awal antar percobaan, naik eksponensial per percobaan.',
            ],
            [
                'key'     => 'retryMaxDelayMs',
                'label'   => 'Delay Maks Retry (ms)',
                'type'    => 'number',
                'default' => 30000,
                'description' => 'Batas atas delay antar percobaan.',
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $credential = $context->parameters['credential'] ?? null;
        if (! is_array($credential)) {
            throw new \Exception('Pilih credential MySQL pada node ini.');
        }

        $pdo = $this->connect($credential, 'mysql');
        $operation = (string) ($params['operation'] ?? 'select');

        $items = [];
        foreach ($inputItems as $item) {
            $contextItem = is_array($item) ? $item : ['json' => $item];

            if (in_array($operation, ['insert', 'update', 'delete'], true)) {
                $data = $context->resolveDeep($params['data'] ?? '{}', $contextItem);
                $data = is_array($data) ? $data : [];
                $where = $context->resolveDeep($params['where'] ?? '{}', $contextItem);
                $where = is_array($where) ? $where : [];

                $result = $this->writeOperation($pdo, $operation, $params['table'] ?? '', $data, $where);
            } else {
                $query = (string) $context->resolve((string) ($params['query'] ?? ''), $contextItem);
                $result = $this->query($pdo, $query, $this->jsonData($contextItem));
            }

            $items[] = ['json' => array_merge($result, ['operation' => $operation])];
        }

        return ['main' => $items];
    }

    protected function writeOperation(\PDO $pdo, string $operation, string $table, array $data, array $where): array
    {
        if ($table === '') {
            throw new \Exception('Nama tabel kosong.');
        }
        $this->assertIdentifier($table);
        if ($operation === 'insert' && $data === []) {
            throw new \Exception('Data insert kosong.');
        }

        if ($operation === 'insert') {
            $columns = array_keys($data);
            foreach ($columns as $col) {
                $this->assertIdentifier((string) $col);
            }
            $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
            return $this->query($pdo, $sql, array_values($data));
        }

        $whereClause = [];
        $bind = [];
        foreach ($where as $col => $val) {
            $this->assertIdentifier((string) $col);
            $whereClause[] = '`' . $col . '` = ?';
            $bind[] = $val;
        }

        if ($operation === 'update') {
            $set = [];
            foreach ($data as $col => $val) {
                $this->assertIdentifier((string) $col);
                $set[] = '`' . $col . '` = ?';
                $bind[] = $val;
            }
            if ($set === []) {
                throw new \Exception('Data update kosong.');
            }
            $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ($whereClause ? ' WHERE ' . implode(' AND ', $whereClause) : '');
            return $this->query($pdo, $sql, $bind);
        }

        if ($whereClause === []) {
            throw new \Exception('DELETE butuh kondisi WHERE (keamanan).');
        }
        $sql = 'DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $whereClause);
        return $this->query($pdo, $sql, $bind);
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: cari pelanggan berdasarkan email',
    'input' => 
    array (
      'email' => 'riski@example.com',
    ),
    'params' => 
    array (
      'operation' => 'query',
      'query' => 'SELECT id, nama FROM pelanggan WHERE email = :email',
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
        'id' => 7,
        'nama' => 'Riski',
      ),
    ),
  ),
);
    }
}
