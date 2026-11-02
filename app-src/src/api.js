const boot = window.wbmcpApp || { restUrl: '/wp-json/weble-mcp/v1/admin/', nonce: '' };

export const env = boot;

/** fetch wrapper for the plugin's REST namespace. Throws Error(message) on non-2xx. */
export async function api(path, { method = 'GET', body, query } = {}) {
	let url = boot.restUrl + path.replace(/^\//, '');
	if (query) {
		const q = new URLSearchParams();
		Object.entries(query).forEach(([k, v]) => {
			if (v !== '' && v !== null && v !== undefined) q.set(k, v);
		});
		const s = q.toString();
		if (s) url += (url.includes('?') ? '&' : '?') + s;
	}
	const ctl = new AbortController();
	const timer = setTimeout(() => ctl.abort(), 30000);
	let res;
	try {
		res = await fetch(url, {
			method,
			credentials: 'same-origin',
			signal: ctl.signal,
			headers: { 'X-WP-Nonce': boot.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
			body: body ? JSON.stringify(body) : undefined,
		});
	} catch (e) {
		throw new Error(e.name === 'AbortError' ? 'The site took too long to answer. Try again.' : "Can't reach the site. Check your connection and try again.");
	} finally {
		clearTimeout(timer);
	}
	let json = null;
	try {
		json = await res.json();
	} catch (e) {
		json = null;
	}
	if (!res.ok) {
		if (json && json.code === 'rest_cookie_invalid_nonce') throw new Error('Your login session expired. Reload the page and try again.');
		throw new Error((json && json.message) || `The server answered with an error (${res.status}). Try again, or check the PHP error log.`);
	}
	if (json === null) throw new Error('The server sent something unexpected, often a PHP notice or a security plugin page. Check the PHP error log.');
	return json;
}

/** Copy to the clipboard. navigator.clipboard needs HTTPS, so plain-HTTP and local sites fall back to execCommand. */
export async function copyText(text) {
	try {
		if (window.isSecureContext && navigator.clipboard) {
			await navigator.clipboard.writeText(text);
			return true;
		}
	} catch (e) {
		// fall through
	}
	const ta = document.createElement('textarea');
	ta.value = text;
	ta.setAttribute('readonly', '');
	ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0';
	document.body.appendChild(ta);
	ta.select();
	let ok = false;
	try {
		ok = document.execCommand('copy');
	} catch (e) {
		ok = false;
	}
	ta.remove();
	return ok;
}
