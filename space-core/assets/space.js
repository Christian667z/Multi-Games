"use strict";

/* =============================================================================
 * Space Core — interface de conversation (barre latérale + fil).
 *
 * Contrat d'API : POST « api_space.php »,
 * corps JSON, en-têtes Content-Type: application/json et X-CSRF-Token.
 *   hello  → { text, action, conversation_id, messages, conversations,
 *              ai_enabled, ai_blocked }
 *   list   → { conversations }
 *   load   → { conversation_id, messages }
 *   new    → { conversation_id, conversations }
 *   rename → { conversations } (conversation_id, title)
 *   delete → { conversations } (conversation_id)
 *   chat   → { text, action, conversation_id, conversations } (message,
 *              learn_from_chat optionnel avec consentement explicite)
 *
 * Le jeton CSRF est lu dans <meta name="space-csrf">. Tout le contenu reçu est
 * échappé avant formatage : le markdown léger ne peut pas injecter de HTML.
 * Aucune dépendance externe.
 * ========================================================================== */

(function () {
    "use strict";

    /* ---------------------------------------------------------------------
     * 1. Constantes et références DOM
     * ------------------------------------------------------------------ */
    var API_URL = "api_space.php";
    var GUEST_STORAGE_KEY = "mg_space_guest_conversations_v1";
    var LEARNING_OPT_IN_KEY = "mg_space_learning_opt_in_v1";
    var MAX_GUEST_CONVERSATIONS = 20;
    var MAX_GUEST_MESSAGES = 100;
    var MAX_GUEST_STORAGE_CHARS = 750000;
    var CSRF_META = document.querySelector('meta[name="space-csrf"]');
    var CSRF_TOKEN = CSRF_META ? CSRF_META.getAttribute("content") || "" : "";
    var MOBILE_QUERY = window.matchMedia("(max-width: 900px)");
    var REDUCED_MOTION = window.matchMedia("(prefers-reduced-motion: reduce)");
    var NAVIGATION_DELAY = 700;   // délai avant navigation après une action de Space
    var SCROLL_THRESHOLD = 140;   // px : en dessous, on considère être « en bas »
    var MAX_LINES = 8;            // hauteur maximale de la zone de saisie
    var COPY_FEEDBACK = 1600;     // durée du retour visuel « Copié ! »
    var DEFAULT_EMPTY_TEXT = "Pose-moi une question, demande une explication de règles, ou lance directement une partie.";
    var DEFAULT_SUBTITLE = "Ton compagnon Multi-Games";

    function byId(id) {
        return document.getElementById(id);
    }

    var dom = {
        app: byId("sp-app"),
        sidebar: byId("sp-sidebar"),
        sidebarToggle: byId("sidebar-toggle"),
        sidebarClose: byId("sidebar-close"),
        backdrop: byId("sp-backdrop"),
        newChat: byId("new-chat"),
        history: byId("history"),
        historyCount: byId("history-count"),
        guestNote: byId("guest-note"),
        chat: byId("chat"),
        emptyState: byId("empty-state"),
        emptyText: byId("empty-text"),
        status: byId("status"),
        banner: byId("ai-banner"),
        form: byId("form"),
        input: byId("message"),
        send: byId("send"),
        mic: byId("mic"),
        voice: byId("voice"),
        voiceLabel: byId("voice-label"),
        learnFromChat: byId("learn-from-chat"),
        stop: byId("stop"),
        scrollBottom: byId("scroll-bottom"),
        subtitle: byId("conv-subtitle"),
        dialog: byId("confirm-dialog"),
        dialogTitle: byId("confirm-title"),
        dialogMessage: byId("confirm-message"),
        dialogOk: byId("confirm-ok"),
        dialogCancel: byId("confirm-cancel"),
        chips: Array.prototype.slice.call(document.querySelectorAll(".sp-chip"))
    };

    var state = {
        conversationId: null,
        conversations: [],
        guestConversations: [],
        guest: null,   // null = inconnu tant que « hello » n'a pas répondu
        ready: false,
        busy: false,
        aiEnabled: true,
        aiBlocked: false,
        threadItems: 0,
        hasSpeech: "speechSynthesis" in window
    };

    var typingNode = null;
    var confirmHandler = null;

    function loadLearningPreference() {
        if (!dom.learnFromChat) {
            return;
        }
        try {
            dom.learnFromChat.checked = window.localStorage.getItem(LEARNING_OPT_IN_KEY) === "1";
        } catch (error) {
            dom.learnFromChat.checked = false;
        }
    }

    /* ---------------------------------------------------------------------
     * 2. Utilitaires : échappement, dates, mise en forme
     * ------------------------------------------------------------------ */
    var ESCAPES = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };

    /** Échappe le HTML : systématiquement appelé AVANT tout formatage. */
    function escapeHtml(value) {
        return String(value === null || value === undefined ? "" : value)
            .replace(/[&<>"']/g, function (character) {
                return ESCAPES[character];
            });
    }

    var TIME_FORMAT = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit" });
    var DATE_FORMAT = new Intl.DateTimeFormat("fr-FR", { day: "2-digit", month: "2-digit", year: "numeric" });

    /** Accepte un horodatage Unix (secondes), en millisecondes, ou une chaîne SQL. */
    function timestampOf(value) {
        if (typeof value === "number" && isFinite(value)) {
            return value > 1e11 ? value : value * 1000;
        }
        if (typeof value === "string" && value !== "") {
            var numeric = Number(value);
            if (isFinite(numeric) && value.trim() !== "") {
                return timestampOf(numeric);
            }
            var parsed = Date.parse(value.replace(" ", "T"));
            if (!isNaN(parsed)) {
                return parsed;
            }
        }
        return Date.now();
    }

    /** « à l'instant », « il y a 5 min », « hier », « 12/03/2026 »… */
    function formatRelative(milliseconds) {
        var seconds = Math.floor((Date.now() - milliseconds) / 1000);
        if (seconds < 60) {
            return "à l'instant";
        }
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) {
            return "il y a " + minutes + " min";
        }
        var hours = Math.floor(minutes / 60);
        if (hours < 24) {
            return "il y a " + hours + " h";
        }
        var days = Math.floor(hours / 24);
        if (days === 1) {
            return "hier";
        }
        if (days < 7) {
            return "il y a " + days + " j";
        }
        return DATE_FORMAT.format(new Date(milliseconds));
    }

    /* ---------------------------------------------------------------------
     * 3. Markdown léger (tout est échappé avant formatage)
     * ------------------------------------------------------------------ */

    /** Gras **x**, code `x`, liens [texte](url) et http(s) — sur du texte DÉJÀ échappé. */
    function renderInline(escaped) {
        var codes = [];
        var output = escaped.replace(/`([^`\n]+)`/g, function (match, code) {
            codes.push(code);
            return "\u0000" + (codes.length - 1) + "\u0000";
        });

        // Liens markdown explicites : [texte](https://…)
        output = output.replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, function (match, label, url) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + "</a>";
        });

        output = output.replace(/\*\*([^*\n]+)\*\*/g, "<strong>$1</strong>");

        // URL brutes (hors attributs déjà générés : le préfixe est un espace ou une parenthèse).
        output = output.replace(/(^|[\s(])(https?:\/\/[^\s<>"']+)/g, function (match, prefix, rawUrl) {
            var url = rawUrl;
            var trailing = "";
            var punctuation = /[.,;:!?)\]]+$/.exec(url);
            if (punctuation) {
                trailing = punctuation[0];
                url = url.slice(0, -trailing.length);
            }
            return prefix + '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + "</a>" + trailing;
        });

        return output.replace(/\u0000(\d+)\u0000/g, function (match, index) {
            return "<code>" + codes[Number(index)] + "</code>";
        });
    }

    /** Paragraphes et listes à puces / numérotées. */
    function renderTextBlock(value) {
        var lines = value.split("\n");
        var blocks = [];
        var paragraph = [];
        var items = [];

        function flushParagraph() {
            if (!paragraph.length) {
                return;
            }
            blocks.push("<p>" + paragraph.map(renderInline).join("<br>") + "</p>");
            paragraph = [];
        }

        function flushList() {
            if (!items.length) {
                return;
            }
            blocks.push("<ul>" + items.map(function (item) {
                return "<li>" + renderInline(item) + "</li>";
            }).join("") + "</ul>");
            items = [];
        }

        lines.forEach(function (line) {
            var bullet = /^\s*[-*•]\s+(.*)$/.exec(line);
            var numbered = /^\s*\d+[.)]\s+(.*)$/.exec(line);
            if (bullet || numbered) {
                flushParagraph();
                items.push((bullet || numbered)[1]);
                return;
            }
            if (/^\s*$/.test(line)) {
                flushParagraph();
                flushList();
                return;
            }
            flushList();
            paragraph.push(line.replace(/^\s+|\s+$/g, ""));
        });

        flushParagraph();
        flushList();
        return blocks.join("");
    }

    function renderCodeBlock(language, code) {
        return '<div class="sp-code">' +
            '<div class="sp-code-head">' +
            '<span class="sp-code-lang">' + (language || "code") + "</span>" +
            '<button type="button" class="sp-code-copy" data-copy-code aria-label="Copier le bloc de code">Copier</button>' +
            "</div>" +
            "<pre><code>" + code + "</code></pre>" +
            "</div>";
    }

    /** Rendu complet d'un message : échappement d'abord, formatage ensuite. */
    function renderMarkdown(source) {
        var text = escapeHtml(source).replace(/\r\n?/g, "\n");
        var parts = [];
        var fence = /```([^\n`]*)\n?([\s\S]*?)```/g;
        var lastIndex = 0;
        var match;

        while ((match = fence.exec(text)) !== null) {
            if (match.index > lastIndex) {
                parts.push({ code: false, value: text.slice(lastIndex, match.index) });
            }
            parts.push({ code: true, language: match[1].trim(), value: match[2].replace(/\n+$/, "") });
            lastIndex = fence.lastIndex;
        }

        if (lastIndex < text.length) {
            parts.push({ code: false, value: text.slice(lastIndex) });
        }
        if (!parts.length) {
            parts.push({ code: false, value: text });
        }

        return parts.map(function (part) {
            return part.code ? renderCodeBlock(part.language, part.value) : renderTextBlock(part.value);
        }).join("");
    }

    /** Texte brut destiné à la synthèse vocale (le markdown n'est pas lisible à voix haute). */
    function stripForSpeech(text) {
        return String(text || "")
            .replace(/```[\s\S]*?```/g, " ")
            .replace(/`([^`]+)`/g, "$1")
            .replace(/\*\*/g, "")
            .replace(/^\s*[-*•]\s+/gm, "")
            .replace(/https?:\/\/\S+/g, "lien")
            .replace(/\s+/g, " ")
            .trim();
    }

    /* ---------------------------------------------------------------------
     * 4. Couche réseau
     * ------------------------------------------------------------------ */
    function api(payload) {
        return fetch(API_URL, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-CSRF-Token": CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (data) {
                if (!response.ok) {
                    var error = new Error(data && data.error ? data.error : "Le serveur a répondu " + response.status + ".");
                    error.status = response.status;
                    throw error;
                }
                if (!data || typeof data !== "object") {
                    throw new Error("Réponse illisible du serveur.");
                }
                if (data.success === false) {
                    throw new Error(data.error || "Action refusée par le serveur.");
                }
                return data;
            });
        });
    }

    /* ---------------------------------------------------------------------
     * 5. États transverses : annonces, disponibilité de l'IA, occupation
     * ------------------------------------------------------------------ */
    function setStatus(message) {
        dom.status.textContent = message || "";
    }

    function aiAvailable() {
        return state.aiEnabled && !state.aiBlocked;
    }

    function updateControls() {
        var available = aiAvailable();
        dom.input.disabled = !state.ready || !available;
        dom.mic.disabled = !state.ready || !available || !recognition;
        dom.send.disabled = !state.ready || state.busy || !available || dom.input.value.trim() === "";
        dom.newChat.disabled = !state.ready || state.busy;
    }

    function updateAiAvailability() {
        var available = aiAvailable();
        dom.banner.hidden = available;
        if (!available) {
            dom.banner.textContent = state.aiEnabled
                ? "Ton accès à Space Core a été suspendu par un administrateur : tu peux relire tes discussions, mais l’envoi de messages est désactivé."
                : "Space Core est momentanément désactivé par l’administrateur : la saisie est désactivée, ton historique reste consultable.";
            dom.input.placeholder = "Saisie désactivée";
        } else {
            dom.input.placeholder = "Écris à Space…";
        }
        updateControls();
    }

    function ensureTyping() {
        if (typingNode) {
            return typingNode;
        }
        typingNode = document.createElement("div");
        typingNode.className = "sp-msg sp-msg--space sp-typing";
        typingNode.setAttribute("aria-hidden", "true");
        typingNode.innerHTML = '<div class="sp-avatar">✦</div>' +
            '<div class="sp-msg-body"><div class="sp-bubble sp-typing-bubble">' +
            '<span class="sp-dot"></span><span class="sp-dot"></span><span class="sp-dot"></span>' +
            '<span class="sp-typing-text">Space réfléchit…</span>' +
            "</div></div>";
        return typingNode;
    }

    function setBusy(busy) {
        state.busy = busy;
        if (busy) {
            dom.chat.appendChild(ensureTyping());
            scrollToBottom(true);
            setStatus("Space réfléchit…");
        } else {
            if (typingNode && typingNode.parentNode) {
                typingNode.parentNode.removeChild(typingNode);
            }
            if (dom.status.textContent === "Space réfléchit…") {
                setStatus("");
            }
        }
        dom.chat.setAttribute("aria-busy", busy ? "true" : "false");
        updateControls();
    }

    /**
     * Exécute une action asynchrone en excluant toute autre action concurrente.
     * Le message d'échec est annoncé via la région #status et éventuellement
     * transmis au gestionnaire d'erreur local (bulle d'erreur, par exemple).
     */
    function runExclusive(task, onError) {
        if (state.busy) {
            return Promise.resolve(undefined);
        }
        setBusy(true);
        var failure = null;
        return Promise.resolve()
            .then(task)
            .catch(function (error) {
                failure = error;
                return undefined;
            })
            .then(function (result) {
                setBusy(false);
                if (failure) {
                    var message = failure && failure.message ? failure.message : "Connexion au serveur impossible.";
                    setStatus(message);
                    if (typeof onError === "function") {
                        onError(message, failure);
                    }
                    return undefined;
                }
                return result;
            });
    }

    /* ---------------------------------------------------------------------
     * 6. Fil de discussion
     * ------------------------------------------------------------------ */
    function isNearBottom() {
        return dom.chat.scrollHeight - dom.chat.scrollTop - dom.chat.clientHeight < SCROLL_THRESHOLD;
    }

    function scrollToBottom(smooth) {
        var behavior = smooth && !REDUCED_MOTION.matches ? "smooth" : "auto";
        if (typeof dom.chat.scrollTo === "function") {
            dom.chat.scrollTo({ top: dom.chat.scrollHeight, behavior: behavior });
        } else {
            dom.chat.scrollTop = dom.chat.scrollHeight;
        }
    }

    function updateScrollButton() {
        var far = dom.chat.scrollHeight - dom.chat.scrollTop - dom.chat.clientHeight > SCROLL_THRESHOLD;
        dom.scrollBottom.hidden = !far;
    }

    function insertThreadNode(node, forceScroll) {
        var stick = forceScroll === true || isNearBottom();
        dom.chat.appendChild(node);
        // L'indicateur « Space réfléchit… » reste toujours le dernier élément.
        if (typingNode && typingNode.parentNode === dom.chat) {
            dom.chat.appendChild(typingNode);
        }
        if (stick) {
            scrollToBottom(true);
        }
        updateScrollButton();
    }

    function refreshEmptyState() {
        dom.emptyState.hidden = state.threadItems !== 0;
    }

    function setEmptyText(text) {
        dom.emptyText.innerHTML = renderMarkdown(text || DEFAULT_EMPTY_TEXT);
    }

    function clearThread() {
        Array.prototype.slice.call(dom.chat.children).forEach(function (child) {
            if (child !== dom.emptyState) {
                dom.chat.removeChild(child);
            }
        });
        if (dom.emptyState.parentNode !== dom.chat) {
            dom.chat.appendChild(dom.emptyState);
        }
        state.threadItems = 0;
        refreshEmptyState();
        updateScrollButton();
    }

    function buildMessage(role, content, createdAt, messageId) {
        var article = document.createElement("article");
        article.className = "sp-msg sp-msg--" + role;
        article.dataset.role = role;
        if (messageId !== null && messageId !== undefined) {
            article.dataset.messageId = String(messageId);
        }

        var avatar = document.createElement("div");
        avatar.className = "sp-avatar";
        avatar.setAttribute("aria-hidden", "true");
        avatar.textContent = role === "user" ? "🧑" : "✦";

        var body = document.createElement("div");
        body.className = "sp-msg-body";

        var meta = document.createElement("div");
        meta.className = "sp-msg-meta";
        var author = document.createElement("span");
        author.className = "sp-msg-author";
        author.textContent = role === "user" ? "Toi" : "Space";
        var moment = timestampOf(createdAt);
        var time = document.createElement("time");
        time.className = "sp-msg-time";
        time.dateTime = new Date(moment).toISOString();
        time.textContent = TIME_FORMAT.format(new Date(moment));
        meta.appendChild(author);
        meta.appendChild(time);

        var bubble = document.createElement("div");
        bubble.className = "sp-bubble";
        var markdown = document.createElement("div");
        markdown.className = "sp-md";
        // renderMarkdown() échappe tout avant de formater : innerHTML reste sûr.
        markdown.innerHTML = renderMarkdown(content);
        bubble.appendChild(markdown);

        body.appendChild(meta);
        body.appendChild(bubble);

        if (role === "space") {
            var actions = document.createElement("div");
            actions.className = "sp-msg-actions";
            var copy = document.createElement("button");
            copy.type = "button";
            copy.className = "sp-copy";
            copy.setAttribute("data-copy-message", "true");
            copy.setAttribute("aria-label", "Copier la réponse de Space");
            copy.textContent = "Copier";
            actions.appendChild(copy);
            body.appendChild(actions);
        }

        article.appendChild(avatar);
        article.appendChild(body);
        return article;
    }

    function appendMessage(message, forceScroll) {
        var role = message && message.role === "user" ? "user" : "space";
        var node = buildMessage(role, message ? message.content : "", message ? message.created_at : null, message ? message.id : null);
        state.threadItems += 1;
        refreshEmptyState();
        insertThreadNode(node, forceScroll);
        return node;
    }

    function renderThread(messages) {
        clearThread();
        (Array.isArray(messages) ? messages : []).forEach(function (message) {
            appendMessage(message, false);
        });
        refreshEmptyState();
        updateScrollButton();
    }

    /** Bloc d'information (erreur réseau, etc.) avec réessai facultatif. */
    function appendNotice(text, retry) {
        var notice = document.createElement("div");
        notice.className = "sp-notice";
        var paragraph = document.createElement("p");
        paragraph.textContent = text;
        notice.appendChild(paragraph);

        if (typeof retry === "function") {
            var button = document.createElement("button");
            button.type = "button";
            button.className = "sp-btn sp-btn--small";
            button.textContent = "Réessayer";
            button.addEventListener("click", function () {
                var parent = notice.parentNode;
                if (parent) {
                    parent.removeChild(notice);
                }
                state.threadItems = Math.max(0, state.threadItems - 1);
                refreshEmptyState();
                retry();
            });
            notice.appendChild(button);
        }

        state.threadItems += 1;
        refreshEmptyState();
        insertThreadNode(notice, true);
        return notice;
    }

    /* ---------------------------------------------------------------------
     * 7. Barre latérale : rendu de l'historique
     * ------------------------------------------------------------------ */
    function conversationById(id) {
        var numeric = Number(id);
        for (var index = 0; index < state.conversations.length; index += 1) {
            if (Number(state.conversations[index].id) === numeric) {
                return state.conversations[index];
            }
        }
        return null;
    }

    function buildHistoryItem(conversation) {
        var id = Number(conversation.id);
        var title = conversation.title || "Discussion";
        var active = id === state.conversationId;

        var item = document.createElement("div");
        item.className = "sp-conv" + (active ? " is-active" : "");
        item.dataset.id = String(id);
        item.setAttribute("role", "listitem");

        var open = document.createElement("button");
        open.type = "button";
        open.className = "sp-conv-open";
        open.dataset.action = "open";
        if (active) {
            open.setAttribute("aria-current", "true");
        }

        var titleNode = document.createElement("span");
        titleNode.className = "sp-conv-title";
        titleNode.textContent = title;

        var excerpt = document.createElement("span");
        excerpt.className = "sp-conv-excerpt";
        excerpt.textContent = conversation.excerpt || "Aucun message pour l’instant.";

        var meta = document.createElement("span");
        meta.className = "sp-conv-meta";
        var total = Number(conversation.messages || 0);
        var count = document.createElement("span");
        count.textContent = total === 1 ? "1 message" : total + " messages";
        var when = document.createElement("span");
        when.textContent = formatRelative(timestampOf(conversation.updated_at || conversation.created_at));
        meta.appendChild(count);
        meta.appendChild(when);

        open.appendChild(titleNode);
        open.appendChild(excerpt);
        open.appendChild(meta);

        var actions = document.createElement("span");
        actions.className = "sp-conv-actions";
        var rename = document.createElement("button");
        rename.type = "button";
        rename.className = "sp-conv-btn";
        rename.dataset.action = "rename";
        rename.setAttribute("aria-label", "Renommer « " + title + " »");
        rename.title = "Renommer";
        rename.textContent = "✎";
        var remove = document.createElement("button");
        remove.type = "button";
        remove.className = "sp-conv-btn";
        remove.dataset.action = "delete";
        remove.setAttribute("aria-label", "Supprimer « " + title + " »");
        remove.title = "Supprimer";
        remove.textContent = "🗑";
        actions.appendChild(rename);
        actions.appendChild(remove);

        item.appendChild(open);
        item.appendChild(actions);
        return item;
    }

    function renderHistory() {
        dom.history.replaceChildren();
        dom.historyCount.textContent = String(state.conversations.length);

        if (!state.conversations.length) {
            var empty = document.createElement("p");
            empty.className = "sp-history-empty";
            if (state.guest === true) {
                empty.textContent = "Tes nouvelles discussions seront sauvegardées sur cet appareil.";
            } else if (state.guest === false) {
                empty.textContent = "Aucune discussion pour l’instant. Lance-toi avec « Nouvelle discussion » !";
            } else {
                empty.textContent = "Chargement de l’historique…";
            }
            dom.history.appendChild(empty);
            return;
        }

        state.conversations.forEach(function (conversation) {
            dom.history.appendChild(buildHistoryItem(conversation));
        });
    }

    function applyConversations(list) {
        if (Array.isArray(list)) {
            state.conversations = list;
        }
        renderHistory();
        updateSubtitle();
    }

    function readGuestConversations() {
        try {
            var stored = window.localStorage.getItem(GUEST_STORAGE_KEY);
            var parsed = stored ? JSON.parse(stored) : [];
            if (!Array.isArray(parsed)) {
                throw new Error("Format d’historique invalide.");
            }
            return parsed.filter(function (conversation) {
                return conversation && typeof conversation.id === "number" && conversation.id < 0 &&
                    typeof conversation.title === "string" && Array.isArray(conversation.messages);
            }).map(function (conversation) {
                return {
                    id: conversation.id,
                    title: conversation.title.slice(0, 80) || "Discussion",
                    created_at: Number(conversation.created_at) || Math.floor(Date.now() / 1000),
                    updated_at: Number(conversation.updated_at) || Math.floor(Date.now() / 1000),
                    messages: conversation.messages.filter(function (message) {
                        return message && (message.role === "user" || message.role === "assistant") &&
                            typeof message.content === "string";
                    }).slice(-MAX_GUEST_MESSAGES).map(function (message) {
                        return {
                            role: message.role,
                            content: message.content.slice(0, 4000),
                            created_at: Number(message.created_at) || Math.floor(Date.now() / 1000)
                        };
                    })
                };
            }).sort(function (a, b) {
                return b.updated_at - a.updated_at;
            }).slice(0, MAX_GUEST_CONVERSATIONS);
        } catch (error) {
            setStatus("L’historique local n’a pas pu être lu. Vérifie les données du navigateur.");
            return [];
        }
    }

    function saveGuestConversations() {
        state.guestConversations.sort(function (a, b) {
            return b.updated_at - a.updated_at;
        });
        state.guestConversations = state.guestConversations.slice(0, MAX_GUEST_CONVERSATIONS);
        state.guestConversations.forEach(function (conversation) {
            conversation.messages = conversation.messages.slice(-MAX_GUEST_MESSAGES);
        });

        var serialized = JSON.stringify(state.guestConversations);
        while (serialized.length > MAX_GUEST_STORAGE_CHARS && state.guestConversations.length > 1) {
            state.guestConversations.pop();
            serialized = JSON.stringify(state.guestConversations);
        }
        if (serialized.length > MAX_GUEST_STORAGE_CHARS) {
            var onlyConversation = state.guestConversations[0];
            onlyConversation.messages = onlyConversation.messages.slice(-20);
            serialized = JSON.stringify(state.guestConversations);
        }

        try {
            window.localStorage.setItem(GUEST_STORAGE_KEY, serialized);
        } catch (error) {
            setStatus("Impossible de sauvegarder l’historique sur cet appareil. Vérifie l’espace disponible du navigateur.");
            return false;
        }
        return true;
    }

    function guestConversationById(id) {
        var numeric = Number(id);
        return state.guestConversations.find(function (conversation) {
            return conversation.id === numeric;
        }) || null;
    }

    function guestConversationSummaries() {
        return state.guestConversations.map(function (conversation) {
            var lastMessage = conversation.messages[conversation.messages.length - 1];
            return {
                id: conversation.id,
                title: conversation.title,
                created_at: conversation.created_at,
                updated_at: conversation.updated_at,
                messages: conversation.messages.length,
                excerpt: lastMessage ? lastMessage.content.slice(0, 120) : "Aucun message pour l’instant."
            };
        });
    }

    function refreshGuestHistory() {
        state.conversations = guestConversationSummaries();
        renderHistory();
        updateSubtitle();
    }

    function createGuestConversation(title) {
        var id = -Date.now();
        while (guestConversationById(id)) {
            id -= 1;
        }
        var now = Math.floor(Date.now() / 1000);
        var conversation = {
            id: id,
            title: String(title || "Nouvelle discussion").trim().slice(0, 80) || "Nouvelle discussion",
            created_at: now,
            updated_at: now,
            messages: []
        };
        state.guestConversations.unshift(conversation);
        state.conversationId = id;
        saveGuestConversations();
        refreshGuestHistory();
        return conversation;
    }

    function appendGuestMessage(conversation, role, content, createdAt) {
        if (!conversation) {
            return;
        }
        var message = {
            role: role === "assistant" ? "assistant" : "user",
            content: String(content || "").slice(0, 4000),
            created_at: Number(createdAt) || Math.floor(Date.now() / 1000)
        };
        if (message.role === "user" && !conversation.messages.some(function (item) {
            return item.role === "user";
        })) {
            conversation.title = message.content.slice(0, 60) || "Nouvelle discussion";
        }
        conversation.messages.push(message);
        conversation.messages = conversation.messages.slice(-MAX_GUEST_MESSAGES);
        conversation.updated_at = message.created_at;
        saveGuestConversations();
        refreshGuestHistory();
    }

    function updateSubtitle() {
        var active = conversationById(state.conversationId);
        if (active && active.title) {
            dom.subtitle.textContent = active.title;
            return;
        }
        dom.subtitle.textContent = state.guest ? "Historique sauvegardé sur cet appareil" : DEFAULT_SUBTITLE;
    }

    /* ---------------------------------------------------------------------
     * 8. Renommage en place et suppression
     * ------------------------------------------------------------------ */
    function startRename(item, id) {
        if (state.busy) {
            return;
        }
        var titleNode = item.querySelector(".sp-conv-title");
        if (!titleNode || item.querySelector(".sp-conv-rename")) {
            return;
        }

        var current = titleNode.textContent || "";
        var input = document.createElement("input");
        input.type = "text";
        input.className = "sp-conv-rename";
        input.value = current;
        input.maxLength = 80;
        input.setAttribute("aria-label", "Nouveau titre de la discussion");
        titleNode.replaceWith(input);
        input.focus();
        input.select();

        var closed = false;
        function close(text) {
            if (closed) {
                return;
            }
            closed = true;
            if (!input.parentNode) {
                return;
            }
            var span = document.createElement("span");
            span.className = "sp-conv-title";
            span.textContent = text === undefined ? current : text;
            input.replaceWith(span);
        }

        function commit() {
            if (closed) {
                return;
            }
            var value = input.value.trim();
            if (!value || value === current) {
                close(current);
                return;
            }
            if (state.busy) {
                close(current);
                return;
            }
            closed = true;
            input.readOnly = true;
            if (state.guest) {
                var guestConversation = guestConversationById(id);
                if (!guestConversation) {
                    closed = false;
                    close(current);
                    setStatus("Cette discussion n’existe plus dans l’historique local.");
                    return;
                }
                guestConversation.title = value.slice(0, 80);
                saveGuestConversations();
                refreshGuestHistory();
                setStatus("Discussion renommée en « " + guestConversation.title + " ».");
                return;
            }
            runExclusive(function () {
                return api({ operation: "rename", conversation_id: id, title: value }).then(function (data) {
                    applyConversations(data.conversations);
                    setStatus("Discussion renommée en « " + value + " ».");
                });
            }, function (message) {
                closed = false;
                close(current);
                setStatus(message);
            });
        }

        input.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                commit();
            } else if (event.key === "Escape") {
                event.preventDefault();
                close(current);
            }
        });
        input.addEventListener("blur", function () {
            commit();
        });
    }

    function openConfirmDialog(options) {
        confirmHandler = options.onConfirm || null;
        dom.dialogTitle.textContent = options.title || "Confirmation";
        dom.dialogMessage.textContent = options.message || "";
        dom.dialogOk.textContent = options.confirmLabel || "Confirmer";

        if (dom.dialog && typeof dom.dialog.showModal === "function") {
            if (!dom.dialog.open) {
                dom.dialog.showModal();
            }
            // Le navigateur place le focus sur le premier contrôle (Annuler).
            return;
        }
        // Repli pour les navigateurs sans <dialog>.
        if (window.confirm(options.message || options.title || "Confirmer ?")) {
            var handler = confirmHandler;
            confirmHandler = null;
            if (handler) {
                handler();
            }
        } else {
            confirmHandler = null;
        }
    }

    function closeConfirmDialog() {
        confirmHandler = null;
        if (dom.dialog && dom.dialog.open) {
            dom.dialog.close();
        }
    }

    function askDelete(id) {
        if (state.busy) {
            return;
        }
        var conversation = conversationById(id);
        var title = conversation && conversation.title ? conversation.title : "Cette discussion";
        openConfirmDialog({
            title: "Supprimer la discussion",
            message: "« " + title + " » et l’ensemble de ses messages seront définitivement supprimés.",
            confirmLabel: "Supprimer",
            onConfirm: function () {
                removeConversation(id);
            }
        });
    }

    function removeConversation(id) {
        if (state.guest) {
            state.guestConversations = state.guestConversations.filter(function (conversation) {
                return conversation.id !== Number(id);
            });
            saveGuestConversations();
            if (state.conversationId === Number(id)) {
                var nextConversation = state.guestConversations[0] || createGuestConversation("Nouvelle discussion");
                state.conversationId = nextConversation.id;
                renderThread(nextConversation.messages);
                setEmptyText(DEFAULT_EMPTY_TEXT);
                updateSubtitle();
            } else {
                refreshGuestHistory();
            }
            setStatus("Discussion supprimée de cet appareil.");
            return;
        }
        runExclusive(function () {
            return api({ operation: "delete", conversation_id: id }).then(function (data) {
                applyConversations(data.conversations);
                if (state.conversationId === id) {
                    state.conversationId = null;
                    return openFreshConversation(true).then(function () {
                        setStatus("Discussion supprimée.");
                    });
                }
                setStatus("Discussion supprimée.");
                return undefined;
            });
        });
    }

    /* ---------------------------------------------------------------------
     * 9. Navigation dans l'historique
     * ------------------------------------------------------------------ */
    function openConversation(id) {
        if (state.busy) {
            return;
        }
        if (id === state.conversationId) {
            closeDrawer();
            return;
        }
        if (state.guest) {
            var guestConversation = guestConversationById(id);
            if (!guestConversation) {
                setStatus("Cette discussion n’existe plus dans l’historique local.");
                refreshGuestHistory();
                return;
            }
            state.conversationId = guestConversation.id;
            renderThread(guestConversation.messages);
            setEmptyText(DEFAULT_EMPTY_TEXT);
            refreshGuestHistory();
            stopSpeaking();
            closeDrawer();
            scrollToBottom(false);
            setStatus("Discussion chargée.");
            return;
        }
        runExclusive(function () {
            return api({ operation: "load", conversation_id: id }).then(function (data) {
                state.conversationId = data.conversation_id === null || data.conversation_id === undefined
                    ? id
                    : Number(data.conversation_id);
                renderThread(data.messages || []);
                setEmptyText(DEFAULT_EMPTY_TEXT);
                renderHistory();
                updateSubtitle();
                stopSpeaking();
                closeDrawer();
                scrollToBottom(false);
                setStatus("Discussion chargée.");
            });
        }, function () {
            // Conversation supprimée entre-temps ou session expirée : on resynchronise.
            api({ operation: "list" }).then(function (data) {
                applyConversations(data.conversations);
            }).catch(function () {
                return undefined;
            });
        });
    }

    /** Ouvre une discussion neuve (le serveur la crée pour les comptes connectés). */
    function openFreshConversation(silent) {
        if (state.guest) {
            var guestConversation = createGuestConversation("Nouvelle discussion");
            return api({ operation: "clear" }).then(function () {
                renderThread([]);
                setEmptyText(DEFAULT_EMPTY_TEXT);
                updateSubtitle();
                stopSpeaking();
                closeDrawer();
                if (!silent) {
                    dom.input.focus();
                    setStatus("Nouvelle discussion ouverte.");
                }
                return guestConversation;
            });
        }
        return api({ operation: "new" }).then(function (data) {
            var id = data.conversation_id === null || data.conversation_id === undefined ? null : Number(data.conversation_id);
            if (id === null) {
                throw new Error("La nouvelle discussion n’a pas pu être créée.");
            }
            return { id: id, conversations: data.conversations };
        }).then(function (result) {
            state.conversationId = result.id;
            applyConversations(result.conversations);
            clearThread();
            setEmptyText(DEFAULT_EMPTY_TEXT);
            refreshEmptyState();
            updateSubtitle();
            stopSpeaking();
            closeDrawer();
            if (!silent) {
                dom.input.focus();
                setStatus("Nouvelle discussion ouverte.");
            }
            return undefined;
        });
    }

    /* ---------------------------------------------------------------------
     * 10. Envoi de messages
     * ------------------------------------------------------------------ */
    function handleAction(action) {
        if (!action || typeof action.url !== "string" || action.url === "") {
            return;
        }
        if (action.action !== "OPEN_GAME" && action.action !== "NAVIGATE") {
            return;
        }
        var target;
        try {
            target = new URL(action.url, window.location.href);
        } catch (error) {
            return;
        }
        // Seules les URL de même origine déclenchent une navigation.
        if (target.origin !== window.location.origin) {
            return;
        }
        setStatus("Navigation vers " + target.href + "…");
        window.setTimeout(function () {
            window.location.assign(target.href);
        }, NAVIGATION_DELAY);
    }

    function sendMessage(text) {
        var content = String(text || "").trim();
        if (!content || state.busy) {
            return Promise.resolve(undefined);
        }
        if (!aiAvailable()) {
            setStatus("Space est indisponible pour le moment.");
            return Promise.resolve(undefined);
        }

        var payload = {
            message: content,
            learn_from_chat: Boolean(dom.learnFromChat && dom.learnFromChat.checked)
        };
        var guestConversation = null;
        if (state.guest) {
            guestConversation = guestConversationById(state.conversationId);
            if (!guestConversation) {
                guestConversation = createGuestConversation(content);
            }
            payload.history = guestConversation.messages.slice(-12).map(function (message) {
                return { role: message.role, content: message.content };
            });
        } else if (state.conversationId !== null) {
            payload.conversation_id = state.conversationId;
        }

        return runExclusive(function () {
            appendMessage({ role: "user", content: content, created_at: Math.floor(Date.now() / 1000) }, true);
            if (guestConversation) {
                appendGuestMessage(guestConversation, "user", content);
            }
            return api(payload).then(function (data) {
                if (data.conversation_id !== null && data.conversation_id !== undefined) {
                    state.conversationId = Number(data.conversation_id);
                }
                if (state.guest) {
                    refreshGuestHistory();
                } else {
                    applyConversations(data.conversations);
                }
                if (data.text) {
                    appendMessage({ role: "space", content: data.text }, false);
                    if (guestConversation) {
                        appendGuestMessage(guestConversation, "assistant", data.text);
                    }
                    speak(data.text);
                }
                handleAction(data.action);
                return undefined;
            });
        }, function (message) {
            appendNotice(message, function () {
                sendMessage(content);
            });
        });
    }

    /* ---------------------------------------------------------------------
     * 11. Zone de saisie
     * ------------------------------------------------------------------ */
    function autoGrow() {
        var input = dom.input;
        input.style.height = "auto";
        var styles = window.getComputedStyle(input);
        var lineHeight = parseFloat(styles.lineHeight);
        if (!isFinite(lineHeight) || lineHeight <= 0) {
            lineHeight = 22;
        }
        var padding = (parseFloat(styles.paddingTop) || 0) + (parseFloat(styles.paddingBottom) || 0);
        var maxHeight = lineHeight * MAX_LINES + padding;
        var next = Math.min(input.scrollHeight, maxHeight);
        input.style.height = next + "px";
        input.style.overflowY = input.scrollHeight > maxHeight + 1 ? "auto" : "hidden";
    }

    function submitComposer() {
        var text = dom.input.value.trim();
        if (!text || state.busy || !aiAvailable()) {
            return;
        }
        dom.input.value = "";
        autoGrow();
        updateControls();
        dom.input.focus();
        sendMessage(text);
    }

    /* ---------------------------------------------------------------------
     * 12. Voix : synthèse (lecture) et reconnaissance (dictée)
     * ------------------------------------------------------------------ */
    function speak(text) {
        if (!state.hasSpeech || !dom.voice.checked) {
            return;
        }
        var phrase = stripForSpeech(text);
        if (!phrase) {
            return;
        }
        window.speechSynthesis.cancel();
        var utterance = new SpeechSynthesisUtterance(phrase);
        utterance.lang = "fr-FR";
        window.speechSynthesis.speak(utterance);
    }

    function stopSpeaking() {
        if (state.hasSpeech) {
            window.speechSynthesis.cancel();
        }
    }

    var recognition = null;
    var listening = false;

    function setupRecognition() {
        var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Recognition) {
            dom.mic.disabled = true;
            dom.mic.title = "Reconnaissance vocale indisponible sur ce navigateur";
            return;
        }

        recognition = new Recognition();
        recognition.lang = "fr-FR";
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;

        recognition.onstart = function () {
            listening = true;
            dom.mic.classList.add("is-listening");
            dom.mic.setAttribute("aria-pressed", "true");
            dom.mic.textContent = "⏹";
            setStatus("Écoute en cours… parle maintenant.");
        };

        recognition.onend = function () {
            listening = false;
            dom.mic.classList.remove("is-listening");
            dom.mic.setAttribute("aria-pressed", "false");
            dom.mic.textContent = "🎙";
            if (!state.busy) {
                setStatus("");
            }
        };

        recognition.onerror = function (event) {
            listening = false;
            dom.mic.classList.remove("is-listening");
            dom.mic.setAttribute("aria-pressed", "false");
            dom.mic.textContent = "🎙";
            var reason = event && event.error ? event.error : "";
            if (reason === "not-allowed" || reason === "service-not-allowed") {
                setStatus("Microphone refusé : autorise l’accès au micro dans le navigateur, ou utilise le clavier.");
            } else if (reason === "no-speech") {
                setStatus("Aucune parole détectée. Réessaie ou utilise le clavier.");
            } else {
                setStatus("Dictée indisponible : utilise le clavier.");
            }
        };

        recognition.onresult = function (event) {
            var transcript = "";
            try {
                transcript = event.results[0][0].transcript || "";
            } catch (error) {
                transcript = "";
            }
            transcript = transcript.trim();
            if (!transcript) {
                return;
            }
            var existing = dom.input.value.replace(/\s+$/, "");
            dom.input.value = existing ? existing + " " + transcript : transcript;
            autoGrow();
            updateControls();
            dom.input.focus();
            setStatus("Transcription ajoutée : vérifie puis envoie.");
        };
    }

    /* ---------------------------------------------------------------------
     * 13. Copie dans le presse-papiers
     * ------------------------------------------------------------------ */
    function legacyCopy(text) {
        var helper = document.createElement("textarea");
        helper.value = text;
        helper.setAttribute("readonly", "readonly");
        helper.style.position = "fixed";
        helper.style.top = "-1000px";
        helper.style.opacity = "0";
        document.body.appendChild(helper);
        helper.select();
        var ok = false;
        try {
            ok = document.execCommand("copy");
        } catch (error) {
            ok = false;
        }
        document.body.removeChild(helper);
        if (!ok) {
            throw new Error("Copie refusée par le navigateur.");
        }
    }

    function copyText(text, button) {
        var original = button.textContent;
        var finish = function (label, success) {
            button.textContent = label;
            button.classList.toggle("is-copied", success);
            window.setTimeout(function () {
                button.textContent = original;
                button.classList.remove("is-copied");
            }, COPY_FEEDBACK);
        };

        var write = (navigator.clipboard && window.isSecureContext)
            ? navigator.clipboard.writeText(text)
            : Promise.reject(new Error("api indisponible"));

        write.then(function () {
            finish("Copié !", true);
        }).catch(function () {
            try {
                legacyCopy(text);
                finish("Copié !", true);
            } catch (error) {
                finish("Copie impossible", false);
            }
        });
    }

    /* ---------------------------------------------------------------------
     * 14. Barre latérale : tiroir mobile / repli bureau
     * ------------------------------------------------------------------ */
    function isMobileLayout() {
        return MOBILE_QUERY.matches;
    }

    function syncSidebarToggle() {
        var expanded = isMobileLayout()
            ? dom.app.classList.contains("is-drawer-open")
            : !dom.app.classList.contains("is-collapsed");
        dom.sidebarToggle.setAttribute("aria-expanded", expanded ? "true" : "false");
    }

    function openDrawer() {
        dom.app.classList.add("is-drawer-open");
        dom.backdrop.hidden = false;
        syncSidebarToggle();
    }

    function closeDrawer() {
        dom.app.classList.remove("is-drawer-open");
        dom.backdrop.hidden = true;
        syncSidebarToggle();
    }

    function toggleSidebar() {
        if (isMobileLayout()) {
            if (dom.app.classList.contains("is-drawer-open")) {
                closeDrawer();
            } else {
                openDrawer();
            }
            return;
        }
        dom.app.classList.toggle("is-collapsed");
        syncSidebarToggle();
    }

    function handleBreakpointChange() {
        closeDrawer();
        if (!isMobileLayout()) {
            dom.app.classList.remove("is-drawer-open");
        }
        syncSidebarToggle();
    }

    /* ---------------------------------------------------------------------
     * 15. Écouteurs d'événements
     * ------------------------------------------------------------------ */
    function bindEvents() {
        // Fil : défilement et bouton « revenir en bas ».
        dom.chat.addEventListener("scroll", updateScrollButton, { passive: true });
        dom.scrollBottom.addEventListener("click", function () {
            scrollToBottom(true);
            dom.chat.focus({ preventScroll: true });
        });
        window.addEventListener("resize", function () {
            updateScrollButton();
            autoGrow();
        });

        // Copie des messages et des blocs de code (délégation d'événements).
        dom.chat.addEventListener("click", function (event) {
            var target = event.target;
            if (!target || typeof target.closest !== "function") {
                return;
            }
            var copyMessage = target.closest("[data-copy-message]");
            if (copyMessage) {
                var message = copyMessage.closest(".sp-msg");
                var body = message ? message.querySelector(".sp-md") : null;
                if (body) {
                    copyText(body.innerText || body.textContent || "", copyMessage);
                }
                return;
            }
            var copyCode = target.closest("[data-copy-code]");
            if (copyCode) {
                var block = copyCode.closest(".sp-code");
                var code = block ? block.querySelector("code") : null;
                if (code) {
                    copyText(code.textContent || "", copyCode);
                }
            }
        });

        // Suggestions de départ.
        dom.chips.forEach(function (chip) {
            chip.addEventListener("click", function () {
                if (state.busy || !aiAvailable()) {
                    return;
                }
                sendMessage(chip.dataset.prompt || chip.textContent || "");
            });
        });

        // Historique : ouvrir, renommer, supprimer.
        dom.history.addEventListener("click", function (event) {
            var button = event.target && typeof event.target.closest === "function"
                ? event.target.closest("button[data-action]")
                : null;
            if (!button) {
                return;
            }
            var item = button.closest(".sp-conv");
            if (!item) {
                return;
            }
            var id = Number(item.dataset.id);
            var action = button.dataset.action;
            if (action === "open") {
                openConversation(id);
            } else if (action === "rename") {
                startRename(item, id);
            } else if (action === "delete") {
                askDelete(id);
            }
        });

        // Nouvelle discussion.
        dom.newChat.addEventListener("click", function () {
            runExclusive(function () {
                return openFreshConversation(false);
            });
        });

        // Zone de saisie.
        dom.input.addEventListener("input", function () {
            autoGrow();
            updateControls();
        });
        dom.input.addEventListener("keydown", function (event) {
            if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                submitComposer();
            }
        });
        dom.form.addEventListener("submit", function (event) {
            event.preventDefault();
            submitComposer();
        });

        // Voix.
        dom.voice.addEventListener("change", function () {
            if (!dom.voice.checked) {
                stopSpeaking();
            }
        });
        dom.learnFromChat.addEventListener("change", function () {
            try {
                window.localStorage.setItem(LEARNING_OPT_IN_KEY, dom.learnFromChat.checked ? "1" : "0");
            } catch (error) {
                setStatus("La préférence d’apprentissage ne peut pas être enregistrée sur cet appareil.");
            }
        });
        dom.stop.addEventListener("click", function () {
            stopSpeaking();
            setStatus("Lecture vocale arrêtée.");
        });
        dom.mic.addEventListener("click", function () {
            if (!recognition) {
                setStatus("La dictée vocale n’est pas disponible sur ce navigateur.");
                return;
            }
            if (listening) {
                recognition.stop();
                return;
            }
            try {
                recognition.start();
            } catch (error) {
                setStatus("Microphone indisponible : autorise l’accès au micro puis réessaie.");
            }
        });

        // Dialogue de confirmation.
        dom.dialogOk.addEventListener("click", function () {
            var handler = confirmHandler;
            confirmHandler = null;
            if (dom.dialog.open) {
                dom.dialog.close();
            }
            if (handler) {
                handler();
            }
        });
        dom.dialogCancel.addEventListener("click", closeConfirmDialog);
        dom.dialog.addEventListener("cancel", function () {
            confirmHandler = null;
        });
        dom.dialog.addEventListener("click", function (event) {
            if (event.target === dom.dialog) {
                closeConfirmDialog();
            }
        });

        // Barre latérale.
        dom.sidebarToggle.addEventListener("click", toggleSidebar);
        dom.sidebarClose.addEventListener("click", function () {
            if (isMobileLayout()) {
                closeDrawer();
                return;
            }
            dom.app.classList.add("is-collapsed");
            syncSidebarToggle();
        });
        dom.backdrop.addEventListener("click", closeDrawer);
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && isMobileLayout() && dom.app.classList.contains("is-drawer-open")) {
                closeDrawer();
            }
        });
        if (typeof MOBILE_QUERY.addEventListener === "function") {
            MOBILE_QUERY.addEventListener("change", handleBreakpointChange);
        } else if (typeof MOBILE_QUERY.addListener === "function") {
            MOBILE_QUERY.addListener(handleBreakpointChange);
        }
    }

    /* ---------------------------------------------------------------------
     * 16. Démarrage
     * ------------------------------------------------------------------ */
    function setupVoice() {
        if (!state.hasSpeech) {
            dom.voice.disabled = true;
            dom.voiceLabel.textContent = "Lecture vocale indisponible sur ce navigateur";
            dom.stop.disabled = true;
            dom.stop.title = "Synthèse vocale indisponible sur ce navigateur";
        }
        setupRecognition();
    }

    function loadInitialState() {
        // Rendu provisoire immédiat : la page reste lisible même si l'API échoue.
        renderHistory();
        setEmptyText(DEFAULT_EMPTY_TEXT);
        refreshEmptyState();
        updateControls();
        syncSidebarToggle();
        autoGrow();

        api({ operation: "hello" }).then(function (data) {
            var id = data.conversation_id === null || data.conversation_id === undefined ? null : Number(data.conversation_id);
            state.guest = id === null;
            state.aiEnabled = data.ai_enabled !== false;
            state.aiBlocked = data.ai_blocked === true;
            state.ready = true;
            dom.guestNote.hidden = !state.guest;

            if (state.guest) {
                state.guestConversations = readGuestConversations();
                if (!state.guestConversations.length && Array.isArray(data.messages) && data.messages.length) {
                    var recoveredConversation = createGuestConversation("Discussion précédente");
                    recoveredConversation.messages = data.messages.filter(function (message) {
                        return message && (message.role === "user" || message.role === "assistant") &&
                            typeof message.content === "string";
                    }).slice(-MAX_GUEST_MESSAGES).map(function (message) {
                        return {
                            role: message.role,
                            content: message.content.slice(0, 4000),
                            created_at: Number(message.created_at) || Math.floor(Date.now() / 1000)
                        };
                    });
                    var firstUserMessage = recoveredConversation.messages.find(function (message) {
                        return message.role === "user";
                    });
                    if (firstUserMessage) {
                        recoveredConversation.title = firstUserMessage.content.slice(0, 60) || recoveredConversation.title;
                    }
                    if (recoveredConversation.messages.length) {
                        recoveredConversation.updated_at = recoveredConversation.messages[recoveredConversation.messages.length - 1].created_at;
                    }
                    saveGuestConversations();
                    refreshGuestHistory();
                }
                state.conversationId = state.guestConversations.length ? state.guestConversations[0].id : null;
                refreshGuestHistory();
            } else {
                state.conversationId = id;
                applyConversations(data.conversations);
            }

            var activeGuestConversation = state.guest ? guestConversationById(state.conversationId) : null;
            var messages = activeGuestConversation
                ? activeGuestConversation.messages
                : (Array.isArray(data.messages) ? data.messages : []);
            if (messages.length) {
                renderThread(messages);
            } else {
                clearThread();
                setEmptyText(data.text || DEFAULT_EMPTY_TEXT);
                refreshEmptyState();
            }

            updateAiAvailability();
            updateControls();
            updateSubtitle();
            scrollToBottom(false);
        }).catch(function (error) {
            // Échec réseau ou session expirée : l'interface reste utilisable.
            state.ready = false;
            updateControls();
            setStatus(error && error.message ? error.message : "Connexion au serveur impossible.");
            renderHistory();
            setEmptyText("Space n’a pas pu charger la conversation. Vérifie ta connexion, puis recharge la page.");
            refreshEmptyState();
        });
    }

    function init() {
        loadLearningPreference();
        setupVoice();
        bindEvents();
        loadInitialState();
    }

    init();
}());
