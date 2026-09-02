<?php

namespace App\Nodes;

/**
 * Render template HTML dari data item lalu publikasikan ke halaman
 * publik di /page/{slug}.
 */
class HtmlNode extends AbstractNode
{
    public function getType(): string
    {
        return 'html';
    }

    public function getName(): string
    {
        return 'HTML';
    }

    public function getCategory(): string
    {
        return 'Core';
    }

    public function getColor(): string
    {
        return '#e34c26';
    }

    public function getIcon(): string
    {
        return 'html';
    }

    public function getDescription(): string
    {
        return 'Ubah data menjadi halaman HTML dan publikasikan di /page/{slug}.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'slug',
                'label'       => 'Slug Halaman',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'artikel-seo-1',
                'description' => 'URL publik: {baseURL}/page/{slug}',
            ],
            [
                'key'      => 'title',
                'label'    => 'Judul Halaman',
                'type'     => 'text',
                'placeholder' => '{{$json.title}}',
            ],
            [
                'key'      => 'template',
                'label'    => 'Template HTML',
                'type'     => 'code',
                'required' => true,
                'default'  => "<!DOCTYPE html>\n<html lang=\"id\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>{{ \$json.title }}</title>\n<style>body{max-width:760px;margin:auto;padding:2rem;font-family:Georgia,serif;line-height:1.7;color:#222} h1{line-height:1.2}</style>\n</head>\n<body>\n<h1>{{ \$json.title }}</h1>\n{{ \$json.content }}\n</body>\n</html>",
            ],
            [
                'key'     => 'publish',
                'label'   => 'Publikasikan Halaman',
                'type'    => 'boolean',
                'default' => true,
            ],
        ];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $slug = (string) ($params['slug'] ?? '');
        if (trim($slug) === '') {
            throw new \Exception('Slug halaman kosong.');
        }
        if (! preg_match('/^[a-z0-9-_]+$/i', $slug)) {
            throw new \Exception('Slug hanya boleh huruf, angka, "-", "_".');
        }

        $items = [];
        foreach ($inputItems as $item) {
            $template = (string) ($params['template'] ?? '');
            $html     = $context->resolve($template, is_array($item) ? $item : ['json' => $item]);
            $title    = (string) $context->resolve((string) ($params['title'] ?? ''), is_array($item) ? $item : ['json' => $item]);

            if (($params['publish'] ?? true) === true || $params['publish'] === 'true') {
                $this->publish($context->workflow['id'] ?? null, $slug, $title, $html);
            }

            $items[] = [
                'json' => [
                    'slug'     => $slug,
                    'title'    => $title,
                    'page_url' => $this->pageUrl($slug),
                    'html'     => $html,
                ],
            ];
        }

        return ['main' => $items];
    }

    protected function publish(?int $workflowId, string $slug, string $title, string $html): void
    {
        $db = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

        $existing = $db->table('workflow_pages')
            ->where('workflow_id', $workflowId)
            ->where('slug', $slug)
            ->get()
            ->getRowArray();

        if ($existing) {
            $db->table('workflow_pages')
                ->where('id', $existing['id'])
                ->update([
                    'title'      => $title,
                    'html'       => $html,
                    'updated_at' => $now,
                ]);
        } else {
            $db->table('workflow_pages')->insert([
                'workflow_id' => $workflowId,
                'node_id'     => $this->nodeId ?? null,
                'slug'        => $slug,
                'title'       => $title,
                'html'        => $html,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    protected function pageUrl(string $slug): string
    {
        $base = config('App')->baseURL ?? '/';
        return rtrim((string) $base, '/') . '/page/' . rawurlencode($slug);
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: buat halaman landing sederhana',
    'input' => 
    array (
      'judul' => 'Promo Agustus',
      'isi' => 'Diskon 50% semua produk.',
    ),
    'params' => 
    array (
      'slug' => 'promo-agustus',
      'title' => '{{$json.judul}}',
      'template' => '<h1>{{$json.judul}}</h1><p>{{$json.isi}}</p>',
      'publish' => true,
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
        'url' => '/page/promo-agustus',
        'published' => true,
      ),
    ),
  ),
);
    }
}
