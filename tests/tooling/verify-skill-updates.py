"""Validate only the vendored-skill maintenance delta and public evidence."""
from pathlib import Path
import json
import re
import subprocess

root = Path(__file__).resolve().parents[2]
def git(*args):
    return subprocess.check_output(['git', '-c', 'core.safecrlf=false', *args], cwd=root, stderr=subprocess.DEVNULL)
report = json.loads((root / 'docs/codex-tools-update-2026-11-21.json').read_text(encoding='utf-8-sig'))
changed = set(git('diff', report['reviewBase'], '--name-only', '-z').decode().split('\0'))
changed |= set(git('ls-files', '--others', '--exclude-standard', '-z').decode().split('\0'))
changed.discard('')
allowed = ('.agents/', '.codex/test-ledger.json', 'docs/codex-tools-', 'tests/tooling/')
assert all(name.startswith(allowed) for name in changed), 'Change outside the tooling maintenance scope'
assert git('diff', report['reviewBase'], '--', 'pixcensus-media-audit.php', 'readme.txt', 'includes', 'assets', 'views', 'languages', 'uninstall.php') == b''
main = (root / 'pixcensus-media-audit.php').read_text(encoding='utf-8')
assert 'Version: 3.5.0' in main and "'PIXCENSUS_VERSION', '3.5.0'" in main
links = 0
for name in changed:
    file = root / name
    if not name.startswith('.agents/') or file.suffix != '.md' or not file.is_file():
        continue
    for destination in re.findall(r'\]\(([^)]+)\)', file.read_text(encoding='utf-8')):
        if '://' in destination or destination.startswith('#'):
            continue
        destination = destination.split('#')[0]
        assert not destination or (file.parent / destination).exists(), f'Missing reference: {name}'
        links += 1
report = json.loads((root / 'docs/codex-tools-update-2026-11-21.json').read_text(encoding='utf-8-sig'))
assert report['globalToolVersionsSnapshotDate'] == '2026-11-21'
assert report['reviewDate'] == '2026-11-25' and report['validationLimits']
assert all(item['path'] == '.agents/skills/' + item['name'] and 'backup' not in item for item in report['skills'])
assert all(item['status'] in ('matches-pinned-upstream', 'local-adaptation', 'local-fallback') for item in report['sourceVerification'])
ledger = json.loads((root / '.codex/test-ledger.json').read_text(encoding='utf-8-sig'))
base = json.loads(git('show', report['reviewBase'] + ':.codex/test-ledger.json').decode('utf-8-sig'))
assert ledger['entries'][:len(base['entries'])] == base['entries'], 'Existing history was modified'
new_entries = ledger['entries'][len(base['entries']):]
public = json.dumps([report, new_entries])
assert not re.search(r'[A-Za-z]:[/\\]+Users[/\\]+', public), 'User-profile path in new public evidence'
assert any(entry['name'] == 'Browser seven improvements journey' and entry['result'] == 'fail' for entry in new_entries), 'Historical failure was lost'
assert sum(entry.get('command_redaction', '') != '' for entry in new_entries) >= 3
print(json.dumps({'result': 'pass', 'relative_links': links, 'historical_entries_unchanged': len(base['entries']), 'addon_version': '3.5.0', 'runtime_unchanged': True}))
