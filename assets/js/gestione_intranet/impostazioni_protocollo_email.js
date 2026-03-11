document.addEventListener("DOMContentLoaded", function () {
  const checkboxes = document.querySelectorAll(".vis-checkbox");
  const saveBtn = document.getElementById("salvaVisibilitaProtocolloBtn");

  function getCurrentConfig() {
    const config = [];
    checkboxes.forEach(cb => {
      if (cb.checked) {
        config.push({
          sezione: "protocollo_email",
          blocco: cb.dataset.blocco,
          ruolo_id: parseInt(cb.dataset.ruoloId, 10)
        });
      }
    });
    return config;
  }

  function loadConfig() {
    customFetch("visibilita_sezioni", "getConfig", { sezione: "protocollo_email" })
      .then(data => {
        if (!data.success || !Array.isArray(data.config)) return;
        data.config.forEach(entry => {
          document
            .querySelector(`.vis-checkbox[data-blocco="${entry.blocco}"][data-ruolo-id="${entry.ruolo_id}"]`)
            ?.setAttribute("checked", "checked");
        });
      })
      .catch(err => {
        console.error("Errore caricamento visibilità:", err);
        showToast("Errore nel caricamento configurazione", "error");
      });
  }

  saveBtn.addEventListener("click", () => {
    const config = getCurrentConfig();
    customFetch("visibilita_sezioni", "saveConfig", { sezione: "protocollo_email", config })
      .then(res => {
        if (res.success) {
          showToast("Visibilità salvata con successo", "success");
        } else {
          showToast("Errore salvataggio visibilità", "error");
        }
      })
      .catch(() => {
        showToast("Errore di connessione", "error");
      });
  });

  loadConfig();
});
