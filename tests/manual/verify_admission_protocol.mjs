/** Test-only, byte-for-byte reviewed protocol. Never substitutes an external implementation. */
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { lstat, readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const snapshot = fileURLToPath(new URL('../Fixtures/admission-protocol/', import.meta.url));
const names = ['coordinator.mjs', 'gateway.mjs', 'hold-queue.mjs', 'journal.mjs', 'participant-client.mjs'];
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');

export async function verifyAdmissionProtocol({ upstreamDirectory = process.env.IDENTITY_ADMISSION_UPSTREAM_DIR } = {}) {
  assert.ok((await lstat(snapshot)).isDirectory(), 'Protocol snapshot must be a real directory.');
  assert.deepEqual((await readdir(snapshot)).sort(), [...names, 'manifest.json'].sort(), 'Protocol inventory changed.');
  const manifestPath = path.join(snapshot, 'manifest.json');
  assert.ok((await lstat(manifestPath)).isFile(), 'Protocol manifest must be a regular file.');
  const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
  assert.deepEqual(Object.keys(manifest).sort(), ['files', 'kind', 'upstream', 'version']);
  assert.equal(manifest.version, 1);
  assert.equal(manifest.kind, 'identity-admission-test-protocol-snapshot');
  assert.deepEqual(manifest.upstream, {
    repository: 'xrugc-superproject', commit: 'f603930906d55c54fc2c3552ed2f431d05cafe18', directory: 'driver/identity-dev-admission',
  });
  assert.deepEqual(Object.keys(manifest.files).sort(), names);
  assert.equal(process.env.IDENTITY_ADMISSION_CORE_DIR, undefined, 'External core replacement is forbidden.');
  if (upstreamDirectory !== undefined) assert.ok(path.isAbsolute(upstreamDirectory), 'Upstream verification requires an absolute path.');
  for (const name of names) {
    const file = path.join(snapshot, name);
    assert.ok((await lstat(file)).isFile(), 'Protocol files must be regular files.');
    assert.match(manifest.files[name], /^[0-9a-f]{64}$/);
    const bytes = await readFile(file);
    assert.equal(sha256(bytes), manifest.files[name], `Snapshot SHA mismatch: ${name}`);
    if (upstreamDirectory !== undefined) {
      const upstream = path.join(upstreamDirectory, name);
      assert.ok((await lstat(upstream)).isFile(), 'Upstream files must be regular files.');
      assert.deepEqual(await readFile(upstream), bytes, `Upstream bytes differ: ${name}`);
    }
  }
  return snapshot;
}
