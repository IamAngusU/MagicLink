<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use PDO;
use RuntimeException;

final class Database
{
    private const SCHEMA_VERSION = 4;

    private function __construct(private PDO $pdo, private string $driver) {}

    public static function connect(Config $config): self
    {
        $driver = $config->string('DB_DRIVER');
        if ($driver === 'sqlite') {
            $path = $config->string('DB_PATH', 'storage/database.sqlite');
            if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
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
            if (!extension_loaded('mysqlnd')) {
                throw new RuntimeException('MySQL deployments require the fail-closed mysqlnd PDO backend.');
            }
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config->string('DB_HOST', '127.0.0.1'),
                $config->int('DB_PORT', 3306),
                $config->string('DB_DATABASE')
            );
            $options = [];
            $sslMode = $config->string('DB_SSL_MODE', 'auto');
            $sslCa = $config->string('DB_SSL_CA');
            if ($sslMode === 'auto') {
                $sslMode = $sslCa === '' ? 'disabled' : 'verify_identity';
            }
            $tlsRequired = $sslMode === 'verify_identity';
            if ($tlsRequired) {
                if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $sslCa)) {
                    $sslCa = $config->root() . '/' . ltrim($sslCa, '/\\');
                }
                if (!is_file($sslCa) || !is_readable($sslCa)) {
                    throw new RuntimeException('DB_SSL_CA must point to a readable CA certificate.');
                }
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
                if (!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    throw new RuntimeException('This PDO MySQL build cannot verify the database server identity.');
                }
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
            $pdo = new PDO($dsn, $config->string('DB_USERNAME'), $config->string('DB_PASSWORD'), $options);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        if ($driver === 'mysql' && ($tlsRequired ?? false)) {
            $tls = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch();
            if (!is_array($tls) || trim((string) ($tls['Value'] ?? '')) === '') {
                throw new RuntimeException('MySQL did not negotiate the required TLS connection.');
            }
        }
        return new self($pdo, $driver);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function ensureSchema(): void
    {
        try {
            $statement = $this->pdo->query("SELECT value FROM schema_meta WHERE name = 'schema_version' LIMIT 1");
            if ((int) $statement->fetchColumn() >= self::SCHEMA_VERSION) {
                return;
            }
        } catch (\PDOException) {
            // A fresh or v1 installation has no schema_meta table yet.
        }
        $this->migrate();
    }

    public function migrate(): void
    {
        if ($this->driver !== 'mysql') {
            $this->migrateUnlocked();
            return;
        }

        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'magiclink-schema-' . substr(hash('sha256', $database), 0, 32);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the MySQL schema migration lock.');
        }
        try {
            $this->migrateUnlocked();
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private function migrateUnlocked(): void
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
        $this->createIndex('idx_magic_links_expiry', 'magic_links', 'expires_at, id');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id {$id},
            bucket CHAR(64) NOT NULL,
            created_at BIGINT NOT NULL
        )");
        $this->createIndex('idx_rate_limits_bucket', 'rate_limits', 'bucket, created_at');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rate_limit_counters (
            id {$id},
            bucket CHAR(64) NOT NULL,
            window_started BIGINT NOT NULL,
            hits BIGINT NOT NULL DEFAULT 0,
            expires_at BIGINT NOT NULL,
            UNIQUE(bucket, window_started)
        )");
        $this->createIndex('idx_rate_limit_counters_expiry', 'rate_limit_counters', 'expires_at');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS audit_events (
            id {$id},
            event_type VARCHAR(80) NOT NULL,
            subject_hash CHAR(64) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            metadata_json {$text} NOT NULL,
            created_at BIGINT NOT NULL
        )");
        $this->createIndex('idx_audit_events_created', 'audit_events', 'created_at');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS mail_outbox (
            id {$id},
            selector VARCHAR(64) NOT NULL UNIQUE,
            deliverable SMALLINT NOT NULL DEFAULT 0,
            payload_cipher {$text} NOT NULL,
            subject_hash CHAR(64) NOT NULL,
            request_ip_hash CHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL,
            attempts BIGINT NOT NULL DEFAULT 0,
            available_at BIGINT NOT NULL,
            lock_token VARCHAR(64) NULL,
            locked_at BIGINT NULL,
            sent_at BIGINT NULL,
            last_error_hash CHAR(64) NULL,
            created_at BIGINT NOT NULL
        )");
        $this->createIndex('idx_mail_outbox_ready', 'mail_outbox', 'status, available_at, id');
        $this->createIndex('idx_mail_outbox_subject_status', 'mail_outbox', 'subject_hash, status');
        $this->createIndex('idx_mail_outbox_retention', 'mail_outbox', 'status, created_at, id');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS auth_handoffs (
            id {$id},
            request_hash CHAR(64) NOT NULL UNIQUE,
            state_cipher {$text} NOT NULL,
            redirect_uri_hash CHAR(64) NOT NULL,
            pkce_challenge CHAR(43) NOT NULL,
            session_binding CHAR(64) NULL,
            code_hash CHAR(64) NULL UNIQUE,
            code_cipher {$text} NULL,
            email_cipher {$text} NULL,
            subject_hash CHAR(64) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at BIGINT NOT NULL,
            authorized_at BIGINT NULL,
            code_expires_at BIGINT NULL,
            consumed_at BIGINT NULL,
            created_at BIGINT NOT NULL
        )");
        $this->createIndex('idx_auth_handoffs_expiry', 'auth_handoffs', 'expires_at, id');
        $this->createIndex('idx_auth_handoffs_status_expiry', 'auth_handoffs', 'status, expires_at, id');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_state (
            name VARCHAR(64) PRIMARY KEY,
            last_run BIGINT NOT NULL
        )");
        $this->insertIgnore('maintenance_state', ['name' => 'cleanup', 'last_run' => 0]);
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (
            name VARCHAR(64) PRIMARY KEY,
            value VARCHAR(255) NOT NULL
        )");
        $this->upsertSchemaVersion();
    }

    /** @param array<string,string|int> $values */
    private function insertIgnore(string $table, array $values): void
    {
        $columns = implode(',', array_keys($values));
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $prefix = $this->driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $statement = $this->pdo->prepare("{$prefix} INTO {$table}({$columns}) VALUES({$placeholders})");
        $statement->execute(array_values($values));
    }

    private function upsertSchemaVersion(): void
    {
        if ($this->driver === 'sqlite') {
            $statement = $this->pdo->prepare("INSERT INTO schema_meta(name,value) VALUES('schema_version',?) ON CONFLICT(name) DO UPDATE SET value = excluded.value");
        } else {
            $statement = $this->pdo->prepare("INSERT INTO schema_meta(name,value) VALUES('schema_version',?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        }
        $statement->execute([(string) self::SCHEMA_VERSION]);
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
