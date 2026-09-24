import path from "node:path";
import { fileURLToPath } from "node:url";
import { expect, test, type Page } from "@playwright/test";
import vue from "@vitejs/plugin-vue";
import { build } from "vite";

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
const viewPath = path.join(repositoryRoot, "plugins/cesa/rekrutmen/resources/js/views/JobApplicationsView.vue");
const origin = "http://rekrutmen-dialog.test";
const encodedHandler = Array.from("globalThis.__evaluationXss=true")
    .map((character) => `&#${character.charCodeAt(0)};`)
    .join("");
const maliciousName = `BUDI <IMG SRC=X ONERROR="${encodedHandler}"> & ' "`;
let javascript = "";
let styles = "";

test.setTimeout(45_000);

test.beforeAll(async () => {
    const entry = "virtual:job-application-dialog-test";
    const result = await build({
        root: repositoryRoot,
        configFile: false,
        resolve: { alias: { "frappe-ui/src": path.join(repositoryRoot, "node_modules/frappe-ui/src") } },
        logLevel: "error",
        plugins: [
            {
                name: "job-application-dialog-test",
                resolveId: (id) => id === entry ? `\0${entry}` : undefined,
                load: (id) => id === `\0${entry}` ? `
                    import { createApp } from 'vue';
                    import { createPinia } from 'pinia';
                    import { createRouter, createMemoryHistory } from 'vue-router';
                    import View from ${JSON.stringify(viewPath)};
                    const router = createRouter({
                        history: createMemoryHistory(),
                        routes: [{ path: '/admin/job-applications', component: View }],
                    });
                    const app = createApp(View).use(createPinia()).use(router);
                    router.push('/admin/job-applications' + window.location.search);
                    router.isReady().then(() => app.mount('#app'));
                ` : undefined,
            },
            vue(),
        ],
        css: { postcss: { plugins: [] } },
        build: {
            write: false,
            minify: false,
            cssCodeSplit: false,
            rollupOptions: {
                input: entry,
                output: { format: "iife", name: "DialogSecurityTest", inlineDynamicImports: true },
            },
        },
    });
    if (Array.isArray(result) || !("output" in result)) {
        throw new Error("Expected a single in-memory browser bundle.");
    }
    for (const output of result.output) {
        if (output.type === "chunk") {
            javascript += output.code;
        } else if (output.fileName.endsWith(".css")) {
            styles += String(output.source);
        }
    }
});

async function mountApplications(page: Page, candidateName: string, jobTitle?: string, completed = false) {
    const evaluationRequests: { path: string; method: string; body: unknown }[] = [];
    let finishEvaluation!: (response: Record<string, unknown>) => void;
    const evaluationResponse = new Promise<Record<string, unknown>>((resolve) => {
        finishEvaluation = resolve;
    });
    const application = {
        id: 41,
        full_name: candidateName,
        email: "candidate@example.test",
        status: "in_progress",
        ai_screening_status: completed ? 'completed' : 'pending',
        ai_match_score: completed ? 81 : null,
        job_posting_id: 7,
        job_posting: { id: 7, title: jobTitle || "Engineer" },
    };

    await page.route("**/*", async (route) => {
        const request = route.request();
        const pathname = new URL(request.url()).pathname;
        if (request.isNavigationRequest()) {
            await route.fulfill({ contentType: "text/html", body: '<html><body class="rekrutmen-spa"><div id="app" data-permissions=\'{"jobApplications":{"viewAny":true,"update":true},"jobPostings":{"viewAny":true}}\'></div></body></html>' });
        } else if (pathname === "/rekrutmen/api/applications") {
            await route.fulfill({ json: {
                applications: [application],
                stages: [],
                active_job: jobTitle ? application.job_posting : null,
            } });
        } else if (pathname === "/rekrutmen/api/job-postings") {
            await route.fulfill({ json: { data: [application.job_posting] } });
        } else if (pathname === "/rekrutmen/api/notifications/heartbeat") {
            await route.fulfill({ json: { processed: 0 } });
        } else if (pathname.endsWith("/analyze-ai") || pathname.endsWith("/batch-analyze-ai")) {
            evaluationRequests.push({ path: pathname, method: request.method(), body: request.postDataJSON() });
            await route.fulfill({ status: 202, json: await evaluationResponse });
        } else if (pathname.endsWith('/ai-status')) {
            await route.fulfill({ json: { total: 1, counts: { [application.ai_screening_status]: 1 }, applications: [application] } });
        } else {
            await route.abort();
        }
    });
    await page.goto(`${origin}/admin/job-applications${jobTitle ? "?job_id=7" : ""}`);
    await page.addStyleTag({ content: styles });
    await page.addScriptTag({ content: javascript });
    const candidateRow = page.getByRole("group").filter({ hasText: "candidate@example.test" });
    await expect(candidateRow).toBeVisible();
    return { candidateRow, evaluationRequests, finishEvaluation };
}

