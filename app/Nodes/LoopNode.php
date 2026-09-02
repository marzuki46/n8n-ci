<?php

namespace App\Nodes;

/**
 * Loop Over Items — iterasi array (sumber) menjadi item per elemen.
 *
 * Output 'loop': satu item per elemen (dengan metadata index/total/done).
 * Output 'done': item terakhir dengan done=true untuk alur setelah loop.
 *
 * Karena engine menjalankan node dalam batch (sinkron), semua elemen dilepas
 * sekaligus di output 'loop', lalu node tujuan memprosesnya sebagai satu batch.
 */
class LoopNode extends AbstractNode
{
    public function getType(): string
    {
        return 'loop';
    }

    public function getName(): string
    {
        return 'Loop Over Items';
    }

    public function getCategory(): string
    {
        return 'Flow';
    }

    public function getColor(): string
    {
        return '#7a29e3';
    }

    public function getIcon(): string
    {
        return 'loop';
    }

    public function getDescription(): string
    {
        return 'Iterasi array dan terbitkan tiap elemen di output loop.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'mode',
                'label'       => 'Sumber Iterasi',
                'type'        => 'select',
                'options'     => [
                    ['value' => 'source', 'label' => 'Array dari ekspresi ({{$json.items}})'],
                    ['value' => 'items',  'label' => 'Item yang masuk (incoming items)'],
                ],
                'default'     => 'source',
            ],
            [
                'key'         => 'loopSource',
                'label'       => 'Loop Source (Array)',
                'type'        => 'text',
                'placeholder' => '{{$json.items}}',
                'description' => 'Ekspresi yang menghasilkan array yang diiterasi.',
            ],
            [
                'key'      => 'itemName',
                'label'    => 'Nama Item',
                'type'     => 'text',
                'default'  => 'item',
                'description' => 'Field untuk elemen saat ini, contoh: item, keyword, row.',
            ],
            [
                'key'     => 'maxItems',
                'label'   => 'Maks Item (0 = tanpa batas)',
                'type'    => 'number',
                'default' => 0,
            ],
        ];
    }

    public function getOutputs(): array
    {
        return ['loop', 'done'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $mode     = $params['mode'] ?? 'source';
        $itemName = (string) ($params['itemName'] ?? 'item');
        $maxItems = max(0, (int) ($params['maxItems'] ?? 0));

        if ($mode === 'items') {
            $source = $inputItems;
        } else {
            $seed  = $inputItems[0] ?? [];
            $value = $context->resolve($params['loopSource'] ?? '', is_array($seed) ? $seed : []);

            if (! is_array($value)) {
                $value = $value === null || $value === '' ? [] : [$value];
            }
            $source = $value;
        }

        $source = array_values($source);

        if ($maxItems > 0) {
            $source = array_slice($source, 0, $maxItems);
        }

        $total = count($source);
        $base  = $this->baseJson($inputItems[0] ?? []);

        $loopItems = [];
        foreach ($source as $i => $element) {
            $loopItems[] = [
                'json' => array_merge($base, [
                    $itemName => $element,
                    'index'   => $i,
                    'total'   => $total,
                    'done'    => false,
                ]),
            ];
        }

        $doneItems = [];
        if ($total > 0) {
            $last       = $source[$total - 1];
            $doneItems[] = [
                'json' => array_merge($base, [
                    $itemName => $last,
                    'index'   => $total - 1,
                    'total'   => $total,
                    'done'    => true,
                ]),
            ];
        }

        return ['loop' => $loopItems, 'done' => $doneItems];
    }

    /**
     * Ambil data dasar (json) dari item masukan untuk diwariskan ke output.
     */
    protected function baseJson(array $item): array
    {
        if (array_key_exists('json', $item) && is_array($item['json'])) {
            return $item['json'];
        }

        return $item;
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: proses item satu per satu',
    'input' => 
    array (
      'nama' => 'item',
    ),
    'params' => 
    array (
      'mode' => 'each_item',
    ),
  ),
);
    }

    public function getExampleOutput(): array
    {
        return array (
  'loop' => 
  array (
    0 => 
    array (
      'json' => 
      array (
        'nama' => 'item',
      ),
    ),
  ),
  'done' => 
  array (
  ),
);
    }
}
