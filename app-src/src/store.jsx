import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { api } from './api.js';

const Ctx = createContext(null);

/** App-wide data: the bootstrap payload plus a toast queue. */
export function StoreProvider({ children }) {
	const [data, setData] = useState(null);
	const [error, setError] = useState('');
	const [toasts, setToasts] = useState([]);

	const reload = useCallback(async () => {
		try {
			setData(await api('bootstrap'));
			setError('');
		} catch (e) {
			setError(e.message);
		}
	}, []);

	useEffect(() => {
		reload();
	}, [reload]);

	const toast = useCallback((msg, kind = 'ok') => {
		const id = Math.random().toString(36).slice(2);
		setToasts((t) => [...t, { id, msg, kind }]);
		setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), kind === 'error' ? 7000 : 3500);
	}, []);

	/** Save a partial settings object; merges the server's answer into the store. */
	const saveSettings = useCallback(async (values) => {
		const res = await api('settings', { method: 'POST', body: values });
		setData((d) => ({ ...d, settings: res.settings }));
		return res;
	}, []);

	const patch = useCallback((part) => setData((d) => ({ ...d, ...part })), []);

	return <Ctx.Provider value={{ data, error, reload, toast, toasts, saveSettings, patch }}>{children}</Ctx.Provider>;
}

export const useStore = () => useContext(Ctx);

/** Hash router: #/route?query */
export function useRoute() {
	const read = () => {
		const h = window.location.hash.replace(/^#\/?/, '');
		const [path, q] = h.split('?');
		return { path: path || '', query: Object.fromEntries(new URLSearchParams(q || '')) };
	};
	const [r, setR] = useState(read);
	useEffect(() => {
		const on = () => setR(read());
		window.addEventListener('hashchange', on);
		return () => window.removeEventListener('hashchange', on);
	}, []);
	return r;
}

/** Unix seconds => "3 hours ago". */
export function timeAgo(ts) {
	if (!ts) return 'Never';
	const s = Math.round(Date.now() / 1000 - ts);
	const fmt = (n, u) => `${n} ${u}${n === 1 ? '' : 's'} ago`;
	if (s < 60) return 'just now';
	if (s < 3600) return fmt(Math.round(s / 60), 'minute');
	if (s < 86400) return fmt(Math.round(s / 3600), 'hour');
	return fmt(Math.round(s / 86400), 'day');
}

export function fmtDate(ts) {
	return ts ? new Date(ts * 1000).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

/** Header state: can an agent connect, and is anything risky switched on. */
export function siteState(data) {
	if (!data.passwords) return { tone: 'is-off', label: 'Application Passwords off' };
	if (data.settings.custom_code) return { tone: 'is-warn', label: 'Custom code on' };
	if (!data.connections.length) return { tone: 'is-off', label: 'No connections yet' };
	return { tone: 'is-on', label: 'Ready for agents' };
}
