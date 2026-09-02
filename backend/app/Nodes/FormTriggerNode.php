<?php

namespace App\Nodes;

/**
 * Trigger berupa form HTML publik. GET = tampilkan form,
 * POST = jalankan workflow dengan data form sebagai item input.
 */
class FormTriggerNode extends AbstractNode
{
    public function getType(): string
    {
        return 'form_trigger';
    }

    public function getName(): string
    {
        return 'Form Trigger';
    }

    public function getCategory(): string
    {
        return 'Trigger';
    }

    public function getColor(): string
    {
        return '#5d9de6';
    }

    public function getIcon(): string
    {
        return 'form';
    }

    public function getDescription(): string
    {
        return 'Tampilkan form publik; kiriman form memicu workflow.';
    }

    public function getParameters(): array
    {
        return [
            [
                'key'         => 'path',
                'label'       => 'Path',
                'type'        => 'text',
                'required'    => true,
                'placeholder' => 'kuesioner-pelanggan',
                'description' => 'URL publik: {baseURL}/webhook/{path}',
            ],
            [
                'key'      => 'form_title',
                'label'    => 'Judul Form',
                'type'     => 'text',
                'default'  => 'Form',
                'required' => true,
            ],
            [
                'key'      => 'submit_label',
                'label'    => 'Label Tombol',
                'type'     => 'text',
                'default'  => 'Kirim',
            ],
            [
                'key'      => 'fields',
                'label'    => 'Field (JSON)',
                'type'     => 'code',
                'required' => true,
                'default'  => '[{"name":"nama","label":"Nama Lengkap","type":"text","required":true},{"name":"email","label":"Email","type":"email","required":true}]',
                'description' => 'Array JSON: name, label, type (text|email|number|textarea|select|checkbox), required',
            ],
            [
                'key'      => 'success_message',
                'label'    => 'Pesan Setelah Kirim',
                'type'     => 'textarea',
                'default'  => 'Terima kasih! Data Anda sudah diterima.',
            ],
        ];
    }

    public function isTrigger(): bool
    {
        return true;
    }

    public function getTriggerKinds(): array
    {
        return ['webhook'];
    }

    public function execute(array $inputItems, array $params, WorkflowContext $context): array
    {
        $webhookData = $context->parameters['webhookData'] ?? [];
        $body = $webhookData['body'] ?? [];
        $data = is_array($body) ? $body : ['value' => $body];

        return ['main' => [$data]];
    }

    /**
     * Render halaman HTML form publik (dipakai WebhookController saat GET).
     */
    public function renderForm(array $params, string $baseUrl): string
    {
        $title = (string) ($params['form_title'] ?? 'Form');
        $submit = (string) ($params['submit_label'] ?? 'Kirim');
        $fieldsRaw = (string) ($params['fields'] ?? '[]');
        $fields = json_decode($fieldsRaw, true);

        if (! is_array($fields)) {
            $fields = [];
        }

        $fieldHtml = '';
        foreach ($fields as $field) {
            $name     = htmlspecialchars((string) ($field['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $label    = htmlspecialchars((string) ($field['label'] ?? $name), ENT_QUOTES, 'UTF-8');
            $type     = (string) ($field['type'] ?? 'text');
            $required = ! empty($field['required']) ? ' required' : '';

            if ($type === 'textarea') {
                $fieldHtml .= '<div class="field"><label>' . $label . '</label><textarea name="' . $name . '"' . $required . '></textarea></div>';
            } elseif ($type === 'select') {
                $options = $field['options'] ?? [];
                $optHtml = '';
                foreach ($options as $opt) {
                    $optHtml .= '<option value="' . htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $opt, ENT_QUOTES, 'UTF-8') . '</option>';
                }
                $fieldHtml .= '<div class="field"><label>' . $label . '</label><select name="' . $name . '"' . $required . '>' . $optHtml . '</select></div>';
            } elseif ($type === 'checkbox') {
                $fieldHtml .= '<div class="field checkbox"><label><input type="checkbox" name="' . $name . '" value="1"' . $required . '> ' . $label . '</label></div>';
            } else {
                if (! in_array($type, ['text', 'email', 'number', 'password', 'date', 'tel'], true)) {
                    $type = 'text';
                }
                $fieldHtml .= '<div class="field"><label>' . $label . '</label><input type="' . $type . '" name="' . $name . '"' . $required . '></div>';
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f4f6fb;color:#1f2937;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:1rem}
.card{background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);max-width:520px;width:100%;padding:2rem}
.card h1{font-size:1.35rem;margin:0 0 1.5rem;color:#111827}
.field{margin-bottom:1.1rem}.field label{display:block;font-size:.85rem;font-weight:600;margin-bottom:.35rem;color:#374151}
.field input,.field select,.field textarea{width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:8px;font-size:.95rem;background:#fff}
.field textarea{min-height:90px;resize:vertical}
.field.checkbox label{display:flex;align-items:center;gap:.5rem;font-weight:500}
.field.checkbox input{width:auto}
button{width:100%;padding:.7rem;background:#2563eb;color:#fff;border:0;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;margin-top:.4rem}
button:hover{background:#1d4ed8}
.msg{display:none;margin-top:1rem;padding:.8rem 1rem;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:8px}
</style>
</head>
<body>
<div class="card">
<h1>{$title}</h1>
<form method="post" action="{$baseUrl}">
{$fieldHtml}
<button type="submit">{$submit}</button>
</form>
<div class="msg" id="msg"></div>
</div>
<script>
document.querySelector('form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  try {
    const res = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const data = await res.json();
    const msg = document.getElementById('msg');
    msg.style.display = 'block';
    msg.textContent = data.success ? 'Terima kasih! Data Anda sudah diterima.' : 'Terjadi kesalahan. Silakan coba lagi.';
  } catch (err) {
    alert('Terjadi kesalahan jaringan.');
  }
});
</script>
</body>
</html>
HTML;
    }

    public function getExamples(): array
    {
        return array (
  0 => 
  array (
    'title' => 'Contoh: formulir kontak publik',
    'input' => 
    array (
    ),
    'params' => 
    array (
      'path' => 'kontak',
      'form_title' => 'Hubungi Kami',
      'submit_label' => 'Kirim',
      'fields' => '[{"name":"nama","label":"Nama","type":"text","required":true},{"name":"pesan","label":"Pesan","type":"textarea"}]',
      'success_message' => 'Terima kasih! Pesan Anda sudah kami terima.',
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
        'nama' => 'Riski',
        'pesan' => 'Halo',
      ),
    ),
  ),
);
    }
}
