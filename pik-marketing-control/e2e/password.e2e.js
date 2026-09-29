'use strict';

/**
 * Email + password sign-in in the browser — how the team works when the web app runs on a regular Gmail account and
 * Google does not tell the app who the visitor is ('anonymous' identity in the dev server).
 *
 * The Admin (script owner, Google identity) creates a user with a generated initial password; the user signs in
 * without a Google identity, must replace the temporary password, stays signed in after a reload, signs out and in
 * again; an Admin reset ends the user's session while the app is open; the profile changes the password; repeated
 * wrong passwords lock the email. Everything is checked against the server too. All data is invented.
 */

const assert = require('node:assert/strict');
const { after, afterEach, before, test } = require('node:test');

const { api, assertNoProblems, drawer, expectToast, go, idle, rows, shot, startApp } = require('./support');

let app;
let admin;
const opened = [];
const GITA = { name: 'Gita Gmail', email: 'gita.contoh@gmail.example' };
const state = {};

before(async () => {
  app = await startApp();
  admin = await app.signIn('admin');
});
after(async () => { if (app) await app.close(); });
afterEach(async () => {
  while (opened.length) await opened.pop().context.close();
  if (admin) await admin.page.evaluate(() => { if (window.PIK && PIK.closeAllLayers) PIK.closeAllLayers(); });
});

/** A browser without a Google identity (regular Gmail deployment) on the sign-in screen. */
async function visitor(viewport) {
  const session = await app.open('anonymous', { viewport });
  opened.push(session);
  await session.page.getByRole('heading', { name: 'Masuk', exact: true }).waitFor();
  return session;
}

async function signInWithPassword(page, email, password) {
  await page.getByLabel(/^Email/).fill(email);
  await page.getByLabel(/^Password/).fill(password);
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
}

async function newPassword(page, password) {
  await page.getByRole('heading', { name: 'Buat password baru' }).waitFor();
  await page.getByLabel(/^Password baru/).fill(password);
  await page.getByLabel(/^Ulangi password baru/).fill(password);
  await page.getByRole('button', { name: 'Simpan dan lanjutkan' }).click();
  await page.locator('.shell').waitFor();
}

test('Admin membuat user dengan password awal; user masuk tanpa akun Google, wajib membuat password baru, tetap masuk setelah muat ulang', async () => {
  const { page } = admin;
  await go(page, 'settings?tab=users');
  await idle(page);
  await page.getByRole('button', { name: 'User baru' }).click();
  const form = drawer(page, 'User baru');
  await form.getByLabel(/^Nama/).fill(GITA.name);
  await form.getByLabel(/^Email/).fill(GITA.email);
  await form.getByRole('button', { name: 'Buat otomatis' }).click();
  state.temporary = await form.getByLabel(/^Password awal/).inputValue();
  assert.match(state.temporary, /^[a-z2-9]{4}-[a-z2-9]{4}-[a-z2-9]{4}$/);
  assert.equal(await form.getByLabel(/^Password awal/).getAttribute('type'), 'text', 'password buatan otomatis ditampilkan agar dapat disalin');
  await shot(page, 'password-01-user-form');
  await form.getByRole('button', { name: 'Simpan' }).click();
  await expectToast(page, 'User ditambahkan.');
  const row = rows(page, GITA.name);
  await row.getByText('Sementara').waitFor();
  assert.ok(await row.getByText('Password', { exact: true }).isVisible());
  const listed = (await api(page, 'users.list', { search: 'gita' })).items[0];
  assert.equal(listed.has_password, true);
  assert.equal('password_hash' in listed, false, 'hash tidak dikirim ke browser');

  const session = await visitor();
  const v = session.page;
  assert.ok(await v.getByText('Masuk dengan email dan password akun Anda.').isVisible());
  await shot(v, 'password-02-login');
  await signInWithPassword(v, GITA.email, 'SalahSekali1');
  await v.getByText('Email atau password salah.').waitFor();
  assert.equal(await v.getByLabel(/^Password/).inputValue(), '', 'password dikosongkan setelah gagal');

  await signInWithPassword(v, GITA.email, state.temporary);
  await v.getByRole('heading', { name: 'Buat password baru' }).waitFor();
  await v.getByLabel(/^Password baru/).fill('pendek1');
  await v.getByLabel(/^Ulangi password baru/).fill('pendek2');
  await v.getByRole('button', { name: 'Simpan dan lanjutkan' }).click();
  await v.getByText('Minimal 8 karakter.').waitFor();
  await v.getByText('Tidak sama dengan password baru.').waitFor();
  await shot(v, 'password-03-new-password');
  state.password = 'RahasiaGita2026';
  await newPassword(v, state.password);
  await expectToast(v, 'Password baru tersimpan.');
  const me = await api(v, 'session.get', {});
  assert.deepEqual([me.user.email, me.user.role, me.user.signInMethod, me.user.mustChangePassword], [GITA.email, 'SALES', 'password', false]);
  await go(v, 'deliveries');
  await idle(v);
  assert.equal(await v.getByRole('button', { name: 'Catat delivery' }).count(), 0, 'Sales hanya membaca delivery');

  await v.reload();
  await v.locator('.shell').waitFor();
  assert.equal((await api(v, 'session.get', {})).user.email, GITA.email, 'tetap masuk setelah muat ulang');

  await v.locator('.user-button').click();
  await v.getByRole('menuitem', { name: 'Keluar' }).click();
  await v.getByText('Anda telah keluar').waitFor();
  assert.equal(await v.evaluate(() => PIK.auth.token()), null, 'token dihapus saat keluar');
  await signInWithPassword(v, GITA.email, state.temporary);
  await v.getByText('Email atau password salah.').waitFor();
  await signInWithPassword(v, GITA.email, state.password);
  await v.locator('.shell').waitFor();
  assertNoProblems(session);
});

