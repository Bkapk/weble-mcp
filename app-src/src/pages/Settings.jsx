import { useState } from 'react';
import { useStore } from '../store.jsx';
import { confirm, Copy, Field, Icon, PageHead, Panel, Switch } from '../ui.jsx';

export function Settings() {
	const { data, saveSettings, toast } = useStore();
	const [busy, setBusy] = useState(false);
	const on = data.settings.custom_code;

	const setCode = async (v) => {
		if (v && !(await confirm({
			title: 'Allow custom code?',
			body: 'Agents can then add scripts, iframes, embeds and forms. Anyone holding a connection password could put JavaScript on this site. Switch it off again when the work is done.',
			action: 'Allow custom code',
			danger: true,
		}))) return;
		setBusy(true);
		try {
			await saveSettings({ custom_code: v });
			toast(v ? 'Custom code is on. Remember to switch it off afterwards.' : 'Custom code is off.');
		} catch (e) {
			toast(e.message, 'error');
		}
		setBusy(false);
	};

	const setDelete = async (v) => {
		setBusy(true);
		try {
			await saveSettings({ delete_data: v });
			toast(v ? 'Deleting the plugin will now remove its data.' : 'Deleting the plugin will keep its data.');
		} catch (e) {
			toast(e.message, 'error');
		}
		setBusy(false);
	};

	return (
		<>
			<PageHead title="Settings" />
			<Panel title="Custom code">
				<Switch
					checked={on}
					disabled={busy || (!on && !data.canCode)}
					onChange={setCode}
					label="Allow scripts, iframes, embeds and forms in what the agent writes"
					description="Needed for custom HTML sections with JavaScript and GSAP animations. HTML markup and CSS are always allowed."
				/>
				{!data.canCode ? <p className="alert alert--warn"><Icon name="lock" size={16} />Your account cannot post unfiltered HTML, so it cannot switch custom code on.</p> : null}
				{on ? <p className="alert alert--warn"><Icon name="alert" size={16} />While this is on, anyone holding a connection password can put JavaScript on the site.</p> : null}
			</Panel>

			<Panel title="Endpoint">
				<Field label="MCP endpoint" hint={data.plainPermalinks ? 'Plain permalinks are on, so the endpoint uses ?rest_route=. That works.' : 'Streamable HTTP, JSON responses. Clients that ask for a server stream get 405, as the spec allows.'}>
					{() => <Copy text={data.endpoint} />}
				</Field>
			</Panel>

			<Panel title="Deactivating and deleting">
				<Switch
					checked={data.settings.delete_data}
					disabled={busy}
					onChange={setDelete}
					label="Delete plugin data when the plugin is deleted"
					description="Off by default. When on, deleting the plugin removes the house rules, these settings and the Elementor undo snapshots. Your pages, posts and kit are never touched."
				/>
				<p className="hint"><Icon name="lock" size={14} />Deactivating the plugin always revokes every connection, so no password outlives it. Reactivate and generate a new one to reconnect.</p>
			</Panel>

			<Panel title="What is never possible">
				<ul className="checks">
					{[
						['Plugins, themes, users and files', 'No tool installs, edits or deletes them. get_site_audit can read them for administrators, with secrets redacted.'],
						['The rest of the REST API', 'Connection passwords are rejected everywhere except the MCP endpoint, and on XML-RPC.'],
						['Permanent deletes', 'Deleting a post moves it to the trash. The last 5 Elementor writes per page can be undone.'],
					].map(([t, d]) => (
						<li key={t} className="is-ok">
							<span className="checks__dot"><Icon name="shield" size={13} /></span>
							<span className="checks__t"><b>{t}</b><span>{d}</span></span>
						</li>
					))}
				</ul>
			</Panel>
		</>
	);
}
