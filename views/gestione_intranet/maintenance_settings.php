<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found', true, 404);
    include('page-errors/404.php');
    die();
}

// Solo admin possono accedere
if (!userHasPermission('view_gestione_intranet')) {
    echo "<div class='error'>Accesso non autorizzato.</div>";
    return;
}

global $database;

// Carica impostazioni correnti
$maintenanceMode = false;
$maintenanceMessage = '';

try {
    $check = $database->query("SHOW TABLES LIKE 'app_settings'", [], __FILE__);
    if ($check && $check->rowCount() > 0) {
        $modeResult = $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'maintenance_mode' LIMIT 1",
            [],
            __FILE__
        );
        if ($modeResult) {
            $row = $modeResult->fetch(PDO::FETCH_ASSOC);
            $maintenanceMode = isset($row['setting_value']) && intval($row['setting_value']) === 1;
        }
        
        $msgResult = $database->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'maintenance_message' LIMIT 1",
            [],
            __FILE__
        );
        if ($msgResult) {
            $row = $msgResult->fetch(PDO::FETCH_ASSOC);
            $maintenanceMessage = isset($row['setting_value']) ? trim($row['setting_value']) : '';
        }
    }
} catch (\Throwable $e) {
    error_log("Errore caricamento impostazioni manutenzione: " . $e->getMessage());
}

