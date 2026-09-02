jadi projek ini maubuat n8n versi codeigniter

CodeIgniter 4 + MySQL, dan yang kamu inginkan adalah fungsi yang secara konsep setara dengan n8n, desain sebelumnya terlalu enterprise dan terlalu banyak tabel.

Kita sederhanakan.

Prinsipnya:

Core sedikit, workflow fleksibel, node sebagai module, execution tersimpan, integrasi ditambahkan sebagai module.

Saya akan memakai sekitar 20–25 tabel inti, bukan 70 tabel.

1. Struktur modul

Saya kelompokkan seperti ini:

CORE
├── Authentication
├── User
├── Workspace
├── Credential
├── Settings
└── Module Manager

WORKFLOW
├── Workflow
├── Workflow Editor
├── Node
├── Connection
├── Expression
└── Variables

TRIGGER
├── Manual Trigger
├── Webhook
├── Schedule / Cron
├── Interval
└── App Trigger

FLOW
├── IF
├── Switch
├── Merge
├── Loop
├── Filter
├── Sort
├── Limit
├── Wait
├── Execute Workflow
└── Stop

DATA
├── Edit Fields
├── Code
├── JSON
├── XML
├── CSV
├── Date & Time
├── Crypto
└── Binary/File

HTTP
├── HTTP Request
├── Webhook
├── Respond to Webhook
└── GraphQL

DATABASE
├── MySQL
├── PostgreSQL
├── Redis
├── MongoDB
└── SQL

AI
├── 9Router
├── AI Chat
├── AI Agent
├── Memory
├── Tool
├── Embedding
├── Vector Store
└── Structured Output

APP INTEGRATIONS
├── Google
├── WordPress
├── WooCommerce
├── Telegram
├── Discord
├── Slack
├── WhatsApp
├── Email
└── dll.

EXECUTION
├── Queue
├── Worker
├── Execution
├── Retry
├── Error
└── Logs
2. Database yang benar-benar diperlukan

Saya sarankan mulai dengan 23 tabel.

CORE — 7 tabel
users
workspaces
workspace_users

credentials
credential_types

settings
modules

Relasi:

users
  │
  └──< workspace_users >── workspaces
                              │
                              └──< workflows

users
  │
  └──< credentials

credential_types
  │
  └──< credentials

Tidak perlu tabel roles, permissions, role_permissions dulu kalau sistem awalnya memang untuk penggunaan sendiri/single owner.

Kalau nanti SaaS multi-user, baru tambahkan RBAC.

3. WORKFLOW — 5 tabel

Ini inti sistem.

workflows
workflow_versions
workflow_nodes
workflow_connections
workflow_variables

Relasi:

workspaces
     │
     └──< workflows
              │
              ├──< workflow_versions
              │
              ├──< workflow_nodes
              │
              ├──< workflow_connections
              │
              └──< workflow_variables

workflows:

id
workspace_id
name
description
status
active
version
settings_json
created_at
updated_at

workflow_nodes:

id
workflow_id
node_id
node_type
name
position_x
position_y
parameters_json
credential_id
disabled
created_at
updated_at

workflow_connections:

id
workflow_id
source_node
source_output
target_node
target_input
connection_type

Dengan dua tabel workflow_nodes + workflow_connections, kamu sudah bisa membuat workflow visual seperti:

       ┌─────────┐
       │ Webhook │
       └────┬────┘
            ↓
      ┌───────────┐
      │ 9Router   │
      └─────┬─────┘
            ↓
        ┌──────┐
        │  IF  │
        └──┬─┬─┘
           │ │
       TRUE│ │FALSE
           ↓ ↓
        Email Telegram

Tidak perlu tabel khusus if_nodes, email_nodes, telegram_nodes, dan sebagainya.

Itulah yang membuat database tetap kecil.

4. EXECUTION — 5 tabel
executions
execution_nodes
execution_data
execution_errors
execution_logs

Relasi:

workflow
   │
   └──< executions
           │
           ├──< execution_nodes
           │       │
           │       └──< execution_data
           │
           ├──< execution_errors
           │
           └──< execution_logs

