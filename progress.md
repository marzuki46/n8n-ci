# Progress — n8n CI4 (CodeIgniter 4 + Vue 3)

> File ini di-update setiap ada progres. Legend: `[x]` = selesai, `[O]` = belum/terbuka.
> Target utama: aplikasi bisa berjalan di hosting (production).

Terakhir diupdate: 2026-08-12

> Catatan sesi 2026-08-12 (Fase lanjutan):
>
> C1 — Background queue:
> 1. Tabel `execution_queue` (status queued/processing/done/error, attempts, available_at,
>    locked_at, error_message).
> 2. `ExecutionQueueService` (enqueue → executions status `waiting` + baris queue;
>    claim → locking; process → jalankan engine dengan `existingExecutionId` agar memakai
>    baris execution yang sama; retry 3x + backoff 5s).
> 3. Command `execution:queue` (jalankan via crontab; `--max=N`).
> 4. `POST /api/workflows/:id/execute` dengan `{"queued": true}` → 202 + segera kembali.
>
> C2 — Sandbox CodeNode:
> 1. Kode user dikompilasi via `vm.Script` host-side lalu dijalankan di konteks sandbox
>    (`vm.createContext` + `codeGeneration: {strings:false, wasm:false}`).
> 2. Daftar putih global; tanpa `require`/`process`/`Buffer`/`module`/`globalThis`/`fetch`
>    → blokir baca FS, jaringan, env, dan escape via `eval`/`new Function`/constructor chain.
> 3. Deteksi binary node (`NODE_BINARY` env atau `node` di PATH) + pesan error jelas bila
>    node tidak ada (fallback tidak menggantung).
>
> C3 — RBAC & sharing:
> 1. `RbacService` dengan matriks peran owner/admin/member (non-anggota = `none`).
> 2. Enforce di controller: workflow write/delete, credentials write, API key manage,
>   schedule write, project update/delete, execute. Member hanya bisa baca + eksekusi.
> 3. `MemberController`: list/add/ubah-role/hapus anggota workspace (owner only) +
>    proteksi owner terakhir. Role ikut di `auth/me`, `select-workspace`, `projects`.
>
> Verifikasi: 96 test / 266 assertion hijau (PHPUnit). Migrasi dev MySQL sukses.

> Catatan sesi lanjutan (2026-08-12, selesai):
>
> 1. **Rate-limit login & endpoint publik**: pakai tabel `rate_limits`
>    (`identifier`, `action`, `window_start`, `attempts`; indeks `(action, identifier,
>    window_start)`); `RateLimitService` (allow/consume) + `RateLimitFilter` yang
>    mengembalikan 429 dengan header `Retry-After`; login: 5×/15m, register: 10×/15m,
>    endpoint publik (webhook trigger + form submit): 30×/60s per identifier. Dikonfigurasi
>    via env `ratelimit.*`. Migrasi `2026-08-12-100002_CreateRateLimits`.
> 2. **Error alert / notifikasi gagal workflow**: tabel `workflow_alerts` (per-workflow,
>    email tujuan, enabled, throttle) + `alert_logs`; `AlertService` (get/save config,
>    `notifyFailure`, recent, unread count); hook otomatis di `WorkflowEngine::run()` saat
>    status akhir `error`/`stopped` (throttle, kirim email best-effort via CI Email);
>    endpoint `GET/PUT api/workflows/:id/alerts` + `GET api/alerts`. Migrasi
>    `2026-08-12-100003_CreateWorkflowAlerts`. `AlertTest` 9 test / 32 assertion hijau.
> 3. **UI versi workflow**: snapshot disimpan append-only di `workflow_versions` setiap
>    save; endpoint `GET api/workflows/:id/versions` (list + current_version) dan
>    `POST api/workflows/:id/versions/:v/restore` (rollback graph, version bertambah).
>    `WorkflowVersionTest` 5 test / 22 assertion hijau.
> 4. **Smoke test webhook & form trigger** sudah ada (`TriggerSmokeTest`, 7 test):
>    webhook sukses/401/404, form render + submit, schedule via engine — diverifikasi hijau.
> 5. **Test schedule/cron**: `ScheduleCronTest` (8 test) — `CronRunner::tick()` menjalankan
>    schedule jatuh tempo, skip yang belum/aktif-matikan, tulis `cron.last_tick(_detail)`;
>    endpoint `POST api/schedules/:id/run-now` + 403 untuk non-anggota. Isolasi data di
>    `setUp()`/`tearDown()` (bersihkan tabel child→induk + user unik).
> 6. **Collapse/hide sidebar di Dashboard**: toggle di topbar → sidebar menyempit jadi rail
>    ikon (label/logo-text/workspace/user-meta disembunyikan), preferensi disimpan di
>    `localStorage`.
>
> Verifikasi akhir: full suite **122 test / 349 assertion hijau** (PHPUnit 10.5.64,
> MySQL `n8n_codeigniter_test`, `refresh=false`). Build frontend produksi sukses
> (`npm run build`, 125 modul).

