-- IT-Tabelander PDF: what happened with a company document at a customer.
-- state: sent, signing (signature on the device started), signed, uploaded,
-- checked (by hand), revoked (earlier proof no longer counts).
-- version = "Stand" of the document at that moment (YYYY-MM-DD).
CREATE TABLE llx_ittabpdf_docevent(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  fk_soc          integer NOT NULL,
  slug            varchar(64) NOT NULL,
  fk_rule         integer NULL,
  state           varchar(16) NOT NULL,
  source          varchar(16) NULL,
  version         varchar(10) NULL,
  date_event      datetime NOT NULL,
  element         varchar(32) NULL,
  fk_object       integer NULL,
  filename        varchar(255) NULL,
  note            text NULL,
  fk_user         integer NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
