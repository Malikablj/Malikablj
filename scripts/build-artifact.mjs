// Turns dist-artifact/index.html into a page fragment for sandboxed hosting:
// the host supplies <!doctype>/<html>/<head>/<body>, so we emit the title,
// styles, root element and bundle only. Usage: node scripts/build-artifact.mjs <out.html>
import { readFileSync, writeFileSync } from 'node:fs';

const html = readFileSync('dist-artifact/index.html', 'utf8');
const title = html.match(/<title>[\s\S]*?<\/title>/)[0];
// Head order produced by vite-plugin-singlefile: …, <script type="module">bundle</script>, <style>css</style>, </head>
const scriptStart = html.indexOf('<script type="module"');
const headEnd = html.indexOf('</head>');
const styleStart = html.lastIndexOf('<style', headEnd);
if (scriptStart < 0 || styleStart < scriptStart) throw new Error('Unexpected build layout');
const bundle = html.slice(scriptStart, styleStart).trim();
const styles = html.slice(styleStart, headEnd).trim();
if (!bundle.endsWith('</script>') || !styles.endsWith('</style>')) throw new Error('Unexpected build layout');
const out = [
  title,
  '<meta name="description" content="NPD Project Control — kontrol project New Mold & Subcont.">',
  styles,
  '<div id="root"></div>',
  bundle,
].join('\n');
const target = process.argv[2] ?? 'dist-artifact/npd-project-control.html';
writeFileSync(target, out);
console.log(`wrote ${target} (${(out.length / 1024).toFixed(0)} KB)`);
