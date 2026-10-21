/** Real same-host PHP/Node HTTP fixture. No deployment or external source access. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtemp, writeFile, readFile, readdir, rm } from 'node:fs/promises';
import { once } from 'node:events';
import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { verifyAdmissionProtocol } from './verify_admission_protocol.mjs';
const core = await verifyAdmissionProtocol();
const { AdmissionCoordinator } = await import(path.join(core, 'coordinator.mjs'));
const { memoryFixtureJournal } = await import(path.join(core, 'journal.mjs'));
const { startControlServer } = await import(path.join(core, 'gateway.mjs'));
const entry = fileURLToPath(new URL('./admission_http_entry.php', import.meta.url));
const waitUntil = async (check, timeout = 4000) => { const until = Date.now() + timeout; while (!await check()) { if (Date.now() >= until) throw new Error('FIXTURE_WAIT_EXPIRED'); await new Promise(r => setTimeout(r, 10)); } };
async function availablePort() { const s = net.createServer(); s.listen(0, '127.0.0.1'); await once(s, 'listening'); const port = s.address().port; await new Promise(r => s.close(r)); return port; }
async function fixture({ enabled = true, delay = null, revision = 'a'.repeat(40), childWaitMs = 1500, controlTimeoutMs = delay ? 100 : 1000, delayMs = 350, fault = null } = {}) {
  const dir = await mkdtemp('/tmp/y1-admission-http-');
  const id = (name, generation) => ({ id: name, bootId: `${name}-boot-fixture-0001`, revision: 'a'.repeat(40), routerHash: 'b'.repeat(64), generation, requiresSessionClose: true, allowedChildren: [] });
  const old = { ...id('y1-old', 'legacy'), allowedChildren: ['other-old'] }, green = id('y1-new', 'identity'), other = id('other-old', 'legacy'), roster = [old, green, other];
  const journal = memoryFixtureJournal(), coordinator = new AdmissionCoordinator({ roster, routers: [{ id: 'fixture-edge', bootId: 'fixture-edge-boot', routerHash: 'c'.repeat(64) }], identityInstanceId: green.id, journal, capabilityKey: randomBytes(32) });
  for (const member of roster) await coordinator.register(member);
  const key = randomBytes(32), keys = new Map([[old.id, key], [green.id, randomBytes(32)], [other.id, randomBytes(32)]]);
  let continueCalls = 0; const originalContinue = coordinator.continueOperation.bind(coordinator);
  coordinator.continueOperation = (...args) => { ++continueCalls; return originalContinue(...args); };
  const control = await startControlServer({ coordinator, operatorKey: randomBytes(32), participantKeys: keys });
  const controlCalls = new Map();
  let proxy, controlUrl = control.url;
  if (delay || fault) {
    proxy = http.createServer((req, res) => {
      controlCalls.set(req.url.slice(1), (controlCalls.get(req.url.slice(1)) ?? 0) + 1);
      if (fault?.operation === req.url.slice(1) && fault.mode === 'before-empty') { req.resume(); res.writeHead(200, { 'content-type': 'application/json' }); res.end('{}'); return; }
      const outgoing = http.request(new URL(req.url, control.url), { method: req.method, headers: req.headers }, incoming => {
        const chunks = []; incoming.on('data', c => chunks.push(c)); incoming.on('end', () => {
          const send = () => {
            if (res.destroyed) return;
            let status = incoming.statusCode, bytes = Buffer.concat(chunks);
            if (fault?.operation === req.url.slice(1)) {
              if (fault.mode === 'empty') { status = 200; bytes = Buffer.from('{}'); }
              if (fault.mode === '500') { status = 500; bytes = Buffer.from('{"code":"ROOT_ADMISSION_CLOSED"}'); }
              if (fault.mode === 'journal') { status = 409; bytes = Buffer.from('{"code":"JOURNAL_RESULT_UNKNOWN_SEALED"}'); }
              if (fault.mode === 'wrong-scope') { const body = JSON.parse(bytes); body.rootOperationId = '22222222-2222-4222-8222-222222222222'; bytes = Buffer.from(JSON.stringify(body)); }
              if (fault.mode === 'wrong-target') { const body = JSON.parse(bytes), pieces = body.capability.split('.'), metadata = JSON.parse(Buffer.from(pieces[0], 'base64url')); metadata.instanceId = 'wrong-target'; body.capability = Buffer.from(JSON.stringify(metadata)).toString('base64url') + '.' + pieces[1]; bytes = Buffer.from(JSON.stringify(body)); }
            }
            res.writeHead(status, { 'content-type': 'application/json' }); res.end(bytes);
          };
          if (req.url === `/${delay}`) setTimeout(send, delayMs); else send();
        });
      }); outgoing.on('error', () => res.destroy()); req.pipe(outgoing);
    }); proxy.listen(0, '127.0.0.1'); await once(proxy, 'listening'); controlUrl = `http://127.0.0.1:${proxy.address().port}/`;
  }
  await writeFile(path.join(dir, 'GIT_COMMIT'), revision + '\n', { mode: 0o444 });
  await writeFile(path.join(dir, 'control-key'), key, { mode: 0o600 });
  const db = 'y1_admission_fixture_' + process.pid + '_' + randomBytes(5).toString('hex');
  const env = { ...process.env, Y1_ADMISSION_FIXTURE_DIR: dir, Y1_ADMISSION_FIXTURE_DB: db,
    Y1_ADMISSION_FIXTURE_MYSQL: 'codex-y1-namespace-mysql-20261013', Y1_ADMISSION_FIXTURE_REDIS: 'codex-y1-namespace-redis-20261013',
    Y1_ADMISSION_ENABLED: enabled ? 'true' : 'false', Y1_ADMISSION_CONTROL_URL: enabled ? controlUrl : 'http://127.0.0.1:1/',
    Y1_ADMISSION_KEY_FILE: enabled ? path.join(dir, 'control-key') : '/missing-default-off', Y1_ADMISSION_INSTANCE_ID: old.id, Y1_ADMISSION_BOOT_ID: old.bootId,
    Y1_ADMISSION_ALLOWED_CHILDREN: JSON.stringify(old.allowedChildren), Y1_ADMISSION_ROUTER_SHA256: old.routerHash, Y1_ADMISSION_GENERATION: old.generation, Y1_ADMISSION_REQUIRES_SESSION_CLOSE: 'true', Y1_ADMISSION_TIMEOUT_MS: String(controlTimeoutMs), Y1_ADMISSION_CHILD_WAIT_MS: String(childWaitMs) };
  const php = arg => { const result = spawnSync('php', [entry, arg], { env, encoding: 'utf8' }); assert.equal(result.status, 0, `Fixture PHP ${arg} failed: ${result.stderr.slice(0, 1200)}`); return result.stdout; };
  try { php('--setup'); } catch (error) {
    proxy?.closeAllConnections(); if (proxy) await new Promise(r => proxy.close(r)); await control.close(); await rm(dir, { recursive: true, force: true }); throw error;
  }
  const port = await availablePort(), child = spawn('php', ['-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'output_buffering=0', '-S', `127.0.0.1:${port}`, entry], { env, stdio: ['ignore', 'ignore', 'pipe'] });
  let stderr = ''; child.stderr.on('data', c => { stderr += c; });
  await waitUntil(() => stderr.includes('Development Server'));
  const events = async () => { try { return (await readFile(path.join(dir, 'events'), 'utf8')).trim().split('\n'); } catch { return []; } };
  const report = () => JSON.parse(php('--report'));
  const original = journal.append.bind(journal);
  journal.append = async record => {
    if (record.event === 'operation_end' && record.instanceId === old.id) {
      const seen = await events();
      const required = ['handler-start', 'sql-commit', 'handler-finally', 'AfterRequest', 'AfterEmit', 'ApplicationShutdown', 'settled'];
      const positions = required.map(name => seen.indexOf(name));
      assert.ok(positions.every((position, i) => position >= 0 && (!i || position > positions[i - 1])), `Completion before actual runner/source cleanup: ${seen.join(',')}`);
      assert.equal(report().pending, null, 'Redis compensation must finish before completion.');
      assert.ok(report().rows.length > 0, 'MySQL commit must be visible before completion.');
    }
    return original(record);
  };
  return { old, green, other, coordinator, child, dir, events, report, continueCalls: () => continueCalls, controlCalls: operation => controlCalls.get(operation) ?? 0, url: `http://127.0.0.1:${port}/`, port,
    async request(mode, headers = {}) { return fetch(`http://127.0.0.1:${port}/${mode}`, { headers: { authorization: 'Bearer public-fixture-only', ...headers }, signal: AbortSignal.timeout(4000) }); },
    async returned() { await waitUntil(async () => (await events()).some(e => e === 'outer-return' || e === 'outer-denied')); },
    async stop() {
      if (child.exitCode === null && child.signalCode === null) { child.kill('SIGTERM'); await once(child, 'exit'); }
      proxy?.closeAllConnections(); if (proxy) await new Promise(r => proxy.close(r)); await control.close(); php('--cleanup'); await rm(dir, { recursive: true, force: true });
    } };
}

for (const mode of ['normal', 'session']) test(`real Yii3 ${mode}: SQL/Redis/session and shutdown finish before complete`, async () => {
  const f = await fixture(); try {
    const response = await f.request(mode, { 'x-identity-generation': 'forged', 'x-admission': 'forged', 'x-continuation': 'forged' });
    assert.equal(response.status, 200, await readFile(path.join(f.dir, 'errors'), 'utf8').catch(() => 'no logged error')); const body = await response.json(); assert.equal(body.publicAuthorization, 'Bearer public-fixture-only');
    await f.returned(); assert.equal(f.coordinator.status().active, 0); assert.equal(f.coordinator.status().unknown, 0); assert.equal(f.coordinator.status().instances[0].lastEnd, 1);
    assert.deepEqual(f.report().rows, [mode]); assert.equal(f.report().cleanup, '1');
    if (mode === 'session') { const session = (await readdir(f.dir)).find(name => name.startsWith('sess_')); assert.match(await readFile(path.join(f.dir, session), 'utf8'), /fixture/); }
  } finally { await f.stop(); }
});
test('default-off real runner makes no admission operation and strips private markers', async () => {
  const f = await fixture({ enabled: false }); try { const r = await f.request('normal', { 'x-identity-admission-lease': 'forged' }); assert.equal(r.status, 200); await r.text(); await f.returned(); assert.equal(f.coordinator.status().instances[0].lastBegin, 0); assert.deepEqual(f.report().rows, ['normal']); } finally { await f.stop(); }
});
test('real gateway capability enters once; replay does not mutate or poison the completed operation', async () => {
  const f = await fixture(); try {
    const lease = await f.coordinator.beginRoot(f.old); await f.coordinator.markForwarded(lease.capability);
    const r = await f.request('normal', { 'x-identity-admission-lease': lease.capability }); assert.equal(r.status, 200); await r.text(); await f.returned();
    assert.equal(f.coordinator.status().instances[0].lastBegin, 1);
    const replay = await f.request('normal', { 'x-identity-admission-lease': lease.capability }); assert.equal(replay.status, 503); await replay.text();
    assert.deepEqual(f.report().rows, ['normal']); assert.equal(f.coordinator.status().unknown, 0); assert.equal(f.coordinator.status().instances[0].lost, false);
  } finally { await f.stop(); }
});
for (const variant of ['empty', 'forged', 'duplicate', 'duplicate-raw', 'underscore-alias', 'other-instance']) test(`real HTTP ${variant} capability fails before side effects`, async () => {
  const f = await fixture(); try {
    let capability = 'fixture.' + 'a'.repeat(43);
    if (variant === 'empty') capability = '';
    if (variant === 'other-instance') capability = (await f.coordinator.beginRoot(f.other)).capability;
    if (variant === 'duplicate') capability += ', ' + capability;
    let r;
    if (variant === 'duplicate-raw') {
      r = await new Promise((resolve, reject) => {
        const req = http.request({ host: '127.0.0.1', port: f.port, path: '/normal', method: 'GET', headers: ['Host', `127.0.0.1:${f.port}`, 'X-Identity-Admission-Lease', capability, 'x-identity-admission-lease', capability] }, res => { const bytes = []; res.on('data', b => bytes.push(b)); res.on('end', () => resolve({ status: res.statusCode, text: async () => Buffer.concat(bytes).toString() })); }); req.on('error', reject); req.end();
      });
    } else r = await f.request('normal', { [variant === 'underscore-alias' ? 'X_Identity_Admission_Lease' : 'x-identity-admission-lease']: capability });
    assert.equal(r.status, 503); assert.match(await r.text(), /ADMISSION_UNAVAILABLE/);
    assert.deepEqual(f.report().rows, []); assert.equal(f.report().cleanup, null); assert.equal(f.coordinator.status().unknown, 0); assert.equal(f.coordinator.status().instances[0].lost, false);
  } finally { await f.stop(); }
});
for (const mode of ['exception', '500', 'transaction', 'event-afterrequest', 'event-afteremit', 'event-applicationshutdown', 'event-outerafter', 'emit']) test(`real runner ${mode} remains unknown`, async () => {
  const f = await fixture(); try {
    const r = await f.request(mode); await r.text(); await f.returned();
    assert.ok(f.coordinator.status().unknown >= 1); assert.equal(f.coordinator.status().instances[0].lastEnd, 0);
    assert.equal(f.report().pending, null); assert.equal(f.report().cleanup, '1');
    if (mode === 'exception' || mode === 'transaction') assert.deepEqual(f.report().rows, []);
  } finally { await f.stop(); }
});
for (const mode of ['exit', 'fatal']) test(`real handler ${mode} cannot complete in shutdown`, async () => {
  const f = await fixture(); try {
    const r = await f.request(mode); await r.text(); await waitUntil(() => f.coordinator.status().unknown > 0);
    assert.equal(f.coordinator.status().instances[0].lastEnd, 0); assert.ok(!(await f.events()).includes('outer-return')); assert.deepEqual(f.report().rows, []);
  } finally { await f.stop(); }
});
test('client TCP disconnect cannot complete even after real handler/Redis compensation returns', async () => {
  const f = await fixture(); try {
    const socket = net.connect(f.port, '127.0.0.1'); socket.on('error', () => {}); await once(socket, 'connect');
    socket.write(`GET /disconnect HTTP/1.1\r\nHost: 127.0.0.1:${f.port}\r\nConnection: close\r\n\r\n`);
    await waitUntil(async () => (await f.events()).includes('handler-start')); socket.resetAndDestroy();
    await f.returned(); assert.ok(f.coordinator.status().unknown > 0); assert.equal(f.coordinator.status().instances[0].lastEnd, 0);
    assert.deepEqual(f.report().rows, ['disconnect']); assert.equal(f.report().pending, null); assert.equal(f.report().cleanup, '1');
    assert.ok((await f.events()).includes('ApplicationShutdown'));
  } finally { await f.stop(); }
});
test('SIGKILL leaves real coordinator operation active; it never expires to zero', async () => {
  const f = await fixture(); try {
    const pending = f.request('kill').catch(() => null); await waitUntil(async () => (await f.events()).includes('handler-start'));
    f.child.kill('SIGKILL'); await once(f.child, 'exit'); await pending;
    assert.equal(f.coordinator.status().active, 1); assert.equal(f.coordinator.status().instances[0].lastEnd, 0);
    await f.coordinator.close(); const row = f.coordinator.status().instances[0]; await f.coordinator.acknowledgeClose(f.old, { epoch: f.coordinator.status().epoch, lastBegin: row.lastBegin, lastEnd: row.lastEnd });
    assert.equal(f.coordinator.status().phase, 'closing'); assert.equal(f.coordinator.status().active, 1);
    await f.coordinator.markInstanceLost(f.old); assert.ok(f.coordinator.status().unknown > 0); assert.equal(f.coordinator.status().phase, 'closing');
  } finally { await f.stop(); }
});
for (const delay of ['begin', 'complete']) test(`real RPC lost ${delay} acknowledgement seals uncertainty without retry`, async () => {
  const f = await fixture({ delay }); try {
    const r = await f.request('normal'); await r.text(); await f.returned();
    assert.ok(f.coordinator.status().unknown > 0); assert.equal(f.coordinator.status().instances[0].lost, true);
    assert.equal(f.coordinator.status().instances[0].lastBegin, 1);
    assert.deepEqual(f.report().rows, delay === 'begin' ? [] : ['normal']);
  } finally { await f.stop(); }
});
test('source revision mismatch rejects registration before source mutation', async () => {
  const f = await fixture({ revision: 'd'.repeat(40) }); try { const r = await f.request('normal'); assert.equal(r.status, 503); await r.text(); await f.returned(); assert.deepEqual(f.report().rows, []); assert.equal(f.coordinator.status().instances[0].lastBegin, 0); } finally { await f.stop(); }
});

for (const variant of ['complete', 'unknown']) test(`contract-only protected child ${variant}: parent waits for coordinator completion`, async () => {
  const f = await fixture({ childWaitMs: 1500 }); try {
    const r = await f.request(variant === 'expired' ? 'parent-expired' : 'parent'); assert.equal(r.status, 200); await r.text();
    const headers = JSON.parse(await readFile(path.join(f.dir, 'child'), 'utf8'));
    assert.equal(headers['X-Identity-Internal-Token'], 'existing-fixture-internal-auth');
    const capability = headers['X-Identity-Admission-Lease'];
    if (variant === 'complete') {
      await waitUntil(async () => (await f.events()).includes('settled'));
      assert.equal(f.coordinator.status().active, 2); assert.equal(f.coordinator.status().instances[0].lastEnd, 0);
      await f.coordinator.enterOperation(f.other, capability);
      await f.coordinator.complete(f.other, capability, { handlerFinished: true, sideEffectsFinished: true, sessionWriteClosed: true });
      await f.returned(); assert.equal(f.coordinator.status().active, 0); assert.equal(f.coordinator.status().unknown, 0);
    } else {
      if (variant === 'unknown') await f.coordinator.markOperationUnknown(capability);
      await f.returned(); assert.ok(f.coordinator.status().unknown > 0); assert.equal(f.coordinator.status().instances[0].lastEnd, 0);
      await assert.rejects(f.coordinator.complete(f.old, 'not-a-capability', {}));
    }
  } finally { await f.stop(); }
});

test('expired root cannot start continuation or return child headers', async () => {
  const f = await fixture({ childWaitMs: 100 }); try {
    const r = await f.request('parent-expired'); assert.equal(r.status, 500); await r.text(); await f.returned();
    assert.equal(f.continueCalls(), 0); assert.equal(f.coordinator.status().instances.find(row => row.id === f.other.id).lastBegin, 0);
    await assert.rejects(readFile(path.join(f.dir, 'child')), { code: 'ENOENT' });
    assert.ok(f.coordinator.status().unknown > 0); assert.equal(f.coordinator.status().instances[0].lastEnd, 0);
  } finally { await f.stop(); }
});
test('real continuation RPC delay uses root remaining budget and cannot expose late child headers', async () => {
  const f = await fixture({ delay: 'continue', delayMs: 1500, controlTimeoutMs: 1000, childWaitMs: 500 }); try {
    const started = performance.now(), r = await f.request('parent'); assert.equal(r.status, 500); await r.text(); await f.returned();
    assert.ok(performance.now() - started < 900, 'Continuation used the full RPC allowance instead of remaining root budget.');
    assert.equal(f.continueCalls(), 1); assert.equal(f.coordinator.status().instances.find(row => row.id === f.other.id).lastBegin, 1);
    await assert.rejects(readFile(path.join(f.dir, 'child')), { code: 'ENOENT' });
    assert.ok(f.coordinator.status().unknown > 0); assert.equal(f.coordinator.status().instances[0].lastEnd, 0);
  } finally { await f.stop(); }
});

for (const fault of [{operation:'register',mode:'empty'},{operation:'begin',mode:'empty'},{operation:'enter',mode:'empty'},{operation:'enter',mode:'wrong-scope'},{operation:'enter',mode:'500'},{operation:'enter',mode:'journal'}]) test(`real control ${fault.operation}/${fault.mode} ACK cannot initialize business writer`, async () => {
  const f = await fixture({fault}); try {
    const r = await f.request('normal'); assert.equal(r.status,503); await r.text(); await f.returned();
    assert.deepEqual(f.report().rows,[]); assert.ok(!(await f.events()).includes('handler-start'));
    assert.ok(f.coordinator.status().unknown>0); assert.equal(f.controlCalls('lost'),1);
  } finally {await f.stop();}
});
test('malformed enter ACK on forged ingress cannot create an untracked business handler', async () => {
  const f = await fixture({fault:{operation:'enter',mode:'before-empty'}}); try {
    const r=await f.request('normal',{'x-identity-admission-lease':'e30.'+'a'.repeat(43)}); assert.equal(r.status,503); await r.text(); await f.returned();
    assert.deepEqual(f.report().rows,[]); assert.equal(f.coordinator.status().instances[0].lastBegin,0);
    assert.ok(f.coordinator.status().unknown>0); assert.equal(f.controlCalls('lost'),1);
  } finally {await f.stop();}
});
for(const mode of ['empty','500']) test(`real control ${mode} after successful complete permanently seals lost without retry`, async()=>{
  const f=await fixture({fault:{operation:'complete',mode}}); try {
    const r=await f.request('normal'); assert.equal(r.status,200); await r.text(); await f.returned();
    assert.equal(f.coordinator.status().instances[0].lastEnd,1); assert.ok(f.coordinator.status().unknown>0);
    assert.equal(f.controlCalls('complete'),1); assert.equal(f.controlCalls('unknown'),1); assert.equal(f.controlCalls('lost'),1);
    const next=await f.request('normal'); assert.equal(next.status,503); await next.text(); assert.deepEqual(f.report().rows,['normal']);
  }finally{await f.stop();}
});
for(const operation of ['unknown','lost']) test(`real malformed ${operation} safety ACK never retries or declares completion`,async()=>{
  const f=await fixture({fault:{operation,mode:'empty'}});try{
    const r=await f.request(operation==='lost'?'parent':'500');await r.text();await f.returned();
    assert.ok(f.coordinator.status().unknown>0);assert.equal(f.coordinator.status().instances[0].lastEnd,0);assert.equal(f.controlCalls('complete'),0);
    if(operation==='unknown') assert.equal(f.controlCalls('lost'),1);
    assert.equal(f.controlCalls(operation),1);
  }finally{await f.stop();}
});
test('real continuation ACK must bind the reviewed target before returning any header',async()=>{
  const f=await fixture({fault:{operation:'continue',mode:'wrong-target'}});try{
    const r=await f.request('parent');assert.equal(r.status,500);await r.text();await f.returned();
    await assert.rejects(readFile(path.join(f.dir,'child')),{code:'ENOENT'});assert.equal(f.continueCalls(),1);assert.ok(f.coordinator.status().unknown>0);assert.equal(f.controlCalls('lost'),1);
  }finally{await f.stop();}
});
