-- =========================================================================
-- Migration 011: Dynamic Template Categories
--
-- Adds `categories` JSON column to elenco_doc_commessa (templates)
-- Adds `segments` JSON column to elenco_doc_documents
-- Old columns kept temporarily for backward compat / rollback safety
-- Run the PHP migration script after this to populate the new columns
-- =========================================================================

-- 1. Template table: add categories column
ALTER TABLE elenco_doc_commessa
    ADD COLUMN categories JSON DEFAULT NULL AFTER tipi_documento;

-- 2. Documents table: add segments column
ALTER TABLE elenco_doc_documents
    ADD COLUMN segments JSON DEFAULT NULL AFTER seg_tipo;

-- 3. Drop generated column numero_documento if it exists
-- (MariaDB: check if exists before dropping)
-- ALTER TABLE elenco_doc_documents DROP COLUMN IF EXISTS numero_documento;

-- =========================================================================
-- ROLLBACK (run manually if needed)
-- =========================================================================
-- ALTER TABLE elenco_doc_commessa DROP COLUMN IF EXISTS categories;
-- ALTER TABLE elenco_doc_documents DROP COLUMN IF EXISTS segments;
