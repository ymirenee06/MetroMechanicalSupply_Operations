-- 007: fixes from tester feedback (October 2026).
-- A technician can cancel a tool transfer they sent while it is still waiting for an answer.
ALTER TABLE tool_transfers MODIFY status ENUM('Awaiting Confirmation','Confirmed','Declined','Cancelled') NOT NULL DEFAULT 'Awaiting Confirmation';

-- Customers can be deactivated (hidden from the dropdowns) when they already have sales or jobs;
-- customers with no history can be deleted outright.
ALTER TABLE customers ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER customer_type;
