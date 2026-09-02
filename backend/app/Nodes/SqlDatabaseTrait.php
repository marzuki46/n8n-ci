<?php

namespace App\Nodes;

/**
 * Bantuan koneksi + eksekusi query untuk node database (MySQL/PostgreSQL)
 * memakai PDO langsung (bukan DB driver aplikasi).
 */
trait SqlDatabaseTrait
{
    protected function connect(array $cred, string $driver): \PDO
    {
        $host = (string) ($cred['host'] ?? '127.0.0.1');
        $port = (string) ($cred['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
        $user = (string) ($cred['user'] ?? '');
        $pass = (string) ($cred['password'] ?? '');
        $dbname = (string) ($cred['database'] ?? '');
        $timeout = (int) ($cred['timeout'] ?? 10);

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        if ($driver === 'pgsql') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout={$timeout}";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $options[\PDO::ATTR_TIMEOUT] = $timeout;
        }

        return new \PDO($dsn, $user, $pass, $options);
    }

    protected function query(\PDO $pdo, string $sql, array $bind = []): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        return [
            'rows'          => $rows,
            'row_count'     => is_array($rows) ? count($rows) : 0,
            'affected_rows' => $stmt->rowCount(),
        ];
    }

    /**
     * Validasi identifier (nama tabel/kolom) agar hanya karakter aman,
     * mencegah penyusupan nama injeksi lewat quote di dalam nama.
     */
    protected function assertIdentifier(string $name): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \Exception('Nama identifier database tidak valid: "' . $name . '".');
        }
    }
}
