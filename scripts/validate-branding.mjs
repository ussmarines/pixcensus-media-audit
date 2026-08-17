import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const runtimePaths = [
  'pixcensus-media-audit.php',
  'uninstall.php',
  'readme.txt',
  'LICENSE',
  'assets/admin.css',
  'assets/admin.js',
  'assets/pixcensus-media-audit-mark.svg',
  'views/admin-page.php',
  'includes/class-pixcensus-cdn-settings.php',
  'includes/class-pixcensus-csv.php',
  'includes/class-pixcensus-scanner.php',
  'languages/pixcensus-media-audit.pot',
];

const forbidden = ['Image Usage Audit', 'image-usage-audit', 'IUA_', 'iua_', 'iua-'];
const forbiddenCamelCase = /\biua[A-Z][A-Za-z0-9_]*\b/g;
for (const relativePath of runtimePaths) {
  const fullPath = path.join(root, relativePath);
  if (!fs.existsSync(fullPath)) throw new Error(`Missing PixCensus runtime file: ${relativePath}`);
  const content = fs.readFileSync(fullPath, 'utf8');
  for (const token of forbidden) {
    if (content.includes(token)) throw new Error(`Legacy identifier ${token} remains in ${relativePath}`);
  }

  const camelCaseMatches = content.match(forbiddenCamelCase) || [];
  if (camelCaseMatches.length > 0) {
    throw new Error(`Legacy camelCase identifier ${camelCaseMatches[0]} remains in ${relativePath}`);
  }
}

const forbiddenLegacyPaths = [
  'image-usage-audit.php',
  'assets/image-usage-audit-mark.svg',
  'includes/class-iua-cdn-settings.php',
  'includes/class-iua-csv.php',
  'includes/class-iua-scanner.php',
  'languages/image-usage-audit.pot',
];
for (const relativePath of forbiddenLegacyPaths) {
  if (fs.existsSync(path.join(root, relativePath))) {
    throw new Error(`Legacy runtime path remains: ${relativePath}`);
  }
}

const directoryAssets = [
  '.wordpress-org/banner-772x250.png',
  '.wordpress-org/banner-1544x500.png',
  '.wordpress-org/icon-128x128.png',
  '.wordpress-org/icon-256x256.png',
  '.wordpress-org/banner-source.svg',
  '.wordpress-org/icon.svg',
];
for (const relativePath of directoryAssets) {
  if (!fs.existsSync(path.join(root, relativePath))) {
    throw new Error(`Missing WordPress.org PixCensus asset: ${relativePath}`);
  }
}

const main = fs.readFileSync(path.join(root, 'pixcensus-media-audit.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
const githubReadme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
const adminScript = fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8');
const adminView = fs.readFileSync(path.join(root, 'views/admin-page.php'), 'utf8');
const requiredMain = [
  'Plugin Name: PixCensus — Media Usage Audit',
  'Version: 3.0.4',
  'Text Domain: pixcensus-media-audit',
  "define( 'PIXCENSUS_VERSION', '3.0.4' )",
  "define( 'PIXCENSUS_SLUG', 'pixcensus-media-audit' )",
  "current_user_can( 'manage_options' )",
  'check_admin_referer(',
  'wp_verify_nonce(',
];
for (const token of requiredMain) {
  if (!main.includes(token)) throw new Error(`Required main-plugin control is missing: ${token}`);
}
if (!readme.includes('Stable tag: 3.0.4')) throw new Error('The WordPress.org stable tag is not 3.0.4.');
if (!readme.includes('Tested up to: 7.1')) throw new Error('The WordPress.org tested-up-to value is not 7.1.');
if (!githubReadme.includes('.wordpress-org/banner-1544x500.png')) {
  throw new Error('The GitHub README does not use the PixCensus banner.');
}
if (
	!adminView.includes('data-pixcensus-density=') ||
	!adminScript.includes("$(this).attr('data-pixcensus-density')")
) {
	throw new Error('The PixCensus density attribute must stay synchronized between the admin view and script.');
}

const ajaxMethods = [
  'ajax_run_scan',
  'ajax_mark_manual_used',
  'ajax_unmark_manual_used',
  'ajax_mark_manual_used_bulk',
  'ajax_unmark_manual_used_bulk',
];
for (const method of ajaxMethods) {
  const start = main.indexOf(`public function ${method}`);
  if (start < 0) throw new Error(`Missing AJAX method: ${method}`);
  const excerpt = main.slice(start, start + 700);
  if (!excerpt.includes('verify_ajax_request(')) throw new Error(`${method} does not verify capability, action, method, and nonce.`);
}

console.log(JSON.stringify({ result: 'pass', name: 'PixCensus — Media Usage Audit', slug: 'pixcensus-media-audit', version: '3.0.4' }));
