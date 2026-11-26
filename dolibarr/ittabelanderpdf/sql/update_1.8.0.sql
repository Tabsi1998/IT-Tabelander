-- 1.8.0: a company document added to one customer by hand (tab "Unterlagen",
-- contract, proposal, document card) is a rule of its own for that customer.
ALTER TABLE llx_ittabpdf_docrule ADD COLUMN fk_soc integer NULL;
ALTER TABLE llx_ittabpdf_docrule ADD INDEX idx_ittabpdf_docrule_soc(fk_soc);
-- 1.8.0: may a document go to customers (e-mail attachment)? Internal text
-- blocks and the website's imprint addition never do.
ALTER TABLE llx_ittabpdf_doc ADD COLUMN sendable smallint DEFAULT 1 NOT NULL;
UPDATE llx_ittabpdf_doc SET sendable = 0 WHERE slug IN ('geraeteannahme-bausteine', 'qs-checklisten', 'impressum-ergaenzung');
