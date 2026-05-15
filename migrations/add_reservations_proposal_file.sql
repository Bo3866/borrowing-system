-- Add proposal_file and proposal_uploaded_at to reservations if missing
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS proposal_file VARCHAR(255) NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS proposal_uploaded_at DATETIME NULL;

-- Optional: if you want to remove proposal_file from space_reservation_items after migrating data, run:
-- ALTER TABLE space_reservation_items DROP COLUMN IF EXISTS proposal_file;
-- ALTER TABLE space_reservation_items DROP COLUMN IF EXISTS proposal_uploaded_at;

-- Note: MySQL supports "IF NOT EXISTS" for ADD COLUMN on newer versions. If your MySQL version
-- doesn't support it, run the following instead (after checking columns):
-- ALTER TABLE reservations ADD COLUMN proposal_file VARCHAR(255) NULL;
-- ALTER TABLE reservations ADD COLUMN proposal_uploaded_at DATETIME NULL;
