import { useState } from 'react';
import { timeAgo, useStore } from '../store.jsx';
import { Button, Copy, Icon, PageHead, Panel } from '../ui.jsx';
import { ConnectPanel, useConnect } from './Connect.jsx';

const FLOW = [
	['Read the brief', <>The agent calls <code>get_site_brief</code> first: setup, content model, design system and the house style measured from your pages.</>],
	['Plan and ask', 'It tells you what it will change and where, and waits for your yes. It only works on its own when you say so.'],
	['Build', 'Pages, templates and posts go through the kit\'s global colors and fonts. New posts start as drafts.'],
	['Lint', <><code>elementor_lint</code> catches rows that never stack, off-palette colors, low contrast and missing alt text.</>],
	['Look', <><code>get_preview_url</code> gives a signed link the agent screenshots at 1440, 768 and 390 px.</>],
];

export const ruleCount = (text) => text.split('\n').filter((l) => l.trim()).length;

export function Overview() {
	const { data, saveSettings, toast } = useStore();
	const connect = useConnect();
	const [busy, setBusy] = useState(false);
	const s = data.settings;
	const conns = data.connections;
	const lastUsed = Math.max(0, ...conns.map((c) => c.last_used));
	const el = data.elementor;
	const rules = ruleCount(s.house_rules);

	const codeOff = async () => {
		setBusy(true);
		try {
			await saveSettings({ custom_code: false });
			toast('Custom code is off. Scripts, iframes and forms are rejected again.');
		} catch (e) {
			toast(e.message, 'error');
		}
		setBusy(false);
	};

	return (
		<>
			<PageHead title="Overview" sub={data.site.name + ' · ' + data.site.host} />

			{s.custom_code ? (
				<div className="callout callout--warn">
					<span className="callout__icon"><Icon name="code" /></span>
					<p className="callout__text"><b>Custom code is on</b><span>Anyone holding a connection password can put JavaScript on this site. Switch it off when the work is done.</span></p>
					<Button onClick={codeOff} loading={busy}>Switch off</Button>
				</div>
			) : null}

			<section className="status" aria-label="Status">
				<div className="status__cell">
					<span className="status__k">Connections</span>
					<span className="status__v">{conns.length ? conns.length + (conns.length === 1 ? ' connection' : ' connections') : 'None yet'}</span>
					<a className="status__a" href="#/connections">{conns.length ? 'Last used ' + timeAgo(lastUsed).toLowerCase() : 'Generate one below'}</a>
				</div>
				<div className="status__cell">
					<span className="status__k">Custom code</span>
					<span className="status__v">{s.custom_code ? <><Icon name="alert" size={16} className="ic--accent" /> On</> : <><Icon name="shield" size={16} /> Off</>}</span>
					<a className="status__a" href="#/settings">{s.custom_code ? 'Scripts allowed' : 'Scripts and forms rejected'}</a>
				</div>
				<div className="status__cell">
					<span className="status__k">Elementor</span>
					<span className="status__v">{el.active ? (el.pro ? 'Pro ' + el.pro : 'Free ' + el.version) : 'Not active'}</span>
					<a className="status__a" href="#/tools">{el.active ? (el.pro ? 'All Elementor tools on' : 'Theme builder needs Pro') : 'Elementor tools hidden'}</a>
				</div>
				<div className="status__cell">
					<span className="status__k">House rules</span>
					<span className="status__v">{rules ? rules + (rules === 1 ? ' rule' : ' rules') : 'None yet'}</span>
					<a className="status__a" href="#/rules">{rules ? 'Edit rules' : 'Add your first rule'}</a>
				</div>
			</section>

			<div className="grid grid--dash">
				<Panel title="Connect an agent">
					<ConnectPanel data={data} connect={connect} />
					<div className="field">
						<span className="field__label">Endpoint</span>
						<Copy text={data.endpoint} />
					</div>
				</Panel>
				<Readiness data={data} />
			</div>

			<ElementorPanel el={data.elementor} />

			<Panel title="How a session works" aside={<a className="link" href="#/tools">See all {data.tools.length} tools <Icon name="right" size={14} /></a>}>
				<ol className="flow">
					{FLOW.map(([t, d], i) => (
						<li key={t}>
							<span className="flow__n">{i + 1}</span>
							<b>{t}</b>
							<span>{d}</span>
						</li>
					))}
				</ol>
			</Panel>
		</>
	);
}

