-- 1.3.0: when and to whom the test report of a QS run was sent.
-- Columns that exist already are skipped by Dolibarr on activation.
ALTER TABLE llx_ittabpdf_qs_run ADD COLUMN date_sent datetime NULL;
ALTER TABLE llx_ittabpdf_qs_run ADD COLUMN sent_to varchar(255) NULL;