async function expectNoInjectedMarkup(page: Page) {
    await expect(page.locator(".swal2-title, .swal2-html-container").locator("img, script, [onerror]")).toHaveCount(0);
    await expect(page.locator('[onerror]')).toHaveCount(0);
    expect(await page.evaluate(() => Reflect.get(globalThis, "__evaluationXss"))).toBeUndefined();
}

for (const [scenario, candidateName] of [["ordinary", "BUDI SANTOSO"], ["HTML-shaped", maliciousName]]) {
    test(`selected candidate queues safely with ${scenario} names`, async ({ page }) => {
        const { candidateRow, evaluationRequests, finishEvaluation } = await mountApplications(page, candidateName);
        await expect(candidateRow).toContainText(candidateName);
        await candidateRow.getByRole("checkbox").check();
        const evaluateButton = page.getByRole("button", { name: "Analisis ulang (1)", exact: true }).first();
        await evaluateButton.click();
        await expect(page.locator(".swal2-title")).toHaveText('Analisis ulang 1 pelamar terpilih?');
        await expectNoInjectedMarkup(page);
        await page.getByRole("button", { name: "Batal", exact: true }).click();
        await expect(page.locator(".swal2-popup")).toHaveCount(0);
        expect(evaluationRequests).toEqual([]);

        await evaluateButton.click();
        await page.getByRole("button", { name: "Analisis Ulang", exact: true }).click();
        await expect(page.locator(".swal2-popup")).toHaveCount(0);
        await expectNoInjectedMarkup(page);
        await expect.poll(() => evaluationRequests).toEqual([{
            path: "/rekrutmen/api/applications/batch-analyze-ai", method: "POST", body: { job_id: null, application_ids: [41], force: true },
        }]);

        const message = `CV ${candidateName} masuk antrean.`;
        finishEvaluation({ success: true, queued: 1, total: 1, skipped: 0, message });
        await expect(page.getByText(message, { exact: true })).toBeVisible();
        await expectNoInjectedMarkup(page);
        await expect(candidateRow.getByRole("checkbox")).not.toBeChecked();
    });
}

test("individual evaluation treats API queue messages as text without a blocking modal", async ({ page }) => {
    const { candidateRow, evaluationRequests, finishEvaluation } = await mountApplications(page, maliciousName);
    await candidateRow.getByRole("button", { name: "Analisis CV", exact: true }).click();
    await expect(page.locator(".swal2-popup")).toHaveCount(0);
    await expectNoInjectedMarkup(page);
    await expect.poll(() => evaluationRequests).toEqual([{
        path: '/rekrutmen/api/applications/41/analyze-ai', method: 'POST', body: { force: false },
    }]);

    const message = `Analisis dijadwalkan untuk ${maliciousName}`;
    finishEvaluation({ success: true, queued: true, message });
    await expect(page.getByText(message, { exact: true })).toBeVisible();
    await expectNoInjectedMarkup(page);
});

test("individual reanalysis renders a candidate name literally and forces only after confirmation", async ({ page }) => {
    const { candidateRow, evaluationRequests, finishEvaluation } = await mountApplications(page, maliciousName, undefined, true);
    await candidateRow.getByRole('button', { name: 'Detail', exact: true }).click();
    await page.getByRole('button', { name: 'Analisis Ulang', exact: true }).click();
    await expect(page.locator('.swal2-title')).toHaveText(`Analisis ulang ${maliciousName}?`);
    await expectNoInjectedMarkup(page);
    await page.getByRole('button', { name: 'Batal', exact: true }).click();
    expect(evaluationRequests).toEqual([]);
    await page.getByRole('button', { name: 'Analisis Ulang', exact: true }).click();
    await page.locator('.swal2-confirm').click();
    await expect.poll(() => evaluationRequests).toEqual([{
        path: '/rekrutmen/api/applications/41/analyze-ai', method: 'POST', body: { force: true },
    }]);
    finishEvaluation({ success: true, queued: true, message: 'Analisis ulang masuk antrean.' });
    await expect(page.getByText('Analisis ulang masuk antrean.', { exact: true })).toBeVisible();
    await expectNoInjectedMarkup(page);
});

test("filtered evaluation treats an HTML-shaped job title literally and queues once", async ({ page }) => {
    const jobTitle = `ENGINEER ${maliciousName}`;
    const { evaluationRequests, finishEvaluation } = await mountApplications(page, "BUDI SANTOSO", jobTitle);
    await expect(page.getByRole('heading', { name: `Pelamar: ${jobTitle}`, exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Analisis yang belum dinilai", exact: true }).click();
    await expect(page.locator('.swal2-popup')).toHaveCount(0);
    await expectNoInjectedMarkup(page);
    await expect.poll(() => evaluationRequests).toEqual([{
        path: "/rekrutmen/api/applications/batch-analyze-ai",
        method: "POST",
        body: { job_id: "7", force: false },
    }]);
    const message = `Antrean analisis untuk ${jobTitle} berhasil dibuat.`;
    finishEvaluation({ success: true, queued: 1, total: 1, skipped: 0, message });
    await expect(page.getByText(message, { exact: true })).toBeVisible();
    await expectNoInjectedMarkup(page);
});
