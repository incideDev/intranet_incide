export async function fetchData(url, options = {}) {
  try {
    const response = await fetch(url, options);
    const data = await response.json();
    if (!data.success) {
      throw new Error(data.message || "Errore sconosciuto");
    }
    return data;
  } catch (error) {
    console.error("Errore API:", error);
    throw error;
  }
}
