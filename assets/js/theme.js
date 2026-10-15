/**
 * Multi-Games - Gestionnaire de Thèmes
 * Sauvegarde la préférence dans localStorage et applique les variables CSS
 */
(function() {
    const THEMES = ['red', 'amber', 'purple', 'cyan', 'emerald'];
    const DEFAULT_THEME = 'red';

    function getSavedTheme() {
        return localStorage.getItem('mg_theme') || DEFAULT_THEME;
    }

    function availableThemes() {
        return Array.from(document.querySelectorAll('.theme-selector-select option, #themeSelect option'))
            .map(option => option.value)
            .filter((theme, index, all) => /^[a-z][a-z0-9-]{1,24}$/.test(theme) && all.indexOf(theme) === index);
    }

    function applyTheme(theme) {
        if (!THEMES.includes(theme) && !availableThemes().includes(theme)) theme = DEFAULT_THEME;
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('mg_theme', theme);

        // Synchroniser tous les sélecteurs de thème sur la page
        document.querySelectorAll('.theme-selector-select').forEach(sel => {
            sel.value = theme;
        });

        const mainSelect = document.getElementById('themeSelect');
        if (mainSelect) mainSelect.value = theme;

        // Mettre à jour l'indicateur visuel de la palette
        const paletteBtn = document.getElementById('theme-palette-btn');
        if (paletteBtn) {
            paletteBtn.setAttribute('data-active-theme', theme);
        }
    }

    // Appliquer immédiatement pour éviter tout clignotement
    applyTheme(getSavedTheme());

    // Cycle au clic sur le bouton de palette s'il existe
    window.cycleTheme = function() {
        const themes = [...new Set([...THEMES, ...availableThemes()])];
        const current = getSavedTheme();
        const nextIndex = (themes.indexOf(current) + 1) % themes.length;
        const nextTheme = themes[nextIndex];
        applyTheme(nextTheme);
        return nextTheme;
    };

    window.setSpecificTheme = function(theme) {
        applyTheme(theme);
    };

    document.addEventListener('DOMContentLoaded', () => {
        applyTheme(getSavedTheme());

        const select = document.getElementById('themeSelect');
        if (select) {
            select.value = getSavedTheme();
            select.addEventListener('change', (e) => applyTheme(e.target.value));
        }

        const paletteBtn = document.getElementById('theme-palette-btn');
        if (paletteBtn) {
            paletteBtn.addEventListener('click', (e) => {
                e.preventDefault();
                window.cycleTheme();
            });
        }
    });
})();
