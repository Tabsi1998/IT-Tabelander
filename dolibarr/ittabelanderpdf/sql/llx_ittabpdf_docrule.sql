-- IT-Tabelander PDF: which company document a customer needs, and when.
-- slug = file name in the Unterlagen folder (without .pdf); action: accept,
-- sign, handover; scope: all, company, private, contract (product refs in
-- scope_value, * allowed), category (customer tag id in scope_value).
CREATE TABLE llx_ittabpdf_docrule(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  label           varchar(128) NOT NULL,
  slug            varchar(64) NOT NULL,
  action          varchar(16) DEFAULT 'accept' NOT NULL,
  scope           varchar(16) DEFAULT 'all' NOT NULL,
  scope_value     varchar(255) NULL,
  required        smallint DEFAULT 1 NOT NULL,
  renew           smallint DEFAULT 0 NOT NULL,
  position        integer DEFAULT 0 NOT NULL,
  active          smallint DEFAULT 1 NOT NULL,
  fk_soc          integer NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
