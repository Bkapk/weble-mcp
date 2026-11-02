import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Builds straight into the plugin so the zip ships ready to run. Fixed names: PHP enqueues app.js.
export default defineConfig({
	plugins: [react()],
	base: './',
	build: {
		outDir: '../weble-mcp/assets/app',
		emptyOutDir: true,
		cssTarget: 'chrome100', // lowers CSS nesting, so every rule stays scoped under .wbmcp-root
		rollupOptions: {
			input: 'src/main.jsx',
			output: {
				format: 'iife', // enqueued as a classic script: nothing may leak into wp-admin globals (e.g. underscore's `_`)
				entryFileNames: 'app.js', // CSS is inlined into app.js in iife mode; fonts load relative to the script
				assetFileNames: (a) => (a.names?.[0] || a.name || '').endsWith('.css') ? 'app.css' : 'fonts/[name][extname]',
			},
		},
	},
});
