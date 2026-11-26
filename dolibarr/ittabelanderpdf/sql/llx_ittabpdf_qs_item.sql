-- IT-Tabelander PDF: points of a QS checklist. kind: check (tick), value
-- (measured value with unit), text (free text). Deactivated points stay for
-- old protocols; a run keeps its own copy of every point.
CREATE TABLE llx_ittabpdf_qs_item(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  fk_type         integer NOT NULL,
  position        integer DEFAULT 0 NOT NULL,
  label           varchar(255) NOT NULL,
  kind            varchar(8) DEFAULT 'check' NOT NULL,
  unit            varchar(16) NULL,
  required        smallint DEFAULT 0 NOT NULL,
  help            varchar(255) NULL,
  active          smallint DEFAULT 1 NOT NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
