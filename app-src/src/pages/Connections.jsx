import { api } from '../api.js';
import { fmtDate, timeAgo, useStore } from '../store.jsx';
import { Button, confirm, Empty, PageHead, Panel } from '../ui.jsx';
import { CommandBox, ConnectPanel, useConnect } from './Connect.jsx';

export function Connections() {
	const { data, patch, toast } = useStore();
	const connect = useConnect();
	const rows = data.connections;

	const revoke = async (c) => {
		const ok = await confirm({
			title: `Revoke "${c.name}"?`,
			body: 'Any agent using it loses access to this site straight away. You can generate a new connection at any time.',
			action: 'Revoke',
			danger: true,
		});
		if (!ok) return;
		try {
			const res = await api(`connections/${c.user_id}/${c.uuid}`, { method: 'DELETE' });
			patch({ connections: res.connections });
			toast('Connection revoked.');
		} catch (e) {
			toast(e.message, 'error');
		}
	};

	return (
		<>
			<PageHead
				title="Connections"
				sub="Each connection is its own Application Password, scoped to the MCP endpoint. Revoke one and only that session stops working."
				actions={rows.length && data.passwords && !connect.conn ? <Button variant="primary" icon="plus" loading={connect.busy} onClick={connect.generate}>New connection</Button> : null}
			/>

			{connect.conn ? <div style={{ marginBottom: 16 }}><CommandBox conn={connect.conn} onDone={connect.clear} /></div> : null}

			{!rows.length ? (
				<Panel>
					<Empty
						icon="plug"
						title="No connections yet"
						action={connect.conn ? null : data.passwords ? <Button variant="primary" icon="plug" loading={connect.busy} onClick={connect.generate}>Generate connection</Button> : <ConnectPanel data={data} connect={connect} />}
					>
						Generate one and add it to your agent: Claude Code, Codex, Cursor, VS Code or any MCP client. The agent then talks to this site directly, through a password that only opens the MCP endpoint.
					</Empty>
				</Panel>
			) : (
				<div className="tablewrap">
					<table className="table table--stack">
						<thead>
							<tr><th>Name</th><th>User</th><th>Created</th><th>Last used</th><th>Last IP</th><th /></tr>
						</thead>
						<tbody>
							{rows.map((c) => (
								<tr key={c.uuid}>
									<td className="table__name"><b>{c.name}</b></td>
									<td data-label="User">{c.user}</td>
									<td data-label="Created" className="nowrap">{fmtDate(c.created)}</td>
									<td data-label="Last used" className="nowrap" title={fmtDate(c.last_used)}>{timeAgo(c.last_used)}</td>
									<td data-label="Last IP"><code>{c.last_ip || '—'}</code></td>
									<td className="table__act" style={{ textAlign: 'right' }}>
										{c.can_revoke ? <Button variant="danger-ghost" size="sm" onClick={() => revoke(c)}>Revoke</Button> : null}
									</td>
								</tr>
							))}
						</tbody>
					</table>
				</div>
			)}
		</>
	);
}
