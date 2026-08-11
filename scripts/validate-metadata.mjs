import fs from 'node:fs';

const version = '3.0.3';
const donationUrl = 'https://paypal.me/ussmarinesdot';
const main = fs.readFileSync('pixcensus-media-audit.php', 'utf8');
const readme = fs.readFileSync('readme.txt', 'utf8');
const funding = fs.readFileSync('.github/FUNDING.yml', 'utf8');
const pot = fs.readFileSync('languages/pixcensus-media-audit.pot', 'utf8');
const license = fs.readFileSync('LICENSE', 'utf8');

const donateLinkMatch = readme.match(/^Donate link:\s*(\S+)\s*$/m);
const expectedFunding = `custom:\n  - "${donationUrl}"`;

const checks = [
	[main.includes(`Version: ${version}`), 'plugin header version'],
	[main.includes(`define( 'PIXCENSUS_VERSION', '${version}' )`), 'PIXCENSUS_VERSION'],
	[readme.includes(`Stable tag: ${version}`), 'readme stable tag'],
	[donateLinkMatch !== null && donateLinkMatch[1] === donationUrl, 'WordPress.org donate link'],
	[
		funding.replace(/\r\n/g, '\n').trim() === expectedFunding,
		'GitHub funding link',
	],
	[pot.includes(`Project-Id-Version: PixCensus — Media Usage Audit ${version}`), 'POT project version'],
	[main.includes('Text Domain: pixcensus-media-audit'), 'plugin text domain'],
	[pot.includes('Language-Team:'), 'POT metadata'],
	[license.includes('GNU GENERAL PUBLIC LICENSE'), 'GPL heading'],
	[license.includes('Version 2, June 1991'), 'GPL version'],
	[license.includes('END OF TERMS AND CONDITIONS'), 'GPL terms'],
];

for (const [passed, label] of checks) {
	if (!passed) {
		throw new Error(`Metadata validation failed: ${label}`);
	}
}

const tagsLine = readme.match(/^Tags:\s*(.+)$/m);
const tags = tagsLine ? tagsLine[1].split(',').map((tag) => tag.trim()).filter(Boolean) : [];

if (tags.length < 1 || tags.length > 5) {
	throw new Error(`readme.txt must contain 1-5 tags; found ${tags.length}.`);
}

const readmeSections = readme.split(/\r?\n\r?\n/);
const shortDescription = (readmeSections[1] || '').split(/\r?\n/, 1)[0].trim();

if (!shortDescription || shortDescription.length > 150) {
	throw new Error(`readme.txt short description must be 1-150 characters; found ${shortDescription.length}.`);
}

if (/^== Screenshots ==$/m.test(readme)) {
	throw new Error('readme.txt lists screenshots, but no WordPress.org screenshot assets are distributed.');
}

console.log(JSON.stringify({ result: 'pass', version, tags: tags.length, shortDescription: shortDescription.length }));
