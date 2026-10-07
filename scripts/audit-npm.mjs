import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const severityRank = new Map([
	['low', 1],
	['moderate', 2],
	['high', 3],
	['critical', 4],
]);

const reviewedDevOnlyExceptions = new Map([
	[
		'http-cache-semantics',
		{
			version: '4.2.0',
			advisories: new Set(['GHSA-ch52-4w7c-c8xp']),
			reason: 'No patched release exists yet for the transitive wp-env dependency.',
		},
	],
	[
		'simple-git',
		{
			version: '3.36.0',
			advisories: new Set([
				'GHSA-g4wm-2vf7-vfgr',
				'GHSA-858h-whjf-mvg5',
				'GHSA-x6jw-m9v5-85vh',
			]),
			reason:
				'@wordpress/env 11.16.0 still uses the callable CommonJS simple-git v3 API; forcing v4 would break that tooling path.',
		},
	],
	[
		'@simple-git/argv-parser',
		{
			version: '1.1.1',
			advisories: new Set(['GHSA-v5rq-49vh-5v5c']),
			reason:
				'This transitive parser remains coupled to simple-git v3 until @wordpress/env can move to simple-git v4.',
		},
	],
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

function advisoryId(entry) {
	if (!entry || typeof entry !== 'object' || typeof entry.url !== 'string') {
		return null;
	}

	const match = entry.url.match(/\/advisories\/(GHSA-[a-z0-9-]+)$/i);
	return match ? match[1] : null;
}

function directAdvisories(vulnerability) {
	return (Array.isArray(vulnerability?.via) ? vulnerability.via : [])
		.map((entry) => ({ entry, id: advisoryId(entry) }))
		.filter(({ id }) => id !== null);
}

function exceptionFor(vulnerability, advisories) {
	const policy = reviewedDevOnlyExceptions.get(vulnerability?.name);
	if (!policy || vulnerability?.isDirect !== false || advisories.length === 0) {
		return null;
	}

	const lockNode = lock.packages?.[`node_modules/${vulnerability.name}`];
	if (lockNode?.version !== policy.version || lockNode?.dev !== true) {
		return null;
	}

	if (!advisories.every(({ id }) => policy.advisories.has(id))) {
		return null;
	}

	return {
		name: vulnerability.name,
		version: policy.version,
		severity: vulnerability.severity,
		advisories: advisories.map(({ id }) => id),
		reason: policy.reason,
	};
}

const minimumRank = severityRank.get(level);
const blocked = [];
const reviewedExceptions = [];

for (const vulnerability of Object.values(report.vulnerabilities)) {
	if ((severityRank.get(vulnerability?.severity) || 0) < minimumRank) {
		continue;
	}

	const advisories = directAdvisories(vulnerability);

	// npm also reports aggregate parent entries whose `via` list only contains
	// package names. The advisory-bearing dependency entries are evaluated here,
	// so those aggregate entries must not be counted a second time.
	if (advisories.length === 0) {
		continue;
	}

	const reviewedException = exceptionFor(vulnerability, advisories);
	if (reviewedException) {
		reviewedExceptions.push(reviewedException);
		continue;
	}

	blocked.push({
		name: vulnerability.name,
		severity: vulnerability.severity,
		advisories: advisories.map(({ id }) => id),
		fixAvailable: vulnerability.fixAvailable,
	});
}

if (blocked.length > 0) {
	console.error(JSON.stringify({ result: 'fail', level, blocked }));
	process.exit(1);
}

console.log(
	JSON.stringify({
		result: 'pass',
		level,
		reviewedExceptions,
	}),
);
