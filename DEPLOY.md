# Panduan Deploy — n8n CI4 (CodeIgniter 4 + Vue 3)

Dokumentasi langkah menaikkan aplikasi ke server produksi (VPS, cPanel, atau shared hosting).

---

## 1. Arsitektur singkat

- **Backend** — CodeIgniter 4 (`backend/`), webroot = `backend/public/`.
- **Frontend** — Vue 3 SPA (`frontend/`), di-build lalu disalin ke `backend/public/` agar **same-origin** dengan API. Router memakai hash (`#/`), jadi tidak perlu rewrite SPA di web server.
- **Webhook & cron** — route publik `/webhook/*` (tanpa auth) + perintah CLI `php spark cron:run`.

## 2. Kebutuhan hosting

| Kebutuhan | Versi / catatan |
|---|---|
| PHP | **8.2+** (framework butuh `^8.2`) |
| Web server | Apache (dengan `mod_rewrite`) atau Nginx |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Ekstensi PHP | `json`, `mysqli`/`pdo_mysql`, `openssl`, `curl`, `mbstring`, `intl`, `fileinfo` |
| Composer | Diperlukan sekali saat instalasi |
| Node.js (opsional) | Hanya untuk **Code Node** (`node` harus ada di PATH). Tanpa node, Code Node gagal |
| Eksekusi perintah | `exec()`, `shell_exec()` boleh (dipakai Code Node & runner) |

## 3. Deploy backend

```bash
# 1) Upload seluruh folder `backend/` ke server, misal: /home/user/apps/n8n/
#    JANGAN upload vendor/ — install ulang di server.

# 2) Install dependency (tanpa paket dev)
cd /home/user/apps/n8n/backend
composer install --no-dev --optimize-autoloader

# 3) Buat .env dari contoh
cp .env.example .env
#    Lalu edit: app.baseURL, database.*, encryption.key (WAJIB ganti).

# 4) Generate kunci enkripsi (32 byte hex) — isikan ke encryption.key
php -r "echo bin2hex(random_bytes(32));"

# 5) Jalankan migrasi + seed data awal (user owner default)
php spark migrate
php spark db:seed InitialData

# 6) Beri izin tulis pada folder `writable/`
chmod -R 755 writable            # cukup; PHP-FPM biasanya sudah bisa tulis
#    Untuk cPanel: pastikan owner file = user cPanel, bukan root.

# 7) Arahkan domain ke webroot `backend/public/`
```

### Apache (.htaccess sudah tersedia di `backend/public/`)
- Pastikan `AllowOverride All` aktif pada vhost, dan `mod_rewrite` menyala.
- Pindahkan `backend/public/` menjadi document root (bukan folder `backend/`).

### Nginx — contoh `server` block

```nginx
server {
    listen 80;
    server_name app.example.com;

    root /home/user/apps/n8n/backend/public;
    index index.php;

    # File statis yang benar-benar ada dilayani langsung
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Jangan layani folder internal
    location ~ ^/(app|system|writable|vendor|\.env|spark|composer\.) { deny all; }

    # Naikkan batas upload kalau ada form trigger dengan lampiran
    client_max_body_size 20m;
}
```

## 4. Deploy frontend (SPA)

```bash
cd frontend
npm install
npm run build          # hasil: frontend/dist/
```

Salin isi `frontend/dist/` **ke dalam** `backend/public/`:

```bash
cp -r dist/* ../backend/public/
```

> Catatan: build memakai base absolut `/assets/...`. Bila aplikasi dipasang di
> subpath (mis. `https://domain.com/n8n/`), set `base` di `frontend/vite.config.js`
> ke path tersebut lalu build ulang. API SPA memakai `baseURL: '/api'` relative
> ke origin, jadi subpath perlu disesuaikan juga.

## 5. CORS

- Frontend & API **same-origin** (keduanya dari `backend/public`) → CORS tidak dipakai, biarkan default.
- Beda origin → set di `.env`:
  ```
  cors.allowedOrigins = https://app.example.com
  ```
  Origin dipisah koma bila lebih dari satu.

## 6. Cron / workflow terjadwal

Tambahkan baris ini ke crontab (jalankan setiap menit):

```cron
* * * * * /usr/local/bin/php /home/user/apps/n8n/backend/spark cron:run >/dev/null 2>&1
```

