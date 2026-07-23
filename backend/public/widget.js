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
  let typingTimer = null;
  let open = false;

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
  .tdec-head{background:var(--tdec-accent,#2a78d6);color:#fff;padding:14px 16px}
  .tdec-head h3{margin:0;font-size:15px;font-weight:600}
  .tdec-status{font-size:12px;opacity:.85;margin-top:2px}
  .tdec-msgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;background:#f7f7f5}
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
  .tdec-bar{display:flex;gap:8px;padding:8px 10px;background:#fff;border-top:1px solid #e1e0d9}
  .tdec-link{background:none;border:none;color:var(--tdec-accent,#2a78d6);cursor:pointer;font-size:12px;padding:4px}
  .tdec-pre{padding:18px;display:flex;flex-direction:column;gap:10px;background:#f7f7f5;flex:1}
  .tdec-pre label{font-size:12px;color:#52514e}
  .tdec-pre input,.tdec-pre select{border:1px solid #c3c2b7;border-radius:8px;padding:9px 12px;font:inherit;width:100%;box-sizing:border-box}
  .tdec-pre .tdec-start{background:var(--tdec-accent,#2a78d6);color:#fff;border:none;border-radius:8px;padding:11px;cursor:pointer;font:inherit;margin-top:4px}
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
      <h3>Chat with us</h3>
      <div class="tdec-status">TDEChat</div>
    </div>
    <div class="tdec-pre">
      <label>Your name (optional)
        <input type="text" class="tdec-name" autocomplete="name" />
      </label>
      <label>Language
        <select class="tdec-lang">
          <option value="ms">Bahasa Malaysia</option>
          <option value="en">English</option>
        </select>
      </label>
      <label>How can we help?
        <input type="text" class="tdec-first" placeholder="Taip mesej anda\u2026" />
      </label>
      <button class="tdec-start">Start chat</button>
    </div>
    <div class="tdec-msgs" style="display:none"></div>
    <div class="tdec-typing">Agent is typing\u2026</div>
    <div class="tdec-bar" style="display:none">
      <button class="tdec-link tdec-escalate">Talk to a human</button>
      <span style="flex:1"></span>
      <button class="tdec-link tdec-end">End chat</button>
    </div>
    <div class="tdec-foot" style="display:none">
      <textarea class="tdec-input" rows="1" placeholder="Type a message\u2026"></textarea>
      <button class="tdec-send" aria-label="Send">\u27A4</button>
    </div>
  `;

  document.body.append(button, panel);

  const refs = {
    status: panel.querySelector('.tdec-status'),
    pre: panel.querySelector('.tdec-pre'),
    msgs: panel.querySelector('.tdec-msgs'),
    typing: panel.querySelector('.tdec-typing'),
    bar: panel.querySelector('.tdec-bar'),
    foot: panel.querySelector('.tdec-foot'),
    input: panel.querySelector('.tdec-input'),
    send: panel.querySelector('.tdec-send'),
    name: panel.querySelector('.tdec-name'),
    lang: panel.querySelector('.tdec-lang'),
    first: panel.querySelector('.tdec-first'),
    start: panel.querySelector('.tdec-start'),
    escalate: panel.querySelector('.tdec-escalate'),
    end: panel.querySelector('.tdec-end'),
  };
  refs.lang.value = config.language;

  // ---------- events ----------
  button.addEventListener('click', () => toggle(!open));
  refs.start.addEventListener('click', start);
  refs.first.addEventListener('keydown', (e) => e.key === 'Enter' && start());
  refs.send.addEventListener('click', sendMessage);
  refs.input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });
  refs.escalate.addEventListener('click', escalate);
  refs.end.addEventListener('click', endChat);

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
      const color = body && body.data && body.data.bubble_color;
      if (color) document.documentElement.style.setProperty('--tdec-accent', color);
    } catch (e) {
      /* keep the default accent */
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

    const response = await api('POST', `/api/v1/public/inboxes/${config.inbox}/conversations`, {
      message: message,
      visitor_name: refs.name.value.trim() || null,
      language: refs.lang.value,
      meta: { page: location.href },
    });

    if (!response) return;

    setConversation(response.data);
    localStorage.setItem(storageKey, conversation.uuid);
    (conversation.messages || []).forEach(render);
    connect();
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
  }

  async function refresh() {
    if (!conversation) return;
    const response = await api('GET', `/api/v1/public/conversations/${conversation.uuid}`);
    if (!response) return;
    setConversation(response.data);
    (conversation.messages || []).forEach(render);
  }

  async function sendMessage() {
    const body = refs.input.value.trim();
    if (body === '' || !conversation) return;
    refs.input.value = '';

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
    seenMessages.clear();
    messageNodes.clear();
    refs.msgs.innerHTML = '';
    show('pre');
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
    const labels = {
      pending: 'Connecting\u2026',
      bot: 'Chatbot \u2014 ask anything',
      awaiting_acceptance: 'Waiting for an agent\u2026',
      active: agent ? 'Chatting with ' + agent.display_name : 'Agent connected',
      closed: 'Conversation ended',
      abandoned: 'Conversation ended',
    };
    refs.status.textContent = labels[status] || status;

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
        message.sender_type === 'bot' ? 'Bot' : (message.agent && message.agent.display_name) || 'Agent',
      );
      refs.msgs.appendChild(sender);
    }

    const bubble = element('div', 'tdec-msg ' + message.sender_type, message.body);
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
})();
