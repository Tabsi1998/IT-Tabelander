-- IT-Tabelander PDF: the points of one QS run, copied from the checklist when
-- the run starts, with tick, value, note, who and when.
CREATE TABLE llx_ittabpdf_qs_result(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  fk_run          integer NOT NULL,
  fk_item         integer NULL,
  position        integer DEFAULT 0 NOT NULL,
  label           varchar(255) NOT NULL,
  kind            varchar(8) DEFAULT 'check' NOT NULL,
  unit            varchar(16) NULL,
  required        smallint DEFAULT 0 NOT NULL,
  help            varchar(255) NULL,
  checked         smallint DEFAULT 0 NOT NULL,
  value           varchar(255) NULL,
  note            text NULL,
  fk_user         integer NULL,
  date_check      datetime NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
