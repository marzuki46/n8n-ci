/**
 * Konten dokumentasi bilingual (ID/EN).
 * Struktur: array section { id, title{en,id}, body: [blocks] }
 * block: {type:'p'|'h'|'code'|'list'|'note', text|items|lang}
 */

export const docsSections = [
  {
    id: 'quickstart',
    title: { en: 'Quick Start', id: 'Mulai Cepat' },
    blocks: [
      { type: 'p', en: 'Build your first automation in 3 steps:', id: 'Bangun otomasi pertama Anda dalam 3 langkah:' },
      { type: 'list', items: {
        en: [
          'Open the Workflows menu → Create New Workflow.',
          'Drag a trigger node (Manual / Webhook / Schedule) onto the canvas.',
          'Connect action nodes (Email, Telegram, AI, HTTP Request) then click Execute.',
        ],
        id: [
          'Buka menu Workflows → Buat Workflow baru.',
          'Tarik node trigger (Manual / Webhook / Schedule) ke canvas.',
          'Sambungkan node aksi (Email, Telegram, AI, HTTP Request) lalu klik Execute.',
        ],
      } },
      { type: 'p', en: 'Every node has an "Examples & Usage" panel in the right sidebar — click a node to see ready-made parameter templates and a "Try Node" button.', id: 'Setiap node punya panel "Contoh & Cara Pakai" di sidebar kanan — klik node untuk melihat template parameter siap pakai dan tombol "Coba Node".' },
    ],
  },
  {
    id: 'expression',
    title: { en: 'Expressions', id: 'Ekspresi' },
    blocks: [
      { type: 'p', en: 'Reference data from previous nodes using double curly braces. Expressions are resolved per item:', id: 'Referensi data dari node sebelumnya memakai kurung kurawal ganda. Ekspresi di-resolve per item:' },
      { type: 'code', lang: 'javascript', code:
`{{ $json.email }}                        // field pada item saat ini
{{ $json.items[0].name }}                // nested access
{{ $node["HTTP Request"].json.title }}   // output node tertentu
{{ $var.myVariable }}                    // workflow variable` },
      { type: 'note', en: 'Tip: use "Try Node" to preview resolved values before running the whole workflow.', id: 'Tips: gunakan "Coba Node" untuk melihat hasil ekspresi sebelum menjalankan seluruh workflow.' },
    ],
  },
  {
    id: 'webhook',
    title: { en: 'Webhook & Respond', id: 'Webhook & Respond' },
    blocks: [
      { type: 'p', en: 'The Webhook node exposes a public URL that starts your workflow on HTTP calls:', id: 'Node Webhook membuka URL publik yang menjalankan workflow ketika dipanggil via HTTP:' },
      { type: 'code', lang: 'bash', code:
`curl -X POST https://app-anda.com/webhook/order-baru \\
  -H "Content-Type: application/json" \\
  -H "X-Webhook-Token: TOKEN-ANDA" \\
  -d '{"orderId": 999, "item": "Laptop Pro"}'` },
      { type: 'p', en: 'Add the Respond to Webhook node at the end of a branch to return custom data and status code to the caller:', id: 'Tambahkan node Respond to Webhook di akhir cabang untuk mengembalikan data dan status code kustom ke pemanggil:' },
      { type: 'code', lang: 'json', code:
`{
  "mode": "custom",
  "body": "{\\"ok\\": true, \\"message\\": \\"Order {{ $json.body.orderId }} diterima\\"}",
  "status_code": 201
}` },
      { type: 'note', en: 'Protect webhooks with auth_token (header X-Webhook-Token or ?token=). Rate limit: 60 requests/minute per IP.', id: 'Lindungi webhook dengan auth_token (header X-Webhook-Token atau ?token=). Rate limit: 60 request/menit per IP.' },
    ],
  },
  {
    id: 'apikey',
    title: { en: 'External API Access', id: 'Akses API Eksternal' },
    blocks: [
      { type: 'p', en: 'Create an API key under API Keys, then call the public API (/api/v1) from other systems:', id: 'Buat API key di menu API Keys, lalu panggil API publik (/api/v1) dari sistem lain:' },
      { type: 'code', lang: 'bash', code:
`# List workflows
curl https://app-anda.com/api/v1/workflows \\
  -H "X-API-Key: ak_xxxxxxxxxxxxxxxx"

# Execute a workflow
curl -X POST https://app-anda.com/api/v1/executions \\
  -H "X-API-Key: ak_xxxxxxxxxxxxxxxx" \\
  -H "Content-Type: application/json" \\
  -d '{"workflow_id": 1, "data": {"nama": "Riski"}}'` },
      { type: 'note', en: 'API keys have expiry dates and can be revoked anytime from the API Keys page.', id: 'API key punya tanggal kedaluwarsa dan bisa dicabut kapan saja dari halaman API Keys.' },
    ],
  },
  {
    id: 'ai',
    title: { en: 'AI Nodes (9Router / OpenAI)', id: 'Node AI (9Router / OpenAI)' },
    blocks: [
      { type: 'p', en: 'Create an OpenAI-type credential (works with any OpenAI-compatible endpoint such as 9Router), mark it as Default, then use it in any AI node without selecting credentials per node.', id: 'Buat credential tipe OpenAI (kompatibel endpoint OpenAI mana pun seperti 9Router), tandai sebagai Default, lalu gunakan di node AI mana pun tanpa memilih credential berulang.' },
      { type: 'code', lang: 'json', code:
`{
  "model": "openai/gpt-4o-mini",
  "system": "Kamu penulis konten SEO berbahasa Indonesia.",
  "prompt": "Tulis ringkasan tentang: {{ $json.topik }}",
  "temperature": 0.7,
  "max_tokens": 500,
  "response_format": "text"
}` },
      { type: 'p', en: 'The WordPress plugin also reuses this default credential for content generation.', id: 'Plugin WordPress juga memakai credential default ini untuk generate konten.' },
    ],
  },
  {
    id: 'security',
    title: { en: 'Security Tips', id: 'Tips Keamanan' },
    blocks: [
      { type: 'list', items: {
        en: [
          'Change the default password immediately after first login.',
          'Enable a custom login path in Settings → Security to hide the login endpoint.',
          'Turn on login email notifications to monitor account access.',
          'Use webhook auth_token for all public webhooks.',
          'Deploy behind HTTPS and keep encryption.key stable.',
        ],
        id: [
          'Segera ganti password default setelah login pertama.',
          'Aktifkan custom login path di Pengaturan → Keamanan untuk menyembunyikan endpoint login.',
          'Nyalakan notifikasi email login untuk memantau akses akun.',
          'Gunakan auth_token webhook untuk semua webhook publik.',
          'Deploy dengan HTTPS dan jaga encryption.key tetap stabil.',
        ],
      } },
    ],
  },
  {
    id: 'api',
    title: { en: 'API Reference', id: 'Referensi API' },
    blocks: [
      { type: 'p', en: 'All API endpoints are under `https://your-domain.com/api`. Responses use standard format: `{ success, message, data? }`.', id: 'Semua endpoint API berada di `https://domain-anda.com/api`. Format respons standar: `{ success, message, data? }`.'},
      { type: 'h', en: 'Authentication', id: 'Otentikasi' },
      { type: 'list', items: {
        en: [
          'POST /api/auth/login — login dengan email & password, return session cookie.',
          'POST /api/auth/logout — logout dan hapus session.',
          'GET /api/user/password — tampil form ganti password (hanya owner).',
          'PUT /api/user/password — ganti password (butuh current_password lama).',
          'POST /api/user/email/request-change — minta verifikasi ganti email (HMAC token 1h).',
          'GET /api/user/email/verify?token= — verifikasi link email (commit & ganti email).',
          'GET /security/oauth-google — baca kredensial & registration_mode.',
          'PUT /security/oauth-google — simpan client_id/client_secret & registration_mode (owner).',
        ],
        id: [
          'POST /api/auth/login — login pakai email & password, return session cookie.',
          'POST /api/auth/logout — logout dan hapus session.',
          'GET /api/user/password — tampilkan form ganti password (hanya owner).',
          'PUT /api/user/password — ganti password (butuh current_password lama).',
          'POST /api/user/email/request-change — minta verifikasi ganti email (HMAC token 1h).',
          'GET /api/user/email/verify?token= — verifikasi link email (commit & ganti email).',
          'GET /security/oauth-google — baca kredensial & registration_mode.',
          'PUT /security/oauth-google — simpan client_id/client_secret & registration_mode (owner).',
        ],
      } },
      { type: 'h', en: 'Workflows', id: 'Workflows' },
      { type: 'list', items: {
        en: [
          'GET /api/workflows — daftar workflow (dikirim workspace_id sesuai user).',
          'GET /api/workflows/{id} — detail workflow beserta versi & nodes.',
          'POST /api/workflows — buat workflow baru (status draft).',
          'POST /api/workflows/{id}/execute — jalankan workflow (202 accepted).',
          'POST /api/workflows/{id}/copy — duplikat workflow.',
          'POST /api/workflows/{id}/import — import dari JSON.',
          'GET /api/workflows/{id}/export — export JSON workflow.',
        ],
        id: [
          'GET /api/workflows — daftar workflow (sesuai workspace user).',
          'GET /api/workflows/{id} — detail workflow beserta versi & nodes.',
          'POST /api/workflows — buat workflow baru (status draft).',
          'POST /api/workflows/{id}/execute — jalankan workflow (202 accepted).',
          'POST /api/workflows/{id}/copy — duplikat workflow.',
          'POST /api/workflows/{id}/import — import dari JSON.',
          'GET /api/workflows/{id}/export — export JSON workflow.',
        ],
      } },
      { type: 'h', en: 'API Keys', id: 'API Keys' },
      { type: 'list', items: {
        en: [
          'GET /api/v1/workflows — daftar workflow (protected, butuh X-API-Key).',
          'POST /api/v1/executions — jalankan workflow lewat API key (202).',
          'GET /api/api-keys — daftar API key milik user.',
          'POST /api/api-keys — buat API key baru.',
          'DELETE /api/api-keys/{id} — batalkan API key.',
        ],
        id: [
          'GET /api/v1/workflows — daftar workflow (protected, butuh X-API-Key).',
          'POST /api/v1/executions — jalankan workflow lewat API key (202).',
          'GET /api/api-keys — daftar API key milik user.',
          'POST /api/api-keys — buat API key baru.',
          'DELETE /api/api-keys/{id} — batalkan API key.',
        ],
      } },
      { type: 'h', en: 'Webhooks', id: 'Webhooks' },
      { type: 'list', items: {
        en: [
          'POST /webhook/<path> — terima webhook publik (tanpa auth), path di definisikan saat buat webhook node.',
          'GET /api/v1/webhook-requests — daftar request webhook (hanya owner, tersimpan workspace_id).',
          'POST /api/v1/webhook-requests/{id}/replay — jalankan ulang request webhook.',
        ],
        id: [
          'POST /webhook/<path> — terima webhook publik (tanpa auth), path di definisikan saat buat webhook node.',
          'GET /api/v1/webhook-requests — daftar request webhook (hanya owner, tersimpan workspace_id).',
          'POST /api/v1/webhook-requests/{id}/replay — jalankan ulang request webhook.',
        ],
      } },
      { type: 'h', en: 'MCP (Model Context Protocol)', id: 'MCP' },
      { type: 'list', items: {
        en: [
          'POST /api/v1/mcp — call tool/endpoint dari MCP server.',
          'Contoh: {"name":"get_weather","arguments":{"city":"Jakarta"}}',
        ],
        id: [
          'POST /api/v1/mcp — panggil tool/endpoint dari MCP server.',
          'Contoh: {"name":"get_weather","arguments":{"city":"Jakarta"}}',
        ],
      } },
      { type: 'h', en: 'Payment Callback Dedup', id: 'Dedup Callback Payment' },
      { type: 'p', en: 'Midtrans/Tripay callback menyetai header `X-Dedup-Key: provider:reference:status`. Jika sudah diproses, respons menyertai `duplicate: true` dan body tidak dieksekusi kembali.', id: 'Callback Midtrans/Tripay menyiapkan header `X-Dedup-Key: provider:reference:status`. Jika sudah diproses, respons menyertai `duplicate: true` dan body tidak dieksekusi kembali.' },
      { type: 'code', lang: 'bash', code:
`# Contoh header dari Midtrans
-X POST https://domain-anda.com/api/v1/nodes/test \\
-H "Content-Type: application/json" \\
-X-Dedup-Key: midtrans:order_999:settlement \\
-d '{"transaction_status":"settlement","order_id":"order_999"}'`},
      { type: 'note', en: 'Dedup berdasarkan UNIQUE index `provider+reference+status` di tabel `payment_events`.', id: 'Dedup berdasarkan UNIQUE index `provider+reference+status` di tabel `payment_events`.' },
      { type: 'h', en: 'Expressions (double curly braces)', id: 'Ekspresi' },
      { type: 'code', lang: 'javascript', code:
`{{ $json.name }}           // field langsung
{{ $node["HTTP Request"].json.title }}  // output node HTTP Request
{{ $var.myVar }}          // workflow variable
{{ $execution.id }}       // ID eksekusi saat ini
{{ $workflow.version }}   // versi workflow`},
      { type: 'note', en: 'Setiap item memiliki konteks $json, $node, $workflow, $execution. Gunakan "Try Node" untuk preview.', id: 'Setiap item memiliki konteks $json, $node, $workflow, $execution. Gunakan "Coba Node" untuk preview.' },
    ],
  },
]