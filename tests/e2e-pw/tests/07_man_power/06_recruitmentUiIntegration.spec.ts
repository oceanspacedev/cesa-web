import path from "node:path";
import { fileURLToPath } from "node:url";
import { expect, test, type Page } from "@playwright/test";
import vue from "@vitejs/plugin-vue";
import { build } from "vite";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
const views = path.join(root, "plugins/cesa/rekrutmen/resources/js/views");
let javascript = "";
let styles = "";
const fullPermissions = {
    jobPostings: { viewAny: true, create: true, update: true, delete: true },
    jobApplications: { viewAny: true, create: true, update: true, delete: true },
    requestManPowers: { viewAny: true, create: true, update: true, delete: true },
    recruitmentProgress: { viewAny: true },
    divisions: { viewAny: true, create: true, update: true, delete: true },
    pipelines: { viewAny: true, create: true, update: true, delete: true },
    approvers: { viewAny: true, create: true, update: true, delete: true },
    ai: { manage: true },
    whatsapp: { manage: true },
    mailSettings: { viewAny: true, update: true },
    mailTemplates: { viewAny: true, update: true },
};

test.setTimeout(45_000);
test.use({ actionTimeout: 10_000 });

test.beforeAll(async () => {
    const entry = "virtual:recruitment-ui-integration";
    const result = await build({
        root,
        configFile: false,
        resolve: { alias: { "frappe-ui/src": path.join(root, "node_modules/frappe-ui/src") } },
        logLevel: "error",
        plugins: [{
            name: "recruitment-ui-integration",
            resolveId: (id) => id === entry ? `\0${entry}` : undefined,
            load: (id) => id === `\0${entry}` ? `
                import { createApp, h } from 'vue';
                import { createPinia } from 'pinia';
                import { createRouter, createMemoryHistory, RouterView } from 'vue-router';
                import Applications from ${JSON.stringify(path.join(views, "JobApplicationsView.vue"))};
                import Postings from ${JSON.stringify(path.join(views, "JobPostingsView.vue"))};
                import Dashboard from ${JSON.stringify(path.join(views, "DashboardView.vue"))};
                import Configurations from ${JSON.stringify(path.join(views, "ConfigurationsView.vue"))};
                const router = createRouter({ history: createMemoryHistory(), routes: [
                    { path: '/admin/job-applications', name: 'applications', component: Applications },
                    { path: '/admin/job-postings', name: 'postings', component: Postings },
                    { path: '/admin/dashboard', name: 'dashboard', component: Dashboard },
                    { path: '/configurations', component: Configurations },
                    { path: '/empty', component: { render: () => h('p', 'Halaman lain') } },
                ] });
                const app = createApp({ render: () => h(RouterView) }).use(createPinia()).use(router);
                window.screeningRouter = router;
                router.push(window.location.pathname + window.location.search);
                router.isReady().then(() => app.mount('#app'));
            ` : undefined,
        }, vue()],
        css: { postcss: { plugins: [] } },
        build: { write: false, minify: false, cssCodeSplit: false, rollupOptions: {
            input: entry, output: { format: "iife", name: "RecruitmentUiIntegration", inlineDynamicImports: true },
        } },
    });
    if (Array.isArray(result) || !("output" in result)) throw new Error("Expected one browser bundle");
    for (const output of result.output) {
        if (output.type === "chunk") javascript += output.code;
        else if (output.fileName.endsWith(".css")) styles += String(output.source);
    }
});