test('reset oleh Admin mengakhiri sesi user yang sedang terbuka; masuk dengan password sementara baru wajib diganti', async () => {
  const session = await visitor();
  const v = session.page;
  await signInWithPassword(v, GITA.email, state.password);
  await v.locator('.shell').waitFor();

  const { page } = admin;
  await go(page, 'settings?tab=users');
  await idle(page);
  await rows(page, GITA.name).getByRole('button', { name: 'Aksi' }).click();
  await page.getByRole('menuitem', { name: 'Atur password' }).click();
  const form = drawer(page, 'Atur password');
  await form.getByRole('button', { name: 'Buat otomatis' }).click();
  const reset = await form.getByLabel(/^Password baru/).inputValue();
  assert.ok(await form.getByRole('checkbox', { name: /Wajib ganti password/ }).isChecked(), 'wajib ganti: bawaan');
  await shot(page, 'password-04-admin-reset');
  await form.getByRole('button', { name: 'Simpan password' }).click();
  await expectToast(page, `Password ${GITA.name} diatur.`);

  // Not go(): the next server call ends the session and replaces the app with the sign-in screen.
  await v.evaluate(() => PIK.router.go('customers', {}));
  await v.getByRole('heading', { name: 'Masuk', exact: true }).waitFor();
  await v.getByText('Sesi Anda sudah berakhir. Silakan masuk lagi.').waitFor();
  await signInWithPassword(v, GITA.email, state.password);
  await v.getByText('Email atau password salah.').waitFor();
  await signInWithPassword(v, GITA.email, reset);
  state.password = 'GitaLagi2026';
  await newPassword(v, state.password);
  assertNoProblems(session);
});

test('profil: ganti password sendiri; password lama tidak berlaku lagi', async () => {
  const session = await visitor();
  const v = session.page;
  await signInWithPassword(v, GITA.email, state.password);
  await v.locator('.shell').waitFor();
  await go(v, 'settings');
  await idle(v);
  assert.ok(await v.getByText('Email dan password').isVisible(), 'profil: masuk dengan email dan password');
  await v.getByRole('button', { name: 'Ganti password' }).click();
  const form = drawer(v, 'Ganti password');
  await form.getByLabel(/^Password saat ini/).fill('bukanPassword1');
  await form.getByLabel(/^Password baru/).fill('GitaTerbaru7');
  await form.getByLabel(/^Ulangi password baru/).fill('GitaTerbaru7');
  await form.getByRole('button', { name: 'Simpan password' }).click();
  await form.getByText('Password saat ini salah.').first().waitFor();
  await form.getByLabel(/^Password saat ini/).fill(state.password);
  await form.getByRole('button', { name: 'Simpan password' }).click();
  await expectToast(v, 'Password diganti. Sesi di perangkat lain sudah berakhir.');
  assert.equal((await api(v, 'session.get', {})).user.email, GITA.email, 'sesi ini tetap berlaku');

  const other = await visitor();
  await signInWithPassword(other.page, GITA.email, state.password);
  await other.page.getByText('Email atau password salah.').waitFor();
  await signInWithPassword(other.page, GITA.email, 'GitaTerbaru7');
  await other.page.locator('.shell').waitFor();
  assertNoProblems(session);
});

test('percobaan salah berulang mengunci email sementara; ponsel: layar masuk tanpa scroll ke samping', async () => {
  const { page } = admin;
  const created = await api(page, 'users.create', { data: { name: 'Hadi Gmail', email: 'hadi.contoh@gmail.example', role: 'VIEWER' }, password: 'HadiAwal123' });
  assert.equal(created.has_password, true);
  const session = await visitor('phone');
  const v = session.page;
  const [scrollWidth, width] = await v.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
  assert.ok(scrollWidth <= width, `layar masuk ponsel melebar (${scrollWidth} > ${width})`);
  for (let i = 0; i < 5; i++) {
    await signInWithPassword(v, 'hadi.contoh@gmail.example', `Salah${i}xyz`);
    await v.getByText('Email atau password salah.').waitFor();
  }
  await signInWithPassword(v, 'hadi.contoh@gmail.example', 'HadiAwal123');
  await v.getByText(/Terlalu banyak percobaan masuk yang gagal\. Coba lagi dalam 15 menit\./).waitFor();
  await shot(v, 'password-05-locked-phone');
  assertNoProblems(session);
});
