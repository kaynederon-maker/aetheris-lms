<?php
/**
 * DATABASE: one SQLite file in storage/. Every query in the app goes through these helpers,
 * which always use prepared statements (the values are sent separately from the SQL, so they
 * can never change the query).
 */

const DB_FILE = ROOT . '/storage/aetheris.sqlite';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $isNew = !file_exists(DB_FILE);
    if (!is_dir(dirname(DB_FILE))) {
        mkdir(dirname(DB_FILE), 0775, true);
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if ($isNew) {
        $pdo->exec(file_get_contents(ROOT . '/database/schema.sql'));
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

/** Insert a row and return its new id. Table and column names only ever come from our own code. */
function insert(string $table, array $row): int
{
    $cols = array_keys($row);
    $sql = "INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    query($sql, array_values($row));
    return (int) db()->lastInsertId();
}

function update(string $table, int $id, array $row): void
{
    $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($row)));
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
