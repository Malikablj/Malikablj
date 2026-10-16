const { chromium } = require(process.env.PW_PATH);
const fs = require('fs');
(async () => {
  const [svgPath, outPath] = process.argv.slice(2);
  const svg = fs.readFileSync(svgPath, 'utf8');
  const w = Number(svg.match(/width="(\d+)"/)[1]), h = Math.ceil(Number(svg.match(/height="([\d.]+)"/)[1]));
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const page = await browser.newPage({ viewport: { width: w, height: Math.min(h, 6000) } });
  // tanpa font web aplikasi: menguji tampilan dengan font "Inter" seperti di Figma
  await page.setContent('<html><body style="margin:0">' + svg.replace(/<\?xml[^>]*>/, '') + '</body></html>');
  await page.waitForTimeout(300);
  await page.screenshot({ path: outPath, fullPage: true });
  await browser.close();
})();
