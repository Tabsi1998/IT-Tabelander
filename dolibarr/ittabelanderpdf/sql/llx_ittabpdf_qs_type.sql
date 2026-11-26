-- IT-Tabelander PDF: kinds of job (Auftragsart) with their QS checklist.
-- Kept in the module setup; a new kind needs no code.
CREATE TABLE llx_ittabpdf_qs_type(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  code            varchar(32) NOT NULL,
  label           varchar(128) NOT NULL,
  position        integer DEFAULT 0 NOT NULL,
  active          smallint DEFAULT 1 NOT NULL,
  signers         varchar(64) DEFAULT 'technician,customer' NOT NULL,
  notice          text NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
