-- 005: supplier list for the Deliveries and Add item dropdowns.
-- items.supplier and stock_in.supplier keep the supplier's name, so older records are unchanged.
CREATE TABLE IF NOT EXISTS suppliers (
  supplier_id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  contact VARCHAR(50) NULL,
  created_by INT(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id),
  UNIQUE KEY uq_supplier_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO suppliers (name) SELECT DISTINCT TRIM(supplier) FROM items WHERE supplier IS NOT NULL AND TRIM(supplier) <> '';
INSERT IGNORE INTO suppliers (name) SELECT DISTINCT TRIM(supplier) FROM stock_in WHERE supplier IS NOT NULL AND TRIM(supplier) <> '';