?>
<div class="main-container">
    <?php renderPageTitle("Modalità Manutenzione", "#cd211d"); ?>
    
    <div class="maintenance-settings-wrapper">
        <div class="maintenance-settings-header">
            <h2 class="maintenance-settings-title">Gestione Modalità Manutenzione</h2>
            <p class="maintenance-settings-description">
                Configura la modalità manutenzione per bloccare temporaneamente l'accesso all'intranet agli utenti non amministratori.
            </p>
        </div>

        <form id="maintenanceSettingsForm" class="maintenance-settings-form">
            <!-- Sezione: Stato Manutenzione -->
            <div class="maintenance-section">
                <div class="maintenance-section-header">
                    <h3 class="maintenance-section-title">
                        Stato Manutenzione
                    </h3>
                </div>
                <div class="maintenance-section-content">
                    <div class="maintenance-toggle-container">
                        <label class="maintenance-toggle-label">
                            <input type="checkbox" 
                                   id="maintenanceModeToggle" 
                                   class="maintenance-toggle-input"
                                   <?= $maintenanceMode ? 'checked' : '' ?>>
                            <span class="maintenance-toggle-slider"></span>
                            <span class="maintenance-toggle-text">
                                <?= $maintenanceMode ? 'Modalità manutenzione attiva' : 'Modalità manutenzione disattiva' ?>
                            </span>
                        </label>
                        <p class="maintenance-toggle-description">
                            <?php if ($maintenanceMode): ?>
                                <span class="status-badge status-active">Attiva</span> - Solo gli amministratori possono accedere all'intranet.
                            <?php else: ?>
                                <span class="status-badge status-inactive">Disattiva</span> - Tutti gli utenti autorizzati possono accedere normalmente.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Sezione: Messaggio Personalizzato -->
            <div class="maintenance-section">
                <div class="maintenance-section-header">
                    <h3 class="maintenance-section-title">
                        <span class="maintenance-icon">💬</span>
                        Messaggio Personalizzato
                    </h3>
                </div>
                <div class="maintenance-section-content">
                    <div class="form-group-maintenance">
                        <label for="maintenanceMessage" class="form-label-maintenance">
                            Messaggio da mostrare agli utenti (opzionale)
                        </label>
                        <textarea id="maintenanceMessage" 
                                  name="maintenanceMessage" 
                                  rows="5" 
                                  class="form-textarea-maintenance"
                                  placeholder="Inserisci un messaggio personalizzato da mostrare agli utenti durante la manutenzione. Se lasciato vuoto, verrà mostrato un messaggio predefinito."><?= htmlspecialchars($maintenanceMessage, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <p class="form-help-text">
                            Questo messaggio verrà visualizzato nella pagina di manutenzione mostrata agli utenti non amministratori.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Azioni -->
            <div class="maintenance-actions">
                <button type="button"
                        id="saveMaintenanceSettingsBtn"
                        class="button btn-success maintenance-save-btn">
                    Salva Impostazioni
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.maintenance-settings-wrapper {
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    padding: 32px;
    margin-top: 24px;
}

.maintenance-settings-header {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 2px solid #f1f1f1;
}

.maintenance-settings-title {
    font-size: 1.75rem;
    color: #1e293b;
    margin: 0 0 8px 0;
    font-weight: 700;
}

.maintenance-settings-description {
    font-size: 1rem;
    color: #64748b;
    margin: 0;
    line-height: 1.6;
}

.maintenance-settings-form {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.maintenance-section {
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    overflow: hidden;
}

.maintenance-section-header {
    background: #ffffff;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
}

.maintenance-section-title {
    font-size: 1.15rem;
    color: #1e293b;
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.maintenance-icon {
    font-size: 1.3rem;
}

.maintenance-section-content {
    padding: 24px 20px;
}

.maintenance-toggle-container {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.maintenance-toggle-label {
    display: flex;
    align-items: center;
    gap: 16px;
    cursor: pointer;
    position: relative;
}

.maintenance-toggle-input {
    width: 0;
    height: 0;
    opacity: 0;
    position: absolute;
}

.maintenance-toggle-slider {
    position: relative;
    width: 56px;
    height: 28px;
    background: #cbd5e1;
    border-radius: 28px;
    transition: background 0.3s;
    flex-shrink: 0;
}

.maintenance-toggle-slider::before {
    content: '';
    position: absolute;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #ffffff;
    top: 3px;
    left: 3px;
    transition: transform 0.3s;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.maintenance-toggle-input:checked + .maintenance-toggle-slider {
    background: #cd211d;
}

.maintenance-toggle-input:checked + .maintenance-toggle-slider::before {
    transform: translateX(28px);
}

.maintenance-toggle-text {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e293b;
    flex: 1;
}

.maintenance-toggle-description {
    margin: 0;
    padding-left: 72px;
    font-size: 0.95rem;
    color: #64748b;
    line-height: 1.5;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
    margin-right: 8px;
}

.status-active {
    background: #fee2e2;
    color: #cd211d;
}

.status-inactive {
    background: #d1fae5;
    color: #059669;
}

.form-group-maintenance {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-label-maintenance {
    font-size: 1rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.form-textarea-maintenance {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 1rem;
    font-family: inherit;
    line-height: 1.5;
    resize: vertical;
    min-height: 120px;
    transition: border-color 0.2s, box-shadow 0.2s;
    box-sizing: border-box;
}

.form-textarea-maintenance:focus {
    outline: none;
    border-color: #cd211d;
    box-shadow: 0 0 0 3px rgba(205, 33, 29, 0.1);
}

.form-help-text {
    margin: 0;
    font-size: 0.9rem;
    color: #64748b;
    line-height: 1.5;
}

.maintenance-actions {
    display: flex;
    justify-content: flex-end;
    padding-top: 8px;
    border-top: 1px solid #e5e7eb;
    margin-top: 8px;
}

.btn-icon {
    font-size: 1.1rem;
}

@media (max-width: 768px) {
    .maintenance-settings-wrapper {
        padding: 20px;
    }
    
    .maintenance-section-content {
        padding: 16px;
    }
    
    .maintenance-toggle-description {
        padding-left: 0;
        margin-top: 8px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('maintenanceModeToggle');
    const message = document.getElementById('maintenanceMessage');
    const saveBtn = document.getElementById('saveMaintenanceSettingsBtn');
    const toggleText = document.querySelector('.maintenance-toggle-text');
    const toggleDescription = document.querySelector('.maintenance-toggle-description');

    if (!toggle || !message || !saveBtn) return;

    // Aggiorna UI quando cambia il toggle
    toggle.addEventListener('change', function() {
        updateToggleUI(toggle.checked);
    });

    function updateToggleUI(isActive) {
        if (toggleText) {
            toggleText.textContent = isActive 
                ? 'Modalità manutenzione attiva' 
                : 'Modalità manutenzione disattiva';
        }
        if (toggleDescription) {
            toggleDescription.innerHTML = isActive
                ? '<span class="status-badge status-active">Attiva</span> - Solo gli amministratori possono accedere all\'intranet.'
                : '<span class="status-badge status-inactive">Disattiva</span> - Tutti gli utenti autorizzati possono accedere normalmente.';
        }
    }

    saveBtn.addEventListener('click', async function() {
        const isActive = toggle.checked ? 1 : 0;
        const msgText = (message.value || '').trim();

        saveBtn.disabled = true;
        const originalText = saveBtn.innerHTML;
        saveBtn.innerHTML = '<span class="btn-icon">⏳</span> Salvataggio...';

        try {
            const result = await customFetch('gestione_intranet', 'saveMaintenanceSettings', {
                maintenance_mode: isActive,
                maintenance_message: msgText
            });

            if (result && result.success) {
                showToast('Impostazioni salvate con successo', 'success');
                updateToggleUI(toggle.checked);
            } else {
                showToast(result?.message || 'Errore durante il salvataggio', 'error');
            }
        } catch (error) {
            console.error('Errore salvataggio:', error);
            showToast('Errore durante il salvataggio', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalText;
        }
    });
});
</script>

