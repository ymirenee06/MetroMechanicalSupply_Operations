<?php
// Objective 1: items, prices, deliveries, and the stock ledger.

class InventoryService
{
    public static function categories(): array
    {
        return q_all("SELECT category_id, name FROM categories ORDER BY name");
    }

    /** Item list with live stock from the v_item_stock view. */
    public static function items(string $search = '', string $filter = '', string $type = ''): array
    {
        $sql = "SELECT s.*, i.selling_price, i.is_sellable, i.allows_decimal, i.image_path, c.name AS category
                FROM v_item_stock s
                JOIN items i ON i.item_id = s.item_id
                LEFT JOIN categories c ON c.category_id = i.category_id
                WHERE 1 = 1";
        $p = [];
        if ($search !== '') {
            $sql .= " AND (s.item_code LIKE ? OR s.name LIKE ? OR i.barcode = ?)";
            array_push($p, "%$search%", "%$search%", $search);
        }
        if ($type === 'tool') $sql .= " AND s.item_type = 'Tool/Equipment' AND i.is_sellable = 0";
        elseif ($type === 'tool_sale') $sql .= " AND s.item_type = 'Tool/Equipment' AND i.is_sellable = 1";
        elseif ($type === 'consumable') $sql .= " AND s.item_type = 'Consumable'";
        $role = current_user()['role'] ?? '';
        if ($filter === 'archived' && $role === 'Executive Officer') $sql .= " AND s.is_archived = 1";
        else {
            $sql .= " AND s.is_archived = 0";
            if ($filter === 'low' && in_array($role, ['Executive Officer','Purchasing Officer','Logistics','Accounting'], true)) $sql .= " AND s.stock_status IN ('Low','Out of Stock')";
        }
        $sql .= " ORDER BY FIELD(s.stock_status,'Out of Stock','Low','Normal'), s.name";
        return q_all($sql, $p);
    }

