// Builds resources/dist from resources/js and resources/css: `npm run build`. The built files are committed, so an
// app that installs the package from Packagist needs no Node.
import { build } from 'esbuild'

const common = { bundle: true, minify: true, target: 'es2020', legalComments: 'none', logLevel: 'info' }

await Promise.all([
    // The chat as an ES module, for Filament's x-load (Alpine and its plugins come from the panel).
    build({ ...common, entryPoints: ['resources/js/agent-chat.js'], outfile: 'resources/dist/agent-chat.js', format: 'esm' }),
    // The chat for a plain page: Alpine bundled for the pages that have none.
    build({ ...common, entryPoints: ['resources/js/standalone.js'], outfile: 'resources/dist/agents-chat.js', format: 'iife' }),
    build({ ...common, entryPoints: ['resources/js/chart.js'], outfile: 'resources/dist/agents-chart.js', format: 'esm' }),
    build({ ...common, entryPoints: ['resources/css/agents-chat.css'], outfile: 'resources/dist/agents-chat.css' }),
])
