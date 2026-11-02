import { useRef, useState } from 'react';
import { api, copyText } from '../api.js';
import { useStore } from '../store.jsx';
import { Button, Icon } from '../ui.jsx';

/** Generates a connection password and keeps its details until "Done". They are shown once. */
export function useConnect() {
	const { patch, toast } = useStore();
	const [conn, setConn] = useState(null);
	const [busy, setBusy] = useState(false);
	const generate = async () => {
		setBusy(true);
		try {
			const res = await api('connections', { method: 'POST' });
			patch({ connections: res.connections });
			setConn(res.connection);
		} catch (e) {
			toast(e.message, 'error');
		}
		setBusy(false);
	};
	return { conn, busy, generate, clear: () => setConn(null) };
}

const json = (v) => JSON.stringify(v, null, 2);

/** Every client gets the same endpoint and header; only the config shape differs. */
const CLIENTS = [
	{
		id: 'ask', label: 'Ask your agent', head: 'Paste this into your agent\'s chat',
		code: (c) => `Add this MCP server to your config for this project, reload, then call get_site_brief to check it works.

Name: ${c.name}
Type: remote, Streamable HTTP
URL: ${c.url}
Header: Authorization: ${c.auth}

The header is a password: keep it in a local config that is never committed to git, and don't repeat it back. If you can't add servers yourself, tell me where to paste it.`,
		hint: <>Works with any agent that can edit its own settings. The password stays in that chat's history, so revoke it here if you share the chat.</>,
	},
	{
		id: 'claude', label: 'Claude Code', head: 'Run this in your terminal',
		code: (c) => `claude mcp add --transport http ${c.name} ${c.url} --header "Authorization: ${c.auth}"`,
		hint: <>Add <code>--scope user</code> to use this site from every project. Then run <code>/mcp</code> in Claude Code.</>,
	},
	{
		id: 'codex', label: 'Codex', head: 'Add to ~/.codex/config.toml',
		code: (c) => `[mcp_servers.${c.name}]\nurl = "${c.url}"\nhttp_headers = { "Authorization" = "${c.auth}" }`,
		hint: <>Restart Codex, then run <code>/mcp</code> to check the connection.</>,
	},
	{
		id: 'cursor', label: 'Cursor', head: 'Add to .cursor/mcp.json',
		code: (c) => json({ mcpServers: { [c.name]: { url: c.url, headers: { Authorization: c.auth } } } }),
		hint: <>Use <code>~/.cursor/mcp.json</code> for every project. Windsurf takes the same file, with <code>serverUrl</code> in place of <code>url</code>.</>,
	},
	{
		id: 'vscode', label: 'VS Code', head: 'Add to .vscode/mcp.json',
		code: (c) => json({ servers: { [c.name]: { type: 'http', url: c.url, headers: { Authorization: c.auth } } } }),
		hint: <>For GitHub Copilot agent mode. Start the server from the file or the MCP view.</>,
	},
	{
		id: 'gemini', label: 'Gemini CLI', head: 'Add to ~/.gemini/settings.json',
		code: (c) => json({ mcpServers: { [c.name]: { httpUrl: c.url, headers: { Authorization: c.auth } } } }),
		hint: <>Then run <code>/mcp</code> in Gemini CLI.</>,
	},
	{
		id: 'bridge', label: 'Claude Desktop', head: 'Add to claude_desktop_config.json',
		code: (c) => json({ mcpServers: { [c.name]: { command: 'npx', args: ['-y', 'mcp-remote', c.url, '--header', 'Authorization:${AUTH}'], env: { AUTH: c.auth } } } }),
		hint: <>Runs through the <code>mcp-remote</code> bridge (needs Node). Works for any client that only starts local servers.</>,
	},
	{
		id: 'other', label: 'Other', head: 'Any MCP client with Streamable HTTP',
		code: (c) => `URL:     ${c.url}\nHeader:  Authorization: ${c.auth}`,
		hint: <>Remote HTTP transport, with the header sent on every request.</>,
	},
];

export function CommandBox({ conn, onDone }) {
	const { toast } = useStore();
	const [tab, setTab] = useState('ask');
	const [copied, setCopied] = useState(false);
	const client = CLIENTS.find((c) => c.id === tab);
	const code = client.code(conn);
	const pre = useRef(null);
	const copy = async () => {
		if (await copyText(code)) {
			setCopied(true);
			setTimeout(() => setCopied(false), 1600);
			return;
		}
		// Clipboard blocked: select the text so Ctrl+C works.
		const r = document.createRange();
		r.selectNodeContents(pre.current);
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange(r);
		toast('Copying is blocked in this browser. The text is selected: press Ctrl+C (Cmd+C on a Mac).', 'error');
	};
	return (
		<div className="cmd" role="region" aria-label="Connection details">
			<div className="cmd__tabs" role="tablist" aria-label="Agent">
				{CLIENTS.map((c) => (
					<button key={c.id} type="button" role="tab" aria-selected={tab === c.id} className={'cmd__tab' + (tab === c.id ? ' is-on' : '')} onClick={() => { setTab(c.id); setCopied(false); }}>{c.label}</button>
				))}
			</div>
			<div className="cmd__head">
				<Icon name="terminal" className="ic--accent" />
				<b>{client.head}</b>
				<span className="pill">Shown once</span>
			</div>
			<pre ref={pre} tabIndex={0} aria-label={client.head}>{code}</pre>
			<div className="cmd__foot">
				<Button variant="accent" icon={copied ? 'check' : 'copy'} onClick={copy}>{copied ? 'Copied' : 'Copy'}</Button>
				<span>{client.hint}</span>
				<span className="top__sp" />
				<Button variant="ghost" size="sm" onClick={onDone}>Done</Button>
			</div>
		</div>
	);
}

export function ConnectPanel({ data, connect }) {
	if (connect.conn) return <CommandBox conn={connect.conn} onDone={connect.clear} />;
	if (!data.passwords) {
		return (
			<p className="alert alert--error">
				<Icon name="alert" size={16} />
				<span>Application Passwords are unavailable. They need HTTPS (or <code>WP_ENVIRONMENT_TYPE</code> set to <code>local</code>) and must not be switched off by a security plugin.</span>
			</p>
		);
	}
	return (
		<>
			<p className="lead">
				Creates an Application Password for <b>{data.user}</b> and shows how to connect your agent: Claude Code, Codex, Cursor, VS Code, Gemini CLI or any other MCP client. The password only opens the MCP endpoint, never the rest of the REST API.
			</p>
			<div className="btnrow">
				<Button variant="primary" icon="plug" loading={connect.busy} onClick={connect.generate}>Generate connection</Button>
			</div>
		</>
	);
}
