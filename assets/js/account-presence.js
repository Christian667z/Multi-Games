(function() {
    const script = document.currentScript;
    const apiUrl = script?.dataset.api;
    if (!apiUrl || !window.MG_CSRF) return;

    async function updatePresence() {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.MG_CSRF
            },
            body: JSON.stringify({ action: 'presence' }),
            cache: 'no-store'
        });
        if (!response.ok) {
            throw new Error(`Mise à jour de présence impossible (${response.status}).`);
        }
    }

    const ping = () => updatePresence().catch(error => console.error(error));
    ping();
    window.setInterval(ping, 30000);
})();
