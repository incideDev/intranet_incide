-- =========================================================================
-- Migration 012: Drop old fixed template/segment columns
--
-- Now that categories JSON and segments JSON are the source of truth,
-- remove the old hardcoded columns.
-- =========================================================================

-- Template table: drop old 4 category columns
ALTER TABLE elenco_doc_commessa
    DROP COLUMN IF EXISTS fasi,
    DROP COLUMN IF EXISTS zone,
    DROP COLUMN IF EXISTS discipline,
    DROP COLUMN IF EXISTS tipi_documento;

-- Documents table: drop old 4 segment columns + generated column
ALTER TABLE elenco_doc_documents
    DROP COLUMN IF EXISTS numero_documento,
    DROP COLUMN IF EXISTS seg_fase,
    DROP COLUMN IF EXISTS seg_zona,
    DROP COLUMN IF EXISTS seg_disc,
    DROP COLUMN IF EXISTS seg_tipo;

-- =========================================================================
-- ROLLBACK: not possible — data was migrated to categories/segments JSON
-- =========================================================================