> Catatan sesi: server dev sempat mati lalu di-restart (`php spark serve`). Smoke test
> lokal: login owner@local.dev OK, save workflow → v3, execute → engine jalan
> (error 401 Telegram wajar karena token bot palsu). CORS diuji: override origin via
> env + preflight OPTIONS → 204 dengan header yang benar.
>
> Audit node (semua file node + engine) menemukan inkonsistensi besar: sebagian node
> output item terbungkus `{"json": ...}`, sebagian polos, dan node yang membaca field
> langsung (Sort, RemoveDuplicates, Merge combine, Aggregate single_json, binding SQL)
> rusak untuk item terbungkus. Perbaikan sesi ini:
> 1. `AbstractNode::jsonData()` normalizer + `resolveField()` → Sort/RemoveDuplicates/
>    Merge combine/Aggregate single_json/binding `:nama` di MySQL & PostgreSQL berfungsi
>    untuk kedua bentuk item.
> 2. SQL nodes: validasi identifier tabel/kolom (`assertIdentifier`), timeout koneksi
>    (PDO `ATTR_TIMEOUT`/`connect_timeout`), binding select memakai `jsonData`.
> 3. CodeNode: timeout eksekusi (default 30s, param `timeout`) + `proc_open` polling +
>    `taskkill /T` saat lewat batas. I/O via redirection shell (pipe proc_open memblokir
>    di Windows: `stream_get_contents`/`feof`/`fread` mengunci meski non-blocking).
> 4. HTTP Request: opsi `onError` (fail/continue — fail melempar untuk HTTP ≥400 / curl
>    error) dan `outputMode` (replace/merge — merge menggabung data input + respons).
> 5. Slack: HTTP 200 dengan body `{"ok":false}` kini dianggap gagal (throw).
> 6. GitHub: operasi `list` kini bekerja untuk gists (`GET /gists`) & issues
>    (`GET /repos/{owner}/{repo}/issues?per_page=30`).
>
> PHPUnit: 47 test / 112 assertion hijau. Test HTTP & Slack memakai PHP built-in server
> `php -S` yang di-*spin* di `tests/unit/NodeUnitTest.php` (router: `tests/support/http_router.php`).
> Test memakai grup DB `tests` (SQLite :memory: via `Config\Database::$tests`), bukan MySQL
> — jadi DB `n8n_codeigniter_test` tidak diperlukan. Ditemukan & diperbaiki 2 bug:
> (1) migrasi `AddWorkflowPages` memakai `\Config\Database::connect()/forge()` global
> dan `down()` mengakses `$forge->db` (protected) → rollback/migrate-test gagal;
> (2) ENUM `executions.status` tidak punya nilai `stopped` sehingga Stop Node crash
> saat `finish('stopped')` → migrasi baru `2026-08-11-120000_AddStoppedStatusToExecutions`
> (sudah dijalankan di dev). Test DB pakai `$refresh=false` (tanpa regress antar-test
> karena `down()` ENUM rapuh di SQLite; data dibersihkan manual di tearDown).
> Boilerplate framework `ExampleDatabaseTest` + support (`ExampleSeeder/Model/migration`)
> dihapus karena regress-all-nya merusak test DB. Catatan: rollback migrasi 120000
> (`down()`) akan gagal bila sudah ada execution berstatus `stopped` — wajar untuk
> penghapusan nilai ENUM.

> Catatan sesi 2026-08-24 (Fase 6 — Paket 1-3 SELESAI):
>
> **Paket 1 — Default credential per proyek** [x]:
> - Migrasi `2026-08-13-100001_AddCredentialDefault` (kolom is_default + indeks
>   komposit `(workspace_id, credential_type_id, is_default)` via processIndexes).
> - `CredentialService::findDefault/setDefault/listForApi(is_default)`,
>   fallback default di `WorkflowEngine::resolveParams`, toggle UI di
>   CredentialsView + hint "Otomatis memakai default" di NodeSettingsPanel.
> - `CredentialDefaultTest` 9 test hijau.
>
> **Paket 2 — Contoh isian + lampu hijau/merah + Coba Node** [x]:
> - `NodeInterface::getExamples/getExampleOutput`; contoh terisi untuk SEMUA node.
> - `WorkflowEngine::testNode()` (isolasi tanpa execution; trigger ditolak);
>   `resolveParams` jadi public; endpoint `POST api/nodes/test` (selalu HTTP 200,
>   body {ok,output,params} / {ok:false,error}; cek akses workflow → 403).
> - Frontend NodeSettingsPanel: strip validasi live (dot merah/kuning/hijau),
>   tombol "▶ Coba Node" (dialog konfirmasi utk node eksternal/AI),
>   panel "Contoh & Cara Pakai" + "Isi Otomatis Contoh" + sample input editable,
>   preview output/error; dot status uji di FlowNode (data.testStatus).
> - Test: `NodeExampleTest` (semua node punya contoh valid & key cocok schema)
>   + `NodeTestEndpointTest` 7 test (sukses/gagal/trigger/403/tanpa node_type).
>
> **Paket 3 — Plugin WordPress Content AI** [x]:
> - Backend `WpContentService` (httpPostJson protected agar bisa di-stub test;
>   prompt gabung topik/tipe/bahasa/target kata/company profile/instruksi;
>   AI credential = DEFAULT PROYEK via slug openai/9router).
> - Endpoint `/api/v1/wp/status|generate|continue` (auth API key grup v1;
>   rate-limit mengikuti grup: 120/menit).
> - Plugin `wordpress-plugin/n8n-content-ai/`: Settings (API URL/key, badge
>   exp date, min word, bahasa auto get_locale/manual, company profile),
>   Scan Konten (post/page/produk WC/tag/kategori + hitung kata lokal +
>   Jalankan Satu & Bulk dgn delay anti rate-limit), Buat Konten (form →
>   generate → preview → Draft/Publish), Lanjutkan Konten (rewrite/expand/
>   polish → preview → update). AJAX + nonce + sanitasi penuh.
> - `WpContentTest` 8 test (stub HTTP; bahasa+profil masuk prompt; 401 key
>   salah; 400 tanpa topik; 500 tanpa AI credential).
>
> Verifikasi akhir: full suite **150 test / 775 assertion hijau** (MySQL
> n8n_codeigniter_test). `npm run build` sukses.