async function mount(page: Page, pathname: string, denyAiSettings = false, includeMatchedCandidate = false, permissions = fullPermissions) {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    const postings = [
        { id: 7, title: "Engineer", company_name: "Company A", company_id: 1, location: "Jakarta", is_published: true, rekrutmen_pipeline_id: 1 },
        { id: 8, title: "Engineer", company_name: "Company B", company_id: 2, location: "Bandung", is_published: true, rekrutmen_pipeline_id: 2 },
    ];
    const stages = [
        { id: 1, name: "Screening CV", rekrutmen_pipeline_id: 1 },
        { id: 9, name: "Technical Review", rekrutmen_pipeline_id: 2 },
        { id: 10, name: "Technical Interview", rekrutmen_pipeline_id: 2 },
    ];
    const application = { ai_screening_status: 'pending', ai_screening_error: null as string | null, ai_match_score: 99, ai_summary: 'Skor lama berbasis aturan lokal', ai_recommendation: 'recommended', id: 41, full_name: "BUDI SANTOSO", email: "candidate@example.test", status: "in_progress", current_stage_id: 9, stage: stages[1], job_posting_id: 8, job_posting: postings[1] };
    const applications = [application];
    if (includeMatchedCandidate) {
        applications.push({ ...application, id: 42, full_name: 'SITI AMINAH', email: 'second@example.test', ai_screening_status: 'completed', ai_match_score: 90 });
    }
    const mutations: string[] = [];
    const aiMutations: { path: string; body: Record<string, unknown> }[] = [];
    let statusRequests = 0;
    const settings = { provider: 'openai_compatible', automatic: true, base_url: 'https://router.example.test/v1', model: 'model-a', has_api_key: true, is_database: true, has_env: true, updated_at: null };
    await page.route("**/*", async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        let data: unknown = {};
        if (request.isNavigationRequest()) {
            await route.fulfill({ contentType: "text/html", body: `<html><body class="rekrutmen-spa"><div id="app" data-permissions='${JSON.stringify(permissions)}'></div></body></html>` });
            return;
        }
        if (request.method() !== "GET") mutations.push(`${request.method()} ${url.pathname}`);
        if (request.method() === 'POST' && /(?:analyze-ai|settings\/ai(?:\/test)?)$/.test(url.pathname)) {
            aiMutations.push({ path: url.pathname, body: request.postDataJSON() });
        }
        if (url.pathname.endsWith("/dashboard")) data = { stats: {}, recent_applications: [{ ...application, name: application.full_name }] };
        else if (url.pathname.endsWith("/applications")) data = { applications, stages, active_job: url.searchParams.has("job_id") ? application.job_posting : null };
        else if (url.pathname.endsWith("/job-postings")) data = { data: postings, pipelines: [{ id: 1, name: "Default" }, { id: 2, name: "Technical" }] };
        else if (url.pathname.endsWith("/companies")) data = [{ id: 1, name: "Company A" }, { id: 2, name: "Company B" }];
        else if (url.pathname.endsWith("/configurations")) data = { pipelines: [{ id: 1, name: "Default" }, { id: 2, name: "Technical" }], stages };
        else if (url.pathname.endsWith('/ai-status')) {
            statusRequests++;
            const requestedIds = url.searchParams.getAll('ids[]');
            const counts: Record<string, number> = { pending: 0, queued: 0, processing: 0, completed: 0, needs_review: 0, failed: 0 };
            applications.forEach(candidate => counts[candidate.ai_screening_status]++);
            data = { total: applications.length, counts, applications: applications.filter(candidate => !requestedIds.length || requestedIds.includes(String(candidate.id))) };
        } else if (url.pathname.endsWith('/batch-analyze-ai') || url.pathname.endsWith('/analyze-ai')) {
            application.ai_screening_status = 'queued';
            await route.fulfill({ status: 202, json: { success: true, queued: 1, total: 1, skipped: 0, message: 'CV masuk antrean. Tetap diproses walau halaman ditutup.', application: { ...application } } });
            return;
        } else if (url.pathname.endsWith('/settings/ai/test')) data = { success: true, message: 'Koneksi berhasil.' };
        else if (url.pathname.endsWith('/settings/ai')) {
            if (denyAiSettings) {
                await route.fulfill({ status: 403, json: { message: 'Forbidden' } });
                return;
            }
            const payload = request.method() === 'POST' ? request.postDataJSON() : null;
            if (payload) Object.assign(settings, { automatic: payload.automatic, base_url: payload.base_url, model: payload.model });
            data = payload ? { success: true, message: 'Pengaturan disimpan.' } : settings;
        }
        else if (url.pathname.endsWith("/heartbeat")) data = { processed: 0 };
        else if (/\/job-postings\/8\/publish$/.test(url.pathname)) {
            postings[1].is_published = !postings[1].is_published;
            data = { success: true, is_published: postings[1].is_published };
        } else if (/\/job-postings\/8$/.test(url.pathname)) data = { success: true, posting: postings[1] };
        else if (url.pathname.endsWith("/settings/mail-templates")) data = { templates: { screening: { subject: "Application Update", body: "Hello {nama_pelamar}" } } };
        else if (url.pathname.endsWith("/whatsapp/senders")) data = { accounts: [], engine_ready: false };
        else if (url.pathname.endsWith("/send-notification")) {
            await route.fulfill({ status: 202, json: { batch_id: 77, queued: true, status: "partial", stats: { total: 1, email_success: 1 }, details: [{ id: 41, name: application.full_name, email: { status: "sent", stage_error: "Stage changed concurrently" }, whatsapp: { status: "failed", message: "Sender unavailable" } }] } });
            return;
        } else if (url.pathname.endsWith("/stage")) data = { success: true };
        await route.fulfill({ json: data });
    });
    await page.goto(`http://recruitment-ui.test${pathname}`);
    await page.addStyleTag({ content: styles });
    await page.addScriptTag({ content: javascript });
    return { errors, mutations, aiMutations, application, statusRequests: () => statusRequests };
}

