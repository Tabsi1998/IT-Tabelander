-- 1.6.0: answers of the input fields, contract and contact of a document
-- generated for a customer (JSON), for the PDF and as defaults next time.
ALTER TABLE llx_ittabpdf_docevent ADD COLUMN data text NULL;
