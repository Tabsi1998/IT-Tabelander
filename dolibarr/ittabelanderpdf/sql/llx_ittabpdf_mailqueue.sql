-- IT-Tabelander PDF: e-mails that go out automatically after a delay.
-- One row per document and event; a row that was cancelled can be planned again.
CREATE TABLE llx_ittabpdf_mailqueue(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  element         varchar(32) NOT NULL,
  fk_object       integer NOT NULL,
  event           varchar(32) NOT NULL,
  date_creation   datetime NOT NULL,
  date_due        datetime NOT NULL,
  date_done       datetime NULL,
  status          varchar(16) DEFAULT 'planned' NOT NULL,
  attempts        integer DEFAULT 0 NOT NULL,
  recipient       varchar(255) NULL,
  fk_template     integer NULL,
  error           text NULL,
  fk_user_action  integer NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
