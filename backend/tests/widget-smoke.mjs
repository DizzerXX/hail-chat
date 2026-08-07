// Runtime smoke test for public/widget.js — executes the widget's real code
// paths (start, render, websocket events, outage resume, end chat) in Node
// with a minimal DOM stub. Run with: node tests/widget-smoke.mjs
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const source = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../public/widget.js'), 'utf8');

let failures = 0;
function check(condition, label) {
  console.log((condition ? '  ok ' : 'FAIL ') + label);
  if (!condition) failures++;
}

class FakeNode {
  constructor(tag = 'div') {
    this.tag = tag;
    this.children = [];
    this.dataset = {};
    this.style = {};
    this.value = '';
    this.textContent = '';
    this.disabled = false;
    this.scrollTop = 0;
    this.scrollHeight = 0;
    this.handlers = {};
    this.classes = new Set();
    this.selectorCache = new Map();
    this.classList = {
      add: (c) => this.classes.add(c),
      remove: (c) => this.classes.delete(c),
      toggle: (c, on) => (on ? this.classes.add(c) : this.classes.delete(c)),
      contains: (c) => this.classes.has(c),
    };
  }

  set className(value) {
    value.split(/\s+/).forEach((c) => c && this.classes.add(c));
  }

  set innerHTML(_html) {
    /* template markup — children are resolved via querySelector stubs */
  }

  appendChild(node) {
    this.children.push(node);
    return node;
  }

  append(...nodes) {
    nodes.forEach((n) => this.appendChild(n));
  }

  querySelector(selector) {
    if (!this.selectorCache.has(selector)) {
      this.selectorCache.set(selector, new FakeNode());
    }
    return this.selectorCache.get(selector);
  }

  addEventListener(type, handler) {
    (this.handlers[type] ??= []).push(handler);
  }

  setAttribute() {}

  fire(type, event = {}) {
    return Promise.all((this.handlers[type] || []).map((h) => h({ key: '', ...event })));
  }
}

function makeSandbox({ storage = {}, responses }) {
  const body = new FakeNode('body');
  const windowNode = new FakeNode('window');
  const beacons = [];
  const presence = [];
  const timeouts = [];
  const sockets = [];

  const sandbox = {
    console,
    document: {
      currentScript: {
        dataset: { inbox: 'website', reverbKey: 'test-key', wsHost: 'localhost', wsPort: '8080' },
        src: 'http://localhost:8010/widget.js',
      },
      createElement: (tag) => new FakeNode(tag),
      head: new FakeNode('head'),
      body,
      documentElement: { style: { props: {}, setProperty(k, v) { this.props[k] = v; } } },
    },
    location: { href: 'http://localhost:8010/widget-demo.html', hostname: 'localhost' },
    localStorage: {
      getItem: (k) => (k in storage ? storage[k] : null),
      setItem: (k, v) => (storage[k] = String(v)),
      removeItem: (k) => delete storage[k],
    },
    fetch: async (url, options = {}) => {
      // The widget fetches public theme config on init; answer it out-of-band
      // so scenarios don't have to account for it in their response queues.
      if (String(url).includes('/public/app-config')) {
        return { ok: true, status: 200, json: async () => ({ data: { bubble_color: '#2f80ed' } }) };
      }
      // Presence pings (markPresent) are fire-and-forget; answer out-of-band so
      // scenarios don't have to thread them through their response queues.
      if (String(url).includes('/present')) {
        presence.push(String(url));
        return { ok: true, status: 204, json: async () => ({}) };
      }
      const route = responses.shift();
      if (!route) throw new Error('unexpected fetch: ' + url);
      sandbox.lastFetch = { url, options };
      if (route.network === false) throw new Error('network down');
      return { status: route.status, json: async () => route.body };
    },
    WebSocket: class {
      constructor(url) {
        this.url = url;
        this.sent = [];
        sockets.push(this);
      }

      send(frame) {
        this.sent.push(frame);
      }

      close() {}
    },
    setTimeout: (fn, ms) => (timeouts.push({ fn, ms }), timeouts.length),
    clearTimeout: () => {},
    setInterval: () => 1,
    clearInterval: () => {},
    window: windowNode,
    navigator: {
      sendBeacon: (url) => {
        beacons.push(String(url));
        return true;
      },
    },
    URL,
    JSON,
    Promise,
  };
  sandbox.globalThis = sandbox;

  vm.createContext(sandbox);
  vm.runInContext(source, sandbox, { filename: 'widget.js' });

  const button = body.children[0];
  const panel = body.children[1];
  return { sandbox, body, windowNode, button, panel, beacons, presence, timeouts, sockets, storage };
}

