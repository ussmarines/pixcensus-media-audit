const baseUrl = process.env.PIXCENSUS_BASE_URL || 'http://localhost:8888';
let assertionCount = 0;

function assert(condition, message) {
	if (!condition) {
		throw new Error(message);
	}

	assertionCount += 1;
}

async function login(username, password) {
	const body = new URLSearchParams({
		log: username,
		pwd: password,
		'wp-submit': 'Log In',
		redirect_to: `${baseUrl}/wp-admin/`,
		testcookie: '1'
	});
	const response = await fetch(`${baseUrl}/wp-login.php`, {
		method: 'POST',
		redirect: 'manual',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
			Cookie: 'wordpress_test_cookie=WP%20Cookie%20check'
		},
		body
	});
	const cookies = response.headers.getSetCookie().map((value) => value.split(';', 1)[0]);

	assert(response.status === 302, `Login for ${username} returned ${response.status}.`);
	assert(cookies.some((cookie) => cookie.startsWith('wordpress_logged_in_')), `Login cookie missing for ${username}.`);

	return cookies.join('; ');
}

async function fetchAdminState(cookie) {
	const response = await fetch(`${baseUrl}/wp-admin/upload.php?page=pixcensus-audit`, {
		headers: { Cookie: cookie }
	});
	const html = await response.text();
	const configMatch = html.match(/var PixCensusAdmin = (\{.*?\});/s);
	const idMatch = html.match(/class="button button-secondary pixcensus-mark-used" data-id="(\d+)"/);
	const settingsNonceMatch = html.match(/name="pixcensus_settings_nonce" value="([^"]+)"/);
	const exportNonceMatch = html.match(/href="[^"]*admin-post\.php[^"]*action=pixcensus_export_csv[^"]*_wpnonce=([^&"]+)/);

	assert(response.status === 200, `Plugin admin page returned ${response.status}.`);
	assert(configMatch, 'Localized AJAX configuration was not found.');
	assert(idMatch, 'AJAX attachment fixture was not rendered.');
	assert(settingsNonceMatch, 'Scan settings nonce was not found.');
	assert(exportNonceMatch, 'CSV export nonce was not found.');

	return {
		config: JSON.parse(configMatch[1]),
		attachmentId: Number.parseInt(idMatch[1], 10),
		settingsNonce: settingsNonceMatch[1],
		exportNonce: exportNonceMatch[1]
	};
}

async function ajax(cookie, fields, method = 'POST') {
	const body = new URLSearchParams(fields);
	const url = method === 'GET'
		? `${baseUrl}/wp-admin/admin-ajax.php?${body}`
		: `${baseUrl}/wp-admin/admin-ajax.php`;
	const response = await fetch(url, {
		method,
		headers: {
			...(cookie ? { Cookie: cookie } : {}),
			...(method === 'POST' ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {})
		},
		...(method === 'POST' ? { body } : {})
	});
	const text = await response.text();
	let payload;

	try {
		payload = JSON.parse(text);
	} catch {
		throw new Error(`AJAX response was not JSON (${response.status}): ${text.slice(0, 120)}`);
	}

	return { response, payload };
}

async function adminPost(cookie, fields, method = 'POST', explicitUrl = '') {
	const body = new URLSearchParams(fields);
	const url = explicitUrl || (method === 'GET'
		? `${baseUrl}/wp-admin/admin-post.php?${body}`
		: `${baseUrl}/wp-admin/admin-post.php`);
	const response = await fetch(url, {
		method,
		redirect: 'manual',
		headers: {
			...(cookie ? { Cookie: cookie } : {}),
			...(method === 'POST' ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {})
		},
		...(method === 'POST' ? { body } : {})
	});

	return { response, text: await response.text() };
}

const restrictedRoles = ['subscriber', 'contributor', 'author', 'editor'];
const adminCookie = await login('admin', 'password');
const restrictedCookies = new Map();

for (const role of restrictedRoles) {
	restrictedCookies.set(
		role,
		await login(`pixcensus-ajax-${role}`, `pixcensus-ajax-${role}-password`)
	);
}

const { config, attachmentId, settingsNonce, exportNonce } = await fetchAdminState(adminCookie);
const exportUrl = new URL('/wp-admin/admin-post.php', baseUrl);
exportUrl.searchParams.set('action', 'pixcensus_export_csv');
exportUrl.searchParams.set('_wpnonce', exportNonce);

const ajaxEndpoints = [
	{
		name: 'scan',
		fields: {
			action: 'pixcensus_run_scan',
			nonce: config.nonces.run_scan
		}
	},
	{
		name: 'mark',
		fields: {
			action: 'pixcensus_mark_manual_used',
			nonce: config.nonces.mark_manual,
			id: String(attachmentId)
		}
	},
	{
		name: 'unmark',
		fields: {
			action: 'pixcensus_unmark_manual_used',
			nonce: config.nonces.unmark_manual,
			id: String(attachmentId)
		}
	},
	{
		name: 'bulk mark',
		fields: {
			action: 'pixcensus_mark_manual_used_bulk',
			nonce: config.nonces.mark_manual_bulk,
			'ids[]': String(attachmentId)
		}
	},
	{
		name: 'bulk unmark',
		fields: {
			action: 'pixcensus_unmark_manual_used_bulk',
			nonce: config.nonces.unmark_bulk,
			'ids[]': String(attachmentId)
		}
	}
];

for (const endpoint of ajaxEndpoints) {
	let result = await ajax('', endpoint.fields);
	assert(
		result.response.status === 401 && result.payload.success === false,
		`Unauthenticated ${endpoint.name} request was not rejected with JSON 401.`
	);

	for (const role of restrictedRoles) {
		result = await ajax(restrictedCookies.get(role), endpoint.fields);
		assert(
			result.response.status === 403 && result.payload.success === false,
			`${role} ${endpoint.name} request was not rejected with JSON 403.`
		);
	}
}

let result = await ajax(adminCookie, {
	action: 'pixcensus_mark_manual_used',
	nonce: '',
	id: String(attachmentId)
});
assert(result.response.status === 403 && result.payload.success === false, 'Missing nonce was not rejected with JSON 403.');

result = await ajax(adminCookie, {
	action: 'pixcensus_mark_manual_used',
	nonce: config.nonces.unmark_manual,
	id: String(attachmentId)
});
assert(result.response.status === 403 && result.payload.success === false, 'Action-specific nonce separation failed.');

result = await ajax(adminCookie, {
	action: 'pixcensus_mark_manual_used',
	nonce: config.nonces.mark_manual,
	id: String(attachmentId)
}, 'GET');
assert(result.response.status === 405 && result.payload.success === false, 'GET request was not rejected with JSON 405.');

result = await ajax(adminCookie, {
	action: 'pixcensus_mark_manual_used',
	nonce: config.nonces.mark_manual,
	id: `${attachmentId}invalid`
});
assert(result.response.status === 400 && result.payload.success === false, 'Malformed attachment ID was not rejected with JSON 400.');

result = await ajax(adminCookie, {
	action: 'pixcensus_mark_manual_used_bulk',
	nonce: config.nonces.mark_manual_bulk,
	'ids[0][nested]': String(attachmentId)
});
assert(result.response.status === 400 && result.payload.success === false, 'Malformed bulk array was not rejected with JSON 400.');

for (const endpoint of ajaxEndpoints) {
	result = await ajax(adminCookie, endpoint.fields);
	assert(
		result.response.status === 200 && result.payload.success === true,
		`Administrator ${endpoint.name} request did not succeed.`
	);
}

const settingsFields = {
	action: 'pixcensus_save_settings',
	pixcensus_section: 'scan',
	pixcensus_include_drafts: '1'
};

result = await adminPost('', settingsFields);
assert(result.response.status >= 400, 'Unauthenticated settings request unexpectedly succeeded.');

for (const role of restrictedRoles) {
	result = await adminPost(restrictedCookies.get(role), settingsFields);
	assert(result.response.status >= 400, `${role} settings request unexpectedly succeeded.`);
	assert(result.text.includes('Permission denied.'), `${role} settings denial did not reach the PixCensus capability guard.`);
}

result = await adminPost(adminCookie, {
	...settingsFields,
	pixcensus_settings_nonce: settingsNonce
});
assert(result.response.status === 302, `Administrator settings request returned ${result.response.status} instead of redirecting.`);

result = await adminPost('', {}, 'GET', exportUrl.toString());
assert(result.response.status >= 400, 'Unauthenticated CSV export unexpectedly succeeded.');

for (const role of restrictedRoles) {
	result = await adminPost(restrictedCookies.get(role), {}, 'GET', exportUrl.toString());
	assert(result.response.status >= 400, `${role} CSV export unexpectedly succeeded.`);
	assert(result.text.includes('Permission denied.'), `${role} export denial did not reach the PixCensus capability guard.`);
}

result = await adminPost(adminCookie, {}, 'GET', exportUrl.toString());
assert(result.response.status === 200, `Administrator CSV export returned ${result.response.status}.`);
assert(
	(result.response.headers.get('content-type') || '').includes('text/csv'),
	'Administrator CSV export did not return text/csv.'
);

console.log(JSON.stringify({ result: 'pass', assertions: assertionCount, attachmentId }));
