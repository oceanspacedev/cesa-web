import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page, type Route } from '@playwright/test';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const buildRoot = path.join(projectRoot, 'public/build');
const sender = { id: 1, name: 'HR Satu', phone_number: '628111111111', is_active: true, is_default: true, status: 'disconnected', delivery_ready: false };
const readySender = { ...sender, status: 'connected', delivery_ready: true };
const testPermissions = { jobApplications: { viewAny: true, update: true }, ai: { manage: true }, whatsapp: { manage: true }, mailTemplates: { viewAny: true, update: true } };
let currentPermissions = testPermissions;
const session = (account = sender) => ({ ...account, status: 'qr', engine_ready: true, qr: 'data:image/png;base64,iVBORw0KGgo=', pairing_code: null });
const json = (route: Route, data: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) });

test.use({ video: 'off', launchOptions: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {} });

async function mockApplication(page: Page) {
  const manifest = JSON.parse(fs.readFileSync(path.join(buildRoot, 'manifest.json'), 'utf8'));
  const entry = manifest['plugins/cesa/rekrutmen/resources/js/app.js'];
  const styles = [...(entry.css || []), manifest['resources/css/app.css']?.file].filter(Boolean);
  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.startsWith('/build/')) {
      const asset = path.resolve(buildRoot, url.pathname.slice('/build/'.length));
      if (!asset.startsWith(`${buildRoot}${path.sep}`)) return route.abort();
      return route.fulfill({ path: asset, contentType: asset.endsWith('.css') ? 'text/css' : 'application/javascript' });
    }
    if (route.request().isNavigationRequest()) {
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${styles.map((style) => `<link rel="stylesheet" href="/build/${style}">`).join('')}</head><body><div id="rekrutmen-app" data-user='{"name":"Test Admin"}' data-permissions='${JSON.stringify(currentPermissions)}'></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
    }
    if (url.pathname === '/rekrutmen/api/settings/whatsapp') return json(route, { gateway: { enabled: true, engine_ready: true }, accounts: [sender] });
    if (url.pathname === '/rekrutmen/api/whatsapp/senders') return json(route, { accounts: [readySender], engine_ready: true });
    if (url.pathname === '/rekrutmen/api/settings/mail-templates') return json(route, { templates: { interview_hr: { subject: 'Undangan', body: 'Halo {nama_pelamar}', name: 'Interview HR', badge: 'Interview HR', has_schedule: false } } });
    if (url.pathname === '/rekrutmen/api/applications') return json(route, {
      applications: [1, 2].map((id) => ({ id, full_name: `Pelamar ${id}`, email: `pelamar${id}@example.test`, phone: '628122222222', status: 'in_progress', current_stage_id: 1, stage: { id: 1, name: 'Screening CV' }, job_posting: { title: 'Engineer' } })),
      stages: [{ id: 1, name: 'Screening CV', color: '#2563eb' }],
    });
    if (url.pathname === '/rekrutmen/api/configurations') return json(route, { divisions: [], stages: [], approvers: [], companies: [] });
    if (url.pathname === '/rekrutmen/api/notifications/heartbeat') return json(route, { processed: 0 });
    if (route.request().method() !== 'GET') throw new Error(`Unmocked mutation: ${url.pathname}`);
    if (url.pathname.startsWith('/rekrutmen/api/')) return json(route, {});
    return route.fulfill({ status: 204 });
  });
}

test.beforeEach(async ({ page }) => {
  currentPermissions = testPermissions;
  await mockApplication(page);
});