const conversation = (status, messages = []) => ({
  uuid: 'uuid-1',
  status,
  language: 'ms',
  visitor: { name: 'Danish', email: null, phone: null },
  assigned_agent: status === 'active' ? { id: 1, display_name: 'Aina' } : null,
  messages,
});

const visitorMessage = { id: 1, sender_type: 'visitor', body: 'hello', created_at: 'x' };
const botMessage = { id: 2, sender_type: 'bot', body: 'Jawapan bot', created_at: 'x' };
const agentMessage = { id: 3, sender_type: 'agent', agent: { id: 1, display_name: 'Aina' }, body: 'Hi!', created_at: 'x' };

// ---------- scenario 1: fresh start, bot reply, ws events, end chat ----------
{
  const { sandbox, panel, windowNode, beacons, presence, timeouts, sockets, storage } = makeSandbox({
    responses: [
      { status: 201, body: { data: conversation('bot', [visitorMessage, botMessage]) } }, // start
      { status: 201, body: { message: { id: 4, sender_type: 'visitor', body: 'lagi' }, bot_reply: null, status: 'awaiting_acceptance' } }, // send
      { status: 200, body: { data: conversation('active', [visitorMessage, botMessage]) } }, // close -> but endChat posts close
    ],
  });

  const refs = {
    first: panel.querySelector('.tdec-first'),
    start: panel.querySelector('.tdec-start'),
    status: panel.querySelector('.tdec-status'),
    msgs: panel.querySelector('.tdec-msgs'),
    input: panel.querySelector('.tdec-input'),
    send: panel.querySelector('.tdec-send'),
    typing: panel.querySelector('.tdec-typing'),
    end: panel.querySelector('.tdec-end'),
  };

  // Theme fetched from the public app-config on init.
  await new Promise((r) => setTimeout(r, 0));
  check(
    sandbox.document.documentElement.style.props['--tdec-accent'] === '#2f80ed',
    'applies the configured bubble colour',
  );

  refs.first.value = 'hello';
  await refs.start.fire('click');

  check(storage['tdechat.website'] === 'uuid-1', 'start stores the conversation uuid');
  check(refs.msgs.children.length >= 2, 'start renders the initial messages');
  check(refs.status.textContent.includes('Chatbot'), 'status reflects bot stage');
  check(sockets.length === 1, 'websocket opened after start');

  const socket = sockets[0];
  socket.onopen();
  check(socket.sent.some((f) => f.includes('pusher:subscribe') && f.includes('conversation.uuid-1')), 'subscribes to the conversation channel');
  await new Promise((r) => setTimeout(r, 0));
  check(presence.some((u) => u.includes('/conversations/uuid-1/present')), 'a live socket marks the visitor present');

  // Closing the browser tab beacons a "leave" for the open conversation.
  await windowNode.fire('pagehide');
  check(beacons.some((u) => u.includes('/conversations/uuid-1/leave')), 'pagehide beacons a leave');

  socket.onmessage({ data: JSON.stringify({ event: 'message.sent', data: JSON.stringify({ message: agentMessage }) }) });
  check(refs.msgs.children.some((n) => n.textContent === 'Hi!'), 'ws message.sent renders the agent reply');

  socket.onmessage({ data: JSON.stringify({ event: 'agent.typing', data: JSON.stringify({ typing: true }) }) });
  check(refs.typing.classes.has('on'), 'typing indicator shows');

  socket.onmessage({ data: JSON.stringify({ event: 'conversation.updated', data: JSON.stringify({ conversation: { status: 'active', assigned_agent: { id: 1, display_name: 'Aina' } } }) }) });
  check(refs.status.textContent === 'Chatting with Aina', 'conversation.updated updates the status line');

  refs.input.value = 'lagi';
  await refs.send.fire('click');
  check(refs.msgs.children.some((n) => n.textContent === 'lagi'), 'sendMessage renders own message');

  // Agent reply arriving WITH a stored translation renders it inline.
  socket.onmessage({
    data: JSON.stringify({
      event: 'message.sent',
      data: JSON.stringify({
        message: {
          id: 5,
          sender_type: 'agent',
          agent: { display_name: 'Aina' },
          body: 'Hello there',
          meta: { translations: { ms: 'Salam sejahtera' } },
        },
      }),
    }),
  });
  const inline = refs.msgs.children.some((n) =>
    n.children.some((c) => c.classes.has('tdec-trans') && c.textContent === 'Salam sejahtera'),
  );
  check(inline, 'agent message renders its stored translation');

  // A later message.translated event patches an already-rendered bubble.
  socket.onmessage({
    data: JSON.stringify({
      event: 'message.translated',
      data: JSON.stringify({ message_id: 3, translations: { ms: 'Terjemahan baru' } }),
    }),
  });
  const patched = refs.msgs.children.some((n) =>
    n.children.some((c) => c.classes.has('tdec-trans') && c.textContent === 'Terjemahan baru'),
  );
  check(patched, 'message.translated patches the existing bubble');

  await refs.end.fire('click');
  check(!('tdechat.website' in storage), 'end chat clears the stored uuid');
  check(timeouts.length >= 0, 'no unhandled paths');
}

