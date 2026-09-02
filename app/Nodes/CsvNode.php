<?php

namespace App\Nodes;

/**
 * CSV — konversi antara teks CSV dan item JSON.
 * operation: parse (CSV -> items) | stringify (items -> CSV).
 */
class CsvNode extends AbstractNode
{
    public function getType(): string
    {
        return 'csv';
    }

    public function getName(): string
    {
        return 'CSV';
    }

    public function getCategory(): string
    {
        return 'Data';
    }

    public function getColor(): string
    {
        return '#f0a24b';
    }

    public function getIcon(): string
    {
        return 'file-text';
    }

    public function getDescription(): string
    {
        return 'Ubah teks CSV menjadi item JSON (parse) atau kumpulan item menjadi teks CSV (stringify).';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'     => 'operation',
                'label'   => 'Operasi',
                'type'    => 'select',
                'options' => [
                    ['value' => 'parse', 'label' => 'Parse: CSV → Item'],
                    ['value' => 'stringify', 'label' => 'Stringify: Item → CSV'],
                ],
                'default' => 'parse',
            ],
            [
                'key'      => 'csvField',
                'label'    => 'Field Berisi CSV (untuk parse)',
                'type'     => 'text',
                'default'  => 'csv',
                'placeholder' => 'csv',
            ],
            [
                'key'      => 'delimiter',
                'label'    => 'Pemisah',
                'type'     => 'select',
                'options'  => [',', ';', '\t', '|'],
                'default'  => ',',
            ],
            [
                'key'     => 'hasHeader',
                'label'   => 'Baris Pertama = Header',
                'type'    => 'boolean',
                'default' => true,
            ],
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
            'title'  => 'Contoh: ubah CSV dari HTTP Request jadi item',
            'input'  => [],
            'params' => [
                'operation' => 'parse',
                'csvField'  => 'csv',
                'delimiter' => ',',
                'hasHeader' => true,
            ],
        ]];
    }

    public function getExampleOutput(): array
    {
        return ['main' => [
            ['json' => ['produk' => 'Laptop Pro', 'harga' => '15000000']],
            ['json' => ['produk' => 'Mouse Gaming', 'harga' => '250000']],
        ]];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $op = (string) ($params['operation'] ?? 'parse');
        $delimiter = (string) ($params['delimiter'] ?? ',');
        if ($delimiter === '\\t') {
            $delimiter = "\t";
        }
        $hasHeader = ! empty($params['hasHeader']);

        if ($op === 'parse') {
            $field = (string) ($params['csvField'] ?? 'csv');
            $texts = [];
            foreach ($inputItems as $item) {
                $data = is_array($item) && array_key_exists('json', $item) ? $item['json'] : $item;
                $text = is_array($data) ? ($data[$field] ?? '') : '';
                if ((string) $text !== '') {
                    $texts[] = (string) $text;
                }
            }

            $rows = [];
            foreach ($texts as $t) {
                foreach (preg_split("/\r\n|\r|\n/", trim($t)) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $rows[] = str_getcsv((string) $line, $delimiter, '"', '\\');
                    }
                }
            }

            $header = [];
            if ($hasHeader && $rows !== []) {
                $header = array_map(static fn ($h) => trim((string) $h), array_shift($rows));
            }

            $items = [];
            foreach ($rows as $row) {
                $obj = [];
                foreach ($row as $j => $val) {
                    $key = ($hasHeader && isset($header[$j]) && $header[$j] !== '') ? $header[$j] : 'col_' . $j;
                    $obj[$key] = $val;
                }
                $items[] = ['json' => $obj];
            }

            return ['main' => $items];
        }

        // stringify: gabungkan semua item jadi satu CSV.
        $dataArray = [];
        foreach ($inputItems as $item) {
            $data = is_array($item) && array_key_exists('json', $item) ? $item['json'] : $item;
            if (is_array($data)) {
                $dataArray[] = $data;
            }
        }

        $columns = [];
        foreach ($dataArray as $d) {
            foreach (array_keys($d) as $k) {
                if (! in_array((string) $k, $columns, true)) {
                    $columns[] = (string) $k;
                }
            }
        }

        $lines = [];
        if ($hasHeader && $columns !== []) {
            $lines[] = implode($delimiter, array_map(static fn ($c) => '"' . str_replace('"', '""', $c) . '"', $columns));
        }
        foreach ($dataArray as $d) {
            $lines[] = implode($delimiter, array_map(static fn ($c) => '"' . str_replace('"', '""', (string) ($d[$c] ?? '')) . '"', $columns));
        }

        return ['main' => [['json' => ['csv' => implode("\n", $lines), 'count' => count($dataArray)]]]];
    }
}
