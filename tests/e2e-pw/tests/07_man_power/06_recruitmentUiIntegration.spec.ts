import path from "node:path";
import { fileURLToPath } from "node:url";
import { expect, test, type Page } from "@playwright/test";
import vue from "@vitejs/plugin-vue";
import { build } from "vite";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
const views = path.join(root, "plugins/cesa/rekrutmen/resources/js/views");
let javascript = "";
let styles = "";

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
                const router = createRouter({ history: createMemoryHistory(), routes: [
                    { path: '/admin/job-applications', name: 'applications', component: Applications },
                    { path: '/admin/job-postings', name: 'postings', component: Postings },
                    { path: '/admin/dashboard', name: 'dashboard', component: Dashboard },
                ] });
                const app = createApp({ render: () => h(RouterView) }).use(createPinia()).use(router);
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

async function mount(page: Page, pathname: string) {
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
    const application = { id: 41, full_name: "BUDI SANTOSO", email: "candidate@example.test", status: "in_progress", current_stage_id: 9, stage: stages[1], job_posting_id: 8, job_posting: postings[1] };
    const mutations: string[] = [];
    await page.route("**/*", async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        let data: unknown = {};
        if (request.isNavigationRequest()) {
            await route.fulfill({ contentType: "text/html", body: '<html><body class="rekrutmen-spa"><div id="app"></div></body></html>' });
            return;
        }
        if (request.method() !== "GET") mutations.push(`${request.method()} ${url.pathname}`);
        if (url.pathname.endsWith("/dashboard")) data = { stats: {}, recent_applications: [{ ...application, name: application.full_name }] };
        else if (url.pathname.endsWith("/applications")) data = { applications: [application], stages, active_job: url.searchParams.has("job_id") ? application.job_posting : null };
        else if (url.pathname.endsWith("/job-postings")) data = { data: postings, pipelines: [{ id: 1, name: "Default" }, { id: 2, name: "Technical" }] };
        else if (url.pathname.endsWith("/companies")) data = [{ id: 1, name: "Company A" }, { id: 2, name: "Company B" }];
        else if (url.pathname.endsWith("/configurations")) data = { pipelines: [{ id: 1, name: "Default" }, { id: 2, name: "Technical" }], stages };
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
    return { errors, mutations };
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
