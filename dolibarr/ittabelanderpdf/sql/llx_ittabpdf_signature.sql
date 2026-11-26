-- IT-Tabelander PDF: signatures given on the device (simple electronic
-- signature). One row per document and role; signing again replaces it.
-- kind: qs (QS run, fk_object = run) or intake (ticket, fk_object = ticket).
-- The image lies in the object's documents, folder signatures/.
CREATE TABLE llx_ittabpdf_signature(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  kind            varchar(16) NOT NULL,
  fk_object       integer NOT NULL,
  role            varchar(16) NOT NULL,
  name            varchar(128) NOT NULL,
  date_sign       datetime NOT NULL,
  filename        varchar(255) NOT NULL,
  ip              varchar(64) NULL,
  fk_user         integer NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
