import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const severityRank = new Map([
	['low', 1],
	['moderate', 2],
	['high', 3],
	['critical', 4],
]);

const allowedLevels = new Set(severityRank.keys());
let level = 'high';
let reportPath = null;

for (const argument of process.argv.slice(2)) {
	if (argument.startsWith('--level=')) {
		level = argument.slice('--level='.length);
	} else if (argument.startsWith('--report=')) {
		reportPath = argument.slice('--report='.length);
	} else {
		throw new Error(`Unsupported argument: ${argument}`);
	}
}

if (!allowedLevels.has(level)) {
	throw new Error(`Unsupported audit level: ${level}`);
}

const npmCommand = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const audit = spawnSync(npmCommand, ['audit', '--json', `--audit-level=${level}`], {
	encoding: 'utf8',
	maxBuffer: 16 * 1024 * 1024,
});

if (audit.error) {
	throw new Error('Unable to execute npm audit.');
}

let report;
try {
	report = JSON.parse(audit.stdout || '');
} catch {
	throw new Error('npm audit did not return valid JSON.');
}

if (reportPath) {
	fs.mkdirSync(path.dirname(reportPath), { recursive: true });
	fs.writeFileSync(reportPath, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
}

if (report.error || !report.vulnerabilities) {
	throw new Error('npm audit failed without a valid vulnerability report.');
}

const lock = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'));
const cachedPolicy = lock.packages?.['node_modules/http-cache-semantics'];

function isReviewedUnpatchedDevOnlyAdvisory(vulnerability) {
	if (
		vulnerability?.name !== 'http-cache-semantics' ||
		vulnerability?.severity !== 'high' ||
		vulnerability?.isDirect !== false ||
		vulnerability?.fixAvailable !== false ||
		cachedPolicy?.version !== '4.2.0' ||
		cachedPolicy?.dev !== true
	) {
		return false;
	}

	const via = Array.isArray(vulnerability.via) ? vulnerability.via : [];
	return (
		via.length === 1 &&
		typeof via[0] === 'object' &&
		via[0] !== null &&
		via[0].url === 'https://github.com/advisories/GHSA-ch52-4w7c-c8xp' &&
		via[0].severity === 'high'
	);
}

const minimumRank = severityRank.get(level);
const relevant = Object.values(report.vulnerabilities).filter(
	(vulnerability) => (severityRank.get(vulnerability.severity) || 0) >= minimumRank,
);
const blocked = relevant.filter((vulnerability) => !isReviewedUnpatchedDevOnlyAdvisory(vulnerability));
const reviewedExceptions = relevant.filter(isReviewedUnpatchedDevOnlyAdvisory);

if (blocked.length > 0) {
	console.error(
		JSON.stringify({
			result: 'fail',
			level,
			blocked: blocked.map(({ name, severity }) => ({ name, severity })),
		}),
	);
	process.exit(1);
}

console.log(
	JSON.stringify({
		result: 'pass',
		level,
		reviewedExceptions: reviewedExceptions.map(({ name, severity }) => ({ name, severity })),
	}),
);
