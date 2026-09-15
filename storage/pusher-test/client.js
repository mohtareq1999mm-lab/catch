const Pusher = require('pusher-js');
const fs = require('fs');

const args = process.argv.slice(2);
const opts = {};
for (let i=0;i<args.length;i+=2) opts[args[i].replace(/^--/,'')] = args[i+1];

const userId = opts.userId;
const token = opts.token;
const key = opts.key;
const cluster = opts.cluster || 'eu';
const logPath = opts.log || 'events.json';
const operationId = opts.operationId || null;

console.log(`[client] userId=${userId} key=${key ? key.substring(0,2)+'***' : 'null'} cluster=${cluster} log=${logPath}`);

const pusher = new Pusher(key, {
  cluster,
  forceTLS: true,
  wsHost: undefined,
  authEndpoint: 'http://127.0.0.1:8001/api/v1/broadcasting/auth',
  auth: {
    headers: {
      'Authorization': token.startsWith('Bearer ') ? token : `Bearer ${token}`,
      'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'application/json',
    }
  },
  enabledTransports: ['ws','wss'],
  disableStats: true,
});

let events = [];
let diagnostics = {
  pusher_connected: false,
  connection_error: null,
  channel: `private-users.${userId}`,
  subscription_succeeded: false,
  subscription_error: null,
  authorization: null,
  events_received: 0,
  last_event: null,
  operation_id: operationId,
};

pusher.connection.bind('connecting', ()=> console.log('[pusher] connecting'));
pusher.connection.bind('connected', ()=> { console.log('[pusher] connected'); diagnostics.pusher_connected = true; dump(); });
pusher.connection.bind('disconnected', ()=> console.log('[pusher] disconnected'));
pusher.connection.bind('failed', ()=> console.log('[pusher] failed'));
pusher.connection.bind('error', (err)=> { console.log('[pusher] error', err); diagnostics.connection_error = String(err.error||err.message||err); dump(); });

const channel = pusher.subscribe(`private-users.${userId}`);

channel.bind('pusher:subscription_succeeded', ()=> {
  console.log('[channel] subscription_succeeded');
  diagnostics.subscription_succeeded = true;
  diagnostics.authorization = 'SUCCESS';
  dump();
});

channel.bind('pusher:subscription_error', (err)=>{
  console.log('[channel] subscription_error', err);
  diagnostics.subscription_error = String(err);
  diagnostics.authorization = 'FAILED';
  dump();
});

// Bind all lifecycle events with leading dot (broadcastAs)
const ALL_EVENTS = [
  'product.import.queued','product.import.progress','product.import.completed','product.import.failed','product.import.cancelling','product.import.cancelled',
  'category.import.queued','category.import.progress','category.import.completed','category.import.failed','category.import.cancelling','category.import.cancelled',
  'brand.import.queued','brand.import.progress','brand.import.completed','brand.import.failed','brand.import.cancelling','brand.import.cancelled',
  'product.export.queued','product.export.progress','product.export.completed','product.export.failed',
  'category.export.queued','category.export.progress','category.export.completed','category.export.failed',
  'brand.export.queued','brand.export.progress','brand.export.completed','brand.export.failed',
];

ALL_EVENTS.forEach(ev=>{
  channel.bind(ev, (data)=>{
    console.log(`[event] ${ev}`, JSON.stringify(data).substring(0,200));
    events.push({event: ev, data, timestamp: new Date().toISOString()});
    diagnostics.events_received = events.length;
    diagnostics.last_event = ev;
    dump();
  });
});

// Also bind global to catch any missed naming
channel.bind_global((ev, data)=>{
  if (!ALL_EVENTS.includes(ev) && ev.startsWith('product.') || ev.startsWith('category.') || ev.startsWith('brand.')) {
    console.log(`[event global] ${ev}`);
  }
});

function dump(){
  const out = { diagnostics, events };
  try { fs.writeFileSync(logPath, JSON.stringify(out, null, 2)); } catch(e){}
}

dump();

// Keep alive, dump every 2s
setInterval(dump, 2000);

// Graceful handling
process.on('SIGTERM', ()=>{ dump(); process.exit(0); });
process.on('SIGINT', ()=>{ dump(); process.exit(0); });

// Exit after 120s if not manually stopped
setTimeout(()=>{ console.log('[client] timeout 120s, exiting'); dump(); pusher.disconnect(); process.exit(0); }, 120000);

console.log('[client] waiting for events...');
