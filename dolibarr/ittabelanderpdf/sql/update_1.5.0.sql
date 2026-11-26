-- 1.5.0: rules with combined conditions (customer type AND contract product
-- AND customer tag, each optional). The single condition of 1.4.0 (scope,
-- scope_value) is carried over once; scope stays for older versions.
ALTER TABLE llx_ittabpdf_docrule ADD COLUMN customer_type varchar(16) DEFAULT '' NOT NULL;
ALTER TABLE llx_ittabpdf_docrule ADD COLUMN products varchar(255) DEFAULT '' NOT NULL;
ALTER TABLE llx_ittabpdf_docrule ADD COLUMN fk_category integer DEFAULT 0 NOT NULL;
ALTER TABLE llx_ittabpdf_docrule ADD COLUMN migrated smallint DEFAULT 0 NOT NULL;
UPDATE llx_ittabpdf_docrule SET customer_type = scope, migrated = 1 WHERE migrated = 0 AND scope IN ('company', 'private');
UPDATE llx_ittabpdf_docrule SET products = scope_value, migrated = 1 WHERE migrated = 0 AND scope = 'contract';
UPDATE llx_ittabpdf_docrule SET fk_category = scope_value, migrated = 1 WHERE migrated = 0 AND scope = 'category' AND scope_value <> '';
UPDATE llx_ittabpdf_docrule SET migrated = 1 WHERE migrated = 0;
