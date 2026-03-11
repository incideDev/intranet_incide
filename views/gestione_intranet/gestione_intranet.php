<?php
if (!defined('AccessoFileInterni')) {
    header('HTTP/1.0 404 Not Found'); die();
}

if (!isAdmin() && !userHasPermission('view_gestione_intranet')) {
    header('HTTP/1.0 403 Forbidden');
    include("page-errors/403.php");
    exit;
}
?>

<div class="main-container">
    <div class="dashboard-impostazioni-wrapper">
        <h1 class="dashboard-title">Impostazioni Intranet</h1>
        <p class="dashboard-desc">
            Da qui puoi gestire le impostazioni di sistema e le principali configurazioni della intranet aziendale.<br>
            Utilizza i blocchi sottostanti per accedere alle varie aree di configurazione.
        </p>

        <div class="dashboard-settings-grid">

            <!-- IMPOSTAZIONI GENERALI -->
            <div class="setting-card">
                <div class="setting-card-header">
                    <h3>impostazioni pagine</h3>
                    <img class="lock is-hidden" src="assets/icons/key.png" alt="" width="18" height="18" aria-hidden="true">
                </div>
                <p>configura le pagine e la loro visibilità nella intranet.</p>
                <a href="index.php?section=gestione_intranet&page=impostazioni_moduli"
                   class="button" data-tooltip="apri impostazioni moduli">gestisci</a>
            </div>

            <!-- GESTIONE USER -->
            <div class="setting-card">
                <div class="setting-card-header">
                    <h3>gestione utenti</h3>
                    <img class="lock" src="assets/icons/key.png" alt="protetto" width="18" height="18" data-tooltip="pagina protetta">
                </div>
                <p>crea, modifica e resetta gli account utente.</p>
                <a href="index.php?section=gestione_intranet&page=reset_user"
                   class="button" data-tooltip="pagina protetta da password aggiuntiva">gestisci</a>
            </div>

            <div class="setting-card">
                <div class="setting-card-header">
                    <h3>gestione ruoli</h3>
                    <img class="lock is-hidden" src="assets/icons/key.png" alt="" width="18" height="18" aria-hidden="true">
                </div>
                <p>gestisci ruoli e permessi associati agli utenti.</p>
                <a href="index.php?section=gestione_intranet&page=ruoli"
                   class="button" data-tooltip="apri gestione ruoli">gestisci</a>
            </div>

            <!-- TOOL -->
            <div class="setting-card">
                <div class="setting-card-header">
                    <h3>import manager</h3>
                    <img class="lock" src="assets/icons/key.png" alt="protetto" width="18" height="18" data-tooltip="pagina protetta">
                </div>
                <p>strumenti di import per anagrafiche e dati operativi.</p>
                <a href="index.php?section=gestione_intranet&page=import_manager"
                   class="button" data-tooltip="pagina protetta da password aggiuntiva">apri</a>
            </div>

            <!-- MODALITÀ MANUTENZIONE -->
            <div class="setting-card">
                <div class="setting-card-header">
                    <h3>modalità manutenzione</h3>
                    <img class="lock" src="assets/icons/key.png" alt="protetto" width="18" height="18" data-tooltip="richiede password per attivare">
                </div>
                <p>attiva o disattiva la modalità manutenzione per bloccare l'accesso agli utenti non amministratori.</p>
                <div class="maintenance-toggle-wrapper" style="margin-top: 16px; margin-bottom: 16px;">
                    <label class="maintenance-toggle-label" style="display: flex; align-items: center; gap: 12px; cursor: pointer;">
                        <input type="checkbox" 
                               id="maintenanceModeToggle" 
                               class="maintenance-toggle"
                               style="width: 48px; height: 24px; cursor: pointer; appearance: none; background: #cbd5e1; border-radius: 12px; position: relative; transition: background 0.3s;">
                        <span id="maintenanceStatusText" style="font-weight: 600; color: #1e293b;">
                            Caricamento...
                        </span>
                    </label>
                </div>
                <a href="index.php?section=gestione_intranet&page=maintenance_settings"
                   class="button" 
                   data-tooltip="apri impostazioni avanzate manutenzione"
                   style="margin-top: auto;">
                    Impostazioni
                </a>
            </div>

        </div>
    </div>
</div>

<style>
.dashboard-impostazioni-wrapper{
    margin:0;
    padding:32px 24px 28px 24px;
    background:#ffffff;
    border-radius:18px;
    box-shadow:0 3px 12px #0001;
}
.dashboard-title{
    font-size:2.1rem;
    color:#27436b;
    margin-bottom:8px;
    letter-spacing:.02em;
    font-weight:700;
}
.dashboard-desc{
    font-size:1.1rem;
    color:#444b;
    margin-bottom:34px;
}

/* griglia full width, auto-colonne */
.dashboard-settings-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(300px,1fr));
    gap:24px;
    align-items:stretch;
}

