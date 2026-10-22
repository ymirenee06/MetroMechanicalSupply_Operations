-- 006: job templates. A template is a starting list of required items (and a usual service fee)
-- for a kind of job. Picking one on Register job fills the rows; everything stays editable.
CREATE TABLE IF NOT EXISTS job_templates (
  template_id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  service_type ENUM('Installation','Repair','Maintenance','Other') NOT NULL,
  default_service_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  notes VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (template_id),
  UNIQUE KEY uq_template_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_template_items (
  template_item_id INT(11) NOT NULL AUTO_INCREMENT,
  template_id INT(11) NOT NULL,
  item_id INT(11) NOT NULL,
  qty DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (template_item_id),
  UNIQUE KEY uq_template_item (template_id, item_id),
  KEY fk_jti_item (item_id),
  CONSTRAINT fk_jti_template FOREIGN KEY (template_id) REFERENCES job_templates (template_id) ON DELETE CASCADE,
  CONSTRAINT fk_jti_item FOREIGN KEY (item_id) REFERENCES items (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Starter templates built from the existing catalog (matched by item code; missing codes are skipped).
INSERT IGNORE INTO job_templates (name, service_type, default_service_fee, notes) VALUES
 ('Split-type aircon installation', 'Installation', 8500.00, 'Indoor/outdoor unit set-up with copper line set, insulation and refrigerant top-up.'),
 ('Aircon preventive maintenance', 'Maintenance', 2500.00, 'Cleaning, leak check and refrigerant top-up.'),
 ('Fire sprinkler line extension', 'Installation', 15000.00, 'Additional sprinkler heads with isolation valve.'),
 ('Electrical panel board installation', 'Installation', 12000.00, 'Distribution panel with breakers and conduit run.'),
 ('Valve replacement (pump room)', 'Repair', 6000.00, 'Replace a gate valve and check valve on the main line.');

INSERT IGNORE INTO job_template_items (template_id, item_id, qty)
SELECT t.template_id, i.item_id, x.qty FROM (
  SELECT 'Split-type aircon installation' AS tname, 'MEC-CU-001' AS code, 2 AS qty UNION ALL
  SELECT 'Split-type aircon installation', 'MEC-CU-002', 2 UNION ALL
  SELECT 'Split-type aircon installation', 'MEC-CU-006', 6 UNION ALL
  SELECT 'Split-type aircon installation', 'INS-AF-001', 4 UNION ALL
  SELECT 'Split-type aircon installation', 'INS-AF-002', 4 UNION ALL
  SELECT 'Split-type aircon installation', 'REF-RG-003', 1 UNION ALL
  SELECT 'Split-type aircon installation', 'TEQ-007', 1 UNION ALL
  SELECT 'Aircon preventive maintenance', 'REF-RG-001', 1 UNION ALL
  SELECT 'Aircon preventive maintenance', 'INS-AF-001', 2 UNION ALL
  SELECT 'Fire sprinkler line extension', 'FIR-SP-001', 12 UNION ALL
  SELECT 'Fire sprinkler line extension', 'FIR-SP-002', 6 UNION ALL
  SELECT 'Fire sprinkler line extension', 'VLV-TZ-004', 2 UNION ALL
  SELECT 'Fire sprinkler line extension', 'TEQ-007', 1 UNION ALL
  SELECT 'Fire sprinkler line extension', 'TEQ-015', 1 UNION ALL
  SELECT 'Electrical panel board installation', 'ELE-PB-001', 1 UNION ALL
  SELECT 'Electrical panel board installation', 'ELE-CB-001', 1 UNION ALL
  SELECT 'Electrical panel board installation', 'ELE-CB-002', 6 UNION ALL
  SELECT 'Electrical panel board installation', 'ELE-EM-001', 6 UNION ALL
  SELECT 'Electrical panel board installation', 'TEQ-005', 1 UNION ALL
  SELECT 'Valve replacement (pump room)', 'VLV-TZ-001', 1 UNION ALL
  SELECT 'Valve replacement (pump room)', 'VLV-TZ-005', 1 UNION ALL
  SELECT 'Valve replacement (pump room)', 'VLV-TZ-003', 1 UNION ALL
  SELECT 'Valve replacement (pump room)', 'TEQ-010', 1 UNION ALL
  SELECT 'Valve replacement (pump room)', 'TEQ-007', 1
) x
JOIN job_templates t ON t.name = x.tname
JOIN items i ON i.item_code = x.code AND i.is_archived = 0;

-- Remember which template a job started from (for reference only; the job's own items are what count).
ALTER TABLE jobs ADD COLUMN template_id INT(11) NULL AFTER service_type;
ALTER TABLE jobs ADD CONSTRAINT fk_jobs_template FOREIGN KEY (template_id) REFERENCES job_templates (template_id) ON DELETE SET NULL;
