# Generator desain Figma (SVG) — PIK Marketing Control

Mengubah halaman aplikasi menjadi file SVG yang bisa di-drag ke Figma sebagai layer yang bisa diedit
(teks asli font Inter, kotak, ikon & logo vektor). Hasil terakhir: `../PIK-Marketing-Figma-Design.zip`.

Langkah membuat ulang (untuk developer):

1. Siapkan database contoh `pik_design` (schema + user dev), jalankan aplikasi di port 18788:
   `DB_DATABASE=pik_design APP_ENV=development php -S 127.0.0.1:18788 -t public public/index.php`
2. Isi data contoh: `php seed_design.php` (dari folder aplikasi; data fiktif, bukan data perusahaan).
3. Alat konversi: `npm install dom-to-svg esbuild bootstrap-icons@1`, lalu bundle
   `export { documentToSVG, elementToSVG, inlineResources } from 'dom-to-svg'` menjadi `dom-to-svg.bundle.js`
   (esbuild `--bundle --format=iife --global-name=DomToSvg`).
4. `PW_PATH=<path playwright> OUT=<folder output> APP=<folder aplikasi> node capture.js screens.json`
   menghasilkan `svg/` dan `png/`; `render-svg.js` merender SVG kembali ke PNG untuk dibandingkan.

Catatan teknis: ikon font Bootstrap Icons diganti SVG vektor, input/select diganti kotak + teks,
mask diubah menjadi clipPath, elemen `position: fixed` menjadi absolute, font "Inter Variable" → "Inter".
