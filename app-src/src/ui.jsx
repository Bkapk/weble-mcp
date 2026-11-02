import { copyText } from './api.js';
import { useEffect, useId, useRef, useState } from 'react';

/* ---------- icons: one 24px grid, 1.7 stroke ---------- */

const P = {
	scan: 'M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2M4 12h16',
	check: 'M5 12.5l4.5 4.5L19 7.5',
	x: 'M6 6l12 12M18 6L6 18',
	plus: 'M12 5v14M5 12h14',
	search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14ZM20 20l-4-4',
	chevron: 'M6 9l6 6 6-6',
	right: 'M9 6l6 6-6 6',
	lock: 'M7 11V8a5 5 0 0 1 10 0v3M5 11h14v9H5z',
	spark: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3ZM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16Z',
	shield: 'M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3Z',
	globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3Z',
	doc: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5ZM14 3v5h5M9 13h6M9 17h6',
	ext: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
	copy: 'M9 9h10v10H9zM5 15V5h10',
	download: 'M12 4v11M7 10l5 5 5-5M5 20h14',
	trash: 'M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
	eye: 'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12ZM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
	refresh: 'M20 11a8 8 0 0 0-14.9-3M4 4v4h4M4 13a8 8 0 0 0 14.9 3M20 20v-4h-4',
	desktop: 'M3 5h18v11H3zM8 20h8M12 16v4',
	mobile: 'M7 3h10v18H7zM11 18h2',
	alert: 'M12 9v4M12 17h.01M10.3 3.9 2.5 17.5A2 2 0 0 0 4.2 20.5h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z',
	print: 'M7 9V3h10v6M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v7H7z',
	image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15 9.5a1 1 0 1 0 0-.01',
	mail: 'M4 6h16v12H4zM4 7l8 6 8-6',
	send: 'M21 3 10 14M21 3l-7 18-4-7-7-4 18-7Z',
	inbox: 'M3 13h5l1.5 3h5L16 13h5M5 5h14l2 8v6H3v-6l2-8Z',
	route: 'M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM18 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4ZM6 15V9a4 4 0 0 1 4-4h6M18 9v6a4 4 0 0 1-4 4H8',
	users: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 7M22 21a7 7 0 0 0-4-6.3',
	bolt: 'M13 2 4 14h7l-1 8 9-12h-7l1-8Z',
	link: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
	key: 'M14.5 13a5 5 0 1 0-4.6-3L3 17v4h4v-2h2v-2h2l1.9-1.9A5 5 0 0 0 14.5 13ZM16 7.5h.01',
	clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM12 7v5l3 2',
	up: 'M12 19V5M6 11l6-6 6 6',
	down: 'M12 5v14M6 13l6 6 6-6',
	grip: 'M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01',
	pause: 'M9 5v14M15 5v14',
	plug: 'M7.5 20.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM9.9 15.8l5.2-4.4M17.5 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
	terminal: 'M4 5h16v14H4zM8 10l3 2.5L8 15M13 15h3',
	code: 'M9 8l-4 4 4 4M15 8l4 4-4 4',
	layout: 'M4 4h16v16H4zM4 9h16M9 9v11',
	book: 'M5 4h10a4 4 0 0 1 4 4v12H9a4 4 0 0 1-4-4V4ZM5 16a4 4 0 0 1 4-4h10',
};

export function Icon({ name, size = 18, className = '' }) {
	return (
		<svg className={'ic ' + className} viewBox="0 0 24 24" width={size} height={size} aria-hidden="true" focusable="false">
			<path d={P[name]} fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" />
		</svg>
	);
}

export function Mark({ size = 28 }) {
	return (
		<svg className="mark" viewBox="0 0 32 32" width={size} height={size} aria-hidden="true">
			<rect width="32" height="32" rx="9" fill="#0A0A0A" />
			<path d="M10.5 24a3.3 3.3 0 1 0 0-6.6 3.3 3.3 0 0 0 0 6.6ZM13.2 18.9l5.6-4.8" fill="none" stroke="#FAF9F6" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
			<circle cx="21.5" cy="11.5" r="3.6" fill="#F28D35" />
		</svg>
	);
}

/* ---------- controls ---------- */

