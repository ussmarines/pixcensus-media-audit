import fs from 'node:fs';
import path from 'node:path';
import { parse as parseYaml } from 'yaml';

const ignoredDirectories = new Set(['.git', 'dist', 'node_modules', 'vendor']);
const jsonFiles = [];
const yamlFiles = [];

function collectJson(directory) {
	for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
		if (entry.isDirectory() && ignoredDirectories.has(entry.name)) {
			continue;
		}

		const entryPath = path.join(directory, entry.name);

		if (entry.isDirectory()) {
			collectJson(entryPath);
		} else if (entry.isFile() && entry.name.endsWith('.json')) {
			jsonFiles.push(entryPath);
		}
	}
}

function collectYaml(directory) {
	for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
		const entryPath = path.join(directory, entry.name);

		if (entry.isDirectory()) {
			collectYaml(entryPath);
		} else if (entry.isFile() && /\.ya?ml$/i.test(entry.name)) {
			yamlFiles.push(entryPath);
		}
	}
}

collectJson('.');
collectYaml('.github');

for (const file of jsonFiles) {
	JSON.parse(fs.readFileSync(file, 'utf8'));
}

const packageManifest = JSON.parse(fs.readFileSync('package.json', 'utf8'));
const approvedInstallScripts = packageManifest.allowScripts || {};

if (
	packageManifest.name !== 'pixcensus-media-audit' ||
	packageManifest.private !== true ||
	packageManifest.devDependencies?.yaml !== '2.9.0' ||
	packageManifest.devDependencies?.['fast-check'] !== '4.9.0' ||
	packageManifest.scripts?.['test:property'] !== 'node tests/property/security-inputs.property.js'
) {
	throw new Error('The npm package identity and direct QA dependencies must remain exact and reviewed.');
}

if (
	Object.keys(approvedInstallScripts).length !== 1 ||
	approvedInstallScripts['fs-ext-extra-prebuilt@2.2.7'] !== true
) {
	throw new Error('The reviewed npm install script must remain approved at its exact version.');
}

const npmConfig = fs.readFileSync('.npmrc', 'utf8');

if (!/^strict-allow-scripts=true$/m.test(npmConfig)) {
	throw new Error('npm must reject unreviewed dependency install scripts.');
}

for (const file of yamlFiles) {
	const parsedYaml = parseYaml(fs.readFileSync(file, 'utf8'));

	if (!parsedYaml || typeof parsedYaml !== 'object') {
		throw new Error(`${file} is not a YAML mapping.`);
	}

	if (file.startsWith(path.join('.github', 'workflows')) && (!parsedYaml.on || !parsedYaml.jobs)) {
		throw new Error(`${file} is not a valid GitHub Actions workflow mapping.`);
	}

	if (file.startsWith(path.join('.github', 'workflows'))) {
		if (!parsedYaml.permissions || !parsedYaml.concurrency) {
			throw new Error(`${file} must declare top-level permissions and concurrency.`);
		}

		for (const [jobName, job] of Object.entries(parsedYaml.jobs)) {
			if (!job || typeof job !== 'object' || !Number.isInteger(job['timeout-minutes'])) {
				throw new Error(`${file} job ${jobName} must declare an integer timeout-minutes.`);
			}

			for (const step of job.steps || []) {
				if (!step.uses || typeof step.uses !== 'string') {
					continue;
				}

				if (!step.uses.startsWith('./') && !/@[0-9a-f]{40}$/.test(step.uses)) {
					throw new Error(`${file} job ${jobName} contains an action that is not pinned to a full commit SHA: ${step.uses}`);
				}

				if (step.uses.startsWith('actions/checkout@') && step.with?.['persist-credentials'] !== false) {
					throw new Error(`${file} job ${jobName} must disable checkout credential persistence.`);
				}
			}
		}
	}

	if (file === path.join('.github', 'workflows', 'codeql.yml')) {
		if (parsedYaml.on?.pull_request?.paths || parsedYaml.on?.push?.paths) {
			throw new Error('CodeQL must run for every pull request and push to main.');
		}
	}

	if (file === path.join('.github', 'workflows', 'qa.yml')) {
		const qaCommands = (parsedYaml.jobs?.qa?.steps || []).map((step) => step.run).filter(Boolean);

		if (!qaCommands.includes('npm ci') || !qaCommands.includes('npm run test:property')) {
			throw new Error('Both PHP QA lanes must install and run the property-based security test.');
		}
	}

	if (file === path.join('.github', 'dependabot.yml') && (parsedYaml.version !== 2 || !Array.isArray(parsedYaml.updates))) {
		throw new Error('Dependabot configuration must use version 2 with an updates array.');
	}
}

console.log(JSON.stringify({ result: 'pass', jsonFiles: jsonFiles.length, yamlFiles: yamlFiles.length }));
