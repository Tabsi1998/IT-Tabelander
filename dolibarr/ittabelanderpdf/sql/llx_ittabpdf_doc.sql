-- IT-Tabelander PDF: company documents and legal texts (Unterlagen), apart
-- from the knowledge base (FAQ). text is the working draft; every release
-- becomes a row of llx_ittabpdf_docversion that never changes.
-- kind: legal (legal text, may be public), internal, customer (template per customer).
CREATE TABLE llx_ittabpdf_doc(
  rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity          integer DEFAULT 1 NOT NULL,
  slug            varchar(64) NOT NULL,
  title           varchar(255) NOT NULL,
  kind            varchar(16) DEFAULT 'internal' NOT NULL,
  public          smallint DEFAULT 0 NOT NULL,
  text            mediumtext NULL,
  changed         smallint DEFAULT 1 NOT NULL,
  version         integer DEFAULT 0 NOT NULL,
  date_release    datetime NULL,
  position        integer DEFAULT 0 NOT NULL,
  fk_km           integer NULL,
  date_creation   datetime NOT NULL,
  fk_user_modif   integer NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