> Catatan sesi 2026-08-24 (lanjutan — keamanan, docs, i18n):
>
> 1. **Notifikasi email login per user**: kolom `users.login_notify` (migrasi
>    2026-08-24-100001), hook di AuthController::login (IP + parse browser/OS
>    dari User-Agent), toggle di Settings + endpoint GET/PUT api/user/preferences.
>    `LoginNotifyService::sendEmail()` protected agar bisa di-stub test.
> 2. **Custom login path**: settings key `login_slug`; endpoint default
>    /api/auth/login → 404 saat slug aktif, login hanya via /api/auth/login/{slug}
>    (tetap rate-limited). UI editor di Settings → Keamanan (owner only) +
>    URL privat ditampilkan untuk bookmark. Router `/login/:slug` menyimpan slug
>    ke localStorage. Test: LoginPathSecurityTest (4 test).
> 3. **Security headers**: SecurityHeadersFilter global (X-Frame-Options,
>    nosniff, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy,
>    HSTS saat https). Menggantikan filter secureheaders bawaan CI4.
> 4. **Fitur n8n baru**: node `respond_to_webhook` (balas HTTP caller dgn body +
>    status code kustom; WebhookController mengonsumsi outputs engine);
>    Tag workflow (CRUD api/tags, attach/detach workflows/:id/tags/:id,
>    filter ?tag_id= di daftar workflow). Test: N8nFeatureTest (4 test).
> 5. **Frontend**: mode gelap/terang (data-theme + localStorage, toggle topbar &
>    login), i18n ID/EN (stores/ui.js + i18n.js, toggle bahasa), halaman Docs
>    bilingual dengan referensi node otomatis dari registry (DocsView.vue),
>    toggle notifikasi login + editor login path di SettingsView.
>
> Verifikasi akhir: full suite **162 test / 826 assertion hijau**;
> `npm run build` sukses; server dev smoke test OK.

> Catatan sesi 2026-08-24 (lanjutan — layout, landing, docs detail, integrasi):
>
> 1. **Layout scroll**: konten dashboard kini di-scroll via .main-content
>    (topbar & sidebar tetap); lebar otomatis menyesuaikan sidebar collapse.
>    Editor workflow full-canvas tanpa scrollbar halaman (.is-editor).
> 2. **Landing page publik**: tamu di `/` melihat halaman netral (app/Views/
>    landing.php) TANPA link login & info teknis; user login tetap dapat SPA.
>    Melengkapi fitur custom login path.
> 3. **Docs per-node**: DocsView menampilkan parameter lengkap (key/tipe/deskripsi/
>    default), penjelasan pemakaian bilingual per kategori node, contoh
>    input+params dari registry — otomatis sinkron dgn node baru.
> 4. **Integrasi spreadsheet**: node `google_sheets_read` (baca sheet publik
>    via CSV export; konversi otomatis URL edit → export?format=csv; casting
>    angka; fetchUrl() stub-able) dan node `csv` (parse/stringify, delimiter
>    pilihan). Test: SpreadsheetNodeTest (8 test).
>
> Verifikasi akhir: full suite **170 test / 864 assertion hijau**;
> build sukses; smoke test guest `/` → landing tanpa link login OK.

