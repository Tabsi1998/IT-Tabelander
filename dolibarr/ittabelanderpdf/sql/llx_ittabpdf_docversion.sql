-- IT-Tabelander PDF: released versions of a company document, unchanged
-- for good (which version a customer got can always be shown).
CREATE TABLE llx_ittabpdf_docversion(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  fk_doc          integer NOT NULL,
  version         integer NOT NULL,
  title           varchar(255) NOT NULL,
  text            mediumtext NULL,
  date_release    datetime NOT NULL,
  note            varchar(255) NULL,
  filename        varchar(255) NULL,
  fk_user         integer NULL
) ENGINE=innodb;