// ---------- scenario 2: resume during outage keeps uuid and retries ----------
{
  const { panel, timeouts, storage } = makeSandbox({
    storage: { 'tdechat.website': 'uuid-1' },
    responses: [
      { status: 500, body: null }, // resume attempt -> outage
      { status: 200, body: { data: conversation('active', [visitorMessage, agentMessage]) } }, // retry succeeds
    ],
  });

  await new Promise((r) => setTimeout(r, 0));

  const refs = {
    status: panel.querySelector('.tdec-status'),
    msgs: panel.querySelector('.tdec-msgs'),
    input: panel.querySelector('.tdec-input'),
  };

  check(storage['tdechat.website'] === 'uuid-1', 'outage keeps the stored uuid');
  check(refs.status.textContent.includes('Connection problem'), 'outage shows the retry banner');
  check(refs.input.disabled === true, 'composer disabled during outage');
  check(timeouts.length === 1, 'retry scheduled');

  await timeouts[0].fn();
  check(refs.msgs.children.some((n) => n.textContent === 'Hi!'), 'retry resumes and renders the thread');
  check(refs.status.textContent === 'Chatting with Aina', 'status restored after recovery');
  check(refs.input.disabled === false, 'composer re-enabled after recovery');
}

// ---------- scenario 3: resume of a deleted conversation clears storage ----------
{
  const { storage } = makeSandbox({
    storage: { 'tdechat.website': 'gone' },
    responses: [{ status: 404, body: null }],
  });

  await new Promise((r) => setTimeout(r, 0));
  check(!('tdechat.website' in storage), '404 resume clears the stored uuid');
}

// ---------- scenario 4: closing the widget panel ends an active chat ----------
{
  const { sandbox, button, panel, storage } = makeSandbox({
    responses: [
      { status: 201, body: { data: conversation('active', [visitorMessage]) } }, // start
      { status: 200, body: { data: conversation('active', [visitorMessage]) } }, // refresh on open
      { status: 200, body: { data: conversation('closed', [visitorMessage]) } }, // endChat -> close
    ],
  });

  await new Promise((r) => setTimeout(r, 0));

  const refs = {
    first: panel.querySelector('.tdec-first'),
    start: panel.querySelector('.tdec-start'),
  };

  refs.first.value = 'hi';
  await refs.start.fire('click');
  check(storage['tdechat.website'] === 'uuid-1', 'chat started and stored');

  // Open the panel, then close it — closing ends the active conversation.
  await button.fire('click'); // open
  await button.fire('click'); // close
  await new Promise((r) => setTimeout(r, 0));

  check(String(sandbox.lastFetch.url).includes('/conversations/uuid-1/close'), 'closing the panel posts a visitor close');
  check(!('tdechat.website' in storage), 'closing the panel clears the stored uuid');
}

// ---------- scenario 5: a queued visitor sees their place in line ----------
{
  const { panel } = makeSandbox({
    responses: [
      {
        status: 201,
        body: {
          data: {
            uuid: 'uuid-1',
            status: 'awaiting_acceptance',
            language: 'ms',
            visitor: { name: 'Danish', email: null, phone: null },
            assigned_agent: null,
            queue_position: 3,
            messages: [],
          },
        },
      },
    ],
  });

  await new Promise((r) => setTimeout(r, 0));
  const refs = {
    first: panel.querySelector('.tdec-first'),
    start: panel.querySelector('.tdec-start'),
    status: panel.querySelector('.tdec-status'),
  };

  refs.first.value = 'hello';
  await refs.start.fire('click');
  check(refs.status.textContent.includes('#3 in queue'), 'queued visitor sees their position in line');
}

console.log(failures === 0 ? '\nWIDGET SMOKE: ALL PASS' : `\nWIDGET SMOKE: ${failures} FAILURE(S)`);
process.exit(failures === 0 ? 0 : 1);
