# Data Tambahan — Kesepakatan

Dokumen ini mencatat tambahan keputusan di luar PRD.MD yang disepakati saat perencanaan.

## 1. Editor Canvas

- Framework frontend: **Vue 3 + Vue Flow** (persis yang dipakai n8n).
- Scope tahap pertama: **Editor + node core dulu** (backend API workflow menyertai).

## 2. Multi-Projek

- Owner/user bisa membuat **beberapa projek** dari menu "Projek".
- Tabel `workspaces` dipakai sebagai **Projek** (1 projek berisi banyak workflow).
- Selector projek di header dashboard (seperti n8n), workflow ter-filter per projek.

## 3. Dashboard

- **Disamakan dengan n8n**.
- Navigasi kiri: Overview, Workflows, Executions, Credentials, Projek, Settings.
- Overview: statistik (total workflow, aktif, sukses/gagal) + workflow terbaru + tombol "Buat Workflow".
- Workflows: daftar per projek, toggle aktif/nonaktif, tombol buka editor.
- Executions: list + detail per node (execution viewer).

## 4. Cron dari Dashboard (tanpa sering edit cPanel/terminal)

- **Target deployment: shared hosting cPanel.**
- **Sekali pasang 1 entri cron di cPanel, selanjutnya semua dikelola dari dashboard.**
  Entri (sekali saja): `* * * * * php /home/USER/path/.../spark cron:run`
- Command CLI CI4 `spark cron:run` membaca tabel `schedules` dan menjalankan workflow yang jadwalnya jatuh tempo, lalu memperbarui `next_run`.
- Dashboard mengelola jadwal sepenuhnya: buat/edit jadwal, ekspresi cron, timezone, aktif/nonaktif, tombol **"Jalankan Sekarang"**.
- Halaman **Cron Status** di Settings: menampilkan `last_tick` dan `next_run` sehingga status cron bisa diverifikasi dari dashboard tanpa terminal.
- Interval jadwal minimum: **per menit**.

## 5. Autentikasi

- **Single owner dulu** (tanpa RBAC) sesuai saran PRD.
- Login sederhana berbasis session. RBAC multi-user disiapkan untuk fase SaaS berikutnya.

## 6. Batasan MVP

- Eksekusi **sinkron** (batas `max_execution_time` PHP ~30 dtk di shared hosting).
- Status eksekusi live via **polling** (bukan WebSocket/Redis) pada tahap pertama.
- Workflow panjang dan Redis queue + worker direncanakan di fase berikutnya.

## 7. Stack Keseluruhan

CodeIgniter 4 + MySQL + Redis (fase berikut) + Vue 3/Vue Flow + 9Router.
