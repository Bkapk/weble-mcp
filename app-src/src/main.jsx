import '@fontsource-variable/onest';
import '@fontsource-variable/funnel-display';
import './app.css';
import { createRoot } from 'react-dom/client';
import { App } from './App.jsx';
import { StoreProvider } from './store.jsx';

const el = document.getElementById('wbmcp-app');
if (el) {
	createRoot(el).render(
		<StoreProvider>
			<App />
		</StoreProvider>
	);
}
