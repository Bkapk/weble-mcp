import { useState } from 'react';
import { useStore } from '../store.jsx';
import { Icon, PageHead, Pill, Segmented } from '../ui.jsx';

const ORDER = ['Start', 'Content', 'Media and menus', 'Site and design system', 'Elementor', 'Verify', 'Audit', 'Other'];

export function Tools() {
	const { data } = useStore();
	const [show, setShow] = useState('all');
	const tools = data.tools.filter((t) => show === 'all' || (show === 'writes') === t.writes);
	const groups = ORDER.map((g) => [g, tools.filter((t) => t.group === g)]).filter(([, list]) => list.length);
	const writes = data.tools.filter((t) => t.writes).length;

	return (
		<>
			<PageHead
				title="Tools"
				sub="What a connected agent can do on this site. Every call is checked against the connected user's WordPress role. There are no tools for plugins, themes, users or files."
				actions={
					<Segmented
						label="Filter tools"
						value={show}
						onChange={setShow}
						options={[
							{ value: 'all', label: `All ${data.tools.length}` },
							{ value: 'reads', label: `Read ${data.tools.length - writes}` },
							{ value: 'writes', label: `Change ${writes}` },
						]}
					/>
				}
			/>
			<div className="grid">
				{groups.map(([g, list]) => (
					<section key={g} className="panel toolgroup">
						<header className="toolgroup__head">
							<h2>{g}</h2>
							{g === 'Elementor' && !data.elementor.active ? <Pill tone="warn">Needs Elementor</Pill> : <span className="muted">{list.length}</span>}
						</header>
						{list.map((t) => (
							<div key={t.name} className={'tool' + (t.available ? '' : ' is-off')}>
								<code>{t.name}</code>
								<p>{t.summary}</p>
								{t.writes ? <Pill tone="accent">Changes</Pill> : <Pill><Icon name="eye" size={12} />Reads</Pill>}
							</div>
						))}
					</section>
				))}
			</div>
		</>
	);
}
