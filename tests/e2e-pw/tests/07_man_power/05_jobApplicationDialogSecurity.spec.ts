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

async function mountApplications(page: Page, candidateName: string, jobTitle?: string) {
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
        ai_match_score: null,
        job_posting_id: 7,
        job_posting: { id: 7, title: jobTitle || "Engineer" },
    };

    await page.route("**/*", async (route) => {
        const request = route.request();
        const pathname = new URL(request.url()).pathname;
        if (request.isNavigationRequest()) {
            await route.fulfill({ contentType: "text/html", body: '<html><body class="rekrutmen-spa"><div id="app"></div></body></html>' });
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
            await route.fulfill({ json: await evaluationResponse });
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
    expect(await page.evaluate(() => Reflect.get(globalThis, "__evaluationXss"))).toBeUndefined();
}

for (const [scenario, candidateName] of [["ordinary", "BUDI SANTOSO"], ["HTML-shaped", maliciousName]]) {
    test(`selected candidate evaluation renders ${scenario} names literally in every dialog`, async ({ page }) => {
        const { candidateRow, evaluationRequests, finishEvaluation } = await mountApplications(page, candidateName);
        await candidateRow.getByRole("checkbox").check();
        const evaluateButton = page.getByRole("button", { name: "Evaluasi AI (1)", exact: true }).first();
        await evaluateButton.click();

        await expect(page.locator(".swal2-title")).toHaveText(`Evaluasi AI: ${candidateName}?`);
        await expect(page.locator(".swal2-html-container b")).toHaveText(candidateName);
        await expectNoInjectedMarkup(page);
        await page.getByRole("button", { name: "Batal", exact: true }).click();
        await expect(page.locator(".swal2-popup")).toHaveCount(0);
        expect(evaluationRequests).toEqual([]);

        await evaluateButton.click();
        await page.getByRole("button", { name: "Evaluasi Sekarang", exact: true }).click();
        await expect(page.locator(".swal2-title")).toHaveText("Mengevaluasi Pelamar");
        await expect(page.locator(".swal2-html-container b")).toHaveText(candidateName);
        await expectNoInjectedMarkup(page);
        await expect.poll(() => evaluationRequests).toEqual([{
            path: "/rekrutmen/api/applications/41/analyze-ai", method: "POST", body: null,
        }]);

        finishEvaluation({ success: true });
        await expect(page.locator(".swal2-title")).toHaveText("Evaluasi Berhasil");
        await expect(page.locator(".swal2-html-container")).toHaveText(`Evaluasi untuk "${candidateName}" berhasil diperbarui.`);
        await expectNoInjectedMarkup(page);
        await expect(candidateRow.getByRole("checkbox")).not.toBeChecked();
    });
}

test("individual evaluation treats candidate names and API success messages as text", async ({ page }) => {
    const { candidateRow, evaluationRequests, finishEvaluation } = await mountApplications(page, maliciousName);
    await candidateRow.getByRole("button", { name: "Evaluasi AI", exact: true }).click();
    await expect(page.locator(".swal2-title")).toHaveText("Mengevaluasi Pelamar");
    await expect(page.locator(".swal2-html-container b")).toHaveText(maliciousName);
    await expectNoInjectedMarkup(page);
    await expect.poll(() => evaluationRequests.length).toBe(1);

    const message = `Evaluasi selesai untuk ${maliciousName}`;
    finishEvaluation({ success: true, message });
    await expect(page.locator(".swal2-title")).toHaveText("Evaluasi Berhasil");
    await expect(page.locator(".swal2-html-container")).toHaveText(message);
    await expectNoInjectedMarkup(page);
});

test("filtered evaluation renders an HTML-shaped job title literally", async ({ page }) => {
    const jobTitle = `ENGINEER ${maliciousName}`;
    const { evaluationRequests, finishEvaluation } = await mountApplications(page, "BUDI SANTOSO", jobTitle);
    await page.getByRole("button", { name: "Evaluasi AI", exact: true }).first().click();
    await expect(page.locator(".swal2-title")).toHaveText(`Screening AI: ${jobTitle}`);
    await expect(page.locator(".swal2-html-container b")).toHaveText(jobTitle);
    await expectNoInjectedMarkup(page);
    await expect.poll(() => evaluationRequests.length).toBe(1);
    expect(evaluationRequests[0]).toMatchObject({
        path: "/rekrutmen/api/applications/batch-analyze-ai",
        method: "POST",
        body: { job_id: "7", force: true },
    });
    finishEvaluation({ success: true, count: 1, total: 1, has_more: false });
    await expect(page.locator(".swal2-title")).toHaveText("Evaluasi Selesai");
    await expectNoInjectedMarkup(page);
});