executions:

id
workflow_id
status
trigger_type
started_at
finished_at
duration
error_message
created_at

execution_nodes:

id
execution_id
node_id
status
started_at
finished_at
input_data
output_data
error_message

Dengan ini kamu bisa membuka:

Executions
────────────────────────

#1829   Success    2.4s
#1828   Success    1.8s
#1827   Failed     4.1s
#1826   Success    2.0s

Kemudian klik:

#1827

Webhook       ✓
HTTP Request  ✓
9Router       ✓
IF            ✓
Telegram      ✕

Ini sudah mendekati konsep execution viewer n8n.

5. TRIGGER — 2 tabel

Tidak perlu tabel untuk setiap jenis trigger.

Cukup:

webhooks
schedules

Manual Trigger tidak membutuhkan tabel.

Misalnya:

workflow_nodes

node_type = manual_trigger

Webhook:

webhooks
----------------
id
workflow_id
path
method
authentication
response_mode
active

Schedule:

schedules
----------------
id
workflow_id
cron
timezone
active
next_run
6. QUEUE — 2 tabel

Untuk awal:

queues
workers

Tapi kalau kamu hanya menjalankan sistem sendiri dengan satu server, bahkan bisa menggunakan Redis tanpa tabel queue.

Arsitekturnya:

CodeIgniter
     ↓
Redis Queue
     ↓
Worker
     ↓
Workflow Engine

Jadi:

MySQL
= data permanen

Redis
= pekerjaan sementara

Ini lebih efisien.

7. AI — cukup 3 tabel

Tidak perlu 10 tabel.

Karena kamu menggunakan 9Router, buat abstraction sederhana:

ai_providers
ai_models
ai_usage

Contoh:

ai_providers
----------------
id
name
base_url
api_key
status
ai_models
----------------
id
provider_id
model_name
context_limit
input_price
output_price
status
ai_usage
----------------
id
workflow_id
execution_id
model
input_tokens
output_tokens
total_tokens
cost
created_at

Tetapi API key 9Router sebaiknya tidak plaintext. Masukkan ke credential system.

Jadi sebenarnya ai_providers bahkan bisa dihilangkan.

Lebih sederhana:

credentials
     ↓
9Router Credential

Kemudian node:

9Router

punya:

credential
model
system_prompt
user_prompt
temperature
max_tokens
response_format

Dengan demikian AI hanya membutuhkan ai_usage jika kamu ingin tracking penggunaan.

8. APP INTEGRATION

Ini bagian yang paling penting untuk modularitas.

Jangan membuat:

telegram
whatsapp
wordpress
gmail
google_sheets

sebagai tabel.

Semuanya adalah Node Module.

Contohnya:

modules/
│
├── 9router/
│
├── telegram/
│
├── wordpress/
│
├── google/
│
├── whatsapp/
│
├── mysql/
│
└── http/

Setiap module mempunyai:

Module.php
Nodes/
Config/
Routes/
Services/

Misalnya:

modules/wordpress/

Module.php

Nodes/
├── CreatePost.php
├── UpdatePost.php
├── GetPost.php
├── DeletePost.php
├── UploadMedia.php
└── GetPosts.php

Services/
└── WordPressService.php

Database tidak perlu berubah ketika kamu menambahkan module baru.

Itu poin penting.

9. Node Registry

Agar CodeIgniter mengetahui node apa saja yang tersedia, buat satu registry.

Misalnya:

NodeRegistry

Saat sistem boot:

Core
 │
 ├── register Manual Trigger
 ├── register Webhook
 ├── register HTTP Request
 ├── register IF
 ├── register Switch
 │
 ├── register 9Router
 │
 ├── register WordPress
 ├── register Telegram
 └── register Google Sheets

Frontend kemudian meminta:

GET /api/nodes

dan mendapatkan:

[
  {
    "type": "http_request",
    "name": "HTTP Request",
    "category": "HTTP"
  },
  {
    "type": "9router",
    "name": "9Router",
    "category": "AI"
  },
  {
    "type": "wordpress_create_post",
    "name": "Create Post",
    "category": "WordPress"
  }
]

