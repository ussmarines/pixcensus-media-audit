import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import test from 'node:test';

const root = fileURLToPath(new URL('../../', import.meta.url));
const plugins = path.join(root, '.agents/skills/wp-plugin-development/scripts/detect_plugins.mjs');
const triage = path.join(root, '.agents/skills/wp-project-triage/scripts/detect_wp_project.mjs');
function fixture(run) {
  const directory = mkdtempSync(path.join(tmpdir(), 'pixcensus-skill-headers-'));
  try { run(directory); } finally {
    // This exact path was created above; no project or user data enters cleanup.
    assert.equal(path.dirname(directory), tmpdir());
    assert.ok(path.basename(directory).startsWith('pixcensus-skill-headers-'));
    rmSync(directory, { recursive: true });
  }
}
function detect(script, cwd) {
  return JSON.parse(execFileSync(process.execPath, [script], { cwd, encoding: 'utf8' }));
}
for (const marker of ['', ' * ']) {
  test(`plugin headers with ${marker ? 'PHPDoc stars' : 'plain comments'}`, () => fixture(directory => {
    writeFileSync(path.join(directory, 'fixture.php'), `<?php\n/*\n${marker}Plugin Name: Header Fixture\n${marker}Version: 3.5.0\n${marker}Text Domain: header-fixture\n*/\n`);
    const found = detect(plugins, directory);
    assert.equal(found.count, 1);
    assert.equal(found.plugins[0].name, 'Header Fixture');
    assert.equal(found.plugins[0].version, '3.5.0');
    assert.equal(found.plugins[0].textDomain, 'header-fixture');
    const project = detect(triage, directory);
    assert.equal(project.signals.detectedPluginName, 'Header Fixture');
    assert.equal(project.project.primary, 'wp-plugin');
  }));
  test(`theme headers with ${marker ? 'PHPDoc stars' : 'plain comments'}`, () => fixture(directory => {
    writeFileSync(path.join(directory, 'style.css'), `/*\n${marker}Theme Name: Theme Fixture\n*/\n`);
    const project = detect(triage, directory);
    assert.equal(project.signals.detectedThemeName, 'Theme Fixture');
    assert.equal(project.project.primary, 'wp-theme');
    assert.equal(detect(plugins, directory).count, 0);
  }));
}
test('ordinary PHP without a plugin header remains unclassified', () => fixture(directory => {
  writeFileSync(path.join(directory, 'fixture.php'), '<?php // No WordPress header.\n');
  assert.equal(detect(plugins, directory).count, 0);
  assert.equal(detect(triage, directory).project.primary, 'unknown');
}));
