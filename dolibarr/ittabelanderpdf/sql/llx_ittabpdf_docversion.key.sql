ALTER TABLE llx_ittabpdf_docversion ADD UNIQUE INDEX uk_ittabpdf_docversion(fk_doc, version);