test("publishing in the posting inspector reflects the persisted status", async ({ page }) => {
    const { errors } = await mount(page, "/admin/job-postings?id=8");
    const toggle = page.getByTitle("Klik untuk mengubah status publikasi");
    await expect(toggle).toHaveText("Tayang Aktif");
    await toggle.click();
    await expect(toggle).toHaveText("Draft");
    await toggle.click();
    await expect(toggle).toHaveText("Tayang Aktif");
    expect(errors).toEqual([]);
});

test("saving a posting with a duplicate title keeps its own inspector open", async ({ page }) => {
    const { errors, mutations } = await mount(page, "/admin/job-postings?edit_id=8");
    await page.getByRole("button", { name: "Simpan Perubahan", exact: true }).click();
    await page.getByRole("button", { name: "Ya, Simpan", exact: true }).click();
    const inspector = page.getByRole("dialog");
    await expect(inspector.getByText("#8", { exact: true })).toBeVisible();
    await expect(inspector.getByText("Company B", { exact: true })).toBeVisible();
    expect(mutations).toContain("POST /rekrutmen/api/job-postings/8");
    expect(errors).toEqual([]);
});

test("candidate deep links open the requested profile", async ({ page }) => {
    const { errors } = await mount(page, "/admin/job-applications?application_id=41&job_id=8");
    await expect(page.getByRole("dialog", { name: "Profil pelamar", exact: true }).getByRole("heading", { name: "BUDI SANTOSO", exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test("read-only recruitment access keeps candidate browsing without mutation controls or background posts", async ({ page }) => {
    const permissions = {
        jobApplications: { viewAny: true, update: false },
        jobPostings: { viewAny: false },
    };
    const { errors, mutations } = await mount(page, "/admin/job-applications", false, false, permissions);
    const candidate = page.getByRole("group", { name: "Pelamar BUDI SANTOSO", exact: true });
    await expect(candidate).toBeVisible();
    await expect(candidate.getByRole("button", { name: "Detail" })).toBeVisible();
    await expect(candidate.getByTitle("Kirim Notifikasi (Email / WhatsApp)")).toHaveCount(0);
    await expect(candidate.locator("select")).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Cocokkan CV" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Analisis yang belum dinilai" })).toHaveCount(0);
    await candidate.getByRole("button", { name: "Detail" }).click();
    await expect(page.getByRole("dialog", { name: "Profil pelamar", exact: true })).toBeVisible();
    await expect(page.getByRole("button", { name: "Kirim Notifikasi", exact: true })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Analisis CV", exact: true })).toHaveCount(0);
    expect(mutations).toEqual([]);
    expect(errors).toEqual([]);
});

test("read-only job posting access hides create, publish, edit, and delete controls", async ({ page }) => {
    const permissions = { jobPostings: { viewAny: true, create: false, update: false, delete: false } };
    const { errors, mutations } = await mount(page, "/admin/job-postings?id=8", false, false, permissions);
    await expect(page.getByRole("dialog")).toBeVisible();
    await expect(page.getByRole("button", { name: "Tambah Lowongan" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Edit", exact: true })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Hapus Lowongan" })).toHaveCount(0);
    await expect(page.getByTitle("Klik untuk mengubah status publikasi")).toHaveCount(0);
    expect(mutations).toEqual([]);
    expect(errors).toEqual([]);
});

test("candidate stage options stay within the posting pipeline and custom stages appear in Kanban", async ({ page }) => {
    const { errors } = await mount(page, "/admin/job-applications");
    const candidate = page.getByRole("group", { name: "Pelamar BUDI SANTOSO", exact: true });
    const choices = candidate.locator("select option");
    await expect(choices).toHaveText(["Technical Review", "Technical Interview", "Ditolak"]);
    await page.getByRole("button", { name: "Kanban", exact: true }).click();
    await expect(page.getByText("BUDI SANTOSO", { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test("notification results retain per-candidate delivery and stage failures", async ({ page }) => {
    const { errors } = await mount(page, "/admin/job-applications");
    await page.getByTitle("Kirim Notifikasi (Email / WhatsApp)").click();
    await page.locator('input[type="checkbox"][value="whatsapp"]').uncheck();
    await page.getByRole("button", { name: "Kirim Notifikasi", exact: true }).click();
    const progress = page.getByRole("region", { name: "Progres pengiriman notifikasi" });
    await expect(progress.getByRole("cell", { name: "BUDI SANTOSO", exact: true })).toBeVisible();
    await expect(progress.getByText("Tahapan belum diperbarui", { exact: true })).toBeVisible();
    await expect(progress.locator('[title="Sender unavailable"]')).toHaveText("Gagal");
    expect(errors).toEqual([]);
});


test('batch screening queues once without blocking and replaces pending legacy scores only after completion', async ({ page }) => {
    await page.clock.install();
    const { errors, aiMutations, application } = await mount(page, '/admin/job-applications?job_id=8');
    const candidate = page.getByRole('group', { name: 'Pelamar BUDI SANTOSO', exact: true });
    await expect(candidate.getByText('Menunggu', { exact: true })).toBeVisible();
    await expect(candidate).not.toContainText('99%');
    await page.getByRole('button', { name: 'Analisis yang belum dinilai', exact: true }).click();
    await expect(page.getByText('CV masuk antrean. Tetap diproses walau halaman ditutup.', { exact: true })).toBeVisible();
    expect(aiMutations).toEqual([{ path: '/rekrutmen/api/applications/batch-analyze-ai', body: { job_id: '8', force: false } }]);
    await expect(page.locator('.swal2-container')).toHaveCount(0);
    await page.clock.runFor(5100);
    await expect(candidate.getByText('Antre', { exact: true })).toBeVisible();
    await candidate.getByRole('button', { name: 'Detail', exact: true }).click();
    const detail = page.getByRole('dialog', { name: 'Profil pelamar', exact: true });
    await expect(detail).not.toContainText('Skor lama berbasis aturan lokal');
    application.ai_screening_status = 'completed';
    application.ai_match_score = 81;
    application.ai_summary = 'Memenuhi pengalaman yang dipersyaratkan.';
    await page.clock.runFor(5100);
    await expect(detail).toContainText('81%');
    await expect(detail).toContainText('Memenuhi pengalaman yang dipersyaratkan.');
    await expect(page.getByRole('region', { name: 'Progres analisis CV' })).toContainText('1 / 1 selesai');
    expect(errors).toEqual([]);
});

test('selected candidates use a forced batch and unreadable CVs never display a numeric result', async ({ page }) => {
    await page.clock.install();
    const { errors, aiMutations, application } = await mount(page, '/admin/job-applications?job_id=8');
    const candidate = page.getByRole('group', { name: 'Pelamar BUDI SANTOSO', exact: true });
    await candidate.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Analisis ulang (1)', exact: true }).first().click();
    await page.getByRole('button', { name: 'Analisis Ulang', exact: true }).click();
    await expect.poll(() => aiMutations.length).toBe(1);
    expect(aiMutations).toEqual([{ path: '/rekrutmen/api/applications/batch-analyze-ai', body: { job_id: '8', force: true, application_ids: [41] } }]);
    application.ai_screening_status = 'needs_review';
    application.ai_screening_error = 'Teks PDF tidak terbaca. Unggah CV yang dapat dibaca.';
    await page.clock.runFor(5100);
    await expect(candidate.getByText('Perlu pemeriksaan', { exact: true })).toBeVisible();
    await expect(candidate).toContainText(application.ai_screening_error);
    await expect(candidate).not.toContainText('99%');
    expect(errors).toEqual([]);
});

test('screening polling pauses while hidden and stops on navigation', async ({ page }) => {
    await page.clock.install();
    const state = await mount(page, '/admin/job-applications');
    await page.clock.runFor(5100);
    await expect.poll(state.statusRequests).toBe(1);
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.clock.runFor(15000);
    expect(state.statusRequests()).toBe(1);
    await page.evaluate(() => {
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.clock.runFor(5100);
    await expect.poll(state.statusRequests).toBe(2);
    await page.evaluate(() => (window as unknown as { screeningRouter: { push: (path: string) => Promise<void> } }).screeningRouter.push('/empty'));
    await page.clock.runFor(15000);
    expect(state.statusRequests()).toBe(2);
    expect(state.errors).toEqual([]);
});

test('Master Rekrutmen tests unsaved endpoint and model and preserves a blank stored key', async ({ page }) => {
    const { errors, aiMutations } = await mount(page, '/configurations');
    await page.getByRole('button', { name: 'Integrasi AI', exact: true }).click();
    const key = page.getByLabel('OpenAI Compatible API Key', { exact: true });
    await expect(key).toHaveValue('');
    await expect(key).toHaveAttribute('type', 'password');
    await expect(page.getByRole('button', { name: 'Uji Koneksi API', exact: true })).toBeEnabled();
    await page.getByLabel('Endpoint API', { exact: true }).fill('https://new-router.example.test/v1');
    await page.getByLabel('Model', { exact: true }).fill('model-b');
    await page.getByRole('checkbox', { name: /Analisis otomatis CV baru/ }).uncheck();
    await page.getByRole('button', { name: 'Uji Koneksi API', exact: true }).click();
    await page.getByRole('button', { name: 'OK', exact: true }).click();
    expect(aiMutations.find(item => item.path.endsWith('/settings/ai/test'))?.body).toEqual({ api_key: '', base_url: 'https://new-router.example.test/v1', model: 'model-b' });
    await page.getByRole('button', { name: 'Simpan Perubahan', exact: true }).click();
    await page.getByRole('button', { name: 'Ya, Simpan', exact: true }).click();
    await expect.poll(() => aiMutations.find(item => item.path.endsWith('/settings/ai'))?.body).toEqual({ automatic: false, base_url: 'https://new-router.example.test/v1', model: 'model-b', api_key: '', clear_api_key: false });
    await expect(key).toHaveValue('');
    expect(errors).toEqual([]);
});


test('Master Rekrutmen requires an explicit clear action and disables connection testing while clearing', async ({ page }) => {
    const { errors, aiMutations } = await mount(page, '/configurations');
    await page.getByRole('button', { name: 'Integrasi AI', exact: true }).click();
    await page.getByRole('checkbox', { name: 'Hapus API key tersimpan saat menyimpan', exact: true }).check();
    await expect(page.getByText('API key dihapus; analisis berhenti sampai key baru disimpan.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Uji Koneksi API', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Simpan Perubahan', exact: true }).click();
    await page.getByRole('button', { name: 'Ya, Simpan', exact: true }).click();
    await expect.poll(() => aiMutations.find(item => item.path.endsWith('/settings/ai'))?.body.clear_api_key).toBe(true);
    expect(errors).toEqual([]);
});

test('Master Rekrutmen hides AI controls when the API denies settings access', async ({ page }) => {
    const { errors, aiMutations } = await mount(page, '/configurations', true);
    await page.getByRole('button', { name: 'Integrasi AI', exact: true }).click();
    await expect(page.getByRole('alert')).toHaveText('Anda tidak memiliki akses untuk mengelola pengaturan AI.');
    await expect(page.getByLabel('Endpoint API', { exact: true })).toHaveCount(0);
    await expect(page.getByLabel('OpenAI Compatible API Key', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Simpan Perubahan', exact: true })).toHaveCount(0);
    expect(aiMutations).toEqual([]);
    expect(errors).toEqual([]);
});


test('pending candidates appear after screening completes while a score filter remains selected', async ({ page }) => {
    await page.clock.install();
    const { errors, application } = await mount(page, '/admin/job-applications?job_id=8', false, true);
    const candidate = page.getByRole('group', { name: 'Pelamar BUDI SANTOSO', exact: true });
    const scoreFilter = page.getByRole('button', { name: /Sangat Sesuai/ });
    await scoreFilter.click();
    await expect(page.getByRole('group', { name: 'Pelamar SITI AMINAH', exact: true })).toBeVisible();
    await expect(candidate).toHaveCount(0);
    application.ai_screening_status = 'completed';
    application.ai_match_score = 81;
    application.ai_summary = 'Memenuhi pengalaman yang dipersyaratkan.';
    await page.clock.runFor(5100);
    await expect(candidate).toBeVisible({ timeout: 3000 });
    await expect(candidate).toContainText('81%');
    await expect(scoreFilter).toHaveClass(/text-emerald-800/);
    await expect(scoreFilter).toContainText('2');
    expect(errors).toEqual([]);
});