Workflow editor tinggal menampilkan node tersebut.

10. Jadi database final saya sarankan

Untuk versi pertama:

01 users
02 workspaces
03 workspace_users

04 workflows
05 workflow_versions
06 workflow_nodes
07 workflow_connections
08 workflow_variables

09 credentials
10 credential_types

11 webhooks
12 schedules

13 executions
14 execution_nodes
15 execution_data
16 execution_errors
17 execution_logs

18 modules

19 settings
20 tags
21 workflow_tags

22 ai_usage

23 binary_files

23 tabel.

Dan sebenarnya untuk MVP yang benar-benar minimal, bisa dipangkas lagi menjadi sekitar 15–18 tabel.

11. Yang tidak perlu dibuat sebagai tabel

Ini penting.

Jangan membuat tabel:

IF
SWITCH
MERGE
LOOP
HTTP
9ROUTER
TELEGRAM
WORDPRESS
GMAIL
MYSQL
JSON
CSV
DATE
CODE

Karena semuanya adalah node.

Strukturnya:

workflow_nodes
        │
        ├── http_request
        ├── if
        ├── switch
        ├── merge
        ├── loop
        ├── 9router
        ├── telegram
        ├── wordpress
        ├── gmail
        └── mysql

Konfigurasi masing-masing ada di:

parameters_json

Contoh node 9Router:

{
  "model": "model-name",
  "system": "You are an SEO expert",
  "prompt": "Analyze {{$json.keyword}}",
  "temperature": 0.7,
  "max_tokens": 2000
}

Contoh HTTP:

{
  "method": "POST",
  "url": "https://example.com/api",
  "headers": {},
  "body": {}
}

Contoh IF:

{
  "conditions": [
    {
      "left": "{{$json.score}}",
      "operator": ">",
      "right": 80
    }
  ]
}

Jadi satu tabel workflow_nodes mampu menampung ratusan jenis node.

12. Arsitektur CodeIgniter 4

Saya akan buat seperti ini:

app/
│
├── Controllers/
│
├── Models/
│
├── Services/
│   │
│   ├── Workflow/
│   │   ├── WorkflowEngine.php
│   │   ├── NodeExecutor.php
│   │   ├── ExecutionManager.php
│   │   └── ExpressionEngine.php
│   │
│   ├── Credential/
│   ├── Queue/
│   └── AI/
│
└── Libraries/

modules/
│
├── Core/
│
├── Trigger/
│
├── Flow/
│
├── Data/
│
├── Http/
│
├── Database/
│
├── AI/
│   └── 9Router/
│
├── Google/
├── WordPress/
├── Telegram/
├── WhatsApp/
└── ...

Frontend:

resources/
└── workflow-editor/

React/Vue
     ↓
Node Editor
     ↓
REST API
     ↓
CodeIgniter 4
     ↓
Workflow Engine
     ↓
Redis
     ↓
Worker
13. Kalau benar-benar ingin "fungsi sama seperti n8n"

Ada tiga bagian yang tidak boleh disepelekan:

Pertama: Node Engine.

Harus bisa menjalankan:

Trigger
→ Node
→ Node
→ Branch
→ Merge
→ Loop
→ Wait
→ Continue
→ Error

Kedua: Expression Engine.

Harus bisa mengambil data node sebelumnya:

{{$json.name}}

{{$json.email}}

{{$node["HTTP Request"].json.title}}

serta operasi data.

Ketiga: Execution Engine.

Harus mendukung:

success
error
retry
continue
stop
wait
resume
timeout
cancel

Kalau tiga ini benar, menambahkan 100–200 integrasi berikutnya relatif mudah karena semuanya hanya menjadi node/module.

Jadi saya justru akan mengubah rencana sebelumnya: jangan membangun 70 tabel dan jangan membangun semua integrasi dulu. Bangun Core Workflow Engine yang kompatibel dengan konsep n8n, kemudian seluruh fungsi lain dipasang sebagai module. Untuk stack kamu, CodeIgniter 4 + MySQL + Redis + React/Vue workflow editor + 9Router sudah cukup sebagai fondasi.