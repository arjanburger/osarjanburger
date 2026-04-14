// @ts-check
import { defineConfig } from 'astro/config';
import fs from 'node:fs/promises';
import path from 'node:path';

/**
 * Dev-only middleware die content aanpassingen opslaat naar src/content/{page}.json.
 * Wordt aangeroepen door public/edit-mode.js op blur van een contenteditable veld.
 *
 * Alleen beschikbaar in `astro dev` — in productie bestaat dit endpoint niet.
 */
function saveContentPlugin() {
  return {
    name: 'flow-save-content-api',
    configureServer(server) {
      server.middlewares.use('/api/save-content', async (req, res, next) => {
        if (req.method !== 'POST') return next();

        let body = '';
        req.on('data', (chunk) => { body += chunk; });
        req.on('end', async () => {
          try {
            const { page, key, value } = JSON.parse(body);

            // Whitelist: alleen bestaande content files toestaan
            const validPages = ['doorbraak', 'doorbraakexclusive'];
            if (!validPages.includes(page)) {
              res.writeHead(400, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ error: 'Invalid page' }));
              return;
            }
            if (typeof key !== 'string' || typeof value !== 'string') {
              res.writeHead(400, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({ error: 'Key and value must be strings' }));
              return;
            }

            const filePath = path.join(process.cwd(), 'src', 'content', `${page}.json`);
            const data = JSON.parse(await fs.readFile(filePath, 'utf-8'));

            // Geen nieuwe keys aanmaken — typo's moeten direct zichtbaar zijn
            if (!(key in data)) {
              res.writeHead(400, { 'Content-Type': 'application/json' });
              res.end(JSON.stringify({
                error: `Unknown content key "${key}" for page "${page}". Alleen updates op bestaande keys zijn toegestaan.`,
              }));
              return;
            }

            data[key] = value;
            await fs.writeFile(filePath, JSON.stringify(data, null, 2) + '\n', 'utf-8');

            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: true }));
          } catch (err) {
            res.writeHead(500, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: String(err) }));
          }
        });
      });
    },
  };
}

export default defineConfig({
  site: 'https://flow.arjanburger.com',
  server: { port: 18200, host: '127.0.0.1' },
  build: { format: 'directory' },
  trailingSlash: 'ignore',

  vite: {
    plugins: [saveContentPlugin()],
  },
});
