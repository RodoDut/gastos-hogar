/*
    This script connects to a Chrome DevTools Protocol WebSocket and navigates to a specified URL.
*/
const WebSocket = require('ws');

const tabTargetId = '26234';
const url = 'http://localhost:8080';

const ws = new WebSocket('ws://localhost:9222/devtools/browser');
let tabSessionId = null;
let pageSessionId = null;
let msgId = 1;

function send(method, params = {}, sessionId = null) {
  const payload = { id: msgId++, method, params };
  if (sessionId) payload.sessionId = sessionId;
  ws.send(JSON.stringify(payload));
}

const timeout = setTimeout(() => { console.log('TIMEOUT'); process.exit(1); }, 8000);

ws.on('open', () => {
  send('Target.attachToTarget', { targetId: tabTargetId, flatten: true });
});

ws.on('message', (data) => {
  const msg = JSON.parse(data.toString());
  console.log('MENSAJE:', data.toString());

  // Respuesta al attach del tab
  if (msg.id === 1 && msg.result?.sessionId) {
    tabSessionId = msg.result.sessionId;
    send('Target.setAutoAttach',
      { autoAttach: true, waitForDebuggerOnStart: false, flatten: true },
      tabSessionId);
  }

  // Evento: se adjuntó automáticamente al target hijo (el page real)
  if (msg.method === 'Target.attachedToTarget' && msg.params.targetInfo.type === 'page') {
    pageSessionId = msg.params.sessionId;
    console.log('Adjuntado al page real, sessionId:', pageSessionId);
    send('Page.navigate', { url }, pageSessionId);
  }

  if (msg.method === 'Page.frameNavigated' && msg.sessionId === pageSessionId) {
    clearTimeout(timeout);
    console.log('NAVEGACIÓN CONFIRMADA');
    ws.close();
  }
});

ws.on('error', (err) => console.error('ERROR:', err));
ws.on('close', (code) => console.log('CLOSED:', code));