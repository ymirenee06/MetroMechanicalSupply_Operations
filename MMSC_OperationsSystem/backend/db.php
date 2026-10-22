<?php
// One shared PDO connection. Every query in the app uses prepared statements,
// so user input is never pasted into SQL text.

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['CONFIG'];
        $dsn = "mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Keep PHP/MySQL string comparisons on the same collation as mmsc_db.
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Strict mode for this connection, whatever the server's setting is.
        // XAMPP ships with it off; without it, invalid values are saved as blanks instead of rejected.
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function q_all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }

function q_one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function q_val(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

/** Runs $fn inside a transaction: everything is saved together, or nothing is. */
function tx(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Locks item rows so two people cannot take the last unit at the same moment. */
function lock_items(array $itemIds): void
{
    $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
    if (!$itemIds) return;
    $in = implode(',', array_fill(0, count($itemIds), '?'));
    q("SELECT item_id FROM items WHERE item_id IN ($in) FOR UPDATE", $itemIds)->fetchAll();
}

function stock_available(int $itemId): float
{
    $r = q_one("SELECT i.item_type, COALESCE((SELECT SUM(qty_change) FROM stock_movements sm WHERE sm.item_id = i.item_id),0) AS physical_qty,
                        COALESCE((SELECT SUM(ta.qty_out - ta.qty_returned - ta.qty_transferred) FROM tool_assignments ta WHERE ta.item_id = i.item_id AND ta.status IN ('Out','Under Incident')),0) AS checked_out
                 FROM items i WHERE i.item_id = ?", [$itemId]);
    if (!$r) return 0.0;
    return max(0.0, (float)$r['physical_qty'] - ($r['item_type'] === 'Tool/Equipment' ? (float)$r['checked_out'] : 0.0));
}

/** The ONLY way stock changes: add a row to the ledger. */
function add_movement(int $itemId, float $qty, string $type, string $sourceTable, int $sourceId, int $userId): void
{
    q("INSERT INTO stock_movements (item_id, qty_change, movement_type, source_table, source_id, created_by)
       VALUES (?, ?, ?, ?, ?, ?)", [$itemId, $qty, $type, $sourceTable, $sourceId, $userId]);
}
