-- ─────────────────────────────────────────────────────────────────────────
-- MIGRATION 009: Fix scopo/modalita in elenco_doc_submittals
--
-- Problema: colonna `scopo` era ENUM('email','PEC','portale') ma semanticamente
-- contiene il mezzo di invio, non lo scopo. Lo scopo e la finalita (Per approvazione, etc).
--
-- Fix:
-- 1. Rinomina `scopo` da ENUM a VARCHAR(100) per contenere la finalita testuale
-- 2. Aggiunge colonna `modalita` VARCHAR(50) per il mezzo di invio
-- 3. Migra i dati esistenti: vecchi valori di scopo ('email','PEC','portale') → modalita
-- ─────────────────────────────────────────────────────────────────────────

-- Step 1: Aggiunge colonna modalita
ALTER TABLE elenco_doc_submittals
    ADD COLUMN modalita VARCHAR(50) DEFAULT 'E-mail' AFTER scopo;

-- Step 2: Migra i vecchi dati di scopo (che erano modalita) nella nuova colonna
UPDATE elenco_doc_submittals SET modalita = CASE
    WHEN scopo = 'email'   THEN 'E-mail'
    WHEN scopo = 'PEC'     THEN 'PEC'
    WHEN scopo = 'portale' THEN 'Portale committente'
    ELSE 'E-mail'
END
WHERE scopo IN ('email', 'PEC', 'portale');

-- Step 3: Converte scopo da ENUM a VARCHAR e imposta default corretto
ALTER TABLE elenco_doc_submittals
    MODIFY COLUMN scopo VARCHAR(100) DEFAULT 'Per approvazione';

-- Step 4: Aggiorna i vecchi record che avevano valori di trasporto in scopo
UPDATE elenco_doc_submittals SET scopo = 'Per approvazione'
WHERE scopo IN ('email', 'PEC', 'portale');
