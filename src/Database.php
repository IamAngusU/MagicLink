<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use PDO;
use RuntimeException;

final class Database
{
    private function __construct(private PDO $pdo, private string $driver) {}

    public static function connect(Config $config): self
    {
        $driver = $config->string('DB_DRIVER');
        if ($driver === 'sqlite') {
            $path = $config->string('DB_PATH', 'storage/database.sqlite');
            if (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $path)) {
                $path = $config->root() . '/' . ltrim($path, '/\\');
            }
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('SQLite directory could not be created.');
            }
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config->string('DB_HOST', '127.0.0.1'),
                $config->int('DB_PORT', 3306),
                $config->string('DB_DATABASE')
            );
            $pdo = new PDO($dsn, $config->string('DB_USERNAME'), $config->string('DB_PASSWORD'));
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return new self($pdo, $driver);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(): void
    {
        $id = $this->driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT';
        $text = 'TEXT';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS magic_links (
            id {$id},
            selector VARCHAR(64) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL,
            email_lookup CHAR(64) NOT NULL,
            email_cipher {$text} NOT NULL,
            session_binding CHAR(64) NOT NULL,
            request_ip_hash CHAR(64) NOT NULL,
            deliverable SMALLINT NOT NULL DEFAULT 0,
            expires_at BIGINT NOT NULL,
            consumed_at BIGINT NULL,
            verified_at BIGINT NULL,
            created_at BIGINT NOT NULL
        )");
        $this->ensureColumn('magic_links', 'verified_at', 'BIGINT NULL');
        $this->createIndex('idx_magic_links_email', 'magic_links', 'email_lookup, created_at');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id {$id},
            bucket CHAR(64) NOT NULL,
            created_at BIGINT NOT NULL
        )");
        $this->createIndex('idx_rate_limits_bucket', 'rate_limits', 'bucket, created_at');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS audit_events (
            id {$id},
            event_type VARCHAR(80) NOT NULL,
            subject_hash CHAR(64) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            metadata_json {$text} NOT NULL,
            created_at BIGINT NOT NULL
        )");
        $this->createIndex('idx_audit_events_created', 'audit_events', 'created_at');
    }

    private function createIndex(string $name, string $table, string $columns): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table}({$columns})");
            return;
        }
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
        $statement->execute([$table, $name]);
        if ((int) $statement->fetchColumn() === 0) {
            $this->pdo->exec("CREATE INDEX {$name} ON {$table}({$columns})");
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        if ($this->driver === 'sqlite') {
            $columns = $this->pdo->query("PRAGMA table_info({$table})")->fetchAll();
            foreach ($columns as $existing) {
                if (($existing['name'] ?? null) === $column) {
                    return;
                }
            }
        } else {
            $statement = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
            $statement->execute([$table, $column]);
            if ((int) $statement->fetchColumn() > 0) {
                return;
            }
        }
        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}
