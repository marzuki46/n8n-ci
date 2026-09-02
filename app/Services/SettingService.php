<?php

namespace App\Services;

/**
 * Setting global aplikasi (tabel settings: key/value).
 * Dipakai antara lain untuk custom login path.
 */
class SettingService
{
    protected $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->db->table('settings')->where('key', $key)->get()->getRowArray();

        return $row ? (string) $row['value'] : $default;
    }

    public function set(string $key, string $value): void
    {
        $exists = $this->db->table('settings')->where('key', $key)->countAllResults();
        $now    = date('Y-m-d H:i:s');

        if ($exists) {
            $this->db->table('settings')->where('key', $key)->update([
                'value'      => $value,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->db->table('settings')->insert([
            'key'        => $key,
            'value'      => $value,
            'type'       => 'string',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Slug path login kustom. '' berarti pakai default.
     * Valid: 4-64 karakter, huruf/angka/dash/underscore.
     */
    public function getLoginSlug(): string
    {
        return (string) ($this->get('login_slug', '') ?? '');
    }

    public function setLoginSlug(string $slug): bool
    {
        $slug = trim(strtolower($slug), "/ \t\n");

        if ($slug === '') {
            $this->set('login_slug', '');

            return true;
        }

        if (! preg_match('/^[a-z0-9_-]{4,64}$/', $slug)) {
            return false;
        }

        $this->set('login_slug', $slug);

        return true;
    }
}
