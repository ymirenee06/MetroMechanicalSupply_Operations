<?php
// Saved customers for the counter-sale dropdown and job registration.

class CustomerService
{
    public static function all(): array
    {
        return q_all("SELECT c.*,
                        (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.customer_id AND s.status <> 'Voided') AS sale_count,
                        (SELECT COUNT(*) FROM jobs j WHERE j.customer_id = c.customer_id) AS job_count,
                        (SELECT COUNT(*) FROM sales s WHERE s.customer_id = c.customer_id) AS any_sale_count
                      FROM customers c ORDER BY c.is_active DESC, c.name");
    }

    public static function find(int $id): ?array
    {
        return q_one("SELECT * FROM customers WHERE customer_id = ?", [$id]);
    }

    /**
     * Returns [customer_id, name, contact] for a form that has either a chosen
     * customer (customer_id) or a newly typed one (new_customer_name).
     * Walk-in sales return [null, 'Walk-in Customer', contact].
     */
    public static function resolve(array $d, bool $allowWalkIn = true): array
    {
        $choice = (string)($d['customer_id'] ?? '');
        if ($choice === 'new') {
            $name = trim($d['new_customer_name'] ?? '');
            if ($name === '') throw new UserError('Type the new customer\'s name, or choose one from the list.');
            $contact = trim($d['new_customer_contact'] ?? '') ?: null;
            $id = self::create($name, $contact, trim($d['new_customer_address'] ?? '') ?: null, $d['new_customer_type'] ?? 'Business');
            return [$id, $name, $contact];
        }
        if ($choice !== '' && ctype_digit($choice)) {
            $c = self::find((int)$choice);
            if (!$c) throw new UserError('That customer no longer exists.');
            if (!(int)$c['is_active']) throw new UserError($c['name'] . ' has been deactivated. Choose another customer, or ask an Executive Officer to reactivate them under Customers.');
            return [(int)$c['customer_id'], $c['name'], $c['contact']];
        }
        if (!$allowWalkIn) throw new UserError('Choose the client, or add them as a new customer.');
        return [null, 'Walk-in Customer', trim($d['customer_contact'] ?? '') ?: null];
    }

    public static function create(string $name, ?string $contact, ?string $address, string $type = 'Business'): int
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'Walk-in Customer') === 0) throw new UserError('Enter the customer\'s name.');
        if (mb_strlen($name) > 100) throw new UserError('The customer name is too long (100 characters at most).');
        if (!has_text($name)) throw new UserError('Enter a real customer name (letters or numbers, not just symbols).');
        $type = in_array($type, ['Walk-in', 'Contractor', 'Business', 'Residential'], true) ? $type : 'Business';
        $existing = q_one("SELECT customer_id, is_active FROM customers WHERE name = ?", [$name]);
        if ($existing) {
            // Adding a name that was deactivated brings the same customer (and their history) back.
            if (!(int)$existing['is_active']) self::setActive((int)$existing['customer_id'], true);
            return (int)$existing['customer_id'];
        }
        q("INSERT INTO customers (name, contact, address, customer_type, created_by) VALUES (?,?,?,?,?)", [$name, $contact, $address, $type, uid()]);
        $id = (int) db()->lastInsertId();
        audit('CUSTOMER_CREATE', 'customers', $id, null, ['name' => $name]);
        return $id;
    }

    public static function update(int $id, array $d): void
    {
        $c = self::find($id);
        if (!$c) throw new UserError('That customer does not exist.');
        $name = trim($d['name'] ?? '');
        if ($name === '') throw new UserError('Enter the customer\'s name.');
        $type = in_array($d['customer_type'] ?? '', ['Walk-in', 'Contractor', 'Business', 'Residential'], true) ? $d['customer_type'] : $c['customer_type'];
        q("UPDATE customers SET name = ?, contact = ?, address = ?, customer_type = ? WHERE customer_id = ?",
          [$name, trim($d['contact'] ?? '') ?: null, trim($d['address'] ?? '') ?: null, $type, $id]);
        audit('CUSTOMER_UPDATE', 'customers', $id, $c, ['name' => $name]);
    }

    /** Customers with sales or jobs are deactivated (hidden from dropdowns, history kept). */
    public static function setActive(int $id, bool $active): void
    {
        $c = self::find($id);
        if (!$c) throw new UserError('That customer does not exist.');
        q("UPDATE customers SET is_active = ? WHERE customer_id = ?", [$active ? 1 : 0, $id]);
        audit($active ? 'CUSTOMER_REACTIVATE' : 'CUSTOMER_DEACTIVATE', 'customers', $id, ['is_active' => (int)$c['is_active']], ['is_active' => $active ? 1 : 0]);
    }

    /** Only a customer with no sales (even voided ones) and no jobs can be deleted; everyone else is deactivated. */
    public static function delete(int $id): void
    {
        tx(function () use ($id) {
            $c = q_one("SELECT * FROM customers WHERE customer_id = ? FOR UPDATE", [$id]);
            if (!$c) throw new UserError('That customer does not exist.');
            $sales = (int) q_val("SELECT COUNT(*) FROM sales WHERE customer_id = ?", [$id]);
            $jobs = (int) q_val("SELECT COUNT(*) FROM jobs WHERE customer_id = ?", [$id]);
            if ($sales || $jobs) throw new UserError($c['name'] . ' has ' . $sales . ' sale(s) and ' . $jobs . ' job(s), so they cannot be deleted. Deactivate them instead; their history is kept.');
            q("DELETE FROM customers WHERE customer_id = ?", [$id]);
            audit('CUSTOMER_DELETE', 'customers', $id, $c, null);
        });
    }

    /** <option> list used by the customer pickers. */
    public static function options(?string $selected = '', bool $walkIn = true): string
    {
        $h = $walkIn ? '<option value="">Walk-in Customer</option>' : '<option value="">Choose a client</option>';
        foreach (self::all() as $c) {
            if (!(int)$c['is_active'] && (string)$selected !== (string)$c['customer_id']) continue;   // deactivated customers are hidden
            $h .= '<option value="' . e($c['customer_id']) . '" data-contact="' . e($c['contact'] ?? '') . '"'
                . ((string)$selected === (string)$c['customer_id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
        }
        $h .= '<option value="new"' . ($selected === 'new' ? ' selected' : '') . '>+ Add a new customer…</option>';
        return $h;
    }
}
