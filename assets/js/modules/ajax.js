function getBasePath() {
  const currentPath = window.location.pathname;
  const match = currentPath.match(/^(\/[^\/]+\/[^\/]+)/);
  return match ? match[1] : "";
}

document.addEventListener("DOMContentLoaded", function () {
  const metaToken = document.querySelector('meta[name="token-csrf"]');

  if (metaToken) {
    const tokenFromMeta = metaToken.getAttribute("content");
    sessionStorage.setItem("CSRFtoken", tokenFromMeta);
    //console.log(" Token CSRF salvato in sessionStorage:", tokenFromMeta);
  } else if (window.CURRENT_USER && window.CURRENT_USER.auth_token) {
    // Se sei loggato, usa auth_token come fallback
    sessionStorage.setItem("CSRFtoken", window.CURRENT_USER.auth_token);
    console.warn(
      " Meta tag non trovato, usato auth_token come fallback:",
      window.CURRENT_USER.auth_token,
    );
  } else {
    console.error(" Nessun token CSRF trovato o generabile.");
  }
});

// Funzione generica per qualsiasi richiesta POST AJAX a URL diretto
function sendAjaxRequest(url, data = {}) {
  const csrfToken = sessionStorage.getItem("CSRFtoken") || "";
  return fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-Csrf-Token": csrfToken,
    },
    body: JSON.stringify(data),
  })
    .then((response) => {
      if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
      return response.json();
    })
    .catch((error) => {
      console.error(" Errore durante la richiesta fetch diretta:", error);
      throw error;
    });
}

window.customFetch = async function (
  section,
  action,
  params = {},
  options = {},
) {
  // Integrazione GlobalLoader
  const showLoader = options && options.showLoader === false ? false : true;
  const loaderText =
    options && options.loaderText
      ? options.loaderText
      : "Operazione in corso...";
  const useLoader = window.GlobalLoader && showLoader;

  if (useLoader) window.GlobalLoader.push(loaderText);

  try {
    const csrfToken = sessionStorage.getItem("CSRFtoken") || "";

    // Se params è FormData: appendi section e action, passa FormData come body
    if (params instanceof FormData) {
      params.append("section", section);
      params.append("action", action);

      // Non mettere content-type a mano!
      const response = await fetch("ajax.php", {
        method: "POST",
        headers: { "X-Csrf-Token": csrfToken },
        credentials: "same-origin",
        body: params,
      });

      // Parsing robusto: sempre prima text, poi JSON.parse con try/catch
      const text = await response.text();
      const contentType = response.headers.get("content-type") || "";

      // Hardening: gestisci body vuoto
      if (!text || text.trim() === "") {
        console.error(`❌ RISPOSTA VUOTA [${section}.${action}]:`, {
          status: response.status,
          statusText: response.statusText,
          contentType: contentType,
        });
        return {
          success: false,
          message: "Risposta server vuota",
          error: "Empty response body",
        };
      }

      // Hardening: verifica content-type se non è JSON
      if (contentType && !contentType.includes("application/json")) {
        const snippet = text.slice(0, 200) + (text.length > 200 ? "..." : "");
        console.error(`❌ CONTENT-TYPE NON JSON [${section}.${action}]:`, {
          status: response.status,
          statusText: response.statusText,
          contentType: contentType,
          snippet: snippet,
        });
        return {
          success: false,
          message: "Risposta server non è JSON",
          error: `Content-Type: ${contentType}`,
          snippet: snippet,
        };
      }

      let json;
      try {
        json = JSON.parse(text);
      } catch (parseError) {
        console.error(`❌ PARSING FALLITO [${section}.${action}] - FormData:`, {
          status: response.status,
          statusText: response.statusText,
          contentType: contentType,
          snippet: text.slice(0, 300) + (text.length > 300 ? "..." : ""),
          error: parseError.message,
        });
        throw new Error(
          `Server returned invalid JSON: ${parseError.message}. Check console for details.`,
        );
      }

      if (typeof json !== "object" || json === null || Array.isArray(json)) {
        console.warn(" JSON non valido o struttura inattesa:", json);
        return {
          success: false,
          message: "Risposta non strutturata correttamente.",
          error: "Malformed JSON",
        };
      }
      return json;
    }

    // Altrimenti, normale invio JSON
    const response = await fetch("ajax.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Csrf-Token": csrfToken,
      },
      credentials: "same-origin",
      body: JSON.stringify({
        section,
        action,
        ...params,
      }),
    });

    // Parsing robusto: sempre prima text, poi JSON.parse con try/catch
    const text = await response.text();
    const contentType = response.headers.get("content-type") || "";

    // Hardening: gestisci body vuoto
    if (!text || text.trim() === "") {
      console.error(`❌ RISPOSTA VUOTA [${section}.${action}]:`, {
        status: response.status,
        statusText: response.statusText,
        contentType: contentType,
      });
      return {
        success: false,
        message: "Risposta server vuota",
        error: "Empty response body",
      };
    }

    // Hardening: verifica content-type se non è JSON
    if (contentType && !contentType.includes("application/json")) {
      const snippet = text.slice(0, 200) + (text.length > 200 ? "..." : "");
      console.error(`❌ CONTENT-TYPE NON JSON [${section}.${action}]:`, {
        status: response.status,
        statusText: response.statusText,
        contentType: contentType,
        snippet: snippet,
      });
      return {
        success: false,
        message: "Risposta server non è JSON",
        error: `Content-Type: ${contentType}`,
        snippet: snippet,
      };
    }

    let json;
    try {
      json = JSON.parse(text);
    } catch (parseError) {
      console.error(`❌ PARSING FALLITO [${section}.${action}] - Normal:`, {
        status: response.status,
        statusText: response.statusText,
        contentType: contentType,
        snippet: text.slice(0, 300) + (text.length > 300 ? "..." : ""),
        error: parseError.message,
      });
      throw new Error(
        `Server returned invalid JSON: ${parseError.message}. Check console for details.`,
      );
    }

    if (typeof json !== "object" || json === null || Array.isArray(json)) {
      console.warn(" JSON non valido o struttura inattesa:", json);
      return {
        success: false,
        message: "Risposta non strutturata correttamente.",
        error: "Malformed JSON",
      };
    }

    return json;
  } catch (error) {
    if (section !== "notifiche") {
      console.error(` Errore customFetch [${section}.${action}]:`, error);
    }
    return {
      success: false,
      message: "Errore nella richiesta al server.",
      error: error.message,
    };
  } finally {
    if (useLoader) window.GlobalLoader.pop();
  }
};
