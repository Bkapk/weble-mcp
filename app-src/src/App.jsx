import { useEffect } from 'react';
import { env } from './api.js';
import { siteState, useRoute, useStore } from './store.jsx';
import { ConfirmHost, Icon, Mark, Skeleton } from './ui.jsx';
import { Overview } from './pages/Overview.jsx';
import { Connections } from './pages/Connections.jsx';
import { Rules } from './pages/Rules.jsx';
import { Tools } from './pages/Tools.jsx';
import { Settings } from './pages/Settings.jsx';

const NAV = [
	{ path: '', label: 'Overview', page: Overview },
	{ path: 'connections', label: 'Connections', page: Connections },
	{ path: 'rules', label: 'House rules', page: Rules },
	{ path: 'tools', label: 'Tools', page: Tools },
	{ path: 'settings', label: 'Settings', page: Settings },
];

/** Keeps the WordPress sidebar submenu highlight in step with the hash route. */
function useWpMenuSync(path) {
	useEffect(() => {
		document.querySelectorAll('#toplevel_page_weble-mcp .wp-submenu li').forEach((li) => {
			const a = li.querySelector('a');
			if (!a) return;
			const route = (a.getAttribute('href') || '').split('#/')[1];
			const on = route !== undefined && route.split('?')[0] === path;
			li.classList.toggle('current', on);
			a.classList.toggle('current', on);
		});
	}, [path]);
}

export function App() {
	const { data, error, toasts, reload } = useStore();
	const { path, query } = useRoute();
	useWpMenuSync(path);

	if (error && !data) {
		return (
			<div className="wbmcp-root">
				<div className="fatal">
					<Icon name="alert" size={22} />
					<p>Weble MCP could not load its data: {error}</p>
					<button type="button" className="btn btn--primary" onClick={reload}>Try again</button>
				</div>
			</div>
		);
	}

	const active = NAV.find((n) => n.path === path) || NAV[0];
	const Page = active.page;
	const state = data ? siteState(data) : null;

	return (
		<div className="wbmcp-root">
			<header className="top">
				<div className="top__row">
					<a className="brand" href="#/">
						<Mark />
						<span className="brand__name">Weble MCP</span>
					</a>
					{state ? (
						<span className={'live ' + state.tone}>
							<i aria-hidden="true" />
							{state.label}
						</span>
					) : null}
					<span className="top__sp" />
					{data && !data.connections.length && active.path !== '' ? (
						<a className="btn btn--accent btn--sm" href="#/"><Icon name="plug" size={16} /><span>Connect an agent</span></a>
					) : null}
				</div>
				<nav className="tabs" aria-label="Weble MCP">
					{NAV.map((n) => (
						<a key={n.path} href={'#/' + n.path} className={'tabs__item' + (n === active ? ' is-active' : '')} aria-current={n === active ? 'page' : undefined}>
							{n.label}
						</a>
					))}
				</nav>
			</header>
			<main className="main">{data ? <Page query={query} /> : <Skeleton rows={8} />}</main>
			<footer className="foot">
				Weble MCP {env.version} · <a href={env.repo} target="_blank" rel="noopener noreferrer">Source on GitHub</a> · Made by <a href="https://weble.studio/" target="_blank" rel="noopener noreferrer">Weble Studio</a>
			</footer>
			<Toasts toasts={toasts} />
			<ConfirmHost />
		</div>
	);
}

function Toasts({ toasts }) {
	return (
		<div className="toasts" aria-live="polite">
			{toasts.map((t) => (
				<div key={t.id} className={'toast toast--' + t.kind} role={t.kind === 'error' ? 'alert' : 'status'}>
					<Icon name={t.kind === 'error' ? 'alert' : 'check'} size={16} />
					{t.msg}
				</div>
			))}
		</div>
	);
}
