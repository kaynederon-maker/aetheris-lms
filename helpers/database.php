<?php
/**
 * Database: MySQL. Every query in the app goes through these helpers,
 * which always use prepared statements (the values are sent separately from the SQL, so they
 * can never change the query).
 *
 * Connection settings come from environment variables (docker-compose.yml sets them).
 * The defaults match a fresh XAMPP install: user "root", no password, database "aetheris".
 */

function db_setting(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $host = db_setting('DB_HOST', '127.0.0.1');
    $port = db_setting('DB_PORT', '3306');
    $name = db_setting('DB_NAME', 'aetheris');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'", // store and compare dates in UTC
    ];
    $connect = fn (string $dsn) => new PDO($dsn, db_setting('DB_USER', 'root'), db_setting('DB_PASS', ''), $options);
    try {
        $pdo = $connect("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4");
    } catch (PDOException $err) {
        if ((int) ($err->errorInfo[1] ?? 0) !== 1049) { // 1049 = database does not exist yet
            throw $err;
        }
        // First run on XAMPP: create the database. (In Docker, MySQL creates it from docker-compose.yml.)
        $server = $connect("mysql:host=$host;port=$port;charset=utf8mb4");
        $server->exec('CREATE DATABASE `' . sql_identifier($name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = $connect("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4");
    }
    if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) {
        $pdo->exec(file_get_contents(ROOT . '/database/schema.sql')); // no tables yet: create them
    }
    return $pdo;
}

function query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** First matching row, or null. */
function one(string $sql, array $params = []): ?array
{
    return query($sql, $params)->fetch() ?: null;
}

function all(string $sql, array $params = []): array
{
    return query($sql, $params)->fetchAll();
}

/** A single value, e.g. a COUNT(*). */
function value(string $sql, array $params = []): mixed
{
    return query($sql, $params)->fetchColumn();
}

function sql_identifier(string $name): string
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
        throw new InvalidArgumentException("Invalid SQL identifier: $name");
    }
    return $name;
}

/** Insert a row and return its new id. Table and column names only ever come from our own code. */
function insert(string $table, array $row): int
{
    $table = sql_identifier($table);
    $cols = array_map('sql_identifier', array_keys($row));
    $sql = "INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    query($sql, array_values($row));
    return (int) db()->lastInsertId();
}

function update(string $table, int $id, array $row): void
{
    $table = sql_identifier($table);
    $cols = array_map('sql_identifier', array_keys($row));
    $set = implode(', ', array_map(fn ($c) => "$c = ?", $cols));
    query("UPDATE $table SET $set WHERE id = ?", [...array_values($row), $id]);
}

/** Current time in UTC, in the format we store. */
function now(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

/** Turn a stored UTC date into a Unix timestamp. */
function ts(?string $utc): int
{
    return $utc ? strtotime($utc . ' UTC') : 0;
}
