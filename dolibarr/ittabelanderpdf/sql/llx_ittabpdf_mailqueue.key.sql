ALTER TABLE llx_ittabpdf_mailqueue ADD UNIQUE INDEX uk_ittabpdf_mailqueue_doc(entity, element, fk_object, event);
ALTER TABLE llx_ittabpdf_mailqueue ADD INDEX idx_ittabpdf_mailqueue_due(status, date_due);
