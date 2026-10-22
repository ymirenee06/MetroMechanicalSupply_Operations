-- 004: item pictures, separate Received By / Encoded By on deliveries,
-- required items per job, customer list, and request status "Released".
-- Safe to run once on an existing mmsc_db (php tools/migrate.php).

-- Item pictures (path relative to the app folder, e.g. uploads/items/item_12_ab12cd.jpg)
ALTER TABLE items ADD COLUMN image_path VARCHAR(255) NULL AFTER barcode;

-- Deliveries: received_by = the employee who physically received the delivery,
-- encoded_by = the user who typed it into the system.
ALTER TABLE stock_in ADD COLUMN encoded_by INT(11) NULL AFTER received_by;
UPDATE stock_in SET encoded_by = received_by WHERE encoded_by IS NULL;
ALTER TABLE stock_in MODIFY encoded_by INT(11) NOT NULL;
ALTER TABLE stock_in ADD CONSTRAINT fk_stock_in_encoded_by FOREIGN KEY (encoded_by) REFERENCES users (user_id);

-- Additional-item requests: mark when Logistics has released them.
ALTER TABLE item_requests MODIFY status ENUM('Pending','Approved','Denied','Cancelled','Released') NOT NULL DEFAULT 'Pending';
UPDATE item_requests r JOIN releases rl ON rl.request_id = r.request_id SET r.status = 'Released' WHERE r.status = 'Approved';

-- Required items planned when the job is registered (used for the estimate and to pre-fill releases).
CREATE TABLE IF NOT EXISTS job_items (
  job_item_id INT(11) NOT NULL AUTO_INCREMENT,
  job_id INT(11) NOT NULL,
  item_id INT(11) NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  added_by INT(11) NOT NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (job_item_id),
  UNIQUE KEY uq_job_item (job_id, item_id),
  KEY fk_job_items_item (item_id),
  CONSTRAINT fk_job_items_job FOREIGN KEY (job_id) REFERENCES jobs (job_id),
  CONSTRAINT fk_job_items_item FOREIGN KEY (item_id) REFERENCES items (item_id),
  CONSTRAINT fk_job_items_user FOREIGN KEY (added_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer list (counter sale dropdown and job registration).
CREATE TABLE IF NOT EXISTS customers (
  customer_id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  contact VARCHAR(50) NULL,
  address VARCHAR(255) NULL,
  customer_type ENUM('Walk-in','Contractor','Business','Residential') NOT NULL DEFAULT 'Business',
  created_by INT(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id),
  UNIQUE KEY uq_customer_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO customers (name, customer_type) SELECT DISTINCT client_name, 'Business' FROM jobs;
INSERT IGNORE INTO customers (name, contact, customer_type)
  SELECT customer_name, MAX(customer_contact), 'Walk-in' FROM sales WHERE customer_name <> 'Walk-in Customer' GROUP BY customer_name;

ALTER TABLE sales ADD COLUMN customer_id INT(11) NULL AFTER job_id;
ALTER TABLE sales ADD CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id);
UPDATE sales s JOIN customers c ON c.name = s.customer_name SET s.customer_id = c.customer_id;

ALTER TABLE jobs ADD COLUMN customer_id INT(11) NULL AFTER client_name;
ALTER TABLE jobs ADD CONSTRAINT fk_jobs_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id);
UPDATE jobs j JOIN customers c ON c.name = j.client_name SET j.customer_id = c.customer_id;