function Readiness({ data }) {
	const el = data.elementor;
	const checks = [
		data.passwords
			? ['ok', 'Application Passwords', 'Available for your account.']
			: ['err', 'Application Passwords', 'Unavailable. Needs HTTPS or a local environment, and no security plugin blocking them.'],
		data.site.https || data.local
			? ['ok', data.site.https ? 'HTTPS' : 'Local environment', data.site.https ? 'Connection passwords travel encrypted.' : 'HTTPS is not needed on a local site.']
			: ['err', 'HTTPS', 'The site is not on HTTPS, so passwords would travel in the clear.'],
		data.plainPermalinks
			? ['warn', 'Plain permalinks', 'It works, through the ?rest_route= URL shown here. Pretty permalinks give a cleaner endpoint.']
			: ['ok', 'Permalinks', 'The REST API has its normal /wp-json/ address.'],
		el.active
			? [el.kit ? 'ok' : 'warn', 'Elementor kit', el.kit ? 'Found. The agent styles through its global colors and fonts.' : 'No active kit, so there are no global colors or fonts to build with.']
			: ['warn', 'Elementor', 'Not active. Content, media, menu and settings tools still work.'],
	];
	const bad = checks.filter((c) => c[0] !== 'ok').length;
	return (
		<Panel title="Site readiness" aside={<span className="muted">{bad ? bad + ' to look at' : 'All good'}</span>}>
			<ul className="checks">
				{checks.map(([tone, title, text]) => (
					<li key={title} className={'is-' + tone}>
						<span className="checks__dot"><Icon name={tone === 'ok' ? 'check' : 'alert'} size={13} /></span>
						<span className="checks__t"><b>{title}</b><span>{text}</span></span>
					</li>
				))}
				<li>
					<span className="checks__dot"><Icon name="desktop" size={13} /></span>
					<span className="checks__t"><b>Visual checks</b><span>The agent looks at each page on desktop, tablet and phone. If it can't open a browser, it tells you how to add one and sends you a preview link meanwhile.</span></span>
				</li>
			</ul>
		</Panel>
	);
}

function ElementorPanel({ el }) {
	const points = [
		['Designs straight into Elementor', 'Describe a page, or paste a design, and the agent builds it as real Elementor containers and widgets you can keep editing by hand.'],
		["In your site's style", "It reads your existing pages first and reuses their spacing, type scale and classes, with colors and fonts from your kit. On a new site it can set up the kit's colors and fonts first."],
		['Theme builder and dynamic content', el.pro ? 'Headers, footers, single and archive templates, loop grids and ACF fields through dynamic tags.' : 'Headers, footers, templates and dynamic tags need Elementor Pro. Pages and saved templates work on the free version.'],
		['Custom CSS in the right place', el.pro ? "In each page's Advanced > Custom CSS, aimed at classes the agent sets on the elements." : 'Without Elementor Pro, in its own block of Appearance > Customize > Additional CSS, limited to that page.'],
	];
	return (
		<Panel title="Elementor" aside={<span className="muted">{el.active ? (el.pro ? 'Pro ' + el.pro : 'Free ' + el.version) : 'Not active'}</span>}>
			{el.active ? null : <p className="alert alert--warn"><Icon name="alert" size={16} />Elementor is not active, so the agent builds with the block editor content tools only. Activate Elementor to unlock the points below.</p>}
			<ul className="checks checks--cols">
				{points.map(([t, d]) => (
					<li key={t} className={el.active ? 'is-ok' : ''}>
						<span className="checks__dot"><Icon name="layout" size={13} /></span>
						<span className="checks__t"><b>{t}</b><span>{d}</span></span>
					</li>
				))}
			</ul>
		</Panel>
	);
}
