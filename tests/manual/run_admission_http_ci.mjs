/** Mandatory real HTTP/SQL/Redis runner, shared by CI and the isolated Docker wrapper. */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { lookup } from 'node:dns/promises';
import { fileURLToPath } from 'node:url';
import { verifyAdmissionProtocol } from './verify_admission_protocol.mjs';

assert.equal(Number(process.versions.node.split('.')[0]), 24, 'Use the reviewed Node 24 runtime.');
await verifyAdmissionProtocol();
assert.equal(process.env.Y1_ADMISSION_FIXTURE_MODE, 'ci-loopback', 'Explicit same-host loopback fixture mode is required.');
for (const name of ['codex-y1-namespace-mysql-20261013', 'codex-y1-namespace-redis-20261013']) {
  const addresses = await lookup(name, { all: true });
  assert.ok(addresses.length > 0);
  for (const { address, family } of addresses) {
    assert.equal(family, 4);
    assert.equal(address, '127.0.0.1', 'Fixtures must resolve only to this runner.');
  }
}
const cwd = fileURLToPath(new URL('../../', import.meta.url));
const php = spawnSync('php', ['-r', 'if (PHP_VERSION_ID < 80400 || !extension_loaded("curl") || !extension_loaded("pdo_mysql")) exit(1);'], { cwd, encoding: 'utf8' });
assert.equal(php.status, 0, 'PHP 8.4 with curl and pdo_mysql is mandatory.');
const result = spawnSync(process.execPath, ['--test', '--test-reporter=tap', 'tests/manual/admission_http.test.mjs'], {
  cwd, encoding: 'utf8', timeout: 180000, maxBuffer: 2 * 1024 * 1024,
});
process.stdout.write(result.stdout ?? '');
process.stderr.write(result.stderr ?? '');
if (result.error) throw result.error;
assert.equal(result.signal, null, 'HTTP fixture was interrupted.');
assert.equal(result.status, 0, 'Real HTTP fixture failed.');
for (const [name, count] of Object.entries({ tests: 41, pass: 41, fail: 0, cancelled: 0, skipped: 0, todo: 0 })) {
  const matches = [...result.stdout.matchAll(new RegExp(`^# ${name} (\\d+)$`, 'gm'))];
  assert.equal(matches.length, 1, `Missing/ambiguous mandatory TAP counter: ${name}`);
  assert.equal(Number(matches[0][1]), count, `Mandatory HTTP ${name} count changed.`);
}
console.log('Mandatory admission HTTP: 41 passed, zero skipped; reviewed protocol SHA verified.');