/* card uniformi */
.setting-card{
    display:flex;
    flex-direction:column;
    background:#fff;
    border-radius:13px;
    box-shadow:0 1.5px 8px #0002;
    padding:24px 22px 20px 22px;
    min-height:230px; /* altezza coerente */
}

/* header titolo + lucchetto */
.setting-card-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    min-height:28px;
    margin-bottom:12px;
}
.setting-card-header h3{
    margin:0;
    font-size:1.23rem;
    color:#20456a;
    font-weight:600;
}
.lock{opacity:.8;filter:grayscale(1);}
.lock.is-hidden{visibility:hidden;} /* mantiene lo spazio */

/* descrizione riempie lo spazio */
.setting-card p{
    color:#555;
    font-size:1.01rem;
    margin:0 0 14px 0;
    flex:1 1 auto;
}

/* bottone sempre in basso */
.setting-card .button{
    margin-top:auto;
    align-self:flex-start;
}

/* mobile */
@media (max-width:900px){
  .dashboard-settings-grid{
      grid-template-columns:1fr;
      gap:18px;
  }
}

/* Toggle manutenzione */
.maintenance-toggle {
    position: relative;
    outline: none;
}

.maintenance-toggle:checked {
    background: #10b981 !important;
}

.maintenance-toggle:checked::before {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: white;
    top: 2px;
    right: 2px;
    transition: right 0.3s;
}

.maintenance-toggle:not(:checked)::before {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: white;
    top: 2px;
    left: 2px;
    transition: left 0.3s;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', async function() {
    const toggle = document.getElementById('maintenanceModeToggle');
    const statusText = document.getElementById('maintenanceStatusText');
    
    if (!toggle || !statusText) return;

    // Carica stato corrente
    async function loadMaintenanceStatus() {
        try {
            const result = await customFetch('gestione_intranet', 'getMaintenanceStatus');
            if (result && result.success !== undefined) {
                toggle.checked = result.maintenance_mode === 1;
                updateStatusText(result.maintenance_mode === 1);
            } else {
                updateStatusText(false);
            }
        } catch (error) {
            console.error('Errore caricamento stato manutenzione:', error);
            updateStatusText(false);
        }
    }

    function updateStatusText(isActive) {
        if (isActive) {
            statusText.textContent = 'Manutenzione attiva';
            statusText.style.color = '#ef4444';
        } else {
            statusText.textContent = 'Manutenzione disattiva';
            statusText.style.color = '#10b981';
        }
    }

    // Gestione click toggle
    toggle.addEventListener('change', async function() {
        const newState = toggle.checked;
        
        // Se si sta ATTIVANDO, richiedi password usando lo stesso sistema di extra_auth.php
        if (newState) {
            // Verifica password usando lo stesso endpoint di extra_auth.php
            const authResult = await verifyExtraAuth();
            if (!authResult) {
                // Non autenticato o password errata, ripristina toggle
                toggle.checked = false;
                return;
            }
        }

        // Salva nuovo stato
        await saveMaintenanceMode(newState);
    });

    // Verifica password usando lo stesso sistema di extra_auth.php
    function verifyExtraAuth() {
        return new Promise((resolve) => {
            // Carica la pagina che include extra_auth.php in un iframe
            // extra_auth.php mostrerà il modale se non autenticato
            const iframe = document.createElement('iframe');
            iframe.style.cssText = 'position:fixed; top:0; left:0; width:100vw; height:100vh; z-index:10000; border:none; background:rgba(30,30,30,0.77);';
            iframe.src = 'index.php?section=gestione_intranet&page=maintenance_auth_check';
            document.body.appendChild(iframe);
            
            // Ascolta messaggi dall'iframe quando l'autenticazione è completata
            const messageHandler = function(e) {
                if (e.data === 'extra_auth_success') {
                    iframe.remove();
                    window.removeEventListener('message', messageHandler);
                    resolve(true);
                } else if (e.data === 'extra_auth_cancelled') {
                    iframe.remove();
                    window.removeEventListener('message', messageHandler);
                    resolve(false);
                }
            };
            window.addEventListener('message', messageHandler);
        });
    }

    // Salva stato manutenzione
    async function saveMaintenanceMode(isActive) {
        try {
            const result = await customFetch('gestione_intranet', 'saveMaintenanceSettings', {
                maintenance_mode: isActive ? 1 : 0,
                maintenance_message: ''
            });

            if (result && result.success) {
                updateStatusText(isActive);
                showToast(isActive ? 'Modalità manutenzione attivata' : 'Modalità manutenzione disattivata', 'success');
            } else {
                // Ripristina stato in caso di errore
                toggle.checked = !isActive;
                updateStatusText(!isActive);
                showToast(result?.message || 'Errore durante il salvataggio', 'error');
            }
        } catch (error) {
            console.error('Errore salvataggio manutenzione:', error);
            toggle.checked = !isActive;
            updateStatusText(!isActive);
            showToast('Errore durante il salvataggio', 'error');
        }
    }

    // Carica stato iniziale
    await loadMaintenanceStatus();
});
</script>