test('restricted SPA navigation opens the first permitted page and hides inaccessible links', async ({ page }) => {
  currentPermissions = { jobApplications: { viewAny: true, update: false } };
  await page.goto('http://whatsapp-ui.test/rekrutmen');
  await expect(page).toHaveURL(/\/admin\/job-applications$/);
  await expect(page.getByRole('link', { name: 'Data Pelamar', exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Lowongan Kerja', exact: true })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Master Data', exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Aplikasi CESA', exact: true })).toHaveCount(0);
});

test('SPA shows access denied when no recruitment section is permitted', async ({ page }) => {
  currentPermissions = {};
  await page.goto('http://whatsapp-ui.test/rekrutmen');
  await expect(page).toHaveURL(/\/rekrutmen\/access-denied$/);
  await expect(page.getByRole('alert')).toHaveText('Anda tidak memiliki akses ke modul Rekrutmen.');
});

async function openGateway(page: Page) {
  await page.goto('http://whatsapp-ui.test/admin/configurations');
  await page.getByRole('button', { name: 'Akun WhatsApp', exact: true }).click();
}

test('closing QR aborts overlapping polling and ignores a late connected response', async ({ page }) => {
  let pendingRoute: Route | undefined;
  let requests = 0;
  await page.route('**/settings/whatsapp/accounts/connect', (route) => json(route, { success: true, data: session() }, 201));
  await page.route('**/settings/whatsapp/accounts/1/session', (route) => { requests += 1; pendingRoute = route; });
  await openGateway(page);
  await page.clock.install();
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await expect(page.getByText('Scan QR Code untuk Menautkan', { exact: true })).toBeVisible();
  await page.clock.fastForward(2100);
  await expect.poll(() => requests).toBe(1);
  await page.clock.fastForward(5000);
  expect(requests).toBe(1);
  await page.getByRole('button', { name: 'Tutup', exact: true }).click();
  await pendingRoute?.fulfill({ contentType: 'application/json', body: JSON.stringify({ ...readySender, engine_ready: true }) }).catch(() => {});
  await page.clock.fastForward(2500);
  await expect(page.getByText('Scan QR Code untuk Menautkan', { exact: true })).not.toBeVisible();
  await expect(page.getByText('WhatsApp Terhubung', { exact: true })).not.toBeVisible();
  expect(requests).toBe(1);
});

test('reconnect uses the selected account phone and keeps QR mode explicit', async ({ page }) => {
  const payloads: any[] = [];
  await page.route('**/settings/whatsapp/accounts/1/connect', (route) => {
    payloads.push(route.request().postDataJSON());
    return json(route, { success: true, data: session() });
  });
  await openGateway(page);
  const row = page.getByRole('row').filter({ hasText: 'HR Satu' });
  await row.getByRole('button', { name: 'Hubungkan', exact: true }).click();
  await page.getByRole('button', { name: 'Gunakan kode pairing', exact: true }).click();
  await expect(page.locator('.swal2-input')).toHaveValue(sender.phone_number);
  await page.getByRole('button', { name: 'Buat kode pairing', exact: true }).click();
  expect(payloads[1]).toMatchObject({ mode: 'pairing', phone_number: sender.phone_number });
  await page.getByRole('button', { name: 'Tutup', exact: true }).click();
  await row.getByRole('button', { name: 'Hubungkan', exact: true }).click();
  expect(payloads[2].mode).toBe('qr');
  expect(payloads[2].phone_number).toBeUndefined();
});

test('a pending connect disables repeat clicks and switching tabs ignores its late response', async ({ page }) => {
  let pendingConnect: Route | undefined;
  let requests = 0;
  await page.route('**/settings/whatsapp/accounts/1/connect', (route) => {
    requests += 1;
    pendingConnect = route;
  });
  await openGateway(page);
  const row = page.getByRole('row').filter({ hasText: 'HR Satu' });
  await row.getByRole('button', { name: 'Hubungkan', exact: true }).click();
  await expect(row.getByRole('button', { name: 'Hubungkan', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Menyiapkan...', exact: true })).toBeDisabled();
  await row.getByRole('button', { name: 'Hubungkan', exact: true }).dispatchEvent('click');
  expect(requests).toBe(1);
  await page.getByRole('button', { name: 'Integrasi AI', exact: true }).click();
  await pendingConnect?.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: session() }) }).catch(() => {});
  await page.getByRole('button', { name: 'Akun WhatsApp', exact: true }).click();
  await expect(page.getByText('Scan QR Code untuk Menautkan', { exact: true })).not.toBeVisible();
  await expect(row.getByRole('button', { name: 'Hubungkan', exact: true })).toBeEnabled();
});

test('failed creation remains visible and retry reuses the saved account', async ({ page }) => {
  let creates = 0;
  let reconnects = 0;
  const failedAccount = { ...sender, id: 9, name: 'Percobaan gagal' };
  await page.route('**/settings/whatsapp/accounts/connect', (route) => {
    creates += 1;
    return json(route, { success: false, message: 'Engine belum tersedia', data: failedAccount }, 422);
  });
  await page.route('**/settings/whatsapp/accounts/9/connect', (route) => {
    reconnects += 1;
    return json(route, { success: true, data: session(failedAccount) });
  });
  await openGateway(page);
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await page.getByRole('button', { name: 'OK', exact: true }).click();
  await expect(page.getByRole('row').filter({ hasText: failedAccount.name })).toBeVisible();
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await expect.poll(() => reconnects).toBe(1);
  expect(creates).toBe(1);
});

test('lost new-account response keeps the same request key after page reload', async ({ page }) => {
  const payloads: any[] = [];
  await page.route('**/settings/whatsapp/accounts/connect', (route) => {
    payloads.push(route.request().postDataJSON());
    if (payloads.length === 1) return route.abort('failed');
    return json(route, { success: true, data: session() }, 201);
  });
  await openGateway(page);
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await page.getByRole('button', { name: 'OK', exact: true }).click();
  await page.reload();
  await page.getByRole('button', { name: 'Akun WhatsApp', exact: true }).click();
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await expect(page.getByText('Scan QR Code untuk Menautkan', { exact: true })).toBeVisible();
  expect(payloads[0].request_key).toMatch(/^[a-f0-9-]{36}$/);
  expect(payloads[1].request_key).toBe(payloads[0].request_key);
  expect(payloads[0].mode).toBe('qr');
  expect(payloads[1].mode).toBe('qr');
});

test('a deleted-account conflict lets the next explicit creation use a new request key', async ({ page }) => {
  const keys: string[] = [];
  await page.route('**/settings/whatsapp/accounts/connect', (route) => {
    keys.push(route.request().postDataJSON().request_key);
    if (keys.length === 1) return json(route, { message: 'Permintaan ini sudah digunakan untuk akun yang dihapus. Buat koneksi baru.' }, 409);
    return json(route, { success: true, data: session() }, 201);
  });
  await openGateway(page);
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await page.getByRole('button', { name: 'OK', exact: true }).click();
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await expect(page.getByText('Scan QR Code untuk Menautkan', { exact: true })).toBeVisible();
  expect(keys).toHaveLength(2);
  expect(keys[1]).toMatch(/^[a-f0-9-]{36}$/);
  expect(keys[1]).not.toBe(keys[0]);
});

test('engine unavailable does not show connected success and denied settings show an access message', async ({ page }) => {
  await page.route('**/settings/whatsapp/accounts/connect', (route) => json(route, { success: true, data: session() }, 201));
  await page.route('**/settings/whatsapp/accounts/1/session', (route) => json(route, { ...readySender, engine_ready: false, engine_error: 'Engine sedang offline' }));
  await openGateway(page);
  await page.clock.install();
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await page.clock.fastForward(2100);
  await expect(page.getByText('Engine sedang offline', { exact: true })).toBeVisible();
  await expect(page.getByText('WhatsApp Terhubung', { exact: true })).not.toBeVisible();
  await page.route('**/settings/whatsapp', (route) => json(route, { message: 'Forbidden' }, 403));
  await page.reload();
  await page.getByRole('button', { name: 'Akun WhatsApp', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('tidak memiliki izin');
  await expect(page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true })).not.toBeVisible();
});

test('failed pause keeps the account visible and qualifies its stale status', async ({ page }) => {
  let disconnected = false;
  await page.route('**/settings/whatsapp', (route) => json(route, { gateway: { enabled: true, engine_ready: true }, accounts: [disconnected ? { ...readySender, stale: true, status: 'unknown', delivery_ready: false } : readySender] }));
  await page.route('**/settings/whatsapp/accounts/1/disconnect', (route) => {
    disconnected = true;
    return json(route, { success: false, message: 'Pengiriman dinonaktifkan, logout belum terkonfirmasi', data: { ...sender, is_active: false } }, 503);
  });
  await openGateway(page);
  await page.getByRole('row').filter({ hasText: 'HR Satu' }).getByRole('button', { name: 'Jeda', exact: true }).click();
  await page.getByRole('button', { name: 'OK', exact: true }).click();
  await expect(page.getByRole('row').filter({ hasText: 'HR Satu' })).toContainText('Status terakhir belum terverifikasi');
  await expect(page.getByRole('row').filter({ hasText: 'HR Satu' }).getByRole('button', { name: 'Tes', exact: true })).not.toBeVisible();
});

test('a completed scheduled replay displays its terminal outcome instead of waiting', async ({ page }) => {
  await page.route('**/applications/bulk-send-notification', (route) => json(route, { success: true, queued: true, scheduled: true, batch_id: 28, status: 'sent', stats: { total: 2, email_success: 2, whatsapp_success: 2 }, details: [] }, 202));
  await page.goto('http://whatsapp-ui.test/admin/job-applications');
  await page.getByRole('checkbox', { name: 'Pilih Semua (2 kandidat)', exact: true }).check();
  await page.getByRole('button', { name: 'Kirim Notifikasi Massal', exact: true }).click();
  await expect(page.locator('select').filter({ has: page.locator('option', { hasText: 'HR Satu' }) })).toBeVisible();
  await page.getByRole('button', { name: 'Kirim ke 2 Pelamar', exact: true }).click();
  const progress = page.getByRole('region', { name: 'Progres pengiriman notifikasi' });
  await expect(progress).toContainText('Pengiriman selesai');
  await expect(progress).not.toContainText('Notifikasi dijadwalkan');
});

test('a single uncertain delivery reports unknown instead of claiming failure', async ({ page }) => {
  await page.route('**/applications/1/send-notification', (route) => json(route, {
    success: false, batch_id: 29, status: 'unknown', message: 'Periksa penerimaan pesan sebelum mengirim ulang.',
    stats: { total: 1, whatsapp_unknown: 1 }, details: [{ id: 1, name: 'Pelamar 1', email: null, whatsapp: { status: 'unknown' } }],
  }, 422));
  await page.goto('http://whatsapp-ui.test/admin/job-applications');
  await page.getByRole('group', { name: 'Pelamar Pelamar 1', exact: true }).getByTitle('Kirim Notifikasi (Email / WhatsApp)').click();
  await expect(page.locator('select').filter({ has: page.locator('option', { hasText: 'HR Satu' }) })).toBeVisible();
  await page.getByRole('button', { name: 'Kirim Notifikasi', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Hasil Pengiriman Belum Pasti', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'OK', exact: true }).click();
  await expect(page.getByRole('region', { name: 'Progres pengiriman notifikasi' })).toContainText('Belum pasti');
});

test('bulk transport retry preserves request key and queued progress reports unknown honestly', async ({ page }) => {
  const keys: string[] = [];
  let submissions = 0;
  let polls = 0;
  await page.route('**/applications/bulk-send-notification', (route) => {
    submissions += 1;
    const key = route.request().postData()?.match(/name="request_key"\r\n\r\n([^\r]+)/)?.[1];
    keys.push(key || '');
    if (submissions === 1) return route.abort('failed');
    return json(route, { success: true, queued: true, batch_id: 27, status: 'pending' }, 202);
  });
  await page.route('**/notifications/27', (route) => {
    polls += 1;
    return json(route, { id: 27, status: 'unknown', stats: { total: 2, email_success: 2, whatsapp_success: 1, whatsapp_unknown: 1 }, details: [
      { id: 1, name: 'Pelamar 1', email: { status: 'sent', stage_error: 'Tahap kandidat berubah selama pengiriman.' }, whatsapp: { status: 'sent' } },
      { id: 2, name: 'Pelamar 2', email: { status: 'sent' }, whatsapp: { status: 'unknown', message: 'Respons pengiriman tidak diterima' } },
    ] });
  });
  await page.goto('http://whatsapp-ui.test/admin/job-applications');
  await page.getByRole('checkbox', { name: 'Pilih Semua (2 kandidat)', exact: true }).check();
  await page.getByRole('button', { name: 'Kirim Notifikasi Massal', exact: true }).click();
  await expect(page.locator('select').filter({ has: page.locator('option', { hasText: 'HR Satu' }) })).toBeVisible();
  await page.getByRole('button', { name: 'Kirim ke 2 Pelamar', exact: true }).click();
  await page.getByRole('button', { name: 'OK', exact: true }).click();
  await page.clock.install();
  await page.getByRole('button', { name: 'Kirim ke 2 Pelamar', exact: true }).click();
  const progress = page.getByRole('region', { name: 'Progres pengiriman notifikasi' });
  await expect(progress).toContainText('Notifikasi dalam antrean');
  expect(keys[0]).toMatch(/^[a-f0-9-]{36}$/);
  expect(keys[1]).toBe(keys[0]);
  await page.clock.fastForward(2100);
  await expect(progress).toContainText('Hasil pengiriman belum pasti');
  await expect(progress.getByRole('row').filter({ hasText: 'Pelamar 1' })).toContainText('Tahapan belum diperbarui');
  await expect(progress.getByRole('row').filter({ hasText: 'Pelamar 2' })).toContainText('Belum pasti');
  await page.clock.fastForward(6000);
  expect(polls).toBe(1);
  await progress.getByRole('button', { name: 'Perbarui Status', exact: true }).click();
  await page.clock.fastForward(2100);
  await expect.poll(() => polls).toBe(2);
  expect(submissions).toBe(2);
});


test('first connection asks for neither account name nor phone and closes automatically on working', async ({ page }) => {
  await page.route('**/settings/whatsapp/accounts/connect', route => json(route, { success: true, data: session() }, 201));
  await page.route('**/settings/whatsapp/accounts/1/session', route => json(route, { ...readySender, engine_ready: true, stale: false }));
  await openGateway(page);
  await expect(page.getByPlaceholder('HR Recruitment')).not.toBeVisible();
  await expect(page.getByPlaceholder('0812xxxxxxx')).not.toBeVisible();
  await page.clock.install();
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await expect(page.getByText('Scan QR Code untuk Menautkan', { exact: true })).toBeVisible();
  await page.clock.fastForward(2100);
  await expect(page.getByText('Scan QR Code untuk Menautkan', { exact: true })).not.toBeVisible();
  await expect(page.getByText('WhatsApp Terhubung', { exact: true })).toBeVisible();
});

test('unlink requires confirmation while pause remains a single action', async ({ page }) => {
  const actions: string[] = [];
  await page.route('**/settings/whatsapp', route => json(route, { gateway: { enabled: true, engine_ready: true }, accounts: [readySender] }));
  await page.route('**/settings/whatsapp/accounts/1/logout', route => { actions.push('logout'); return json(route, { success: true }, 202); });
  await page.route('**/settings/whatsapp/accounts/1/disconnect', route => { actions.push('stop'); return json(route, { success: true }, 202); });
  await openGateway(page);
  const row = page.getByRole('row').filter({ hasText: 'HR Satu' });
  await row.getByRole('button', { name: 'Lepas tautan', exact: true }).click();
  await expect(page.getByText('Autentikasi akan dihapus.', { exact: false })).toBeVisible();
  expect(actions).toHaveLength(0);
  await page.getByRole('button', { name: 'Batal', exact: true }).click();
  await row.getByRole('button', { name: 'Jeda', exact: true }).click();
  await expect.poll(() => actions).toEqual(['stop']);
});

test('connects from the notification workflow and returns to its preserved draft', async ({ page }) => {
  let connected = false;
  await page.route('**/whatsapp/senders', route => json(route, { accounts: connected ? [readySender] : [], engine_ready: true, can_manage: true }));
  await page.route('**/settings/whatsapp/accounts/connect', route => json(route, { success: true, data: session() }, 201));
  await page.route('**/settings/whatsapp/accounts/1/session', route => { connected = true; return json(route, { ...readySender, engine_ready: true, stale: false }); });
  await page.goto('http://whatsapp-ui.test/admin/job-applications');
  await page.getByRole('group', { name: 'Pelamar Pelamar 1', exact: true }).getByTitle('Kirim Notifikasi (Email / WhatsApp)').click();
  await page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true }).click();
  await expect(page.getByText('Scan QR Code untuk Menautkan', { exact: true })).toBeVisible();
  await expect(page).toHaveURL(/job-applications/);
  await expect(page.getByRole('button', { name: 'Kirim Notifikasi', exact: true })).toBeVisible();
  await expect(page.locator('select').filter({ has: page.locator('option', { hasText: 'HR Satu' }) })).toBeVisible();
});

test('administrator configures the hub once and clears the credential from the form', async ({ page }) => {
  let configured = false;
  let saved: any;
  await page.route('**/settings/whatsapp', route => json(route, { gateway: { enabled: true, engine_ready: configured }, accounts: [], integration_url: 'https://hub.example.test', integration: configured ? { scopes: ['capacity:manage'], capacity: { used: 0, configured_limit: 3, maximum_allowed: 5 } } : null }));
  await page.route('**/settings/whatsapp/integration', route => { saved = route.request().postDataJSON(); configured = true; return json(route, { success: true }); });
  await openGateway(page);
  await page.getByPlaceholder('https://hub.example.com').fill('https://hub.example.test');
  await page.getByPlaceholder('Biarkan kosong untuk mempertahankan token').fill('one-time-secret');
  await page.getByRole('button', { name: 'Simpan dan hubungkan hub', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Hubungkan WhatsApp', exact: true })).toBeEnabled();
  expect(saved).toEqual({ url: 'https://hub.example.test', token: 'one-time-secret' });
  await expect(page.getByPlaceholder('Biarkan kosong untuk mempertahankan token')).toHaveValue('');
});

test('capacity changes use the granted bound and reflect the hub response', async ({ page }) => {
  let configuredLimit = 3;
  await page.route('**/settings/whatsapp', route => json(route, { gateway: { enabled: true, engine_ready: true }, accounts: [], integration: { scopes: ['capacity:manage'], capacity: { used: 2, configured_limit: configuredLimit, maximum_allowed: 5 } } }));
  await page.route('**/settings/whatsapp/capacity', route => { configuredLimit = route.request().postDataJSON().configured_limit; return json(route, { success: true }); });
  await openGateway(page);
  await page.getByRole('button', { name: 'Ubah batas', exact: true }).click();
  await expect(page.locator('.swal2-input')).toHaveAttribute('max', '5');
  await page.locator('.swal2-input').fill('4');
  await page.getByRole('button', { name: 'Simpan', exact: true }).click();
  await expect(page.getByText('2 dari 4 akun digunakan', { exact: false })).toBeVisible();
});
