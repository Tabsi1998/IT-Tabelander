-- IT-Tabelander PDF: one QS on an intervention (Serviceauftrag).
-- status 0 = open, 1 = closed (protocol written).
CREATE TABLE llx_ittabpdf_qs_run(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  fk_fichinter    integer NOT NULL,
  fk_type         integer NOT NULL,
  type_label      varchar(128) NOT NULL,
  notice          text NULL,
  signers         varchar(64) NULL,
  status          smallint DEFAULT 0 NOT NULL,
  note            text NULL,
  filename        varchar(255) NULL,
  fk_user_creat   integer NULL,
  date_creation   datetime NOT NULL,
  fk_user_close   integer NULL,
  date_close      datetime NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
