(function () {
  'use strict';

  if (window.opensolrChatBot || typeof window.fetch !== 'function' || typeof Element.prototype.attachShadow !== 'function') {
    return;
  }
  window.opensolrChatBot = true;

  const script = document.currentScript || findScript();
  if (!script || !script.src) {
    return;
  }
  const SRC = new URL(script.src, window.location.href);
  const BASE_PATH = SRC.pathname.replace(/\/widget\.js$/, '');
  const BASE = SRC.origin + BASE_PATH;
  const KEY = 'opensolr-chat:' + BASE_PATH + ':';
  const STALL_MS = 45000;
  const TOTAL_MS = 180000;
  const REQUEST_MS = 20000;
  const Z = 1999999000;
  const NS = 'http://www.w3.org/2000/svg';
  const TERMINAL = { done: true, error: true, captcha: true, limit: true };
  const MONO = 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace';
  const PUNCT = /[!-\/:-@\[-`{-~]/;
  const LIST = /^([ \t]*)([-*+]|\d{1,9}[.)])[ \t]+(.*)$/;
  const HR = /^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/;
  const FENCE = /^ {0,3}(`{3,}|~{3,})/;
  const HEADING = /^ {0,3}(#{1,6})[ \t]+(.*?)(?:[ \t]+#+)?[ \t]*$/;
  const QUOTE = /^ {0,3}>/;
  const SEP = /^\|?\s*:?-+:?\s*(?:\|\s*:?-+:?\s*)*\|?$/;

  const ICON = {
    chat: ['M4 5h16v11h-8l-5 4v-4H4z'],
    close: ['M6 6l12 12', 'M18 6L6 18'],
    plus: ['M12 5v14', 'M5 12h14'],
    help: ['M12 3a9 9 0 1 0 0 18a9 9 0 1 0 0-18z', 'M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6', 'M12 17h.01'],
    send: ['M5 12h14', 'M13 6l6 6-6 6'],
    back: ['M15 6l-6 6 6 6'],
    grip: ['M4 10V4h6', 'M4 4l7 7', 'M20 14v6h-6', 'M20 20l-7-7']
  };

  const T = {
    open: 'Open the chat',
    close: 'Close the chat',
    newChat: 'New chat',
    newChatTitle: 'Start a new chat',
    commands: 'Commands',
    resize: 'Resize the chat: drag, or use the arrow keys',
    resizeTitle: 'Drag to resize',
    message: 'Your message',
    send: 'Send',
    log: 'Conversation',
    back: 'Back to the commands',
    closeDialog: 'Close',
    helpIntro: 'Start a message with a command to look something up at once, without waiting for the assistant. Click an example to put it in the message box, change it if you like, and send it.',
    example: 'Put this example in the message box',
    codesLink: 'Table of language codes',
    codesTitle: 'Language codes',
    codesIntro: 'Write two codes joined by a hyphen, from-to (/translate en-es Good morning), or only the language to translate into (/translate es Good morning).',
    codesFind: 'Find a language or a code',
    codesNone: 'No language matches what you wrote.',
    code: 'Code',
    language: 'Language',
    native: 'In the language',
    noAnswer: 'No answer was received for this question.',
    empty: 'No answer was written. Please ask again.',
    failed: 'The question could not be answered. Please try again.',
    unreachable: 'The chat could not be reached. Check your connection and try again.',
    lost: 'The connection was lost before the answer was complete. Please try again.',
    stalled: 'The answer stopped coming. Please try again.',
    tooLong: 'The answer took too long and was stopped. Please try again.',
    cut: 'The answer was cut off before it was complete. Please try again.',
    unreadable: 'The chat sent an answer that could not be read. Please try again.',
    waitCheck: 'Waiting for you to confirm that you are not a robot\u2026',
    notChecked: 'The question was not sent, because the check that you are not a robot was not completed. Ask again to retry.',
    cookies: 'The check passed, but this browser did not keep it. Allow cookies for this site and ask again.',
    noCaptcha: 'The chat asks for a check that is not set up on this site. Please try again later.',
    waitExample: 'Wait for the answer, then pick the example again.',
    capTitle: 'Confirm that you are not a robot',
    capQuestion: 'Tick the box below. Your question is sent as soon as the check passes.',
    capStart: 'Tick the box below to start chatting.',
    capChecking: 'Checking\u2026',
    capFailed: 'The check failed. Please tick the box again.',
    capUnsent: 'The check could not be sent. Check your connection and tick the box again.',
    capExpired: 'The check expired. Please tick the box again.',
    capLoad: 'The check could not be loaded. Check your connection, then try again.',
    capRetry: 'Try again',
    capCancel: 'Cancel'
  };

  const CSS = [
    '*,*::before,*::after{box-sizing:border-box}',
    '[hidden]{display:none!important}',
    '.root{font-family:inherit;font-size:15px;font-weight:400;font-style:normal;line-height:1.5;color:#1f1d1a;letter-spacing:normal;word-spacing:normal;text-transform:none;text-align:left;text-indent:0;text-shadow:none;white-space:normal;direction:ltr;visibility:visible;-webkit-text-size-adjust:100%}',
    'button,input,textarea{margin:0;font-family:inherit;font-size:inherit;line-height:inherit;color:inherit;letter-spacing:inherit}',
    'button{cursor:pointer}',
    'button:disabled{cursor:default;opacity:.5}',
    ':focus:not(:focus-visible){outline:none}',
    ':focus-visible{outline:2px solid #c05520;outline-offset:1px}',
    'svg{display:block;flex:0 0 auto}',
    '.sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}',
    '.launcher{position:fixed;right:calc(20px + env(safe-area-inset-right,0px));bottom:calc(20px + env(safe-area-inset-bottom,0px));display:flex;align-items:center;justify-content:center;width:56px;height:56px;padding:0;border:1px solid #c05520;border-radius:2px;background:#c05520;color:#ffffff}',
    '.launcher:hover,.launcher:focus-visible{background:#ffffff;color:#c05520}',
    '.panel{position:fixed;right:calc(20px + env(safe-area-inset-right,0px));bottom:calc(20px + env(safe-area-inset-bottom,0px));display:flex;flex-direction:column;width:380px;height:600px;max-width:calc(100vw - 40px);max-height:calc(100vh - 40px);max-height:calc(100dvh - 40px);overflow:hidden;border:1px solid #d9d4cc;border-radius:2px;background:#ffffff}',
    '.panel.resizing{-webkit-user-select:none;user-select:none}',
    '.head{position:relative;flex:0 0 auto;display:flex;align-items:center;gap:6px;min-height:54px;padding:8px 8px 8px 34px;background:#c05520;color:#ffffff}',
    '.head :focus-visible{outline-color:#ffffff}',
    '.title{flex:1 1 auto;min-width:0;margin:0;overflow:hidden;font-size:16px;font-weight:600;line-height:1.3;white-space:nowrap;text-overflow:ellipsis}',
    '.hbtn{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;gap:6px;min-width:34px;height:34px;padding:0 8px;border:1px solid #ffffff00;border-radius:2px;background:#ffffff00;color:#ffffff;font-size:14px;font-weight:600;line-height:1;white-space:nowrap}',
    '.hbtn:hover:not(:disabled),.hbtn:focus-visible{border-color:#ffffff}',
    '.hbtn.new{border-color:#ffffff99}',
    '.grip{position:absolute;top:3px;left:3px;display:flex;align-items:center;justify-content:center;width:26px;height:26px;padding:0;border:0;border-radius:2px;background:#ffffff00;color:#ffffff;cursor:nwse-resize;touch-action:none}',
    '.body{position:relative;flex:1 1 auto;display:flex;flex-direction:column;min-height:0;background:#ffffff}',
    '.log{flex:1 1 auto;display:flex;flex-direction:column;gap:10px;min-height:0;padding:14px;overflow-y:auto;overscroll-behavior:contain;background:#f7f5f2}',
    '.msg{max-width:90%;padding:9px 12px;border:1px solid #e5e1da;border-radius:2px;background:#ffffff;overflow-wrap:anywhere;word-break:break-word}',
    '.msg.user{align-self:flex-end;border-color:#ddd5ca;background:#efe9e2;white-space:pre-wrap}',
    '.msg.bot{align-self:flex-start}',
    '.note{align-self:center;margin:0;color:#4a4540;font-size:14px;font-style:italic}',
    '.err{margin:0;color:#b42318}',
    '.md+.err{margin-top:.5em;padding-top:.5em;border-top:1px solid #e5e1da}',
    '.progress{display:flex;align-items:center;gap:10px;min-height:22px;color:#4a4540;font-style:italic}',
    '.dot{flex:0 0 auto;width:8px;height:8px;border-radius:2px;background:#c05520;animation:osc-pulse 1s ease-in-out infinite}',
    '@keyframes osc-pulse{0%,100%{opacity:.25}50%{opacity:1}}',
    '@media (prefers-reduced-motion:reduce){.dot{animation:none}}',
    '.md>:first-child{margin-top:0}',
    '.md>:last-child{margin-bottom:0}',
    '.md p,.md ul,.md ol,.md pre,.md blockquote,.md .tbl{margin:0 0 .6em}',
    '.md ul,.md ol{padding-left:1.4em}',
    '.md li{margin:.2em 0}',
    '.md li>p{margin:0 0 .3em}',
    '.md li>:last-child{margin-bottom:0}',
    '.md li>ul,.md li>ol{margin:.2em 0 0}',
    '.md .h{font-size:16px}',
    '.md a{color:#c05520;text-decoration:underline;text-underline-offset:2px}',
    '.md a:hover{text-decoration-thickness:2px}',
    '.md code{padding:1px 4px;border-radius:2px;background:#f1ede8;font-family:' + MONO + ';font-size:14px}',
    '.md pre{padding:8px 10px;overflow-x:auto;border:1px solid #e5e1da;border-radius:2px;background:#f7f5f2;white-space:pre}',
    '.md pre code{padding:0;background:#ffffff00;overflow-wrap:normal;word-break:normal}',
    '.md blockquote{padding-left:10px;border-left:2px solid #d9d4cc;color:#4a4540}',
    '.md hr{margin:.8em 0;border:0;border-top:1px solid #e5e1da}',
    '.md .tbl{max-width:100%;overflow-x:auto}',
    '.md table{border-collapse:collapse;font-size:14px;overflow-wrap:normal;word-break:normal}',
    '.md th,.md td{padding:4px 8px;border:1px solid #e5e1da;text-align:left;vertical-align:top}',
    '.md thead th{background:#f7f5f2;font-weight:600}',
    '.md del{color:#4a4540}',
    '.foot{position:relative;flex:0 0 auto;padding:10px;border-top:1px solid #e5e1da;background:#ffffff}',
    '.notice{margin:0 0 8px;color:#b42318;font-size:14px}',
    '.form{display:flex;align-items:flex-end;gap:8px;margin:0}',
    '.input{flex:1 1 auto;display:block;min-width:0;height:44px;min-height:44px;max-height:160px;padding:9px 10px;border:1px solid #d9d4cc;border-radius:2px;background:#ffffff;color:#1f1d1a;font-size:15px;line-height:24px;resize:none;overflow-y:auto}',
    '.input:focus{outline:none;border-color:#c05520}',
    '.input::placeholder{color:#6b645c;opacity:1}',
    '.input[aria-disabled="true"]{background:#f7f5f2}',
    '.send{flex:0 0 auto;display:flex;align-items:center;justify-content:center;width:44px;height:44px;padding:0;border:1px solid #c05520;border-radius:2px;background:#c05520;color:#ffffff}',
    '.send:hover:not(:disabled),.send:focus-visible{background:#ffffff;color:#c05520}',
    '.menu{position:absolute;right:10px;bottom:100%;left:10px;z-index:2;max-height:240px;overflow-y:auto;border:1px solid #d9d4cc;border-radius:2px;background:#ffffff;font-size:14px;line-height:1.4}',
    '.opt{display:flex;flex-direction:column;gap:2px;padding:8px 12px;cursor:pointer}',
    '.opt+.opt{border-top:1px solid #f1ede8}',
    '.opt code{color:#c05520;font-family:' + MONO + ';font-size:14px;font-weight:600}',
    '.opt span{color:#4a4540}',
    '.opt.active,.opt:hover{background:#f7f5f2}',
    '.dialog{position:absolute;top:0;right:0;bottom:0;left:0;z-index:3;overflow-y:auto;padding:14px 16px 20px;background:#ffffff;font-size:14px;line-height:1.5}',
    '.dialog:focus{outline:none}',
    '.dhead{display:flex;align-items:center;gap:6px;margin:0 0 8px}',
    '.dtitle{flex:1 1 auto;margin:0;font-size:16px;font-weight:600}',
    '.dbtn{flex:0 0 auto;display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;padding:0;border:1px solid #ffffff00;border-radius:2px;background:#ffffff00;color:#1f1d1a}',
    '.dbtn:hover{border-color:#d9d4cc}',
    '.intro{margin:0 0 12px;color:#4a4540}',
    '.cmds{margin:0}',
    '.cmds dt{margin:14px 0 0}',
    '.cmds dt code{padding:1px 6px;border-radius:2px;background:#f1ede8;color:#c05520;font-family:' + MONO + ';font-size:14px;font-weight:600;overflow-wrap:anywhere}',
    '.cmds dd{margin:4px 0 0}',
    '.example{display:inline-block;max-width:100%;margin:6px 10px 0 0;padding:3px 8px;border:1px solid #c05520;border-radius:2px;background:#ffffff;color:#c05520;font-family:' + MONO + ';font-size:14px;text-align:left;overflow-wrap:anywhere}',
    '.example:hover,.example:focus-visible{background:#c05520;color:#ffffff}',
    '.link{display:inline-block;margin:6px 0 0;padding:3px 0;border:0;background:#ffffff00;color:#c05520;font-size:14px;font-weight:600;text-decoration:underline}',
    '.search{display:block;width:100%;margin:0 0 10px;padding:8px 10px;border:1px solid #d9d4cc;border-radius:2px;background:#ffffff;color:#1f1d1a;font-size:15px;line-height:1.4}',
    '.search:focus{outline:none;border-color:#c05520}',
    '.codes{overflow-x:auto}',
    '.codes table{width:100%;border-collapse:collapse;font-size:14px}',
    '.codes th,.codes td{padding:6px 8px;border:1px solid #e5e1da;text-align:left;vertical-align:top}',
    '.codes thead th{background:#f7f5f2;font-weight:600;white-space:nowrap}',
    '.codes tbody th{font-weight:400;white-space:nowrap}',
    '.codes code{color:#c05520;font-family:' + MONO + ';font-size:14px;font-weight:600}',
    '.none{margin:8px 0 0;color:#4a4540}',
    '.mirror{position:absolute;top:0;left:-10000px;visibility:hidden;white-space:pre-wrap;overflow-wrap:break-word;word-wrap:break-word;border:0}',
    '@media (max-width:480px){',
    '.panel{top:var(--osc-top,0px);right:0;bottom:auto;left:0;width:auto!important;height:var(--osc-h,100%)!important;max-width:none;max-height:none;padding:env(safe-area-inset-top,0px) env(safe-area-inset-right,0px) 0 env(safe-area-inset-left,0px);border:0;border-radius:0;background:#c05520}',
    '.head{padding-left:12px;touch-action:none}',
    '.grip{display:none}',
    '.foot{padding-bottom:calc(10px + env(safe-area-inset-bottom,0px))}',
    '.input,.search{font-size:16px}',
    '.msg{max-width:94%}',
    '}',
    '@media print{.root{display:none}}'
  ].join('\n');

  const local = storage('localStorage');
  const session = storage('sessionStorage');

  let cfg = null;
  let host = null;
  let root = null;
  const ui = {};
  let built = false;
  let conv = null;
  let busy = false;
  let failsafe = 0;
  let sent = [];
  let recall = -1;
  let draft = '';
  let lastValue = '';
  let matches = [];
  let active = 0;
  let passed = !!local.get('pass');
  let declined = !!session.get('declined');
  let cap = null;
  let recaptcha = null;
  let frame = 0;

  ready(function () { start(0); });

  function findScript() {
    const all = document.getElementsByTagName('script');
    for (let i = all.length - 1; i >= 0; i--) {
      if (/\/widget\.js(?:[?#]|$)/.test(all[i].src)) {
        return all[i];
      }
    }
    return null;
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    }
    else {
      fn();
    }
  }

  function storage(kind) {
    return {
      get: function (k) {
        try {
          const v = window[kind].getItem(KEY + k);
          return v === null ? null : JSON.parse(v);
        }
        catch (e) {
          return null;
        }
      },
      set: function (k, v) {
        try {
          window[kind].setItem(KEY + k, JSON.stringify(v));
        }
        catch (e) {}
      },
      del: function (k) {
        try {
          window[kind].removeItem(KEY + k);
        }
        catch (e) {}
      }
    };
  }

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) {
      e.className = cls;
    }
    if (text != null) {
      e.textContent = text;
    }
    return e;
  }

  function button(cls, label) {
    const b = el('button', cls);
    b.type = 'button';
    if (label) {
      b.setAttribute('aria-label', label);
    }
    return b;
  }

  function icon(paths, size) {
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.5');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    paths.forEach(function (d) {
      const p = document.createElementNS(NS, 'path');
      p.setAttribute('d', d);
      svg.appendChild(p);
    });
    return svg;
  }

  function css(node, map) {
    Object.keys(map).forEach(function (k) {
      node.style.setProperty(k, map[k], 'important');
    });
  }

  function str(v) {
    return typeof v === 'string' ? v.trim() : '';
  }

  function clip(v) {
    return str(v).slice(0, 600);
  }

  function positive(v) {
    const n = parseInt(v, 10);
    return n > 0 ? n : 0;
  }

  function fmt(n) {
    return Number(n).toLocaleString('en-US');
  }

  function chars(s) {
    return s.length - (s.match(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g) || []).length;
  }

  function narrow() {
    return window.matchMedia('(max-width: 480px)').matches;
  }

  function coarse() {
    return window.matchMedia('(pointer: coarse)').matches;
  }

  function newId() {
    const b = new Uint8Array(16);
    window.crypto.getRandomValues(b);
    return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }

  function requestJSON(path, init) {
    const ctrl = new AbortController();
    const timer = setTimeout(function () { ctrl.abort(); }, REQUEST_MS);
    const options = Object.assign({ credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } }, init, { signal: ctrl.signal });
    return fetch(BASE + path, options).then(function (r) {
      return r.text().then(function (body) {
        let data = null;
        try {
          data = JSON.parse(body);
        }
        catch (e) {
          data = null;
        }
        return { ok: r.ok, status: r.status, data: data };
      });
    }).finally(function () { clearTimeout(timer); });
  }

  function start(attempt) {
    requestJSON('/config', { method: 'GET' }).then(function (r) {
      if (!r.ok || !r.data || typeof r.data !== 'object') {
        throw new Error('config');
      }
      cfg = normalize(r.data);
      mount();
      if (session.get('open')) {
        open(true);
      }
    }).catch(function () {
      if (!attempt && !cfg) {
        setTimeout(function () { start(1); }, 3000);
      }
    });
  }

  function normalize(c) {
    const captcha = c.captcha && typeof c.captcha === 'object' ? c.captcha : {};
    const commands = [];
    (Array.isArray(c.commands) ? c.commands : []).forEach(function (x) {
      const name = x ? str(x.name).replace(/^\//, '').toLowerCase() : '';
      if (/^[a-z][a-z0-9_-]*$/.test(name)) {
        commands.push({ name: name, usage: str(x.usage) || '/' + name, description: str(x.description), example: str(x.example) });
      }
    });
    const languages = [];
    if (Array.isArray(c.languages)) {
      c.languages.forEach(function (l) {
        if (Array.isArray(l) && str(l[0])) {
          languages.push([str(l[0]), str(l[1]), str(l[2]) || str(l[1])]);
        }
      });
    }
    else if (c.languages && typeof c.languages === 'object') {
      Object.keys(c.languages).sort().forEach(function (code) {
        const names = c.languages[code];
        if (Array.isArray(names) && /^[A-Za-z0-9-]{1,20}$/.test(code)) {
          languages.push([code, str(names[0]), str(names[1]) || str(names[0])]);
        }
      });
    }
    return {
      title: str(c.title) || 'Chat',
      greeting: str(c.greeting),
      placeholder: str(c.placeholder),
      maxChars: positive(c.max_chars),
      maxTranslate: positive(c.max_translate_chars),
      maxQuestions: positive(c.questions_per_conversation),
      captcha: captcha.required === true,
      siteKey: str(captcha.site_key),
      commands: commands,
      languages: languages
    };
  }

  function mount() {
    host = document.createElement('opensolr-chat-bot');
    css(host, { position: 'fixed', top: '0', left: '0', width: '0', height: '0', margin: '0', padding: '0', border: '0', overflow: 'visible', display: 'block', 'z-index': String(Z) });
    root = host.attachShadow({ mode: 'open' });
    const style = el('style');
    style.textContent = CSS;
    root.appendChild(style);
    ui.root = el('div', 'root');
    root.appendChild(ui.root);
    ui.launcher = button('launcher', T.open);
    ui.launcher.title = cfg.title;
    ui.launcher.setAttribute('aria-expanded', 'false');
    ui.launcher.setAttribute('aria-haspopup', 'dialog');
    ui.launcher.appendChild(icon(ICON.chat, 26));
    ui.launcher.addEventListener('click', function () { open(false); });
    ui.root.appendChild(ui.launcher);
    document.body.appendChild(host);

    window.addEventListener('resize', onViewport);
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', onViewport);
      window.visualViewport.addEventListener('scroll', onViewport);
    }
    window.addEventListener('storage', function (e) {
      if (!built || busy || e.key !== KEY + 'conversation') {
        return;
      }
      conv = loadConversation();
      rebuildSent();
      renderAll();
      updatePlaceholder();
      scrollBottom();
    });
  }

  function build() {
    built = true;
    conv = loadConversation();
    rebuildSent();

    const panel = ui.panel = el('div', 'panel');
    panel.id = 'osc-panel';
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-labelledby', 'osc-title');
    ui.launcher.setAttribute('aria-controls', 'osc-panel');

    const head = el('div', 'head');
    ui.grip = button('grip', T.resize);
    ui.grip.title = T.resizeTitle;
    ui.grip.appendChild(icon(ICON.grip, 18));
    head.appendChild(ui.grip);
    const title = el('h2', 'title', cfg.title);
    title.id = 'osc-title';
    head.appendChild(title);
    if (cfg.commands.length) {
      ui.helpButton = button('hbtn', T.commands);
      ui.helpButton.title = T.commands;
      ui.helpButton.setAttribute('aria-haspopup', 'dialog');
      ui.helpButton.appendChild(icon(ICON.help, 20));
      ui.helpButton.addEventListener('click', toggleHelp);
      head.appendChild(ui.helpButton);
    }
    ui.newChat = button('hbtn new');
    ui.newChat.title = T.newChatTitle;
    ui.newChat.appendChild(icon(ICON.plus, 16));
    ui.newChat.appendChild(el('span', null, T.newChat));
    ui.newChat.addEventListener('click', newChat);
    head.appendChild(ui.newChat);
    const x = button('hbtn', T.close);
    x.title = T.close;
    x.appendChild(icon(ICON.close, 20));
    x.addEventListener('click', close);
    head.appendChild(x);
    panel.appendChild(head);

    const body = ui.body = el('div', 'body');
    ui.log = el('div', 'log');
    ui.log.setAttribute('role', 'log');
    ui.log.setAttribute('aria-live', 'polite');
    ui.log.setAttribute('aria-label', T.log);
    ui.log.setAttribute('aria-busy', 'false');
    ui.log.tabIndex = 0;
    body.appendChild(ui.log);

    ui.foot = el('div', 'foot');
    ui.menu = el('div', 'menu');
    ui.menu.id = 'osc-menu';
    ui.menu.setAttribute('role', 'listbox');
    ui.menu.setAttribute('aria-label', T.commands);
    ui.menu.hidden = true;
    ui.notice = el('p', 'notice');
    ui.notice.setAttribute('role', 'alert');
    ui.notice.hidden = true;
    const form = el('form', 'form');
    form.noValidate = true;
    ui.input = el('textarea', 'input');
    ui.input.rows = 1;
    ui.input.setAttribute('aria-label', T.message);
    ui.input.setAttribute('enterkeyhint', 'send');
    ui.input.setAttribute('autocomplete', 'off');
    ui.input.setAttribute('dir', 'auto');
    ui.input.setAttribute('aria-disabled', 'false');
    if (cfg.commands.length) {
      ui.input.setAttribute('aria-autocomplete', 'list');
      ui.input.setAttribute('aria-controls', 'osc-menu');
    }
    ui.send = button('send', T.send);
    ui.send.type = 'submit';
    ui.send.title = T.send;
    ui.send.appendChild(icon(ICON.send, 20));
    form.appendChild(ui.input);
    form.appendChild(ui.send);
    ui.foot.appendChild(ui.menu);
    ui.foot.appendChild(ui.notice);
    ui.foot.appendChild(form);
    body.appendChild(ui.foot);
    panel.appendChild(body);

    ui.status = el('div', 'sr');
    ui.status.setAttribute('role', 'status');
    ui.status.setAttribute('aria-live', 'polite');
    ui.mirror = el('div', 'mirror');
    ui.mirror.setAttribute('aria-hidden', 'true');
    ui.root.appendChild(panel);
    ui.root.appendChild(ui.status);
    ui.root.appendChild(ui.mirror);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!busy) {
        submit();
      }
    });
    ui.send.addEventListener('mousedown', function (e) { e.preventDefault(); });
    ui.input.addEventListener('beforeinput', function (e) {
      if (busy) {
        e.preventDefault();
      }
    });
    ['paste', 'drop', 'cut'].forEach(function (type) {
      ui.input.addEventListener(type, function (e) {
        if (busy) {
          e.preventDefault();
        }
      });
    });
    ui.input.addEventListener('input', function () {
      if (busy) {
        if (ui.input.value !== lastValue) {
          ui.input.value = lastValue;
        }
        return;
      }
      lastValue = ui.input.value;
      autosize();
      if (!ui.notice.hidden) {
        notice('');
      }
      active = 0;
      updateMenu();
    });
    ui.input.addEventListener('keydown', onKey);
    ui.input.addEventListener('blur', function () { setTimeout(hideMenu, 150); });
    panel.addEventListener('keydown', onEscape);
    bindGrip();

    renderAll();
    updatePlaceholder();
  }

  function onKey(e) {
    if (e.isComposing || e.keyCode === 229) {
      return;
    }
    const k = e.key;
    const plain = !e.shiftKey && !e.altKey && !e.ctrlKey && !e.metaKey;
    if (!ui.menu.hidden && matches.length) {
      if (k === 'ArrowDown' || k === 'ArrowUp') {
        active = (active + (k === 'ArrowDown' ? 1 : matches.length - 1)) % matches.length;
        renderMenu();
        e.preventDefault();
        return;
      }
      if ((k === 'Enter' || k === 'Tab') && plain) {
        choose(active);
        e.preventDefault();
        return;
      }
      if (k === 'Escape') {
        hideMenu();
        e.preventDefault();
        e.stopPropagation();
        return;
      }
    }
    if (k === 'Enter' && !e.shiftKey && !e.altKey) {
      e.preventDefault();
      if (!busy) {
        submit();
      }
      return;
    }
    if ((k === 'ArrowUp' || k === 'ArrowDown') && plain && !busy && browse(k === 'ArrowUp')) {
      e.preventDefault();
    }
  }

  function onEscape(e) {
    if (e.key !== 'Escape' || e.defaultPrevented || e.isComposing) {
      return;
    }
    e.preventDefault();
    if (ui.codes && !ui.codes.hidden) {
      if (e.target === ui.filter && ui.filter.value) {
        ui.filter.value = '';
        ui.filter.dispatchEvent(new Event('input'));
        return;
      }
      backToHelp();
      return;
    }
    if (ui.help && !ui.help.hidden) {
      closeDialogs(true);
      return;
    }
    close();
  }

  function open(auto) {
    if (!reveal()) {
      return;
    }
    if (auto) {
      return;
    }
    if (cfg.captcha && cfg.siteKey && !passed && !declined) {
      showCaptcha(null);
      return;
    }
    caretEnd();
  }

  function reveal() {
    if (!built) {
      build();
    }
    if (!ui.panel.hidden) {
      return false;
    }
    ui.panel.hidden = false;
    ui.launcher.hidden = true;
    ui.launcher.setAttribute('aria-expanded', 'true');
    session.set('open', true);
    applySize();
    viewport();
    scrollBottom();
    return true;
  }

  function close() {
    if (cap && cap.visible) {
      cancelCaptcha();
    }
    hideMenu();
    closeDialogs(false);
    ui.panel.hidden = true;
    ui.launcher.hidden = false;
    ui.launcher.setAttribute('aria-expanded', 'false');
    session.del('open');
    ui.launcher.focus();
  }

  function loadConversation() {
    const c = local.get('conversation');
    if (c && typeof c.id === 'string' && /^[A-Za-z0-9_-]{8,64}$/.test(c.id) && Array.isArray(c.messages)) {
      const messages = [];
      c.messages.forEach(function (m) {
        if (m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string') {
          messages.push({ role: m.role, content: m.content, error: typeof m.error === 'string' ? m.error : '' });
        }
      });
      return { id: c.id, messages: messages };
    }
    return { id: newId(), messages: [] };
  }

  function save() {
    conv.messages = conv.messages.slice(-100);
    local.set('conversation', conv);
  }

  function rebuildSent() {
    sent = [];
    conv.messages.forEach(function (m) {
      if (m.role === 'user') {
        remember(m.content);
      }
    });
  }

  function remember(text) {
    text = String(text || '').trim();
    if (text !== '' && sent[sent.length - 1] !== text) {
      sent.push(text);
    }
    recall = -1;
    draft = '';
  }

  function history() {
    const list = [];
    conv.messages.forEach(function (m) {
      if (m.content !== '') {
        list.push({ role: m.role, content: m.content });
      }
    });
    const out = list.slice(-21);
    while (out.length > 1 && out[0].role !== 'user') {
      out.shift();
    }
    return out;
  }

  function questionsAsked() {
    const m = conv.messages;
    let n = 0;
    for (let i = 0; i < m.length; i++) {
      if (m[i].role !== 'user' || m[i].content.charAt(0) === '/') {
        continue;
      }
      const next = m[i + 1];
      if (next && next.role === 'assistant' && next.content === '' && next.error) {
        continue;
      }
      n++;
    }
    return n;
  }

  function full() {
    return cfg.maxQuestions > 0 && questionsAsked() >= cfg.maxQuestions;
  }

  function updatePlaceholder() {
    ui.input.placeholder = full() ? 'This conversation has reached ' + fmt(cfg.maxQuestions) + ' questions. Start a new chat.' : cfg.placeholder;
  }

  function notice(text) {
    ui.notice.textContent = text;
    ui.notice.hidden = !text;
  }

  function setValue(v) {
    ui.input.value = v;
    lastValue = v;
    autosize();
  }

  function autosize() {
    const t = ui.input;
    t.style.height = '';
    t.style.height = Math.max(44, Math.min(t.scrollHeight + 2, 160)) + 'px';
  }

  function caretEnd() {
    if (!built || ui.panel.hidden) {
      return;
    }
    try {
      ui.input.focus({ preventScroll: true });
    }
    catch (e) {
      ui.input.focus();
    }
    const n = ui.input.value.length;
    ui.input.setSelectionRange(n, n);
  }

  function restoreCaret() {
    if (ui.panel.hidden) {
      return;
    }
    const inside = root.activeElement;
    if (inside === ui.input || (!inside && document.activeElement === document.body && !coarse())) {
      caretEnd();
    }
  }

  function nearBottom() {
    const l = ui.log;
    return l.scrollHeight - l.scrollTop - l.clientHeight < 60;
  }

  function scrollBottom() {
    ui.log.scrollTop = ui.log.scrollHeight;
  }

  function setBusy(on) {
    const was = busy;
    busy = on;
    ui.input.setAttribute('aria-disabled', on ? 'true' : 'false');
    ui.send.disabled = on;
    ui.newChat.disabled = on;
    ui.log.setAttribute('aria-busy', on ? 'true' : 'false');
    clearTimeout(failsafe);
    if (on) {
      hideMenu();
      failsafe = setTimeout(function () {
        if (busy && !(cap && cap.visible)) {
          setBusy(false);
        }
      }, TOTAL_MS + 30000);
    }
    else if (was) {
      restoreCaret();
    }
  }

  function newChat() {
    if (busy) {
      return;
    }
    closeDialogs(false);
    conv = { id: newId(), messages: [] };
    save();
    sent = [];
    recall = -1;
    draft = '';
    renderAll();
    setValue('');
    notice('');
    hideMenu();
    updatePlaceholder();
    caretEnd();
  }

  function submit() {
    const text = ui.input.value.replace(/\u00a0/g, ' ').trim();
    if (!text) {
      return;
    }
    const command = text.charAt(0) === '/';
    const translate = /^\/translate(\s|$)/i.test(text);
    const length = chars(translate ? text.replace(/^\/translate\s+\S+\s*/i, '') : text);
    const limit = translate ? cfg.maxTranslate : cfg.maxChars;
    if (limit && length > limit) {
      notice((translate ? 'The text to translate is too long: ' : 'Your message is too long: ') + fmt(length) + ' characters, at most ' + fmt(limit) + '.');
      return;
    }
    if (!command && full()) {
      notice('This conversation has reached ' + fmt(cfg.maxQuestions) + ' questions. Start a new chat with the New chat button.');
      return;
    }
    hideMenu();
    notice('');
    conv.messages.push({ role: 'user', content: text, error: '' });
    save();
    remember(text);
    setValue('');
    ui.log.appendChild(bubbleUser(text));
    scrollBottom();
    if (root.activeElement === ui.send) {
      caretEnd();
    }
    ask(null);
  }

  function renderAll() {
    ui.log.textContent = '';
    if (cfg.greeting) {
      ui.log.appendChild(bubbleBot(cfg.greeting, ''));
    }
    const m = conv.messages;
    m.forEach(function (msg, i) {
      if (msg.role === 'user') {
        ui.log.appendChild(bubbleUser(msg.content));
        const next = m[i + 1];
        if (!next || next.role !== 'assistant') {
          ui.log.appendChild(el('p', 'note', T.noAnswer));
        }
      }
      else if (msg.content || msg.error) {
        ui.log.appendChild(bubbleBot(msg.content, msg.error));
      }
    });
  }

  function bubbleUser(text) {
    const box = el('div', 'msg user', text);
    box.setAttribute('dir', 'auto');
    return box;
  }

  function bubbleBot(text, error) {
    const box = el('div', 'msg bot');
    box.setAttribute('dir', 'auto');
    if (text) {
      const md = el('div', 'md');
      fill(md, text);
      box.appendChild(md);
    }
    if (error) {
      box.appendChild(el('p', 'err', error));
    }
    return box;
  }

  function fill(node, text) {
    node.textContent = '';
    try {
      node.appendChild(markdown(text));
    }
    catch (e) {
      node.textContent = text;
    }
  }

  function answerBox() {
    const box = el('div', 'msg bot');
    box.setAttribute('dir', 'auto');
    const prog = el('div', 'progress');
    const dot = el('span', 'dot');
    dot.setAttribute('aria-hidden', 'true');
    const progText = el('span');
    prog.appendChild(dot);
    prog.appendChild(progText);
    const md = el('div', 'md');
    md.hidden = true;
    box.appendChild(prog);
    box.appendChild(md);
    return { box: box, prog: prog, progText: progText, md: md, text: '', started: false, raf: 0, checked: false };
  }

  function setProgress(a, text) {
    text = clip(text).replace(/\\([!-\/:-@\[-`{-~])/g, '$1');
    a.prog.hidden = false;
    a.progText.textContent = text;
    ui.status.textContent = text;
  }

  function addText(a, text) {
    if (!a.started) {
      a.started = true;
      a.prog.hidden = true;
      a.md.hidden = false;
      ui.status.textContent = '';
    }
    a.text += text;
    if (!a.raf) {
      a.raf = window.requestAnimationFrame(function () {
        a.raf = 0;
        paint(a);
      });
    }
  }

  function paint(a) {
    const stick = nearBottom();
    fill(a.md, a.text);
    if (stick) {
      scrollBottom();
    }
  }

  function ask(a) {
    if (!a) {
      a = answerBox();
      ui.log.appendChild(a.box);
      scrollBottom();
    }
    setBusy(true);
    stream(a).then(function (ev) {
      settle(a, ev);
    }, function () {
      settle(a, { type: 'error', message: T.failed });
    });
  }

  function settle(a, ev) {
    if (a.raf) {
      window.cancelAnimationFrame(a.raf);
      a.raf = 0;
    }
    const stick = nearBottom();
    if (a.text) {
      fill(a.md, a.text);
    }
    if (ev.type === 'captcha') {
      passed = false;
      local.del('pass');
      if (cfg.siteKey && !a.checked) {
        setProgress(a, T.waitCheck);
        showCaptcha(a);
        return;
      }
      ev = { type: 'error', message: cfg.siteKey ? T.cookies : T.noCaptcha };
    }
    let error = '';
    if (ev.type === 'error' || ev.type === 'limit') {
      error = clip(ev.message) || T.failed;
    }
    else if (!a.text) {
      error = T.empty;
    }
    a.prog.hidden = true;
    a.md.hidden = !a.text;
    if (error) {
      a.box.appendChild(el('p', 'err', error));
    }
    conv.messages.push({ role: 'assistant', content: a.text, error: error });
    save();
    ui.status.textContent = '';
    setBusy(false);
    updatePlaceholder();
    if (stick) {
      scrollBottom();
    }
  }

  function httpError(status) {
    if (status === 404) {
      return 'The chat is not available at this address.';
    }
    if (status === 429) {
      return 'Too many questions right now. Please try again later.';
    }
    if (status === 401 || status === 403) {
      return 'The chat refused the question. Reload the page and try again.';
    }
    if (status >= 500) {
      return 'The chat is not available right now. Please try again in a moment.';
    }
    return 'The chat answered with an error (HTTP ' + status + '). Please try again.';
  }

  function stream(a) {
    return new Promise(function (resolve) {
      const ctrl = new AbortController();
      let reason = '';
      let reached = false;
      let status = 0;
      let events = 0;
      let ended = false;
      let stall = 0;
      const total = setTimeout(function () { abort('total'); }, TOTAL_MS);
      function end(ev) {
        if (ended) {
          return;
        }
        ended = true;
        clearTimeout(stall);
        clearTimeout(total);
        resolve(ev);
      }
      function abort(why) {
        if (!ended) {
          reason = why;
          ctrl.abort();
        }
      }
      function arm() {
        clearTimeout(stall);
        stall = setTimeout(function () { abort('stall'); }, STALL_MS);
      }
      const parser = sse(function (ev) {
        if (ended) {
          return;
        }
        events++;
        if (ev.type === 'progress') {
          if (!a.started && typeof ev.text === 'string') {
            setProgress(a, ev.text);
          }
        }
        else if (ev.type === 'text') {
          if (typeof ev.text === 'string' && ev.text !== '') {
            addText(a, ev.text);
          }
        }
        else if (TERMINAL[ev.type] === true) {
          end(ev);
        }
      });
      async function run() {
        const res = await fetch(BASE + '/chat', {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
          body: JSON.stringify({ conversation: conv.id, messages: history() }),
          signal: ctrl.signal
        });
        reached = true;
        status = res.status;
        arm();
        const type = (res.headers.get('Content-Type') || '').toLowerCase();
        if (!res.ok && type.indexOf('text/event-stream') === -1) {
          end({ type: 'error', message: httpError(res.status) });
          if (res.body && typeof res.body.cancel === 'function') {
            res.body.cancel().catch(function () {});
          }
          return;
        }
        if (!res.body || typeof res.body.getReader !== 'function') {
          parser.push(await res.text());
          parser.end();
          return;
        }
        const reader = res.body.getReader();
        const decoder = new TextDecoder('utf-8');
        try {
          while (!ended) {
            const step = await reader.read();
            if (step.done) {
              parser.push(decoder.decode());
              parser.end();
              break;
            }
            arm();
            parser.push(decoder.decode(step.value, { stream: true }));
          }
        }
        finally {
          if (ended) {
            reader.cancel().catch(function () {});
          }
        }
      }
      arm();
      run().then(function () {
        if (!ended) {
          end({ type: 'error', message: status && status >= 400 ? httpError(status) : (events ? T.cut : T.unreadable) });
        }
      }, function () {
        end({ type: 'error', message: reason === 'stall' ? T.stalled : reason === 'total' ? T.tooLong : (reached ? T.lost : T.unreachable) });
      });
    });
  }

  function sse(onEvent) {
    let buffer = '';
    let data = [];
    function dispatch() {
      if (!data.length) {
        return;
      }
      const raw = data.join('\n');
      data = [];
      let ev = null;
      try {
        ev = JSON.parse(raw);
      }
      catch (e) {
        return;
      }
      if (ev && typeof ev === 'object' && typeof ev.type === 'string') {
        onEvent(ev);
      }
    }
    function whole() {
      try {
        JSON.parse(data.join('\n'));
        return true;
      }
      catch (e) {
        return false;
      }
    }
    function line(l) {
      if (l === '') {
        dispatch();
        return;
      }
      if (l.charAt(0) === ':') {
        return;
      }
      const c = l.indexOf(':');
      if ((c === -1 ? l : l.slice(0, c)) !== 'data') {
        return;
      }
      let value = c === -1 ? '' : l.slice(c + 1);
      if (value.charAt(0) === ' ') {
        value = value.slice(1);
      }
      // a missing blank line between two events must not glue them together
      if (data.length && whole()) {
        dispatch();
      }
      data.push(value);
    }
    return {
      push: function (chunk) {
        if (!chunk) {
          return;
        }
        buffer += chunk;
        if (!/[\r\n]/.test(chunk)) {
          return;
        }
        let end = buffer.length;
        if (buffer.charCodeAt(end - 1) === 13) {
          end--;
        }
        const lines = buffer.slice(0, end).split(/\r\n|\r|\n/);
        buffer = lines.pop() + buffer.slice(end);
        for (let i = 0; i < lines.length; i++) {
          line(lines[i]);
        }
      },
      end: function () {
        if (buffer) {
          line(buffer.replace(/\r$/, ''));
          buffer = '';
        }
        dispatch();
      }
    };
  }

  function updateMenu() {
    const t = ui.input.value.replace(/^\s+/, '');
    if (busy || !cfg.commands.length || t.charAt(0) !== '/' || /\s/.test(t)) {
      hideMenu();
      return;
    }
    const typed = t.slice(1).toLowerCase();
    matches = cfg.commands.filter(function (c) { return c.name.indexOf(typed) === 0; });
    if (!matches.length) {
      hideMenu();
      return;
    }
    active = Math.min(active, matches.length - 1);
    renderMenu();
  }

  function renderMenu() {
    ui.menu.textContent = '';
    matches.forEach(function (c, i) {
      const o = el('div', 'opt' + (i === active ? ' active' : ''));
      o.id = 'osc-opt-' + i;
      o.setAttribute('role', 'option');
      o.setAttribute('aria-selected', i === active ? 'true' : 'false');
      o.appendChild(el('code', null, c.usage));
      const first = c.description.split('. ')[0].replace(/\.$/, '');
      if (first) {
        o.appendChild(el('span', null, first));
      }
      o.addEventListener('mousedown', function (e) { e.preventDefault(); });
      o.addEventListener('click', function () { choose(i); });
      ui.menu.appendChild(o);
    });
    ui.menu.hidden = false;
    // The list takes the whole height of the chat above the input
    ui.menu.style.maxHeight = Math.max(160, Math.floor(ui.foot.getBoundingClientRect().top - ui.body.getBoundingClientRect().top - 8)) + 'px';
    ui.input.setAttribute('aria-activedescendant', 'osc-opt-' + active);
    const cur = ui.menu.children[active];
    if (cur) {
      if (cur.offsetTop < ui.menu.scrollTop) {
        ui.menu.scrollTop = cur.offsetTop;
      }
      else if (cur.offsetTop + cur.offsetHeight > ui.menu.scrollTop + ui.menu.clientHeight) {
        ui.menu.scrollTop = cur.offsetTop + cur.offsetHeight - ui.menu.clientHeight;
      }
    }
  }

  function hideMenu() {
    if (!built) {
      return;
    }
    ui.menu.hidden = true;
    matches = [];
    ui.input.removeAttribute('aria-activedescendant');
  }

  function choose(i) {
    const c = matches[i];
    if (!c || busy) {
      return;
    }
    // a non-breaking space: the visitor keeps typing the argument right after the command
    setValue('/' + c.name + '\u00a0');
    hideMenu();
    caretEnd();
  }

  function browse(up) {
    if (!sent.length || (up && recall === 0) || (!up && recall === -1) || !onEdge(up)) {
      return false;
    }
    if (recall === -1) {
      draft = ui.input.value;
    }
    recall = up ? (recall === -1 ? sent.length - 1 : recall - 1) : (recall === sent.length - 1 ? -1 : recall + 1);
    setValue(recall === -1 ? draft : sent[recall]);
    hideMenu();
    caretEnd();
    return true;
  }

  function onEdge(up) {
    const t = ui.input;
    const v = t.value;
    if (v.trim() === '' || (recall !== -1 && v === sent[recall])) {
      return true;
    }
    if (t.selectionStart !== t.selectionEnd) {
      return false;
    }
    const pos = t.selectionStart;
    if (up ? (pos > 0 && v.lastIndexOf('\n', pos - 1) !== -1) : v.indexOf('\n', pos) !== -1) {
      return false;
    }
    // the caret's visual line in a wrapped text, measured on a copy of the textarea
    const m = ui.mirror;
    const cs = window.getComputedStyle(t);
    ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing', 'wordSpacing', 'lineHeight', 'textTransform', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'].forEach(function (p) {
      m.style[p] = cs[p];
    });
    m.style.width = t.clientWidth + 'px';
    m.textContent = '';
    const first = el('span', null, '\u200b');
    const caret = el('span', null, '\u200b');
    const last = el('span', null, '\u200b');
    m.appendChild(first);
    m.appendChild(document.createTextNode(v.slice(0, pos)));
    m.appendChild(caret);
    m.appendChild(document.createTextNode(v.slice(pos)));
    m.appendChild(last);
    const top = caret.offsetTop;
    const result = up ? top <= first.offsetTop : top >= last.offsetTop;
    m.textContent = '';
    return result;
  }

  function inert(on) {
    ui.log.inert = on;
    ui.foot.inert = on;
  }

  function toggleHelp() {
    if ((ui.help && !ui.help.hidden) || (ui.codes && !ui.codes.hidden)) {
      closeDialogs(true);
      return;
    }
    if (!ui.help) {
      buildHelp();
    }
    hideMenu();
    ui.help.hidden = false;
    inert(true);
    ui.help.focus();
  }

  function closeDialogs(focusBack) {
    if (!ui.help) {
      return;
    }
    const was = !ui.help.hidden || (ui.codes && !ui.codes.hidden);
    ui.help.hidden = true;
    if (ui.codes) {
      ui.codes.hidden = true;
    }
    inert(false);
    if (focusBack && was && ui.helpButton) {
      ui.helpButton.focus();
    }
  }

  function showCodes() {
    if (!ui.codes) {
      buildCodes();
    }
    ui.help.hidden = true;
    ui.codes.hidden = false;
    ui.filter.focus();
  }

  function backToHelp() {
    ui.codes.hidden = true;
    ui.help.hidden = false;
    if (ui.codesButton) {
      ui.codesButton.focus();
    }
    else {
      ui.help.focus();
    }
  }

  function dialog(id, title, withBack) {
    const d = el('div', 'dialog');
    d.hidden = true;
    d.tabIndex = -1;
    d.setAttribute('role', 'dialog');
    d.setAttribute('aria-labelledby', id + '-title');
    const head = el('div', 'dhead');
    if (withBack) {
      const b = button('dbtn', T.back);
      b.title = T.back;
      b.appendChild(icon(ICON.back, 18));
      b.addEventListener('click', backToHelp);
      head.appendChild(b);
    }
    const h = el('h3', 'dtitle', title);
    h.id = id + '-title';
    head.appendChild(h);
    const x = button('dbtn', T.closeDialog);
    x.title = T.closeDialog;
    x.appendChild(icon(ICON.close, 18));
    x.addEventListener('click', function () { closeDialogs(true); });
    head.appendChild(x);
    d.appendChild(head);
    return d;
  }

  function buildHelp() {
    const d = ui.help = dialog('osc-help', T.commands, false);
    d.appendChild(el('p', 'intro', T.helpIntro));
    const list = el('dl', 'cmds');
    cfg.commands.forEach(function (c) {
      const dt = el('dt');
      dt.appendChild(el('code', null, c.usage));
      list.appendChild(dt);
      const dd = el('dd');
      if (c.description) {
        dd.appendChild(el('span', null, c.description + ' '));
      }
      if (c.example) {
        const ex = button('example');
        ex.textContent = c.example;
        ex.title = T.example;
        ex.addEventListener('click', function () { useExample(c.example); });
        dd.appendChild(ex);
      }
      if (c.name === 'translate' && cfg.languages.length) {
        const b = ui.codesButton = button('link');
        b.textContent = T.codesLink;
        b.setAttribute('aria-haspopup', 'dialog');
        b.addEventListener('click', showCodes);
        dd.appendChild(b);
      }
      list.appendChild(dd);
    });
    d.appendChild(list);
    ui.body.appendChild(d);
  }

  function buildCodes() {
    const d = ui.codes = dialog('osc-codes', T.codesTitle, true);
    d.appendChild(el('p', 'intro', T.codesIntro));
    const f = ui.filter = el('input', 'search');
    f.type = 'search';
    f.placeholder = T.codesFind;
    f.setAttribute('aria-label', T.codesFind);
    f.setAttribute('autocomplete', 'off');
    f.setAttribute('aria-controls', 'osc-codes-table');
    d.appendChild(f);
    const wrap = el('div', 'codes');
    const table = el('table');
    table.id = 'osc-codes-table';
    const thead = el('thead');
    const hr = el('tr');
    [T.code, T.language, T.native].forEach(function (label) {
      const th = el('th', null, label);
      th.scope = 'col';
      hr.appendChild(th);
    });
    thead.appendChild(hr);
    table.appendChild(thead);
    const tbody = el('tbody');
    const rows = [];
    cfg.languages.forEach(function (l) {
      const tr = el('tr');
      const th = el('th');
      th.scope = 'row';
      th.appendChild(el('code', null, l[0]));
      tr.appendChild(th);
      tr.appendChild(el('td', null, l[1]));
      const td = el('td', null, l[2]);
      td.setAttribute('dir', 'auto');
      tr.appendChild(td);
      tbody.appendChild(tr);
      rows.push([tr, (l[0] + ' ' + l[1] + ' ' + l[2]).toLowerCase()]);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    d.appendChild(wrap);
    const none = el('p', 'none', T.codesNone);
    none.hidden = true;
    d.appendChild(none);
    f.addEventListener('input', function () {
      const q = f.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach(function (r) {
        const hit = q === '' || r[1].indexOf(q) !== -1;
        r[0].hidden = !hit;
        if (hit) {
          shown++;
        }
      });
      none.hidden = shown > 0;
    });
    ui.body.appendChild(d);
  }

  function useExample(text) {
    closeDialogs(false);
    if (busy) {
      notice(T.waitExample);
      caretEnd();
      return;
    }
    setValue(text);
    notice('');
    hideMenu();
    caretEnd();
  }

  function clampSize(w, h) {
    const maxW = Math.max(280, window.innerWidth - 40);
    const maxH = Math.max(320, window.innerHeight - 40);
    return [Math.round(Math.min(Math.max(w, 320), maxW)), Math.round(Math.min(Math.max(h, 400), maxH))];
  }

  function setSize(size) {
    ui.panel.style.width = size[0] + 'px';
    ui.panel.style.height = size[1] + 'px';
    placeCaptcha();
  }

  function applySize() {
    if (narrow()) {
      return;
    }
    const saved = local.get('size');
    if (Array.isArray(saved) && saved.length === 2 && isFinite(saved[0]) && isFinite(saved[1])) {
      setSize(clampSize(Number(saved[0]), Number(saved[1])));
    }
  }

  function bindGrip() {
    const grip = ui.grip;
    grip.addEventListener('pointerdown', function (e) {
      if (e.button !== 0 || narrow()) {
        return;
      }
      e.preventDefault();
      const r = ui.panel.getBoundingClientRect();
      const x = e.clientX;
      const y = e.clientY;
      let size = [r.width, r.height];
      try {
        grip.setPointerCapture(e.pointerId);
      }
      catch (err) {}
      ui.panel.classList.add('resizing');
      function move(ev) {
        size = clampSize(r.width + x - ev.clientX, r.height + y - ev.clientY);
        setSize(size);
      }
      function up() {
        grip.removeEventListener('pointermove', move);
        grip.removeEventListener('pointerup', up);
        grip.removeEventListener('pointercancel', up);
        ui.panel.classList.remove('resizing');
        local.set('size', size);
      }
      grip.addEventListener('pointermove', move);
      grip.addEventListener('pointerup', up);
      grip.addEventListener('pointercancel', up);
    });
    grip.addEventListener('keydown', function (e) {
      const step = { ArrowLeft: [20, 0], ArrowRight: [-20, 0], ArrowUp: [0, 20], ArrowDown: [0, -20] }[e.key];
      if (!step || narrow()) {
        return;
      }
      e.preventDefault();
      const r = ui.panel.getBoundingClientRect();
      const size = clampSize(r.width + step[0], r.height + step[1]);
      setSize(size);
      local.set('size', size);
    });
  }

  function viewport() {
    const vv = window.visualViewport;
    if (vv && narrow()) {
      host.style.setProperty('--osc-h', vv.height + 'px');
      host.style.setProperty('--osc-top', vv.offsetTop + 'px');
    }
    else {
      host.style.removeProperty('--osc-h');
      host.style.removeProperty('--osc-top');
    }
  }

  function onViewport() {
    if (frame) {
      return;
    }
    frame = window.requestAnimationFrame(function () {
      frame = 0;
      if (!built || ui.panel.hidden) {
        return;
      }
      applySize();
      viewport();
      placeCaptcha();
    });
  }

  function loadRecaptcha() {
    if (window.grecaptcha && typeof window.grecaptcha.render === 'function') {
      return Promise.resolve(window.grecaptcha);
    }
    if (recaptcha) {
      return recaptcha;
    }
    recaptcha = new Promise(function (resolve, reject) {
      const name = 'opensolrChatCaptchaReady';
      const s = document.createElement('script');
      const timer = setTimeout(fail, REQUEST_MS);
      function fail() {
        clearTimeout(timer);
        if (s.parentNode) {
          s.parentNode.removeChild(s);
        }
        recaptcha = null;
        window[name] = function () {};
        reject(new Error('recaptcha'));
      }
      window[name] = function () {
        clearTimeout(timer);
        if (window.grecaptcha && typeof window.grecaptcha.render === 'function') {
          resolve(window.grecaptcha);
        }
        else {
          fail();
        }
      };
      s.src = 'https://www.google.com/recaptcha/api.js?render=explicit&onload=' + name;
      s.async = true;
      s.defer = true;
      s.onerror = fail;
      document.head.appendChild(s);
    });
    return recaptcha;
  }

  function capButton(label, primary) {
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    css(b, {
      display: 'inline-block', width: 'auto', height: 'auto', 'min-width': '0', margin: '0', padding: '8px 16px',
      border: '1px solid ' + (primary ? '#c05520' : '#d9d4cc'), 'border-radius': '2px', 'box-shadow': 'none',
      background: primary ? '#c05520' : '#ffffff', color: primary ? '#ffffff' : '#1f1d1a', 'font-family': 'inherit',
      'font-size': '14px', 'font-weight': '600', 'line-height': '1.2', 'letter-spacing': 'normal', 'text-transform': 'none', cursor: 'pointer'
    });
    return b;
  }

  function capText(size, color, weight) {
    const d = document.createElement('div');
    css(d, { display: 'block', margin: '0', padding: '0', 'max-width': '340px', 'font-family': 'inherit', 'font-size': size, 'font-weight': weight, 'line-height': '1.5', color: color, 'text-align': 'center', 'letter-spacing': 'normal', 'text-transform': 'none' });
    return d;
  }

  function buildCaptcha() {
    const c = { visible: false, answer: null, widget: null };
    const box = c.box = document.createElement('div');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-labelledby', 'opensolr-chat-captcha-title');
    box.tabIndex = -1;
    css(box, {
      position: 'fixed', 'z-index': String(Z + 1), display: 'none', 'flex-direction': 'column', 'align-items': 'center',
      'justify-content': 'center', gap: '14px', margin: '0', padding: '24px 16px', 'box-sizing': 'border-box', overflow: 'auto',
      border: '1px solid #d9d4cc', 'border-radius': '2px', 'box-shadow': 'none', background: '#ffffff', color: '#1f1d1a',
      'font-family': 'inherit', 'font-size': '15px', 'font-weight': '400', 'line-height': '1.5', 'text-align': 'center', outline: 'none'
    });
    const title = capText('17px', '#1f1d1a', '600');
    title.id = 'opensolr-chat-captcha-title';
    title.textContent = T.capTitle;
    c.intro = capText('15px', '#4a4540', '400');
    c.slot = document.createElement('div');
    css(c.slot, { display: 'flex', 'justify-content': 'center', 'min-height': '78px', margin: '0', padding: '0' });
    c.msg = capText('14px', '#b42318', '400');
    c.msg.setAttribute('role', 'alert');
    const row = document.createElement('div');
    css(row, { display: 'flex', 'flex-wrap': 'wrap', 'justify-content': 'center', gap: '8px', margin: '0', padding: '0' });
    c.retry = capButton(T.capRetry, true);
    css(c.retry, { display: 'none' });
    c.retry.addEventListener('click', renderCaptcha);
    const cancel = capButton(T.capCancel, false);
    cancel.addEventListener('click', cancelCaptcha);
    row.appendChild(c.retry);
    row.appendChild(cancel);
    box.appendChild(title);
    box.appendChild(c.intro);
    box.appendChild(c.slot);
    box.appendChild(c.msg);
    box.appendChild(row);
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        cancelCaptcha();
      }
    });
    document.body.appendChild(box);
    return c;
  }

  function capStatus(text, retry) {
    cap.msg.textContent = text;
    css(cap.retry, { display: retry ? 'inline-block' : 'none' });
  }

  function placeCaptcha() {
    if (!cap || !cap.visible || !built) {
      return;
    }
    const r = ui.panel.getBoundingClientRect();
    css(cap.box, { left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' });
  }

  function showCaptcha(a) {
    reveal();
    if (!cap) {
      cap = buildCaptcha();
    }
    cap.answer = a;
    cap.visible = true;
    cap.intro.textContent = a ? T.capQuestion : T.capStart;
    css(cap.box, { display: 'flex' });
    placeCaptcha();
    try {
      cap.box.focus({ preventScroll: true });
    }
    catch (e) {
      cap.box.focus();
    }
    renderCaptcha();
  }

  function renderCaptcha() {
    capStatus('', false);
    loadRecaptcha().then(function (g) {
      if (!cap.visible) {
        return;
      }
      if (cap.widget === null) {
        cap.widget = g.render(cap.slot, {
          sitekey: cfg.siteKey,
          size: ui.panel.getBoundingClientRect().width < 340 ? 'compact' : 'normal',
          callback: onToken,
          'expired-callback': function () { capStatus(T.capExpired, false); },
          'error-callback': function () { capStatus(T.capLoad, true); }
        });
      }
      else {
        g.reset(cap.widget);
      }
    }).catch(function () {
      if (cap.visible) {
        capStatus(T.capLoad, true);
      }
    });
  }

  function resetCaptcha() {
    try {
      if (cap.widget !== null) {
        window.grecaptcha.reset(cap.widget);
      }
    }
    catch (e) {
      capStatus(T.capLoad, true);
    }
  }

  function onToken(token) {
    if (!cap.visible) {
      return;
    }
    capStatus(T.capChecking, false);
    requestJSON('/captcha', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ token: String(token || '') })
    }).then(function (r) {
      if (!cap.visible) {
        return;
      }
      if (r.data && r.data.ok === true) {
        passed = true;
        local.set('pass', Date.now());
        const a = cap.answer;
        hideCaptcha();
        if (a) {
          a.checked = true;
          setProgress(a, '');
          ask(a);
        }
        else {
          caretEnd();
        }
        return;
      }
      const said = clip(r.data && r.data.error);
      capStatus(/\s/.test(said) ? said : T.capFailed, false);
      resetCaptcha();
    }).catch(function () {
      if (cap.visible) {
        capStatus(T.capUnsent, false);
        resetCaptcha();
      }
    });
  }

  function hideCaptcha() {
    cap.visible = false;
    cap.answer = null;
    css(cap.box, { display: 'none' });
  }

  function cancelCaptcha() {
    const a = cap.answer;
    hideCaptcha();
    if (a) {
      settle(a, { type: 'error', message: T.notChecked });
    }
    else {
      declined = true;
      session.set('declined', true);
    }
    caretEnd();
  }

  function markdown(src) {
    const frag = document.createDocumentFragment();
    blocks(String(src).replace(/\r\n?/g, '\n').split('\n'), frag, 0);
    return frag;
  }

  function leading(s) {
    let n = 0;
    for (let i = 0; i < s.length; i++) {
      const c = s.charAt(i);
      if (c === ' ') {
        n++;
      }
      else if (c === '\t') {
        n += 4;
      }
      else {
        break;
      }
    }
    return n;
  }

  function dedent(s, cols) {
    let i = 0;
    let n = 0;
    while (i < s.length && n < cols) {
      const c = s.charAt(i);
      if (c === ' ') {
        n++;
      }
      else if (c === '\t') {
        n += 4;
      }
      else {
        break;
      }
      i++;
    }
    return s.slice(i);
  }

  function tableAt(lines, i) {
    return i + 1 < lines.length && lines[i].indexOf('|') !== -1 && lines[i + 1].indexOf('|') !== -1 && lines[i + 1].indexOf('-') !== -1 && SEP.test(lines[i + 1].trim());
  }

  function startsBlock(lines, i) {
    const l = lines[i];
    return FENCE.test(l) || HEADING.test(l) || HR.test(l) || QUOTE.test(l) || LIST.test(l) || tableAt(lines, i);
  }

  function blocks(lines, parent, depth) {
    let i = 0;
    while (i < lines.length) {
      const line = lines[i];
      if (line.trim() === '') {
        i++;
        continue;
      }
      if (depth > 12) {
        const p = el('p', null, lines.slice(i).join('\n').trim());
        p.style.whiteSpace = 'pre-wrap';
        parent.appendChild(p);
        return;
      }
      let m = FENCE.exec(line);
      if (m) {
        const close = new RegExp('^ {0,3}' + (m[1].charAt(0) === '`' ? '`' : '~') + '{' + m[1].length + ',}[ \\t]*$');
        const body = [];
        i++;
        while (i < lines.length && !close.test(lines[i])) {
          body.push(lines[i]);
          i++;
        }
        i++;
        const pre = el('pre');
        pre.appendChild(el('code', null, body.join('\n')));
        parent.appendChild(pre);
        continue;
      }
      m = HEADING.exec(line);
      if (m) {
        if (m[2]) {
          const p = el('p', 'h');
          const s = el('strong');
          inline(m[2], s, 0, false);
          p.appendChild(s);
          parent.appendChild(p);
        }
        i++;
        continue;
      }
      if (HR.test(line)) {
        parent.appendChild(el('hr'));
        i++;
        continue;
      }
      if (QUOTE.test(line)) {
        const inner = [];
        while (i < lines.length && QUOTE.test(lines[i])) {
          inner.push(lines[i].replace(/^ {0,3}> ?/, ''));
          i++;
        }
        const q = el('blockquote');
        blocks(inner, q, depth + 1);
        parent.appendChild(q);
        continue;
      }
      if (tableAt(lines, i)) {
        i = table(lines, i, parent);
        continue;
      }
      if (LIST.test(line)) {
        i = list(lines, i, parent, depth);
        continue;
      }
      const p = el('p');
      let first = true;
      while (i < lines.length && lines[i].trim() !== '' && (first || !startsBlock(lines, i))) {
        if (!first) {
          p.appendChild(el('br'));
        }
        inline(lines[i].trim(), p, 0, false);
        first = false;
        i++;
      }
      parent.appendChild(p);
    }
  }

  function list(lines, i, parent, depth) {
    const head = LIST.exec(lines[i]);
    const indent = leading(head[1]);
    const ordered = /\d/.test(head[2]);
    const node = el(ordered ? 'ol' : 'ul');
    if (ordered) {
      const n = parseInt(head[2], 10);
      if (n !== 1) {
        node.start = n;
      }
    }
    while (i < lines.length) {
      const m = LIST.exec(lines[i]);
      if (!m || leading(m[1]) > indent + 1 || /\d/.test(m[2]) !== ordered) {
        break;
      }
      const offset = leading(m[1]) + m[2].length + 1;
      const body = [m[3]];
      i++;
      while (i < lines.length) {
        const l = lines[i];
        if (l.trim() === '') {
          let j = i + 1;
          while (j < lines.length && lines[j].trim() === '') {
            j++;
          }
          if (j < lines.length && leading(lines[j]) > indent + 1) {
            for (; i < j; i++) {
              body.push('');
            }
            continue;
          }
          break;
        }
        const lead = leading(l);
        if (lead > indent + 1) {
          body.push(dedent(l, Math.min(lead, offset)));
          i++;
          continue;
        }
        if (startsBlock(lines, i)) {
          break;
        }
        body.push(l.trim());
        i++;
      }
      const li = el('li');
      blocks(body, li, depth + 1);
      node.appendChild(li);
      if (i < lines.length && lines[i].trim() === '') {
        let j = i;
        while (j < lines.length && lines[j].trim() === '') {
          j++;
        }
        const n = j < lines.length ? LIST.exec(lines[j]) : null;
        if (n && leading(n[1]) <= indent + 1 && /\d/.test(n[2]) === ordered) {
          i = j;
        }
        else {
          break;
        }
      }
    }
    parent.appendChild(node);
    return i;
  }

  function cells(line) {
    let s = line.trim();
    if (s.charAt(0) === '|') {
      s = s.slice(1);
    }
    if (s.charAt(s.length - 1) === '|' && s.charAt(s.length - 2) !== '\\') {
      s = s.slice(0, -1);
    }
    const out = [];
    let cur = '';
    for (let i = 0; i < s.length; i++) {
      const c = s.charAt(i);
      if (c === '\\' && s.charAt(i + 1) === '|') {
        cur += '|';
        i++;
      }
      else if (c === '|') {
        out.push(cur.trim());
        cur = '';
      }
      else {
        cur += c;
      }
    }
    out.push(cur.trim());
    return out;
  }

  function table(lines, i, parent) {
    const headers = cells(lines[i]);
    const aligns = cells(lines[i + 1]).map(function (c) {
      return /^:-+:$/.test(c) ? 'center' : (/-:$/.test(c) ? 'right' : '');
    });
    const wrap = el('div', 'tbl');
    const t = el('table');
    const thead = el('thead');
    const hr = el('tr');
    headers.forEach(function (h, k) {
      const th = el('th');
      th.scope = 'col';
      if (aligns[k]) {
        th.style.textAlign = aligns[k];
      }
      inline(h, th, 0, false);
      hr.appendChild(th);
    });
    thead.appendChild(hr);
    t.appendChild(thead);
    const tbody = el('tbody');
    i += 2;
    while (i < lines.length && lines[i].trim() !== '' && lines[i].indexOf('|') !== -1) {
      const row = cells(lines[i]);
      const tr = el('tr');
      for (let k = 0; k < headers.length; k++) {
        const td = el('td');
        if (aligns[k]) {
          td.style.textAlign = aligns[k];
        }
        inline(row[k] || '', td, 0, false);
        tr.appendChild(td);
      }
      tbody.appendChild(tr);
      i++;
    }
    t.appendChild(tbody);
    wrap.appendChild(t);
    parent.appendChild(wrap);
    return i;
  }

  function safeUrl(raw) {
    const s = String(raw || '').trim();
    if (!/^https?:\/\//i.test(s)) {
      return null;
    }
    try {
      const u = new URL(s);
      return u.protocol === 'http:' || u.protocol === 'https:' ? u.href : null;
    }
    catch (e) {
      return null;
    }
  }

  function anchor(url) {
    const a = el('a');
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    return a;
  }

  function closer(text, from, ch, len) {
    const end = Math.min(text.length, from + 3000);
    let j = from;
    while (j < end) {
      const c = text.charAt(j);
      if (c === '\\') {
        j += 2;
        continue;
      }
      if (c === '`') {
        let r = 1;
        while (text.charAt(j + r) === '`') {
          r++;
        }
        const k = text.indexOf(text.slice(j, j + r), j + r);
        j = k === -1 ? j + r : k + r;
        continue;
      }
      if (c === ch) {
        let r = 1;
        while (text.charAt(j + r) === ch) {
          r++;
        }
        const fits = len === 2 ? r >= 2 : (r === 1 || r === 3);
        if (fits && j > from && !/\s/.test(text.charAt(j - 1)) && (ch !== '_' || !/[A-Za-z0-9]/.test(text.charAt(j + r)))) {
          return j + r - len;
        }
        j += r;
        continue;
      }
      j++;
    }
    return -1;
  }

  function parseLink(text, s) {
    const limit = Math.min(text.length, s + 1000);
    let depth = 0;
    let j = s;
    for (; j < limit; j++) {
      const c = text.charAt(j);
      if (c === '\\') {
        j++;
        continue;
      }
      if (c === '[') {
        depth++;
      }
      else if (c === ']') {
        depth--;
        if (depth === 0) {
          break;
        }
      }
    }
    if (j >= limit || text.charAt(j + 1) !== '(') {
      return null;
    }
    const label = text.slice(s + 1, j);
    let k = j + 2;
    while (text.charAt(k) === ' ') {
      k++;
    }
    let url = '';
    if (text.charAt(k) === '<') {
      const g = text.indexOf('>', k);
      if (g === -1) {
        return null;
      }
      url = text.slice(k + 1, g);
      k = g + 1;
    }
    else {
      const from = k;
      const stop = Math.min(text.length, k + 2048);
      let par = 0;
      for (; k < stop; k++) {
        const c = text.charAt(k);
        if (c === ' ' || c === '\t') {
          break;
        }
        if (c === '(') {
          par++;
        }
        else if (c === ')') {
          if (par === 0) {
            break;
          }
          par--;
        }
      }
      url = text.slice(from, k);
    }
    while (text.charAt(k) === ' ') {
      k++;
    }
    const q = text.charAt(k);
    if (q === '"' || q === '\'') {
      const g = text.indexOf(q, k + 1);
      if (g === -1) {
        return null;
      }
      k = g + 1;
      while (text.charAt(k) === ' ') {
        k++;
      }
    }
    if (text.charAt(k) !== ')') {
      return null;
    }
    return { label: label, url: url, end: k + 1 };
  }

  function inline(text, parent, depth, noLink) {
    let buf = '';
    let i = 0;
    const n = text.length;
    function flush() {
      if (buf) {
        parent.appendChild(document.createTextNode(buf));
        buf = '';
      }
    }
    while (i < n) {
      const c = text.charAt(i);
      if (c === '\\' && i + 1 < n && PUNCT.test(text.charAt(i + 1))) {
        buf += text.charAt(i + 1);
        i += 2;
        continue;
      }
      if (c === '`') {
        let r = 1;
        while (text.charAt(i + r) === '`') {
          r++;
        }
        const k = text.indexOf(text.slice(i, i + r), i + r);
        if (k !== -1) {
          flush();
          let body = text.slice(i + r, k);
          if (body.length > 2 && body.charAt(0) === ' ' && body.charAt(body.length - 1) === ' ' && body.trim() !== '') {
            body = body.slice(1, -1);
          }
          parent.appendChild(el('code', null, body));
          i = k + r;
          continue;
        }
        buf += text.slice(i, i + r);
        i += r;
        continue;
      }
      if ((c === '*' || c === '_' || c === '~') && depth < 8) {
        let r = 1;
        while (text.charAt(i + r) === c) {
          r++;
        }
        const len = r >= 2 ? 2 : 1;
        if (c !== '~' || len === 2) {
          const next = text.charAt(i + len);
          const opens = next !== '' && !/\s/.test(next) && (c !== '_' || i === 0 || !/[A-Za-z0-9]/.test(text.charAt(i - 1)));
          const k = opens ? closer(text, i + len, c, len) : -1;
          if (k !== -1) {
            flush();
            const node = el(c === '~' ? 'del' : (len === 2 ? 'strong' : 'em'));
            inline(text.slice(i + len, k), node, depth + 1, noLink);
            parent.appendChild(node);
            i = k + len;
            continue;
          }
        }
        buf += text.slice(i, i + r);
        i += r;
        continue;
      }
      if (!noLink && (c === '[' || (c === '!' && text.charAt(i + 1) === '['))) {
        const link = parseLink(text, c === '!' ? i + 1 : i);
        if (link) {
          flush();
          const url = safeUrl(link.url);
          const label = link.label.trim();
          if (url) {
            const a = anchor(url);
            if (label) {
              inline(label, a, depth + 1, true);
            }
            else {
              a.textContent = link.url;
            }
            parent.appendChild(a);
          }
          else if (label) {
            inline(label, parent, depth + 1, true);
          }
          i = link.end;
          continue;
        }
      }
      if (c === '<') {
        const br = /^<br\s*\/?>/i.exec(text.slice(i, i + 8));
        if (br) {
          flush();
          parent.appendChild(el('br'));
          i += br[0].length;
          continue;
        }
        if (!noLink) {
          const m = /^<(https?:\/\/[^\s<>]+)>/i.exec(text.slice(i, i + 2100));
          const url = m ? safeUrl(m[1]) : null;
          if (url) {
            flush();
            const a = anchor(url);
            a.textContent = m[1];
            parent.appendChild(a);
            i += m[0].length;
            continue;
          }
        }
      }
      if (!noLink && (c === 'h' || c === 'H') && (i === 0 || /[^A-Za-z0-9]/.test(text.charAt(i - 1)))) {
        const start = text.slice(i, i + 8).toLowerCase();
        if (start.indexOf('http://') === 0 || start === 'https://') {
          const m = /^https?:\/\/[^\s<>"'`]+/i.exec(text.slice(i, i + 2100));
          if (m) {
            let raw = m[0].replace(/[.,;:!?*_~]+$/, '');
            while (raw.charAt(raw.length - 1) === ')' && raw.split(')').length > raw.split('(').length) {
              raw = raw.slice(0, -1);
            }
            const url = raw.length > 10 ? safeUrl(raw) : null;
            if (url) {
              flush();
              const a = anchor(url);
              a.textContent = raw;
              parent.appendChild(a);
              i += raw.length;
              continue;
            }
          }
        }
      }
      buf += c;
      i++;
    }
    flush();
  }
})();
