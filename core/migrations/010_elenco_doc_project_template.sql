-- Migration: elenco_doc_project_template
-- Maps each project to its active template

CREATE TABLE IF NOT EXISTS elenco_doc_project_template (
    id_project    VARCHAR(32) NOT NULL,
    template_id   INT NOT NULL,
    assigned_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_project),
    INDEX idx_template (template_id),
    FOREIGN KEY (template_id) REFERENCES elenco_doc_commessa(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: migrate existing project-specific templates
INSERT IGNORE INTO elenco_doc_project_template (id_project, template_id)
SELECT id_project, id FROM elenco_doc_commessa WHERE is_global = 0 AND id_project != 'GLOBAL';
