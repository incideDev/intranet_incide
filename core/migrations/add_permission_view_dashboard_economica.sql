-- Migration: Aggiunge il permesso view_dashboard_economica
-- Data: 2026-03-09
-- Descrizione: Permesso per accedere alla Dashboard Economica (sezione Commesse)

-- Inserisci permesso per il ruolo Admin (role_id=1)
-- L'admin ha già bypass automatico, ma per coerenza lo aggiungiamo
INSERT IGNORE INTO `sys_role_permissions` (`role_id`, `permission`)
VALUES (1, 'view_dashboard_economica');

-- Per assegnare il permesso ad altri ruoli, eseguire:
-- INSERT INTO `sys_role_permissions` (`role_id`, `permission`) VALUES (<role_id>, 'view_dashboard_economica');