- Di cPanel: cron job → jalankan perintah di atas, interval *Every minute*.
- Pastikan `spark` punya izin execute. Uji manual dulu:
  ```bash
  php spark cron:run
  ```
- Runner hanya memproses schedule yang `active` dan sudah jatuh tempo (`next_run`).

## 7. Login pertama & keamanan

1. Buka `https://app.example.com/`.
2. Login default dari seeder: **`owner@local.dev` / `owner123`**.
3. **SEGERA ganti password** (sampai ada fitur ubah password, ganti manual di DB:
   ```sql
   UPDATE users SET password = SHA2('password_baru_kuat', 0) ...
   ```
   — atau pakai CLI:
   ```bash
   php spark db:query "UPDATE users SET password = '$(php -r "echo password_hash('password_baru', PASSWORD_DEFAULT);")' WHERE email='owner@local.dev';"
   ```
   Lebih aman lagi, jalankan `php -r` terlebih dahulu lalu tempel hash-nya.)
4. Pastikan `CI_ENVIRONMENT = production` (tidak ada debug bar).
5. Aktifkan HTTPS: set `app.forceGlobalSecureRequests = true` dan sediakan SSL.
6. Jangan pernah commit `.env` (sudah ada di `.gitignore`).

## 8. Backup & migrasi DB

Backup rutin (crontab mingguan misal):

```bash
# Dump database
mysqldump -u db_user -p n8n_codeigniter > backup_$(date +%F).sql

# Backup file kunci (wajib disimpan terpisah)
#   - backend/.env            (berisi encryption.key + kredensial DB)
#   - backend/writable/logs   (jejak error)
```

Restore:

```bash
mysql -u db_user -p n8n_codeigniter < backup_2026-08-11.sql
```

### Upgrade / migrasi DB saat rilis baru

```bash
cd /home/user/apps/n8n/backend
php spark migrate            # jalankan migrasi baru
php spark migrate:status     # pastikan semua 'up to date'
```

Best practice: dump DB dulu sebelum `migrate` di production, dan lakukan di jam
sepi. Migration bersifat aditif; tidak menghapus data.

## 9. Smoke test setelah deploy

```bash
# 1) Health check
curl -s https://app.example.com/ | head -c 200

# 2) Login (simpan session cookie)
curl -s -c cookies.txt -X POST https://app.example.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"owner@local.dev","password":"password_baru_kuat"}'
#   Harus: {"success":true,"message":"Login berhasil",...}

# 3) Lihat daftar workflow
curl -s -b cookies.txt https://app.example.com/api/workflows

# 4) Buat workflow sederhana (Manual Trigger -> HTTP Request / Telegram),
#    save, lalu execute:
curl -s -b cookies.txt -X POST https://app.example.com/api/workflows/1/execute \
  -H "Content-Type: application/json" -d '{}'
#   Harus menghasilkan execution record (status success/error), bukan HTTP 500.

# 5) Webhook publik (tanpa auth)
curl -s -X POST https://app.example.com/webhook/<webhook-path> -d '{"test":1}'
```

## 10. Troubleshooting umum

| Gejala | Kemungkinan penyebab | Cek |
|---|---|---|
| 500 / halaman kosong | `CI_ENVIRONMENT` masih `development`; folder `writable` tidak bisa ditulis | `php spark` di CLI; `chmod -R 755 writable` |
| 404 di semua route | Rewrite tidak aktif / document root salah | pastikan webroot = `backend/public`, `mod_rewrite` menyala |
| Aset JS/CSS 404 | Frontend `dist/` belum disalin ke `backend/public` | cek `backend/public/assets/*` |
| Login selalu gagal / session hilang | `app.baseURL` salah atau beda dengan domain yang diakses | samakan `app.baseURL` dengan URL nyata |
| Credential gagal didekripsi | `encryption.key` berubah | restore kunci lama; jangan ubah key setelah produksi |
| Code Node error | `node` tidak ada di PATH | `which node`; pasang Node.js di server |
| CORS blocked di browser | frontend & backend beda origin | set `cors.allowedOrigins` |
| Workflow terjadwal tidak jalan | cron tidak terpasang / path `spark` salah | jalankan manual `php spark cron:run` |