    public static function item(int $id): array
    {
        $i = q_one("SELECT i.*, c.name AS category, s.qty_available, s.qty_checked_out, s.stock_status
                    FROM items i
                    JOIN v_item_stock s ON s.item_id = i.item_id
                    LEFT JOIN categories c ON c.category_id = i.category_id
                    WHERE i.item_id = ?", [$id]);
        if (!$i) throw new UserError('That item does not exist.');
        return $i;
    }

    /** Every ledger row for one item, with a running balance. */
    public static function history(int $id): array
    {
        $rows = q_all("SELECT sm.*, u.full_name FROM stock_movements sm
                       JOIN users u ON u.user_id = sm.created_by
                       WHERE sm.item_id = ? ORDER BY sm.movement_id", [$id]);
        $bal = 0;
        foreach ($rows as &$r) { $bal += (float)$r['qty_change']; $r['balance'] = $bal; }
        return array_reverse($rows);
    }

    public static function priceHistory(int $id): array
    {
        return q_all("SELECT ph.*, u.full_name FROM price_history ph JOIN users u ON u.user_id = ph.changed_by
                      WHERE ph.item_id = ? ORDER BY ph.price_history_id DESC", [$id]);
    }

    /**
     * Items sold at the counter or billed on jobs (consumables and tools for sale) need a selling price
     * that is not below their unit cost, otherwise every sale loses money. Company tools are never sold,
     * so their selling price may stay at zero.
     */
    public static function checkPrices(string $type, bool $sellable, float $cost, float $price): void
    {
        if ($cost < 0 || $price < 0) throw new UserError('Cost and selling price cannot be negative.');
        $sold = $type === 'Consumable' || $sellable;
        if (!$sold) return;
        if ($price <= 0) throw new UserError('Enter a selling price. This item is sold, so it cannot be priced at ₱0.00.');
        if ($price < $cost) {
            throw new UserError('The selling price (' . money($price) . ') is lower than the unit cost (' . money($cost) . '), so every sale would lose '
                . money($cost - $price) . '. Raise the selling price or correct the unit cost.');
        }
    }

    public static function createItem(array $d, int $userId): int
    {
        if (!has_role('Executive Officer') || uid() !== $userId) throw new UserError('Only an Executive Officer can create catalog items.');
        return tx(function () use ($d, $userId) {
            $code = trim($d['item_code'] ?? '');
            if ($code === '') {
                $n = (int) q_val("SELECT COALESCE(MAX(CAST(SUBSTRING(item_code, 6) AS UNSIGNED)), 0) + 1 FROM items WHERE item_code LIKE 'ITEM-%'");
                $code = sprintf('ITEM-%05d', $n);
            }
            if (trim($d['name'] ?? '') === '') throw new UserError('Enter the item name.');
            $dup = q_one("SELECT item_code FROM items WHERE LOWER(TRIM(name)) = LOWER(?) LIMIT 1", [trim($d['name'])]);
            if ($dup) throw new UserError('An item named "' . trim($d['name']) . '" already exists (' . $dup['item_code'] . '). Record a delivery for it instead of adding it again.');
            if (trim($d['unit_of_measure'] ?? '') === '') throw new UserError('Enter the unit, such as meter, kg, or piece.');
            $type = $d['item_type'] === 'Tool/Equipment' ? 'Tool/Equipment' : 'Consumable';
            self::checkPrices($type, $type === 'Tool/Equipment' && !empty($d['is_sellable']), to_num($d['unit_cost'] ?? 0), to_num($d['selling_price'] ?? 0));
            q("INSERT INTO items (item_code, name, description, category_id, item_type, is_sellable, unit_of_measure,
                                  allows_decimal, reorder_point, unit_cost, selling_price, supplier, brand_origin, storage_location, barcode, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
                $code, trim($d['name']), trim($d['description'] ?? '') ?: null,
                ($d['category_id'] ?? '') !== '' ? (int)$d['category_id'] : null,
                $type, !empty($d['is_sellable']) ? 1 : 0, trim($d['unit_of_measure']),
                !empty($d['allows_decimal']) ? 1 : 0, to_num($d['reorder_point'] ?? 0),
                to_num($d['unit_cost'] ?? 0), to_num($d['selling_price'] ?? 0),
                self::resolveSupplier($d, false), trim($d['brand_origin'] ?? '') ?: null, trim($d['storage_location'] ?? '') ?: null, trim($d['barcode'] ?? '') ?: null, $userId,
            ]);
            $id = (int) db()->lastInsertId();
            q("INSERT INTO price_history (item_id, new_unit_cost, new_selling_price, changed_by) VALUES (?,?,?,?)",
              [$id, to_num($d['unit_cost'] ?? 0), to_num($d['selling_price'] ?? 0), $userId]);
            audit('ITEM_CREATE', 'items', $id, null, ['item_code' => $code, 'name' => trim($d['name'])]);
            return $id;
        });
    }

    /**
     * Saves an uploaded picture for an item (JPG, PNG, or WEBP, up to 3 MB).
     * The file type is checked from the image itself, not from its name.
     */
    public static function saveImage(int $itemId, ?array $file): void
    {
        if (!has_role('Executive Officer', 'Purchasing Officer', 'Logistics')) throw new UserError('You cannot change item pictures.');
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
        if ($file['error'] !== UPLOAD_ERR_OK) throw new UserError('The picture did not upload. Try a smaller file (3 MB or less).');
        if ($file['size'] > 3 * 1024 * 1024) throw new UserError('The picture is too large. Use a file of 3 MB or less.');
        $info = @getimagesize($file['tmp_name']);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$info || !$ext) throw new UserError('Upload a JPG, PNG, or WEBP picture.');
        $dir = dirname(__DIR__, 2) . '/uploads/items';
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new UserError('The uploads/items folder could not be created.');
        $name = 'item_' . $itemId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new UserError('The picture could not be saved.');
        $old = q_val("SELECT image_path FROM items WHERE item_id = ?", [$itemId]);
        q("UPDATE items SET image_path = ? WHERE item_id = ?", ['uploads/items/' . $name, $itemId]);
        if ($old && str_starts_with($old, 'uploads/items/') && is_file(dirname(__DIR__, 2) . '/' . $old)) @unlink(dirname(__DIR__, 2) . '/' . $old);
        audit('ITEM_IMAGE', 'items', $itemId, ['image_path' => $old], ['image_path' => 'uploads/items/' . $name]);
    }

    /** Removes an item's picture (the list and item page go back to "No picture yet"). */
    public static function removeImage(int $itemId): void
    {
        if (!has_role('Executive Officer', 'Purchasing Officer', 'Logistics')) throw new UserError('You cannot change item pictures.');
        $old = q_val("SELECT image_path FROM items WHERE item_id = ?", [$itemId]);
        if (!$old) throw new UserError('This item has no picture to remove.');
        q("UPDATE items SET image_path = NULL WHERE item_id = ?", [$itemId]);
        if (str_starts_with($old, 'uploads/items/') && is_file(dirname(__DIR__, 2) . '/' . $old)) @unlink(dirname(__DIR__, 2) . '/' . $old);
        audit('ITEM_IMAGE_REMOVE', 'items', $itemId, ['image_path' => $old], ['image_path' => null]);
    }

    /** Price changes are kept in price_history; the old price is never lost. */
    public static function updatePrices(int $id, float $cost, float $price, int $reorder, int $userId): void
    {
        if (!has_role('Executive Officer') || uid() !== $userId) throw new UserError('Only an Executive Officer can change item prices.');
        tx(function () use ($id, $cost, $price, $reorder, $userId) {
            if ($cost < 0 || $price < 0 || $reorder < 0) throw new UserError('Cost, selling price, and reorder point cannot be negative.');
            $old = q_one("SELECT unit_cost, selling_price, reorder_point, item_type, is_sellable FROM items WHERE item_id = ? FOR UPDATE", [$id]);
            self::checkPrices($old['item_type'], (bool)$old['is_sellable'], $cost, $price);
            if ((float)$old['unit_cost'] != $cost || (float)$old['selling_price'] != $price) {
                q("INSERT INTO price_history (item_id, old_unit_cost, new_unit_cost, old_selling_price, new_selling_price, changed_by)
                   VALUES (?,?,?,?,?,?)", [$id, $old['unit_cost'], $cost, $old['selling_price'], $price, $userId]);
            }
            q("UPDATE items SET unit_cost = ?, selling_price = ?, reorder_point = ? WHERE item_id = ?", [$cost, $price, $reorder, $id]);
            audit('PRICE_CHANGE', 'items', $id, $old, ['unit_cost' => $cost, 'selling_price' => $price, 'reorder_point' => $reorder]);
        });
    }

    /** Switches a tool between company equipment (lent) and merchandise (sold). */
    public static function setToolUsage(int $id, bool $forSale, int $userId): void
    {
        if (!has_role('Executive Officer') || uid() !== $userId) throw new UserError('Only an Executive Officer can change how a tool is used.');
        $i = self::item($id);
        if ($i['item_type'] !== 'Tool/Equipment') throw new UserError('Only tools have this setting.');
        if ($forSale && (float)$i['qty_checked_out'] > 0) throw new UserError('Some units are still with technicians. Have them returned before making this tool merchandise.');
        if ($forSale && (float)$i['selling_price'] <= 0) throw new UserError('Set a selling price first, then mark the tool for sale.');
        if ($forSale && (float)$i['selling_price'] < (float)$i['unit_cost']) throw new UserError('The selling price (' . money($i['selling_price']) . ') is lower than the unit cost (' . money($i['unit_cost']) . '). Raise the selling price first, then mark the tool for sale.');
        q("UPDATE items SET is_sellable = ? WHERE item_id = ?", [$forSale ? 1 : 0, $id]);
        audit('TOOL_USAGE_CHANGE', 'items', $id, ['is_sellable' => (int)$i['is_sellable']], ['is_sellable' => $forSale ? 1 : 0]);
    }

    public static function setArchived(int $id, bool $archived): void
    {
        if (!has_role('Executive Officer')) throw new UserError('Only an Executive Officer can archive or restore catalog items.');
        if ($archived) {
            if (stock_available($id) > 0) throw new UserError('An item can only be archived when Qty Available is zero.');
            $checked = (float)q_val("SELECT COALESCE(SUM(qty_held),0) FROM v_tool_holdings WHERE item_id = ?", [$id]);
            if ($checked > 0) throw new UserError('Return all checked-out quantity before archiving this item.');
        }
        q("UPDATE items SET is_archived = ? WHERE item_id = ?", [$archived ? 1 : 0, $id]);
        audit($archived ? 'ITEM_ARCHIVE' : 'ITEM_RESTORE', 'items', $id);
    }

    /** Flow 1.3: Purchasing or Logistics records an incoming delivery. Nothing is added to stock yet. */
    public static function encodeStockIn(array $d, int $userId): int
    {
        if (!has_role('Purchasing Officer', 'Logistics', 'Executive Officer') || uid() !== $userId) throw new UserError('Only Purchasing, Logistics, or an Executive Officer can record incoming deliveries.');
        $item = self::item((int)($d['item_id'] ?? 0));
        $q = to_num($d['qty_received'] ?? 0);
        if ($q <= 0) throw new UserError('Enter how many actually arrived.');
        require_whole($q, $item);
        if (trim($d['dr_po_number'] ?? '') === '') throw new UserError('Enter the delivery receipt or PO number.');
        $receiver = (int)($d['received_by'] ?? 0);
        if (!$receiver || !q_val("SELECT 1 FROM users WHERE user_id = ? AND status = 'Active' AND role IN ('Purchasing Officer','Logistics','Executive Officer')", [$receiver])) {
            throw new UserError('Choose who physically received the delivery.');
        }
        $date = ($d['date_received'] ?? '') ?: today();
        if ($date > today()) throw new UserError('The delivery date cannot be in the future.');
        // User-entered deliveries are always normal stock-in transactions.
        // The legacy is_beginning_inventory column is retained only for historical
        // migrated opening balances already present in the seed data.
        q("INSERT INTO stock_in (item_id, qty_received, dr_qty_text, supplier, dr_po_number, date_received, is_beginning_inventory, received_by, encoded_by)
           VALUES (?,?,?,?,?,?,0,?,?)", [
            $item['item_id'], $q, trim($d['dr_qty_text'] ?? '') ?: null, self::resolveSupplier($d),
            trim($d['dr_po_number']), $date, $receiver, $userId,
        ]);
        $id = (int) db()->lastInsertId();
        audit('STOCK_IN_ENCODE', 'stock_in', $id, null, ['item' => $item['item_code'], 'qty' => $q, 'received_by' => $receiver]);
        return $id;
    }

    public static function suppliers(): array
    {
        return q_all("SELECT supplier_id, name, contact FROM suppliers ORDER BY name");
    }

    /** Supplier chosen from the list, or added on the spot ("new"). Returns the supplier name. */
    public static function resolveSupplier(array $d, bool $required = true): ?string
    {
        $choice = (string)($d['supplier_id'] ?? '');
        if ($choice === 'new') {
            $name = trim($d['new_supplier_name'] ?? '');
            if ($name === '') throw new UserError('Type the new supplier\'s name, or choose one from the list.');
            if (mb_strlen($name) > 100) throw new UserError('The supplier name is too long (100 characters at most).');
            if (!q_val("SELECT 1 FROM suppliers WHERE name = ?", [$name])) {
                q("INSERT INTO suppliers (name, contact, created_by) VALUES (?,?,?)", [$name, trim($d['new_supplier_contact'] ?? '') ?: null, uid()]);
                audit('SUPPLIER_CREATE', 'suppliers', (int) db()->lastInsertId(), null, ['name' => $name]);
            }
            return $name;
        }
        if ($choice !== '' && ctype_digit($choice)) {
            $name = q_val("SELECT name FROM suppliers WHERE supplier_id = ?", [(int)$choice]);
            if (!$name) throw new UserError('That supplier no longer exists.');
            return $name;
        }
        if ($required) throw new UserError('Choose the supplier, or add a new one.');
        return null;
    }

    /** People who can physically receive a delivery at the stockroom. */
    public static function receivers(): array
    {
        return q_all("SELECT user_id, CONCAT(full_name, ' (', role, ')') AS label FROM users
                      WHERE status = 'Active' AND role IN ('Purchasing Officer','Logistics','Executive Officer') ORDER BY full_name");
    }

    public static function stockIns(string $status = ''): array
    {
        $sql = "SELECT si.*, i.item_code, i.name, i.item_type, i.is_sellable, i.unit_of_measure, r.full_name AS received_name, en.full_name AS encoded_name, v.full_name AS verified_name
                FROM stock_in si JOIN items i ON i.item_id = si.item_id
                JOIN users r ON r.user_id = si.received_by JOIN users en ON en.user_id = si.encoded_by LEFT JOIN users v ON v.user_id = si.verified_by";
        $p = [];
        if ($status !== '') { $sql .= " WHERE si.status = ?"; $p[] = $status; }
        return q_all($sql . " ORDER BY si.stock_in_id DESC LIMIT 100", $p);
    }

    /** An Executive Officer checks the entry against the paper delivery receipt. Posting is what adds stock. */
    public static function verifyStockIn(int $id, bool $post, string $reason, int $adminId): void
    {
        if (!has_role('Executive Officer') || uid() !== $adminId) throw new UserError('Only an Executive Officer can verify incoming deliveries.');
        tx(function () use ($id, $post, $reason, $adminId) {
            $si = q_one("SELECT * FROM stock_in WHERE stock_in_id = ? FOR UPDATE", [$id]);
            if (!$si || $si['status'] !== 'Pending Verification') throw new UserError('This entry was already verified.');
            if ((int)$si['encoded_by'] === $adminId || (int)$si['received_by'] === $adminId) throw new UserError('A different Executive Officer must verify this delivery. You recorded or received it yourself.');
            if (!$post && !reason_ok($reason)) throw new UserError(reason_msg('Give a reason for rejecting it.'));
            q("UPDATE stock_in SET status = ?, rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE stock_in_id = ?",
              [$post ? 'Posted' : 'Rejected', $post ? null : $reason, $adminId, $id]);
            if ($post) add_movement((int)$si['item_id'], (float)$si['qty_received'], 'Stock In', 'stock_in', $id, $adminId);
            audit($post ? 'STOCK_IN_POST' : 'STOCK_IN_REJECT', 'stock_in', $id, null, ['reason' => $reason ?: null]);
        });
    }
}