> Catatan sesi 2026-08-24 (lanjutan — landing profil + inquiry):
>
> 1. **Landing page profil publik**: app/Views/landing.php menampilkan profil
>    (nama/tagline/bio/lokasi/kontak — default "Juki, Website Developer & SEO
>    Solo") + layanan + form inquiry. Tamu di `/` melihat ini; tanpa link login.
> 2. **Form inquiry berlapis keamanan**: honeypot field tersembunyi; captcha
>    matematika stateless bertanda tangan HMAC-SHA256 (TTL 10 menit) ATAU
>    Google reCAPTCHA v2 bila kedua kunci diisi di Settings; rate-limit route
>    5/jam per IP (captcha 30/jam). Tabel `inquiries` (migrasi 2026-08-24-100002)
>    menyimpan pesan + IP + user agent.
> 3. **Endpoint**: publik GET api/public/profile|captcha, POST api/public/inquiry;
>    auth GET api/settings/profile + PUT (owner), GET api/inquiries,
>    DELETE api/inquiries/:id (owner).
> 4. **Settings UI**: kartu Profil Publik & Kontak (9 field termasuk kunci
>    reCAPTCHA) + tabel Pesan Masuk dengan hapus.
> 5. Test: InquirySecurityTest (7 test: captcha valid/salah/palsu, honeypot,
>    validasi email+pesan, secret tidak bocor).
>
> Verifikasi akhir: full suite **177 test / 879 assertion hijau**;
> smoke: landing tampil profil+form, captcha endpoint mengembalikan soal.

> Catatan sesi 2026-08-24 (API key per-proyek + workflow SEO):
>
> 1. **API key per-proyek**: kolom `api_keys.workspace_id` (migrasi
>    2026-08-24-100003, nullable + indeks). generate() terima workspaceId;
>    controller validasi keanggotaan via roleInWorkspace (hati-hati: non-anggota
>    = string 'none', bukan null); listForUser join nama workspace.
>    ApiView: dropdown "Ikat ke proyek" saat create + kolom Proyek.
>    Test: ApiKeyWorkspaceTest (3 test).
> 2. **4 workflow Technical SEO siap pakai** di-seed ke DB dev (id 24-27):
>    - SEO – Broken Link & Sitemap Checker (sitemap → extract URL → cek HTTP
>      per URL → filter >=400 → log warning)
>    - SEO – On-Page Audit (fetch halaman → title/meta/H1/canonical/noindex)
>    - SEO – PageSpeed Insights (PSI API mobile → skor Lighthouse ringkas)
>    - SEO – Robots.txt Validator (UA*, Sitemap directive, blokir asset)
>    Seeder: writable/seed_seo_workflows.php (idempoten, koneksi 'default').
>    Smoke test WF Broken Link end-to-end SUKSES via /api/workflows/24/execute:
>    sitemap lokal (public/test-sitemap.xml) → 200 lolos, 404 tertangkap+log.
>    Catatan dev: server `spark serve` single-thread — request HTTP internal ke
>    dirinya sendiri akan timeout; gunakan server kedua port lain untuk uji.
>
> Verifikasi akhir: full suite **180 test / 884 assertion hijau**; build sukses.

> Catatan sesi 2026-08-24 (workflow SEO diperluas — total 12):
>
> 8 workflow teknikal SEO tambahan (id 28-35, seeder
> writable/seed_seo_workflows_2.php):
> - Mass On-Page Audit (sitemap → audit tiap halaman → filter bermasalah → log)
> - Duplicate Content Detector (FNV-1a hash konten → kelompokkan ≥2 URL identik)
> - Mixed Content Checker (resource http:// di halaman)
> - JSON-LD Structured Data Checker (valid/invalid + tipe schema)
> - Image Alt Checker (hitung img tanpa alt + contoh)
> - HTTPS & WWW Redirect Consistency (3 varian domain harus redirect benar)
> - Daily Health Check Otonom (schedule_trigger 02.30 WIB: homepage/robots/
>   sitemap harus 200 & robots punya Sitemap directive)
> - AI Title & Meta Optimizer (nine_router json_object: rewrite title ≤60 &
>   desc ≤155; butuh credential AI default)
>
> Pelajaran penting CodeNode: mode "Each Item" tetap mengekspos `items`
> (arg ke-3) — kode bergaya items.map HARUS entryPoint "All Items", kalau tidak
> output terduplikasi N×N. Ditemukan via smoke test (hashn ×3), fixed di DB
> node 96/118 dan seeder.
>
> Smoke test sukses end-to-end via API lokal (server uji port 8081 +
> fixture public/test-page.html, test-dup-a/b.html, robots.txt,
> test-sitemap*.xml): mass audit, duplikat (grup 2 URL), mixed content (1),
> alt checker (1/2), broken link. AI optimizer butuh credential AI valid.

> Catatan sesi 2026-08-24 (penutupan gap vs n8n — tahap 1):
>
> 1. **AI Agent node** (`ai_agent`): loop tool-calling OpenAI-compatible.
>    Tools user-definisi via JSON: type `http` (method/url/headers/body,
>    resolve ekspresi) atau type `workflow` (jalankan sub-workflow via engine).
>    Protokol tool_calls valid (assistant msg + role:tool + tool_call_id).
>    Memory percakapan per memory_key (tabel ai_memories, migrasi
>    2026-08-24-100004, max 20 pesan, best-effort). llmPostJson stub-able.
> 2. **Text Classifier node** (`text_classifier`): klasifikasi ke kategori JSON,
>    output field classification (+reason), normalisasi lowercase & fallback.
>    **Information Extractor node** (`info_extractor`): ekstraksi terstruktur
>    sesuai schema JSON → field extracted. Keduanya response_format json_object.
> 3. **Draft vs Publish**: snapshot graf di tabel workflow_publications
>    (migrasi 2026-08-24-100005 + kolom workflows.published_at). Engine
>    loadExecutionGraph(): eksekusi/webhook/schedule memakai snapshot bila ada;
>    tanpa publish = perilaku lama (graf live). Endpoint POST
>    api/workflows/:id/publish|unpublish, GET :id/publication (has_draft_changes
>    = updated_at > published_at). Editor: badge DRAFT/LIVE/PERUBAHAN DRAFT +
>    tombol 🚀 Publish.
> 4. **Execution Replay**: POST api/executions/:id/replay {from_node?} —
>    default dari node error pertama; engine opsi replay_from_node+items:
>    mulai hanya dari node itu dengan input tercatat (hulu tidak dieksekusi).
>    UI: tombol Replay di ExecutionDetailView.
>
> Test: N8nGapFeatureTest (7 test — agent tool-calling via stub LLM +
> workflow-tool nyata, batas iterasi, persistensi memory, classifier
> normalisasi, extractor terstruktur, draft-vs-publish dua fase, replay).
>
> Verifikasi akhir: full suite **187 test / 955 assertion hijau**; build sukses.

> Catatan sesi 2026-08-24 (Python Code Node):
>
> **PythonCodeNode** (`python_code`): setara CodeNode tapi eksekusi Python.
> - Deteksi binary: env PYTHON_BINARY → `python`/`python3`/`py` (validasi
>   output --version); error jelas bila tidak ada.
> - Kode user ditulis ke file .py temp; input workflow via stdin JSON;
>   hasil stdout JSON; timeout + taskkill/kill pohon proses (mirror CodeNode).
> - Dua mode entryPoint seperti n8n: All Items — run(items, params);
>   Each Item — run(item, index, items, params). Konvensi return:
>   dict → 1 item, list → banyak item, otomatis dibungkus {json: ...}.
> - ⚠️ Keamanan didokumentasikan: subprocess TANPA sandbox penuh (beda dgn
>   vm JS) — hanya untuk self-hosted terpercaya; multi-tenant butuh container.
> Test: PythonCodeNodeTest (5 test; skip otomatis bila Python tak ada).
>
> Verifikasi: full suite **192 test / 974 assertion hijau**.

> Catatan sesi 2026-08-24 (panel status Engine & Runtime):
>
> - Endpoint GET api/system/runtimes (auth): deteksi Node.js, Python,
>   PHP, MySQL/MariaDB + ekstensi PHP penting. RuntimeService::detect()
>   exec --version + pola versi; env NODE_BINARY/PYTHON_BINARY didahulukan.
> - SettingsView: kartu Engine & Runtime — badge Aktif/Tidak aktif per engine,
>   versi terdeteksi, dan <details> "Cara mengaktifkan di hosting"
>   (nvm/apt/cPanel/python.org, hint .env) bilingual.
> Test: SystemRuntimeTest (2 test: 401 tanpa auth; struktur & versi).
>
> Verifikasi: full suite **194 test / 989 assertion hijau**;
> smoke HTTP nyata: node v22.15.0, Python 3.x, MariaDB terdeteksi.

> Catatan sesi 2026-08-24 (status hosting di dashboard + MCP Server):
>
> 1. **Status Hosting di Dashboard**: OverviewView menampilkan kartu
>    "🖥️ Status Hosting" (PHP/MySQL/Node.js/Python + badge Aktif/Mati +
>    versi) memakai endpoint GET api/system/runtimes yang sudah ada.
> 2. **MCP Server** (setara n8n 2.5.x): POST api/v1/mcp — JSON-RPC 2.0
>    dengan auth API key. Method: initialize, ping, tools/list (workflow
>    workspace → tools wf_{id} + inputSchema), tools/call (eksekusi engine,
>    hasil status/execution_id/node_states sebagai text content + isError).
>    Error handling: -32700 parse, -32600 invalid request, -32601 method.
>    Test: McpServerTest (6 test — handshake, list, call nyata sukses,
>    tool tak dikenal isError, method asing -32601, 401 tanpa API key).
>
> Verifikasi akhir: full suite **200 test / 1002 assertion hijau**;
> build frontend sukses.

> Catatan sesi 2026-08-24 (RAG foundation + ROI + ai_usage):
>
> 1. **Vector Store / RAG**: tabel ai_vectors (migrasi
>    2026-08-24-100006) + AiVectorService (embeddings via API OpenAI-
>    compatible, upsert/search/delete, cosine similarity di PHP).
>    Node `vector_store`: operasi search/upsert/delete per namespace.
> 2. **Time Saved tracking** (ROI ala n8n Time Saved): kolom
>    workflows.time_saved_minutes; input di editor toolbar; overview
>    dashboard menampilkan total jam dihemat + jumlah run.
> 3. **ai_usage aktif** (tabel PRD akhirnya terpakai): LlmCallerTrait,
>    AiAgentNode, TextClassifierNode, InfoExtractorNode kini mencatat
>    prompt/completion/total tokens per panggilan AI (best-effort).
> Test: RagRoiFeatureTest (4 test — upsert+search cosine, delete namespace,
> cosine basics, agregasi time-saved di overview).
>
> Verifikasi akhir: full suite **204 test / 1037 assertion hijau**;
> build frontend sukses.

> Catatan sesi 2026-08-24 (penutupan seluruh backlog):
>
> 1. **Memory Node** (`memory`): operasi load/save/clear per memory_key
>    (tabel ai_memories — bisa berbagi memori dengan AI Agent).
> 2. **Vector Browser**: GET api/vectors/summary + DELETE
>    api/vectors/namespace/:ns (scoped workspace) + kartu "AI Knowledge
>    Base" di Pengaturan (daftar namespace, jumlah vektor, hapus).
> 3. **Google OAuth SSO ringan**: GoogleOauthService (state HMAC 10 menit,
>    exchangeCode stub-able, auto-create member di workspace pertama,
>    email terverifikasi). Route publik: auth/oauth/status,
>    /google/start (throttled), /google/callback; owner settings via
>    GET/PUT api/security/oauth-google. Tombol "Masuk dengan Google"
>    muncul di halaman login hanya bila client_id+secret diisi di Settings.
> Test: BacklogFeatureTest (7 test — memory roundtrip, key wajib,
> agent↔memory shared table, vector summary/delete endpoint, state HMAC,
> auto-create member + idempoten login, status publik).
>
> Verifikasi akhir: full suite **211 test / 1072 assertion hijau**.

> Catatan sesi 2026-08-25 (paket nilai-plus vs n8n — G/A/H/C/B selesai):
>
> 1. **G. AI Budget Guardrail**: setting `ai_monthly_token_limit` +
>    `ai_action_on_exceed` (warn/block). AiUsageService::guard() melempar
>    exception saat block; semua node AI memanggil guard di awal execute
>    (AiBudgetGuardTrait + fallback di LlmCallerTrait). Endpoint
>    GET/PUT api/system/ai-budget (owner) + kartu Pengaturan dgn progress
>    bar pemakaian bulan ini.
> 2. **A. WhatsApp Node** (`whatsapp_send`, provider Fonnte): credential
>    type `fonnte` (token device, migrasi 100007); POST api.fonnte.com/send,
>    per-item dengan resolve ekspresi; error reason dari API dilempar.
> 3. **H. Webhook Inspector**: tabel webhook_requests (migrasi 100008);
>    SEMUA request masuk /webhook/* diarsipkan (termasuk gagal token/404),
>    valid+workflow_id ditandai setelah verifikasi, retention max ±500.
>    API list/detail/replay (auth session). Replay menjalankan engine
>    dengan body/query tersimpan.
> 4. **C. Template Gallery**: folder backend/workflow-templates/*.json
>    (11 template SEO ter-export dari DB). GET api/templates + POST
>    api/templates/:slug/install (normalize graph sendiri, workflow baru
>    inactive). UI: tombol "📋 Dari Template" di WorkflowsView + modal grid.
> 5. **B. Payment Gateway lokal**: credential types `midtrans` & `tripay`
>    (migrasi 100009). Node MidtransVerifyNode (sha512 signature, hash_equals,
>    paid=capture/settlement+fraud accept), TripayVerifyNode (HMAC-SHA256 raw
>    body, header fallback X-Callback-Signature), TripayInvoiceNode (buat
>    transaksi signed, sandbox/production, checkout_url output).
> Test: AiBudgetGuardrailTest (4), WhatsAppSendNodeTest (4),
> WebhookInspectorTest (2, end-to-end arsip+replay), TemplateGalleryTest (3),
> PaymentGatewayNodeTest (8).
>
> Verifikasi akhir: full suite **232 test / 1190 assertion hijau**;
> build frontend sukses.

---

## Fase 1 — Fondasi aplikasi

- [x] Backend CodeIgniter 4.7.4 + struktur controller/service/node
- [x] Migrasi database (10 migration sudah dijalankan)
- [x] Auth login/logout + session (`AuthController`, `AuthFilter`)
- [x] Workspace / projects + scoping akses (`BaseApiController::hasAccessToWorkflow`)
- [x] Frontend Vue 3 + Pinia + Vue Router + Vue Flow (Vite)
- [x] Editor workflow: canvas, palette, koneksi antar node, settings panel
- [x] CRUD workflow + save/duplicate/delete
- [x] Halaman Executions (list, detail per node, stats)
- [x] CRUD Credentials + tipe credential
- [x] Halaman Projects, Settings, Api Console, Overview
- [x] Webhook publik + form trigger (render form HTML)
- [x] Schedules + cron runner (`CronRunner`, `CronRun`)

## Fase 2 — Nodes & engine eksekusi

- [x] Engine eksekusi BFS sinkron (`WorkflowEngine`)
- [x] Ekspresi `{{$json...}}`, `{{$node["x"]...}}`, `{{$var...}}`, builtin function
- [x] Per-item expression (resolveParams tidak lagi membekukan nilai ke item pertama)
- [x] Node trigger: Manual, Schedule, Webhook, Form, Error
- [x] Node Flow: IF, Switch, Loop Over Items, Stop, Execute Workflow, Merge
- [x] Node Data: Set, Filter, Sort, Limit, Remove Duplicates, Aggregate, Wait, Log, Code (JS)
- [x] Node HTTP: HTTP Request
- [x] Node Integrations: Email (SMTP), Telegram, Discord, Slack, GitHub, MySQL, PostgreSQL, Notion
- [x] Node AI: OpenAI, 9Router (OpenAI/Anthropic/Gemini format)
- [x] Stop + sub-workflow: status `stopped`, loop per-item, execute_workflow berfungsi
- [x] Verifikasi node Flow (Loop/Stop/Execute Workflow) lewat tes eksekusi
- [x] Output node dibungkus konsisten (`json`), Set menulis di dalam wrapper

## Fase 3 — Hosting readiness (PRIORITAS SAAT INI)

- [x] Enkripsi credential beneran (AES-256 via CI Encrypter) — ganti base64
- [x] CORS bisa dikonfigurasi lewat env (`cors.allowedOrigins`)
- [x] Fix Merge/Aggregate: buka wrapper `json` saat combine
- [x] Build frontend produksi (`npm run build`) berhasil
- [x] Dokumentasi langkah deploy (env, DB, writable, cron, proxy) → `DEPLOY.md`
- [x] Cek & dokumentasi dependency hosting → `DEPLOY.md` §2 (PHP 8.2+, MySQL, ekstensi, `node` untuk CodeNode)
- [x] `.env.example` lengkap dengan komentar konfigurasi production → `backend/.env.example`
- [x] Backup & migrasi DB di server produksi (langkah) → `DEPLOY.md` §8
- [O] Test login + execute setelah deploy (smoke test) — sudah lolos di lokal; ulangi di server produksi
- [x] CORS origin bisa di-override via env `cors.allowedOrigins` (`app/Config/Cors.php`) + rute OPTIONS catch-all supaya preflight tidak 404 (`app/Config/Routes.php`)

## Fase 4 — Pengujian & kualitas

- [x] PHPUnit integration test untuk engine + node (`tests/database/WorkflowEngineTest.php`)
- [x] PHPUnit test untuk ekspresi (`tests/unit/ExpressionEngineTest.php`) & node (`tests/unit/NodeUnitTest.php`)
- [x] Injeksi koneksi DB ke `ExecutionManager`/`WorkflowEngine` (opsional, default `\Config\Database::connect()`)
- [x] Audit seluruh node + perbaikan konsistensi item (`jsonData` normalizer), SQL binding/validasi/timeout, CodeNode timeout, HTTP Request onError/outputMode, Slack `ok:false`, GitHub list
- [x] Lint PHP + build frontend di CI (jika repo di-push ke GitHub)
- [x] Smoke test webhook & form trigger
- [x] Test schedule/cron (run-now + CronRun)

## Fase 6 — Rencana berjalan berikutnya (sudah disetujui user)

> Ditulis 2026-08-12. Urutan eksekusi: Paket 1 → 2 → 3. Semua keputusan desain
> sudah dikonfirmasi user lewat tanya-jawab (lihat catatan tiap paket).

### PAKET 1 — Default Credential per Proyek
Keputusan: toggle default ada di halaman **Credentials** (bukan Settings); node tanpa
credential terpilih otomatis pakai default workspace.
1. Migrasi `2026-08-13-100001_AddCredentialDefault.php`:
   `ALTER credentials ADD is_default TINYINT(1) DEFAULT 0` + indeks
   `(workspace_id, credential_type_id)`.
2. `CredentialService`: `findDefault(int $workspaceId, int $typeId)` + `setDefault(int $id, bool)`
   (reset `is_default=0` untuk credential lain di (workspace,type) sama); `listForApi()` sertakan `is_default`.
3. `CredentialController::update()`: terima `is_default`.
4. `WorkflowEngine::resolveParams()` (line ~378-393): saat node tanpa `$credId` → fallback
   `findDefault($workflow['workspace_id'], $typeId)`.
5. Frontend `CredentialsView.vue` (toggle "✓ Default") + `NodeSettingsPanel.vue`
   (hint "Otomatis memakai default: [nama]" bila dropdown kosong & ada default).
6. Test `tests/database/CredentialDefaultTest.php`.

### PAKET 2 — Contoh Isian + Lampu Hijau/Merah + Coba Node
Keputusan: contoh isian = **template preset siap pakai** (tombol "Isi Otomatis Contoh");
lampu hijau/merah = **validasi isian live** + tombol "▶ Coba Node" yang **memanggil API asli**
(untuk node AI/eksternal tampilkan konfirmasi dialog; bisa kena biaya AI).
1. `NodeInterface` + `AbstractNode`: tambah `getExamples(): array` (title, input, params)
   dan `getExampleOutput(): array`. `NodeRegistry::toApi()` sertakan keduanya.
2. Isi contoh untuk node: 9Router, OpenAI, NoAISlop, Email, Telegram, Discord, Slack,
   HTTP Request, GitHub, Notion, MySQL, PostgreSQL, Set, Filter, Sort, IF, Switch,
   Aggregate, Log, Code (bahasa Indonesia, gunakan `{{$json.*}}`).
3. `WorkflowEngine::testNode(array $node, array $sampleData): array` (public) + `resolveParams`
   jadi `public`; endpoint baru `POST /api/nodes/test` di `NodeController`
   (response selalu HTTP 200 dengan `{ok:true,output,...}` atau `{ok:false,error}`).
4. Frontend `NodeSettingsPanel.vue`: panel "Contoh & Cara Pakai" (contoh isian, tombol
   Isi Otomatis Contoh, preview output, sample input editable), validasi live
   hijau/merah/kuning (semua required valid / ada yang kurang / lengkap-belum-dicoba),
   tombol "▶ Coba Node". Indikator dot di `FlowNode.vue`.
5. Test `tests/unit/NodeExampleTest.php` (semua node punya contoh valid) +
   `tests/database/NodeTestNodeTest.php` (endpoint test node non-API sukses, node gagal
   → `ok:false`, 403 non-anggota).

### PAKET 3 — Plugin WordPress Content AI
Keputusan: scan & hitung kata **di plugin WP** (query DB WP lokal); generate/continue
**lewat endpoint khusus backend** (`/api/v1/wp/*`); company profile & min-word di **wp_options
plugin** (satu n8n-CI bisa layani banyak site); tipe konten **Post + Page + Produk WooCommerce +
arsip tag/kategori**; bahasa **auto `get_locale()`** (`id_ID`→id, `en_US`→en) + override manual.
AI credential = **default proyek** (hasil Paket 1) → plugin cukup API key n8n-CI (punya `expires_at`).
1. Backend `WpContentService`: pakai pola `LlmCallerTrait` (refactor `httpPostJson` jadi
   virtual agar bisa di-stub di test); prompt template gabung topik/tipe/bahasa/target kata/
   company profile (soft-selling + rebranding)/instruksi.
2. Backend `WpContentController` (di grup `/api/v1`, auth API key):
   - `POST /api/v1/wp/generate` → `{topic, content_type, language, min_words, company_profile, instructions, model?}` → `{content, word_count, model, usage}`
   - `POST /api/v1/wp/continue` → `{existing_content, existing_title?, language, company_profile, instructions, action: rewrite|expand|polish}` → hasil
   - `GET /api/v1/wp/status` → `{valid, expires_at, workspace_name, ai_credential_ready}`
   - Route WP pakai rate-limit longgar (`ratelimit:300:60`) karena bulk.
3. Plugin WordPress folder baru `wordpress-plugin/` (tanpa build tool):
   - Settings page: API URL, API key, status + **exp date** (badge hijau valid / merah expired),
     min word, bahasa (auto/manual), company profile.
   - Scan page: query `WP_Query`/`$wpdb` (post, page, produk WooCommerce jika aktif, tag & kategori),
     hitung kata lokal, tabel (ID, judul, tipe, jumlah kata, status kurang, link edit) + checkbox,
     tombol **Jalankan Satu** (test) dan **Jalankan Bulk** (progres, panggil `/wp/continue` per item,
     delay anti rate-limit).
   - Create page: form (topik, tipe, target kata, instruksi) → `/wp/generate` → preview
     (word count, model) → tombol Draft/Publish via `wp_insert_post`.
   - Continue page: pilih konten → aksi rewrite/expand/polish → `/wp/continue` → diff ringkas →
     update via `wp_update_post`.
   - AJAX `admin-ajax.php` + `wp_remote_post` (timeout 120s) + nonce + sanitasi.
4. Test `tests/database/WpContentTest.php`: stub HTTP → generate/continue valid, bahasa & profil
   masuk prompt, 401 key salah, 400 tanpa data, 500 tanpa AI credential.
5. Verifikasi: `php -l` semua file, `npm run build`, full suite (target naik dari 122),
   smoke manual plugin (opsional).

### Checkbox progres (di-update saat tiap paket selesai)
- [x] Paket 1: Default credential per proyek
- [x] Paket 2: Contoh isian + lampu hijau/merah + Coba Node
- [x] Paket 3: Plugin WordPress Content AI

## Fase 5 — Fitur lanjutan (belum dibutuhkan untuk rilis pertama)

- [x] Collapse/hide sidebar di Dashboard
- [x] Eksekusi background/queue (biar workflow panjang tidak kena timeout HTTP)
- [x] Import/export workflow JSON
- [x] UI versi workflow (`workflow_versions`)
- [x] RBAC/peran user & sharing antar user
- [x] API key untuk eksekusi eksternal
- [x] Sandboxing CodeNode (tanpa akses FS/network bebas) + fallback bila `node` tidak ada
- [x] Rate-limit login & endpoint publik
- [x] Error alert / notifikasi gagal workflow
- [x] Retry per-node dengan backoff

---

## Catatan keputusan penting

- Engine sinkron batch (BFS): node memproses semua item sekaligus; node yang sudah `done` tidak dieksekusi ulang.
- Loop melepas semua elemen sekaligus di output `loop`; `done` hanya item terakhir.
- Ekspresi di-resolve per item oleh tiap node (`params` dikirim mentah).
- Status `stopped` = Stop Node; frontend menampilkan badge muted.

## Selesai: Hardening Security & Reliability (Fase S1/S2/S3 + F1/F2/F4/F5)
- F2: 4 transBegin di WorkflowController wrapped per-block dengan try/catch/transRollback — php -l lulus di semua site.
- F4: PaymentEventService + dedup marker di MidtransVerifyNode & TripayVerifyNode (payment_events UNIQUE provider+reference+status).
- F5: AiVectorService::embed( optional) + VectorStoreNode passing context workflow id ke embed().
- S1: WebhookController handle() + markInspector workspace-aware; WebhookInspectorController scopeFilter/visibleWorkspaces applied index/show/replay.
- S2: GoogleOauthService autoRegisterEnabled() + loginWithGoogleProfile(profile, ?autoRegister=null); AuthController oauthSettings/ saveOauthSettings menyimpan registration_mode (off/member-auto).
- S3: Active-block di ApiV1Controller::createExecution + MCPController::callTool + ExecutionController::execute → 409 bila workflow tidak aktif.
- Akun: change password, request+verify email change via HMAC token 1h.
- Frontend SettingsView: form ganti password, request email verifikasi, select mode OAuth (off/member-auto).
- Full suite: 232/232 green, 1190 assertions. Baseline terjaga.
- Frontend build sukses, dist → public copy done.
- Test DB ter-migrasi migrasi 100010 (workspace_id, payment_events, users pending cols).
- RbacTest makeWorkflow diaktifkan status aktif untuk kompatibilitas S3.
- SecurityFixTest belum dibuat (dokumen tersisa untuk rollanjut).

