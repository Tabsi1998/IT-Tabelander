ALTER TABLE llx_ittabpdf_docevent ADD INDEX idx_ittabpdf_docevent_soc(entity, fk_soc, slug, date_event);
ALTER TABLE llx_ittabpdf_docevent ADD INDEX idx_ittabpdf_docevent_object(element, fk_object);
