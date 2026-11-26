-- 2.0.0: every row of the history keeps the id of its agenda event (logged once).
ALTER TABLE llx_ittabpdf_docevent ADD COLUMN fk_actioncomm integer NULL;
ALTER TABLE llx_ittabpdf_qs_run ADD COLUMN fk_actioncomm integer NULL;