export function Button({ variant = 'default', size, icon, loading, children, className = '', ...rest }) {
	return (
		<button type="button" className={`btn btn--${variant} ${size ? 'btn--' + size : ''} ${loading ? 'is-loading' : ''} ${className}`} disabled={loading || rest.disabled} {...rest}>
			{loading ? <span className="spin" aria-hidden="true" /> : icon ? <Icon name={icon} size={16} /> : null}
			{children ? <span>{children}</span> : null}
		</button>
	);
}

export function Switch({ checked, onChange, label, disabled, description }) {
	const id = useId();
	return (
		<label className={'switch-row' + (disabled ? ' is-disabled' : '')} htmlFor={id}>
			<span className="switch">
				<input id={id} type="checkbox" role="switch" checked={!!checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
				<span className="switch__track" aria-hidden="true" />
			</span>
			{label ? (
				<span className="switch-row__text">
					<span className="switch-row__label">{label}</span>
					{description ? <span className="hint">{description}</span> : null}
				</span>
			) : null}
		</label>
	);
}

export function Field({ label, hint, children, error, wide }) {
	const id = useId();
	const child = typeof children === 'function' ? children(id) : children;
	return (
		<div className={'field' + (wide ? ' field--wide' : '')}>
			{label ? <label className="field__label" htmlFor={id}>{label}</label> : null}
			{child}
			{error ? <p className="field__error">{error}</p> : hint ? <p className="hint">{hint}</p> : null}
		</div>
	);
}

export function Text({ value, onChange, ...rest }) {
	return <input className="input" type="text" value={value ?? ''} onChange={(e) => onChange(e.target.value)} {...rest} />;
}

export function TextArea({ value, onChange, rows = 3, ...rest }) {
	return <textarea className="input input--area" rows={rows} value={value ?? ''} onChange={(e) => onChange(e.target.value)} {...rest} />;
}

export function Select({ value, onChange, options, ...rest }) {
	return (
		<span className="select">
			<select className="input" value={value ?? ''} onChange={(e) => onChange(e.target.value)} {...rest}>
				{options.map((o) => (
					<option key={o.value} value={o.value}>{o.label}</option>
				))}
			</select>
			<Icon name="chevron" size={16} />
		</span>
	);
}

export function Segmented({ value, onChange, options, label }) {
	return (
		<div className="seg" role="radiogroup" aria-label={label}>
			{options.map((o) => (
				<button key={o.value} type="button" role="radio" aria-checked={value === o.value} className={'seg__opt' + (value === o.value ? ' is-on' : '')} onClick={() => onChange(o.value)}>
					{o.icon ? <Icon name={o.icon} size={16} /> : null}
					{o.label}
				</button>
			))}
		</div>
	);
}

export function Pill({ tone = 'neutral', children }) {
	return <span className={'pill pill--' + tone}>{children}</span>;
}

export function Empty({ icon = 'mail', title, children, action }) {
	return (
		<div className="empty">
			<span className="empty__icon"><Icon name={icon} size={22} /></span>
			<p className="empty__title">{title}</p>
			{children ? <p className="empty__text">{children}</p> : null}
			{action}
		</div>
	);
}

export function Skeleton({ rows = 4 }) {
	return (
		<div className="skel" aria-busy="true" aria-label="Loading">
			{Array.from({ length: rows }).map((_, i) => <span key={i} style={{ width: `${92 - i * 11}%` }} />)}
		</div>
	);
}

export function Panel({ title, aside, children, className = '', flush }) {
	return (
		<section className={'panel ' + (flush ? 'panel--flush ' : '') + className}>
			{title || aside ? (
				<header className="panel__head">
					{title ? <h2 className="panel__title">{title}</h2> : <span />}
					{aside}
				</header>
			) : null}
			{children}
		</section>
	);
}

export function PageHead({ title, sub, actions }) {
	return (
		<header className="pagehead">
			<div>
				<h1 className="pagehead__title">{title}</h1>
				{sub ? <p className="pagehead__sub">{sub}</p> : null}
			</div>
			{actions ? <div className="pagehead__actions">{actions}</div> : null}
		</header>
	);
}

/** Side drawer built on <dialog> (focus trap, Esc and top layer come from the platform). */
export function Drawer({ open, onClose, title, children, footer, wide }) {
	const ref = useRef(null);
	useEffect(() => {
		const d = ref.current;
		if (!d) return;
		if (open && !d.open) d.showModal();
		if (!open && d.open) d.close();
	}, [open]);
	return (
		<dialog ref={ref} className={'drawer wbmcp-root' + (wide ? ' drawer--wide' : '')} onClose={onClose} onMouseDown={(e) => { if (e.target === ref.current) onClose(); }}>
			{open ? (
				<div className="drawer__panel">
					<header className="drawer__head">
						<h2>{title}</h2>
						<button type="button" className="iconbtn" onClick={onClose} aria-label="Close"><Icon name="x" /></button>
					</header>
					<div className="drawer__body">{children}</div>
					{footer ? <footer className="drawer__foot">{footer}</footer> : null}
				</div>
			) : null}
		</dialog>
	);
}

/** Promise-based confirm: const ok = await confirm({ title, body, action }). */
let confirmImpl = null;
export const confirm = (opts) => (confirmImpl ? confirmImpl(opts) : Promise.resolve(window.confirm(opts.title)));

export function ConfirmHost() {
	const ref = useRef(null);
	const [opts, setOpts] = useState(null);
	const resolver = useRef(null);
	useEffect(() => {
		confirmImpl = (o) => new Promise((res) => {
			resolver.current = res;
			setOpts(o);
		});
		return () => { confirmImpl = null; };
	}, []);
	useEffect(() => {
		if (opts && ref.current && !ref.current.open) ref.current.showModal();
	}, [opts]);
	const done = (v) => {
		if (ref.current?.open) ref.current.close();
		setOpts(null);
		resolver.current?.(v);
	};
	return (
		<dialog ref={ref} className="confirm wbmcp-root" onClose={() => opts && done(false)}>
			{opts ? (
				<div className="confirm__panel">
					<h2>{opts.title}</h2>
					{opts.body ? <p>{opts.body}</p> : null}
					<div className="confirm__actions">
						<Button onClick={() => done(false)}>Cancel</Button>
						<Button variant={opts.danger ? 'danger' : 'primary'} onClick={() => done(true)} autoFocus>{opts.action || 'Confirm'}</Button>
					</div>
				</div>
			) : null}
		</dialog>
	);
}

export function Copy({ text }) {
	const [state, setState] = useState('');
	const copy = async () => {
		setState((await copyText(text)) ? 'done' : 'fail');
		setTimeout(() => setState(''), 1800);
	};
	return (
		<button type="button" className="copy" onClick={copy} title={text}>
			<code>{text}</code>
			<span className="copy__state" aria-live="polite">
				{state === 'done' ? <><Icon name="check" size={14} /> Copied</> : state === 'fail' ? <><Icon name="alert" size={14} /> Select and copy</> : <><Icon name="copy" size={14} /> Copy</>}
			</span>
		</button>
	);
}

/** Sticky save bar shown while a form is dirty. */
export function SaveBar({ dirty, saving, onSave, onReset }) {
	if (!dirty) return null;
	return (
		<div className="savebar" role="region" aria-label="Unsaved changes">
			<span className="savebar__dot" aria-hidden="true" />
			<span>Unsaved changes</span>
			<span className="savebar__sp" />
			<Button variant="ghost-inv" onClick={onReset} disabled={saving}>Discard</Button>
			<Button variant="accent" onClick={onSave} loading={saving}>Save changes</Button>
		</div>
	);
}

/** Local draft of the settings, with dirty tracking against the store copy. */
export function useDraft(source, keys) {
	const pick = (s) => Object.fromEntries(keys.map((k) => [k, s ? s[k] : undefined]));
	const [draft, setDraft] = useState(() => pick(source));
	const base = pick(source);
	useEffect(() => { setDraft(pick(source)); }, [source]); // eslint-disable-line react-hooks/exhaustive-deps
	const dirty = keys.some((k) => JSON.stringify(draft[k]) !== JSON.stringify(base[k]));
	const set = (k) => (v) => setDraft((d) => ({ ...d, [k]: v }));
	return { draft, setDraft, set, dirty, reset: () => setDraft(base) };
}


/** Inline result line for test buttons. */
export function Result({ r }) {
	if (!r || r.busy) return null;
	return <p className={'alert ' + (r.ok ? 'alert--ok' : 'alert--error')}><Icon name={r.ok ? 'check' : 'alert'} size={16} />{r.msg}</p>;
}
