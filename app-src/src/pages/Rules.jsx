import { useState } from 'react';
import { useStore } from '../store.jsx';
import { PageHead, Panel, SaveBar, TextArea, useDraft } from '../ui.jsx';
import { ruleCount } from './Overview.jsx';

const MAX = 5000;
const EXAMPLES = ['Buttons are pill-shaped', 'Never use pure black', 'Hero headings are left-aligned', 'Sections use 120px top and bottom padding on desktop', 'Write in British English', 'Every page ends with the contact CTA section'];

export function Rules() {
	const { data, saveSettings, toast } = useStore();
	const { draft, set, dirty, reset } = useDraft(data.settings, ['house_rules']);
	const [saving, setSaving] = useState(false);
	const text = draft.house_rules || '';

	const add = (rule) => set('house_rules')((text.trim() ? text.replace(/\s+$/, '') + '\n' : '') + rule);

	const save = async () => {
		setSaving(true);
		try {
			await saveSettings({ house_rules: text });
			toast('House rules saved. The agent reads them at the start of every session.');
		} catch (e) {
			toast(e.message, 'error');
		}
		setSaving(false);
	};

	return (
		<>
			<PageHead title="House rules" sub="Standing design and content rules for this site. They come with every site brief and override the house style the agent measures from your pages." />
			<Panel>
				<TextArea
					className="input input--area rules__area"
					value={text}
					onChange={set('house_rules')}
					rows={14}
					maxLength={MAX}
					aria-label="House rules"
					placeholder={'One rule per line, for example:\nButtons are pill-shaped\nNever use pure black'}
				/>
				<div className="rules__meta">
					<span>{ruleCount(text)} {ruleCount(text) === 1 ? 'rule' : 'rules'} · You can also ask your agent to remember a rule; it saves it here.</span>
					<span className="nowrap">{text.length.toLocaleString()} / {MAX.toLocaleString()}</span>
				</div>
			</Panel>
			<Panel title="Examples">
				<div className="examples">
					{EXAMPLES.map((e) => <button key={e} type="button" onClick={() => add(e)}>+ {e}</button>)}
				</div>
			</Panel>
			<SaveBar dirty={dirty} saving={saving} onSave={save} onReset={reset} />
		</>
	);
}
