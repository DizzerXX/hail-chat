/**
 * TDEChat embeddable visitor widget.
 *
 * Embed on any page:
 *   <script src="https://your-tdechat-host/widget.js"
 *           data-inbox="website"
 *           data-reverb-key="YOUR_REVERB_APP_KEY"
 *           data-api="https://your-tdechat-host"   (optional, defaults to the script's origin)
 *           data-ws-host="your-tdechat-host"       (optional, defaults to the API host)
 *           data-ws-port="8080"                    (optional)
 *           data-ws-tls="false"                    (optional)
 *           data-language="ms"></script>
 *
 * Self-contained: no dependencies. Realtime over the Pusher protocol
 * (Laravel Reverb) with HTTP polling as a fallback.
 */
(function () {
    'use strict';

    const script = document.currentScript;
    const config = {
        api: (script.dataset.api || new URL(script.src).origin).replace(/\/$/, ''),
        inbox: script.dataset.inbox || 'website',
        reverbKey: script.dataset.reverbKey || '',
        wsHost: script.dataset.wsHost || new URL(script.dataset.api || script.src).hostname,
        wsPort: script.dataset.wsPort || '8080',
        wsTls: script.dataset.wsTls === 'true',
        language: script.dataset.language || 'ms',
    };

    const storageKey = 'tdechat.' + config.inbox;
    const seenMessages = new Set();
    const messageNodes = new Map();

    let conversation = null;
    let socket = null;
    let pollTimer = null;
    let queueTimer = null;
    let typingTimer = null;
    let visitorTypingActive = false;
    let visitorTypingTimer = null;
    let open = false;
    let botName = 'Bot'; // overridden by admin branding (app-config)

    // Pre-chat field config (admin-driven via app-config). Defaults collect just
    // an optional name until app-config resolves.
    let fieldConfig = {
        name: { enabled: true, required: false, label: 'Your name (optional)' },
        email: { enabled: false, required: false, label: 'Email' },
        phone: { enabled: false, required: false, label: 'Phone' },
        custom: [],
    };
    let fieldInputs = []; // [{ key, builtin, required, el }]

    // FAQ deflection (admin-driven via app-config). When enabled and the inbox
    // has FAQs for the chosen language, the pre-chat form leads to a "Quick
    // answers" screen before the chat starts.
    // Title/prompt/button are bilingual maps ({ ms, en }) that switch with the
    // FAQ language tabs; app-config overrides these defaults.
    let faqConfig = {
        enabled: true,
        skipBot: false, // proceed straight to a human after the FAQ screen
        title: { ms: 'Jawapan Pantas', en: 'Quick answers' },
        prompt: { ms: 'Tidak menemui apa yang anda cari?', en: "Didn't find what you were looking for?" },
        button: { ms: 'Berbual dengan ejen', en: 'Chat with a live agent' },
    };
    let cameFromFaq = false; // the visitor reached the form via the FAQ screen
    // Languages offered in the pre-chat form and the FAQ tabs (mirrors the <select>).
    const languages = [
        { code: 'ms', label: 'Bahasa Malaysia' },
        { code: 'en', label: 'English' },
    ];
    let faqLang = config.language; // language shown on the FAQ screen
    const faqCache = {}; // language -> FAQ list, so switching tabs doesn't refetch

    // ---------- styles ----------
    const css = `
  .tdec-btn{position:fixed;right:20px;bottom:20px;width:56px;height:56px;border-radius:50%;
    background:var(--tdec-accent,#2a78d6);color:#fff;border:none;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25);
    font-size:24px;z-index:2147483000;display:flex;align-items:center;justify-content:center}
  .tdec-btn:hover{background:#1c5cab}
  .tdec-panel{position:fixed;right:20px;bottom:88px;width:360px;max-width:calc(100vw - 40px);
    height:520px;max-height:calc(100vh - 120px);background:#fff;border-radius:14px;overflow:hidden;
    box-shadow:0 12px 40px rgba(0,0,0,.28);display:none;flex-direction:column;z-index:2147483000;
    font-family:system-ui,-apple-system,'Segoe UI',sans-serif;font-size:14px;color:#1c1b1f}
  .tdec-panel.open{display:flex}
  .tdec-head{background:var(--tdec-accent,#2a78d6);color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px}
  .tdec-head h3{margin:0;font-size:15px;font-weight:600}
  .tdec-head-icon{font-size:22px;line-height:1;display:flex;align-items:center}
  .tdec-head-icon img{width:auto;height:34px;border-radius:6px;object-fit:cover;display:block}
  .tdec-btn img{width:26px;height:26px;border-radius:6px;object-fit:cover;display:block}
  .tdec-status{font-size:12px;opacity:.85;margin-top:2px}
  .tdec-msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:#f7f7f5;position:relative}
  .tdec-msgs.drag{outline:2px dashed var(--tdec-accent,#2a78d6);outline-offset:-6px}
  .tdec-msgs.drag::after{content:'Drop to upload';position:absolute;inset:0;display:flex;align-items:center;
    justify-content:center;background:rgba(247,247,245,.9);color:var(--tdec-accent,#2a78d6);font-weight:600;pointer-events:none}
  .tdec-msg{max-width:80%;padding:8px 12px;border-radius:12px;white-space:pre-wrap;word-break:break-word;line-height:1.35}
  .tdec-msg.visitor{align-self:flex-end;background:var(--tdec-accent,#2a78d6);color:#fff;border-bottom-right-radius:4px}
  .tdec-msg.agent,.tdec-msg.bot{align-self:flex-start;background:#fff;border:1px solid #e1e0d9;border-bottom-left-radius:4px}
  .tdec-msg.bot{border-style:dashed}
  .tdec-msg.system{align-self:center;background:none;color:#898781;font-size:12px;padding:2px}
  .tdec-trans{margin-top:5px;padding-top:5px;border-top:1px dashed #e1e0d9;font-style:italic;
    font-size:12.5px;color:#52514e}
  .tdec-sender{font-size:11px;color:#898781;margin:0 4px 2px}
  .tdec-typing{align-self:flex-start;color:#898781;font-size:12px;padding:2px 6px;display:none}
  .tdec-typing.on{display:block}
  .tdec-foot{border-top:1px solid #e1e0d9;padding:10px;display:flex;gap:8px;background:#fff}
  .tdec-input{flex:1;border:1px solid #c3c2b7;border-radius:8px;padding:9px 12px;font:inherit;resize:none;max-height:90px}
  .tdec-send{background:var(--tdec-accent,#2a78d6);color:#fff;border:none;border-radius:8px;padding:0 14px;cursor:pointer;font-size:16px}
  .tdec-send:disabled{opacity:.4;cursor:default}
  .tdec-attach{background:none;border:none;cursor:pointer;font-size:17px;padding:0 4px;opacity:.65}
  .tdec-attach:hover{opacity:1}
  .tdec-att-img{display:block;margin-top:6px}
  .tdec-att-img img{max-width:200px;max-height:200px;border-radius:8px;display:block}
  .tdec-att-file{display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:7px 9px;
    border-radius:8px;background:rgba(0,0,0,.06);text-decoration:none;color:inherit;font-size:12.5px}
  .tdec-msg.visitor .tdec-att-file{background:rgba(255,255,255,.2)}
  .tdec-att-file .tdec-att-size{opacity:.7;font-size:11px}
  .tdec-bar{display:flex;gap:8px;padding:8px 10px;background:#fff;border-top:1px solid #e1e0d9}
  .tdec-link{background:none;border:none;color:var(--tdec-accent,#2a78d6);cursor:pointer;font-size:12px;padding:4px}
  .tdec-pre{padding:18px;display:flex;flex-direction:column;gap:10px;background:#f7f7f5;flex:1;overflow:auto}
  .tdec-fields{display:flex;flex-direction:column;gap:10px}
  .tdec-field{display:flex;flex-direction:column;gap:4px}
  .tdec-field-label{font-size:12px;color:#52514e}
  .tdec-cascade{display:flex;flex-direction:column;gap:6px}
  .tdec-pre label{font-size:12px;color:#52514e;display:flex;flex-direction:column;gap:4px}
  .tdec-pre input,.tdec-pre select{border:1px solid #c3c2b7;border-radius:8px;padding:9px 12px;font:inherit;width:100%;box-sizing:border-box}
  .tdec-pre .tdec-start{background:var(--tdec-accent,#2a78d6);color:#fff;border:none;border-radius:8px;padding:11px;cursor:pointer;font:inherit;margin-top:4px}
  .tdec-faq{flex:1;overflow-y:auto;padding:18px;background:#f7f7f5;display:none;flex-direction:column}
  .tdec-faq.open{display:flex}
  .tdec-faq-title{font-size:22px;font-weight:800;margin:0 0 14px;line-height:1.15}
  .tdec-faq-tabs{display:flex;gap:6px;margin:0 0 14px}
  .tdec-faq-tab{flex:1;padding:8px 10px;border:1px solid #d5d4cc;background:#fff;border-radius:8px;cursor:pointer;
    font:inherit;font-weight:600;font-size:13px;color:#52514e;text-align:center}
  .tdec-faq-tab.active{background:var(--tdec-accent,#2a78d6);color:#fff;border-color:var(--tdec-accent,#2a78d6)}
  .tdec-faq-empty{color:#898781;font-size:13px;padding:10px 12px}
  .tdec-faq-list{display:flex;flex-direction:column;gap:2px}
  .tdec-faq-item{border-radius:10px}
  .tdec-faq-item.open{background:#ececec}
  .tdec-faq-q{display:flex;width:100%;box-sizing:border-box;justify-content:space-between;align-items:center;gap:10px;
    background:none;border:none;cursor:pointer;font:inherit;font-weight:700;font-size:14px;text-align:left;
    padding:13px 12px;color:#1c1b1f;border-radius:10px}
  .tdec-faq-q:hover{background:rgba(0,0,0,.04)}
  .tdec-faq-item.open .tdec-faq-q:hover{background:none}
  .tdec-faq-chev{flex-shrink:0;font-size:12px;color:#52514e;transition:transform .15s ease}
  .tdec-faq-item.open .tdec-faq-chev{transform:rotate(180deg)}
  .tdec-faq-a{display:none;padding:0 12px 14px;line-height:1.5;color:#1c1b1f;white-space:pre-wrap;word-break:break-word;font-size:13.5px}
  .tdec-faq-item.open .tdec-faq-a{display:block}
  .tdec-faq-a a{color:var(--tdec-accent,#2a78d6);word-break:break-all}
  .tdec-faq-foot{margin-top:auto;padding-top:16px}
  .tdec-faq-prompt{text-align:center;color:#52514e;font-size:13px;margin:0 0 10px}
  .tdec-faq-proceed{width:100%;background:var(--tdec-accent,#2a78d6);color:#fff;border:none;border-radius:10px;
    padding:14px;cursor:pointer;font:inherit;font-weight:700;font-size:15px}
  .tdec-faq-proceed:disabled{opacity:.5;cursor:default}
  `;

    // ---------- DOM ----------
    const style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    const button = element('button', 'tdec-btn', '\uD83D\uDCAC');
    button.setAttribute('aria-label', 'Open chat');

    const panel = element('div', 'tdec-panel');
    panel.innerHTML = `
    <div class="tdec-head">
      <span class="tdec-head-icon" style="display:none"></span>
      <div class="tdec-head-text">
        <h3 class="tdec-title">Chat with us</h3>
        <div class="tdec-status">TDEChat</div>
      </div>
    </div>
    <div class="tdec-pre">
      <div class="tdec-fields"></div>
      <label><span class="tdec-lang-label">Language</span>
        <select class="tdec-lang">
          <option value="ms">Bahasa Malaysia</option>
          <option value="en">English</option>
        </select>
      </label>
      <label><span class="tdec-msg-label">How can we help?</span>
        <input type="text" class="tdec-first" placeholder="Taip mesej anda\u2026" />
      </label>
      <button class="tdec-start">Start chat</button>
    </div>
    <div class="tdec-faq">
      <h2 class="tdec-faq-title">Quick answers</h2>
      <div class="tdec-faq-tabs"></div>
      <div class="tdec-faq-list"></div>
      <div class="tdec-faq-foot">
        <p class="tdec-faq-prompt">Didn't find what you were looking for?</p>
        <button class="tdec-faq-proceed">Chat with a live agent</button>
      </div>
    </div>
    <div class="tdec-msgs" style="display:none"></div>
    <div class="tdec-typing">Agent is typing\u2026</div>
    <div class="tdec-bar" style="display:none">
      <button class="tdec-link tdec-escalate">Talk to a human</button>
      <span style="flex:1"></span>
      <button class="tdec-link tdec-end">End chat</button>
    </div>
    <div class="tdec-foot" style="display:none">
      <button class="tdec-attach" aria-label="Attach a file">\uD83D\uDCCE</button>
      <input class="tdec-file" type="file"
        accept="image/jpeg,image/png,image/gif,image/webp,application/pdf" style="display:none" />
      <textarea class="tdec-input" rows="1" placeholder="Type a message\u2026"></textarea>
      <button class="tdec-send" aria-label="Send">\u27A4</button>
    </div>
  `;

    document.body.append(button, panel);

    const refs = {
        status: panel.querySelector('.tdec-status'),
        pre: panel.querySelector('.tdec-pre'),
        faq: panel.querySelector('.tdec-faq'),
        faqTitle: panel.querySelector('.tdec-faq-title'),
        faqTabs: panel.querySelector('.tdec-faq-tabs'),
        faqList: panel.querySelector('.tdec-faq-list'),
        faqPrompt: panel.querySelector('.tdec-faq-prompt'),
        faqProceed: panel.querySelector('.tdec-faq-proceed'),
        msgs: panel.querySelector('.tdec-msgs'),
        typing: panel.querySelector('.tdec-typing'),
        bar: panel.querySelector('.tdec-bar'),
        foot: panel.querySelector('.tdec-foot'),
        input: panel.querySelector('.tdec-input'),
        send: panel.querySelector('.tdec-send'),
        attach: panel.querySelector('.tdec-attach'),
        file: panel.querySelector('.tdec-file'),
        fields: panel.querySelector('.tdec-fields'),
        lang: panel.querySelector('.tdec-lang'),
        first: panel.querySelector('.tdec-first'),
        start: panel.querySelector('.tdec-start'),
        escalate: panel.querySelector('.tdec-escalate'),
        end: panel.querySelector('.tdec-end'),
    };
    refs.lang.value = config.language;

    // ---------- events ----------
    button.addEventListener('click', () => {
        // Closing the widget ends an active chat (no minimize-and-return); opening
        // it just shows the panel.
        if (open && conversation && isOpenStatus(conversation.status)) {
            endChat();
        }
        toggle(!open);
    });

    // Closing the browser tab/window ends the chat after a short server-side
    // grace (see IdleSweepJob). pagehide is the reliable unload signal and also
    // covers bfcache; a plain refresh reconnects and clears the flag in time.
    window.addEventListener('pagehide', () => {
        if (conversation && isOpenStatus(conversation.status) && navigator.sendBeacon) {
            navigator.sendBeacon(
                config.api + '/api/v1/public/conversations/' + conversation.uuid + '/leave',
            );
        }
    });

    // Restored from the bfcache (JS state intact) - tell the server we're back.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) markPresent();
    });
    refs.start.addEventListener('click', start);
    refs.first.addEventListener('keydown', (e) => e.key === 'Enter' && start());
    refs.send.addEventListener('click', sendMessage);
    refs.input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    refs.input.addEventListener('input', onVisitorTyping);
    refs.input.addEventListener('blur', stopVisitorTyping);
    refs.escalate.addEventListener('click', escalate);
    refs.end.addEventListener('click', endChat);
    refs.faqProceed.addEventListener('click', goToPreChat);
    refs.attach.addEventListener('click', () => refs.file.click());
    refs.file.addEventListener('change', uploadFile);
    refs.input.addEventListener('paste', onComposerPaste);
    // Drag-and-drop uploads onto the chat thread.
    panel.addEventListener('dragover', onPanelDragOver);
    panel.addEventListener('dragleave', onPanelDragLeave);
    panel.addEventListener('drop', onPanelDrop);

    renderFields(); // default fields until app-config resolves
    loadTheme();

    const savedUuid = localStorage.getItem(storageKey);
    if (savedUuid) {
        resume(savedUuid);
    }

    // ---------- flows ----------
    async function loadTheme() {
        try {
            const response = await fetch(config.api + '/api/v1/public/app-config', {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return;
            const body = await response.json();
            const data = (body && body.data) || {};
            if (data.bubble_color) document.documentElement.style.setProperty('--tdec-accent', data.bubble_color);
            applyBranding(data.widget);
            applyFaq(data.faq);
            applyFields(data.fields);
            await initFaqScreen();
        } catch (e) {
            /* keep the default accent + hardcoded copy */
        }
    }

    // Merge admin FAQ-deflection config (bilingual maps) and apply the copy for
    // the current language.
    function applyFaq(f) {
        if (!f) return;
        faqConfig.enabled = f.enabled !== false;
        faqConfig.skipBot = f.skip_bot === true;
        ['title', 'prompt', 'button'].forEach(function (key) {
            const map = f[key];
            if (map && typeof map === 'object') {
                if (map.ms) faqConfig[key].ms = map.ms;
                if (map.en) faqConfig[key].en = map.en;
            }
        });
        applyFaqText();
    }

    // Pick a bilingual value for the language currently shown on the FAQ screen.
    function faqText(key) {
        const map = faqConfig[key];
        return map[faqLang] || map[config.language] || map.en;
    }

    // Set the FAQ title/prompt/button to the current language.
    function applyFaqText() {
        refs.faqTitle.textContent = faqText('title');
        refs.faqPrompt.textContent = faqText('prompt');
        refs.faqProceed.textContent = faqText('button');
    }

    // Merge admin field config and re-render the pre-chat form.
    function applyFields(f) {
        if (!f) return;
        if (f.name) fieldConfig.name = f.name;
        if (f.email) fieldConfig.email = f.email;
        if (f.phone) fieldConfig.phone = f.phone;
        if (Array.isArray(f.custom)) fieldConfig.custom = f.custom;
        renderFields();
    }

    // Build the pre-chat inputs from fieldConfig into the .tdec-fields container.
    function renderFields() {
        refs.fields.innerHTML = '';
        fieldInputs = [];

        const add = (def) => {
            const label = document.createElement('label');
            label.appendChild(element('span', 'tdec-field-label', def.required ? def.label + ' *' : def.label));

            let input;
            if (def.type === 'select') {
                input = document.createElement('select');
                if (!def.required) input.appendChild(new Option('', ''));
                (def.options || []).forEach((o) => input.appendChild(new Option(o, o)));
            } else {
                input = document.createElement('input');
                input.type =
                    def.type === 'email' ? 'email' : def.type === 'phone' ? 'tel' : def.type === 'number' ? 'number' : 'text';
            }
            input.className = 'tdec-field-input';
            label.appendChild(input);
            refs.fields.appendChild(label);
            fieldInputs.push({ key: def.key, builtin: !!def.builtin, required: !!def.required, el: input });
        };

        ['name', 'email', 'phone'].forEach((key) => {
            const c = fieldConfig[key];
            if (c && c.enabled) {
                add({ key: key, builtin: true, type: key === 'name' ? 'text' : key, required: c.required, label: c.label });
            }
        });
        (fieldConfig.custom || []).forEach((f) => {
            if (f.type === 'cascade') {
                addCascade(f);
            } else {
                add({ key: f.key, builtin: false, type: f.type, required: f.required, label: f.label, options: f.options });
            }
        });
    }

    // A cascade field: dependent dropdowns driven by a {key,label,children} tree.
    function addCascade(f) {
        const wrap = document.createElement('div');
        wrap.className = 'tdec-field';
        wrap.appendChild(element('span', 'tdec-field-label', f.required ? f.label + ' *' : f.label));
        const levels = document.createElement('div');
        levels.className = 'tdec-cascade';
        wrap.appendChild(levels);
        refs.fields.appendChild(wrap);

        addCascadeSelect(levels, f.tree || []);
        fieldInputs.push({ key: f.key, builtin: false, required: !!f.required, cascade: true, tree: f.tree || [], levels: levels });
    }

    function addCascadeSelect(levels, nodes) {
        const select = document.createElement('select');
        select.className = 'tdec-field-input';
        select.appendChild(new Option('', ''));
        nodes.forEach((n) => select.appendChild(new Option(n.label, n.key)));
        select.addEventListener('change', () => {
            // Drop any deeper dropdowns, then reveal the next level if the choice has children.
            let sib = select.nextElementSibling;
            while (sib) {
                const next = sib.nextElementSibling;
                sib.remove();
                sib = next;
            }
            const node = nodes.find((n) => n.key === select.value);
            if (node && node.children && node.children.length) {
                addCascadeSelect(levels, node.children);
            }
        });
        levels.appendChild(select);
    }

    // The selected path (node keys, top to bottom), stopping at the first blank.
    function readCascadePath(levels) {
        const path = [];
        const selects = levels.querySelectorAll('select');
        for (let i = 0; i < selects.length; i++) {
            if (selects[i].value === '') break;
            path.push(selects[i].value);
        }
        return path;
    }

    // Whether a path ends at a leaf (a complete choice).
    function cascadeAtLeaf(nodes, path) {
        let current = nodes;
        let node = null;
        for (let i = 0; i < path.length; i++) {
            node = current.find((n) => n.key === path[i]);
            if (!node) return false;
            current = node.children || [];
        }
        return node !== null && (!node.children || node.children.length === 0);
    }

    // Apply admin-configured branding over the built-in defaults. Runs once
    // app-config resolves; anything blank/absent keeps the default already shown.
    function applyBranding(w) {
        if (!w) return;

        const setText = (selector, value) => {
            const el = panel.querySelector(selector);
            if (el && value) el.textContent = value;
        };
        setText('.tdec-title', w.widget_title);
        setText('.tdec-lang-label', w.widget_language_label);
        setText('.tdec-msg-label', w.widget_message_label);
        setText('.tdec-start', w.widget_start_label);

        // Subtitle may be intentionally blank - hide it when empty.
        const subtitle = panel.querySelector('.tdec-status');
        if (subtitle && w.widget_subtitle !== undefined) {
            subtitle.textContent = w.widget_subtitle || '';
            subtitle.style.display = w.widget_subtitle ? '' : 'none';
        }

        if (w.widget_greeting_placeholder) refs.first.setAttribute('placeholder', w.widget_greeting_placeholder);
        if (w.widget_input_placeholder) refs.input.setAttribute('placeholder', w.widget_input_placeholder);
        if (w.widget_bot_name) botName = w.widget_bot_name;

        applyIcon(button, w.widget_launcher_icon);
        const headIcon = panel.querySelector('.tdec-head-icon');
        if (headIcon) {
            if (w.widget_header_icon) {
                applyIcon(headIcon, w.widget_header_icon);
                headIcon.style.display = '';
            } else {
                headIcon.style.display = 'none';
            }
        }
    }

    // An icon value is an image (URL or inline data-URI, rendered as <img>) or an emoji/text.
    function applyIcon(el, value) {
        if (!el || !value) return;
        if (/^https?:\/\//.test(value) || value.slice(0, 5) === 'data:') {
            el.textContent = '';
            const img = document.createElement('img');
            img.src = value;
            img.alt = '';
            el.appendChild(img);
        } else {
            el.textContent = value;
        }
    }

    function toggle(show) {
        open = show;
        panel.classList.toggle('open', show);
        if (show && conversation) {
            refresh();
        }
    }

    async function start() {
        const message = refs.first.value.trim();
        if (message === '') {
            refs.first.focus();
            return;
        }

        const visitor = { visitor_name: null, visitor_email: null, visitor_phone: null };
        const fields = {};
        for (let i = 0; i < fieldInputs.length; i++) {
            const fi = fieldInputs[i];

            if (fi.cascade) {
                const path = readCascadePath(fi.levels);
                if (fi.required && path.length === 0) {
                    refs.status.textContent = 'Please fill in the required fields.';
                    return;
                }
                // A started selection must be finished to a leaf.
                if (path.length > 0 && !cascadeAtLeaf(fi.tree, path)) {
                    refs.status.textContent = 'Please complete the selection.';
                    return;
                }
                if (path.length > 0) fields[fi.key] = path;
                continue;
            }

            const value = (fi.el.value || '').trim();
            if (fi.required && value === '') {
                fi.el.focus();
                refs.status.textContent = 'Please fill in the required fields.';
                return;
            }
            if (fi.builtin) {
                visitor['visitor_' + fi.key] = value || null;
            } else if (value !== '') {
                fields[fi.key] = value;
            }
        }

        // Use request() (not api()) so we can surface a validation error instead of
        // silently resyncing.
        const result = await request('POST', `/api/v1/public/inboxes/${config.inbox}/conversations`, {
            message: message,
            visitor_name: visitor.visitor_name,
            visitor_email: visitor.visitor_email,
            visitor_phone: visitor.visitor_phone,
            language: refs.lang.value,
            fields: fields,
            meta: { page: location.href },
        });

        if (result.status === 422) {
            refs.status.textContent = 'Please check your details and try again.';
            return;
        }
        if (result.status < 200 || result.status >= 300 || !result.data) {
            refs.status.textContent = 'Connection problem, please try again.';
            return;
        }

        setConversation(result.data.data);
        localStorage.setItem(storageKey, conversation.uuid);
        (conversation.messages || []).forEach(render);
        connect();

        // Came here via the FAQ screen and the admin wants to skip the bot: jump
        // straight to the human queue (the FAQ already served the self-service role).
        if (faqConfig.skipBot && cameFromFaq && conversation.status === 'bot') {
            escalate();
        }
        cameFromFaq = false;
    }

    // Opening screen: when deflection is on and the inbox has FAQs for the
    // widget's default language, greet the visitor with "Quick answers" before
    // the pre-chat form. Skipped while an existing conversation is resuming.
    async function initFaqScreen() {
        if (!faqConfig.enabled) return;
        if (conversation || localStorage.getItem(storageKey)) return;

        faqLang = config.language;
        const faqs = await cachedFaqs(faqLang);
        if (!faqs.length) return;

        renderFaqTabs();
        renderFaqList(faqs);
        if (!conversation) show('faq');
    }

    // FAQs for a language, fetched once and memoised so switching tabs is instant.
    async function cachedFaqs(language) {
        if (!faqCache[language]) faqCache[language] = await loadFaqs(language);
        return faqCache[language];
    }

    // Fetch the inbox's active FAQs for a language. Failures (or none) leave the
    // FAQ screen off, so the widget opens straight to the pre-chat form.
    async function loadFaqs(language) {
        try {
            const result = await request(
                'GET',
                `/api/v1/public/inboxes/${config.inbox}/faqs?language=${encodeURIComponent(language)}`,
            );
            if (result.status >= 200 && result.status < 300 && result.data && Array.isArray(result.data.data)) {
                return result.data.data;
            }
        } catch (e) {
            /* fall through - deflection is optional */
        }
        return [];
    }

    // The language switcher (one tab per configured language).
    function renderFaqTabs() {
        refs.faqTabs.innerHTML = '';
        languages.forEach(function (lang) {
            const tab = element('button', 'tdec-faq-tab' + (lang.code === faqLang ? ' active' : ''), lang.label);
            tab.type = 'button';
            tab.dataset.lang = lang.code;
            tab.addEventListener('click', function () {
                return selectFaqLang(lang.code);
            });
            refs.faqTabs.appendChild(tab);
        });
    }

    // Switch the FAQ language; also preselects it in the pre-chat form so the
    // visitor keeps the language they were reading in.
    async function selectFaqLang(language) {
        if (language === faqLang) return;
        faqLang = language;
        refs.lang.value = language;

        const tabs = refs.faqTabs.children;
        for (let i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].dataset.lang === language);
        }

        applyFaqText();
        renderFaqList(await cachedFaqs(language));
    }

    // Build the accordion of question/answer items (or an empty note).
    function renderFaqList(faqs) {
        refs.faqList.innerHTML = '';
        if (!faqs.length) {
            refs.faqList.appendChild(element('div', 'tdec-faq-empty', 'No answers in this language yet.'));
            return;
        }
        faqs.forEach(function (faq) {
            const item = element('div', 'tdec-faq-item');

            const q = element('button', 'tdec-faq-q');
            q.type = 'button';
            q.appendChild(element('span', 'tdec-faq-q-text', faq.question));
            q.appendChild(element('span', 'tdec-faq-chev', '\u25BE'));

            const a = element('div', 'tdec-faq-a');
            appendLinkified(a, faq.answer || '');

            q.addEventListener('click', function () {
                item.classList.toggle('open');
            });

            item.append(q, a);
            refs.faqList.appendChild(item);
        });
        refs.faq.scrollTop = 0;
    }

    // The FAQ screen's CTA: move on to the pre-chat form.
    function goToPreChat() {
        cameFromFaq = true;
        show('pre');
        refs.first.focus();
    }

    // The opening view for a visitor with no active chat: the FAQ screen when
    // one is available, otherwise the pre-chat form.
    function showStart() {
        if (faqConfig.enabled && refs.faqList.children.length) show('faq');
        else show('pre');
    }

    async function resume(uuid) {
        const result = await request('GET', `/api/v1/public/conversations/${uuid}`);

        if (result.status === 404) {
            // The conversation genuinely no longer exists server-side.
            localStorage.removeItem(storageKey);
            return;
        }

        if (result.status < 200 || result.status >= 300) {
            // Transient failure (backend/db down): keep the stored conversation
            // and keep retrying until the server comes back.
            show('chat');
            refs.status.textContent = 'Connection problem \u2014 retrying\u2026';
            refs.input.disabled = true;
            refs.send.disabled = true;
            setTimeout(() => resume(uuid), 5000);
            return;
        }

        setConversation(result.data.data);
        (conversation.messages || []).forEach(render);
        connect();
        // We came back (e.g. after a refresh) - cancel any pending "left" flag the
        // previous page's unload may have set, before the grace window elapses.
        markPresent();
    }

    async function refresh() {
        if (!conversation) return;
        const response = await api('GET', `/api/v1/public/conversations/${conversation.uuid}`);
        if (!response) return;
        setConversation(response.data);
        (conversation.messages || []).forEach(render);
    }

    // Tell the agent the visitor is typing (mirror of the agent's indicator).
    function onVisitorTyping() {
        if (!conversation) return;
        if (!visitorTypingActive) {
            visitorTypingActive = true;
            sendVisitorTyping(true);
        }
        clearTimeout(visitorTypingTimer);
        visitorTypingTimer = setTimeout(stopVisitorTyping, 3000);
    }

    function stopVisitorTyping() {
        clearTimeout(visitorTypingTimer);
        if (visitorTypingActive) {
            visitorTypingActive = false;
            sendVisitorTyping(false);
        }
    }

    function sendVisitorTyping(on) {
        if (!conversation) return;
        request('POST', '/api/v1/public/conversations/' + conversation.uuid + '/typing', { typing: on });
    }

    async function sendMessage() {
        const body = refs.input.value.trim();
        if (body === '' || !conversation) return;
        refs.input.value = '';
        stopVisitorTyping();

        const response = await api(
            'POST',
            `/api/v1/public/conversations/${conversation.uuid}/messages`,
            { body: body },
        );
        if (!response) return;

        render(response.message);
        if (response.bot_reply) render(response.bot_reply);
        updateStatus(response.status);
    }

    function uploadFile(e) {
        const files = e.target.files;
        e.target.value = ''; // let the same file be re-picked later
        return uploadFiles(files);
    }

    // Whether a file dropped/pasted now would be accepted (a chat is open).
    function chatUploadReady() {
        return !!conversation && isOpenStatus(conversation.status);
    }

    // Upload one or more files sequentially through the same endpoint, showing
    // per-file progress in the status line.
    async function uploadFiles(files) {
        if (!files || !files.length || !chatUploadReady()) return;
        const total = files.length;
        for (let i = 0; i < total; i++) {
            refs.status.textContent = total > 1 ? 'Uploading ' + (i + 1) + '/' + total + '\u2026' : 'Uploading\u2026';
            await uploadOneFile(files[i]);
        }
        // Restore the normal status once every file is done.
        if (conversation) updateStatus(conversation.status);
    }

    async function uploadOneFile(file) {
        if (!file || !conversation) return;

        // Multipart, so let the browser set the boundary - can't use api().
        const form = new FormData();
        form.append('file', file);
        try {
            const response = await fetch(
                config.api + '/api/v1/public/conversations/' + conversation.uuid + '/attachments',
                { method: 'POST', headers: { Accept: 'application/json' }, body: form },
            );
            if (response.status >= 200 && response.status < 300) {
                const data = await response.json();
                render(data.message);
            } else if (response.status === 422) {
                const data = await response.json().catch(() => null);
                refs.status.textContent = (data && data.message) || 'That file is not allowed.';
            } else {
                refs.status.textContent = 'Upload problem \u2014 try again.';
            }
        } catch (err) {
            refs.status.textContent = 'Upload problem \u2014 try again.';
        }
    }

    // Dragging files over the panel: prevent the browser from opening them, and
    // show a drop hint over the thread while a chat is open.
    function onPanelDragOver(e) {
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') === -1) return;
        e.preventDefault();
        if (chatUploadReady()) {
            e.dataTransfer.dropEffect = 'copy';
            refs.msgs.classList.add('drag');
        }
    }

    function onPanelDragLeave(e) {
        if (!panel.contains(e.relatedTarget)) refs.msgs.classList.remove('drag');
    }

    function onPanelDrop(e) {
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') === -1) return;
        e.preventDefault();
        refs.msgs.classList.remove('drag');
        return uploadFiles(e.dataTransfer.files);
    }

    // Pasting an image (e.g. a screenshot) into the composer uploads it.
    function onComposerPaste(e) {
        if (!chatUploadReady() || !e.clipboardData) return;
        const files = [];
        const items = e.clipboardData.items || [];
        for (let i = 0; i < items.length; i++) {
            if (items[i].kind === 'file') {
                const file = items[i].getAsFile();
                if (file) files.push(file);
            }
        }
        if (files.length) {
            e.preventDefault();
            return uploadFiles(files);
        }
    }

    function renderAttachment(att) {
        if (att.is_image) {
            const link = element('a', 'tdec-att-img');
            link.href = att.url;
            link.target = '_blank';
            link.rel = 'noopener';
            const img = document.createElement('img');
            img.src = att.url;
            img.alt = att.name;
            img.loading = 'lazy';
            link.appendChild(img);
            return link;
        }
        const link = element('a', 'tdec-att-file');
        link.href = att.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.appendChild(element('span', 'tdec-att-name', att.name));
        return link;
    }

    async function escalate() {
        if (!conversation) return;
        const response = await api('POST', `/api/v1/public/conversations/${conversation.uuid}/escalate`);
        if (response) setConversation(response.data);
    }

    async function endChat() {
        if (conversation && isOpenStatus(conversation.status)) {
            await api('POST', `/api/v1/public/conversations/${conversation.uuid}/close`);
        }
        localStorage.removeItem(storageKey);
        disconnect();
        conversation = null;
        cameFromFaq = false;
        seenMessages.clear();
        messageNodes.clear();
        refs.msgs.innerHTML = '';
        showStart();
        refs.status.textContent = 'TDEChat';
    }

    // ---------- rendering ----------
    function setConversation(data) {
        conversation = data;
        show('chat');
        updateStatus(conversation.status);
    }

    function updateStatus(status) {
        if (conversation) conversation.status = status;

        const agent = conversation && conversation.assigned_agent;
        const position = conversation && conversation.queue_position;
        const labels = {
            pending: 'Connecting\u2026',
            bot: 'Chatbot \u2014 ask anything',
            awaiting_acceptance: position
                ? 'Waiting for an agent \u2014 #' + position + ' in queue'
                : 'Waiting for an agent\u2026',
            active: agent ? 'Chatting with ' + agent.display_name : 'Agent connected',
            closed: 'Conversation ended',
            abandoned: 'Conversation ended',
        };
        refs.status.textContent = labels[status] || status;

        // Keep the queue position fresh while waiting \u2014 the realtime channel
        // doesn't carry it, so poll the conversation on a slow cadence.
        if (status === 'awaiting_acceptance') startQueuePoll();
        else stopQueuePoll();

        const openNow = isOpenStatus(status);
        refs.input.disabled = !openNow;
        refs.send.disabled = !openNow;
        refs.escalate.style.display = status === 'bot' || status === 'pending' ? '' : 'none';
        refs.end.textContent = openNow ? 'End chat' : 'Start a new chat';
    }

    function render(message) {
        if (!message || seenMessages.has(message.id)) return;
        seenMessages.add(message.id);

        if (message.sender_type !== 'visitor' && message.sender_type !== 'system') {
            const sender = element(
                'div',
                'tdec-sender',
                message.sender_type === 'bot' ? botName : (message.agent && message.agent.display_name) || 'Agent',
            );
            refs.msgs.appendChild(sender);
        }

        const bubble = element('div', 'tdec-msg ' + message.sender_type, message.body);
        (message.attachments || []).forEach(function (att) {
            bubble.appendChild(renderAttachment(att));
        });
        refs.msgs.appendChild(bubble);
        messageNodes.set(message.id, { bubble: bubble, senderType: message.sender_type });

        applyTranslation(message.id, (message.meta && message.meta.translations) || null);
        refs.msgs.scrollTop = refs.msgs.scrollHeight;
    }

    /**
     * Shows the visitor-language translation under agent replies (auto mode
     * translates agent messages into the conversation language).
     */
    function applyTranslation(messageId, translations) {
        const entry = messageNodes.get(messageId);
        if (!entry || entry.senderType !== 'agent' || !translations || !conversation) return;

        const text = translations[conversation.language];
        if (!text || text === entry.bubble.firstChild?.textContent) return;

        if (!entry.translationNode) {
            entry.translationNode = element('div', 'tdec-trans');
            entry.bubble.appendChild(entry.translationNode);
        }
        entry.translationNode.textContent = text;
    }

    function show(which) {
        refs.pre.style.display = which === 'pre' ? 'flex' : 'none';
        refs.faq.classList.toggle('open', which === 'faq');
        refs.msgs.style.display = which === 'chat' ? 'flex' : 'none';
        refs.bar.style.display = which === 'chat' ? 'flex' : 'none';
        refs.foot.style.display = which === 'chat' ? 'flex' : 'none';
    }

    // ---------- realtime (Pusher protocol over Reverb) ----------
    function connect() {
        if (!config.reverbKey || !conversation) {
            startPolling();
            return;
        }

        const scheme = config.wsTls ? 'wss' : 'ws';
        socket = new WebSocket(
            `${scheme}://${config.wsHost}:${config.wsPort}/app/${config.reverbKey}?protocol=7&client=tdechat-widget&version=1.0`,
        );

        socket.onopen = () => {
            stopPolling();
            socket.send(
                JSON.stringify({
                    event: 'pusher:subscribe',
                    data: { channel: 'conversation.' + conversation.uuid },
                }),
            );
            // A live socket means the visitor is here; clear any pending "left" flag
            // (covers reconnects after the tab was suspended, e.g. laptop sleep).
            markPresent();
        };

        socket.onmessage = (frame) => {
            const message = JSON.parse(frame.data);
            const payload = typeof message.data === 'string' ? safeJson(message.data) : message.data;

            switch (message.event) {
                case 'pusher:ping':
                    socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                    break;
                case 'message.sent':
                    render(payload.message);
                    break;
                case 'agent.typing':
                    showTyping(payload.typing);
                    break;
                case 'message.translated':
                    applyTranslation(payload.message_id, payload.translations);
                    break;
                case 'conversation.updated':
                    conversation.assigned_agent = payload.conversation.assigned_agent;
                    updateStatus(payload.conversation.status);
                    break;
            }
        };

        socket.onclose = () => {
            socket = null;
            if (conversation && isOpenStatus(conversation.status)) {
                startPolling();
                setTimeout(connect, 3000);
            }
        };
    }

    function disconnect() {
        stopPolling();
        stopQueuePoll();
        if (socket) {
            socket.onclose = null;
            socket.close();
            socket = null;
        }
    }

    function showTyping(on) {
        refs.typing.classList.toggle('on', on !== false);
        clearTimeout(typingTimer);
        if (on !== false) {
            typingTimer = setTimeout(() => refs.typing.classList.remove('on'), 4000);
        }
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(() => {
            if (open) refresh();
        }, 5000);
    }

    function stopPolling() {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    // Refresh the queued visitor's place in line on a slow cadence. Runs
    // independently of the message poll (which only fires when the socket is
    // down) because queue position isn't pushed over the realtime channel.
    function startQueuePoll() {
        if (queueTimer) return;
        queueTimer = setInterval(function () {
            if (conversation && conversation.status === 'awaiting_acceptance') refresh();
            else stopQueuePoll();
        }, 12000);
    }

    function stopQueuePoll() {
        clearInterval(queueTimer);
        queueTimer = null;
    }

    // ---------- helpers ----------
    async function request(method, path, body) {
        try {
            const response = await fetch(config.api + path, {
                method: method,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: body ? JSON.stringify(body) : undefined,
            });

            return {
                status: response.status,
                data: response.status === 204 ? {} : await response.json().catch(() => null),
            };
        } catch (error) {
            return { status: 0, data: null };
        }
    }

    async function api(method, path, body) {
        const result = await request(method, path, body);

        if (result.status >= 200 && result.status < 300) {
            return result.data;
        }

        if (result.status >= 400 && result.status < 500) {
            // Client-state conflict (e.g. escalating an already-escalated chat):
            // resync with the server instead of alarming the visitor.
            refresh();
            return null;
        }

        refs.status.textContent = 'Connection problem \u2014 retrying\u2026';
        return null;
    }

    function isOpenStatus(status) {
        return !!status && status !== 'closed' && status !== 'abandoned';
    }

    // Tell the server the visitor is present, cancelling a pending unload/leave.
    function markPresent() {
        if (conversation && isOpenStatus(conversation.status)) {
            request('POST', '/api/v1/public/conversations/' + conversation.uuid + '/present');
        }
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function safeJson(text) {
        try {
            return JSON.parse(text);
        } catch (error) {
            return {};
        }
    }

    // Append plain text to a node, turning bare http(s) URLs into real links.
    // Built from text + anchor nodes (never innerHTML) so answers can't inject markup.
    function appendLinkified(node, text) {
        const pattern = /(https?:\/\/[^\s]+)/g;
        let lastIndex = 0;
        let match;
        while ((match = pattern.exec(text)) !== null) {
            if (match.index > lastIndex) {
                node.appendChild(document.createTextNode(text.slice(lastIndex, match.index)));
            }
            const link = document.createElement('a');
            link.href = match[0];
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = match[0];
            node.appendChild(link);
            lastIndex = pattern.lastIndex;
        }
        if (lastIndex < text.length) {
            node.appendChild(document.createTextNode(text.slice(lastIndex)));
        }
    }
})();
