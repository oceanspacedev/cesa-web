import { expect, type Locator, type Page } from "@playwright/test";

export type ExitClearancePersonalData = {
    name: string;
    email: string;
    phone: string;
    position: string;
    placement: string;
    company: string;
    department: string;
    joinDate: string;
    departureDate: string;
};

export class ExitClearancePage {
    readonly page: Page;

    readonly pageTitle: Locator;
    readonly nextButton: Locator;
    readonly submitButton: Locator;
    readonly companyTrigger: Locator;
    readonly departmentTrigger: Locator;

    readonly progressPageTitle: Locator;
    readonly currentStatusLabel: Locator;
    readonly approvalFlowHeading: Locator;

    readonly publicUrlCandidates: string[];

    constructor(page: Page) {
        this.page = page;
        this.pageTitle = page.getByRole("heading", { name: "FORM EXIT CLEARANCE" });
        this.nextButton = page.getByRole("button", { name: "Berikutnya" });
        this.submitButton = page.getByRole("button", { name: "Kirim" });
        this.companyTrigger = page
            .locator(
                '[wire\\:key*="form.company_id"] button.fi-select-input-btn, [wire\\:key*="company_id"] button.fi-select-input-btn'
            )
            .first();
        this.departmentTrigger = page
            .locator(
                '[wire\\:key*="form.department_id"] button.fi-select-input-btn, [wire\\:key*="department_id"] button.fi-select-input-btn'
            )
            .first();
        this.progressPageTitle = page.getByRole("heading", { name: "PROGRES EXIT CLEARANCE" });
        this.currentStatusLabel = page.getByText("Status saat ini");
        this.approvalFlowHeading = page.getByRole("heading", { name: "ALUR PERSETUJUAN" });
        this.publicUrlCandidates = [
            process.env.EXIT_CLEARANCE_PUBLIC_URL ?? "http://web-cesa.test/exit-clearance",
            "/exit-clearance",
        ];
    }

    async gotoRequestForm(): Promise<void> {
        let lastError: unknown;

        for (const url of this.publicUrlCandidates) {
            try {
                await this.page.goto(url, { waitUntil: "domcontentloaded" });
                await expect(this.pageTitle).toBeVisible({ timeout: 15_000 });
                await expect(this.page).toHaveURL(/exit-clearance/);

                return;
            } catch (error) {
                lastError = error;
            }
        }

        throw lastError instanceof Error
            ? lastError
            : new Error("Unable to open the exit clearance public form.");
    }

    async goToPersonalDataStep(): Promise<void> {
        await expect(this.page.getByText("Halaman 1 dari 4")).toBeVisible();
        await this.nextButton.click();
        await expect(this.page.getByRole("heading", { name: "Data Diri" })).toBeVisible();
        await expect(this.page.getByText("Halaman 2 dari 4")).toBeVisible();
    }

    async fillPersonalData(personalData: ExitClearancePersonalData): Promise<void> {
        await this.page.locator('#form\\.name').fill(personalData.name);
        await this.page.locator('#form\\.email').fill(personalData.email);
        await this.page.locator('#form\\.phone').fill(personalData.phone);
        await this.page.locator('#form\\.position').fill(personalData.position);
        await this.page.locator('#form\\.placement').fill(personalData.placement);
        await this.selectCompany(personalData.company);
        await this.selectDepartment(personalData.department);
        await this.page.locator('#form\\.join_date').fill(personalData.joinDate);
        await this.page.locator('#form\\.departure_date').fill(personalData.departureDate);
    }

    async goToQuestionnaireStep(): Promise<void> {
        await this.nextButton.click();
        await expect(this.page.getByRole("heading", { name: "Wawancara Keluar" })).toBeVisible();
        await expect(this.page.getByText("Halaman 3 dari 4")).toBeVisible();
    }

    async expectPersonalDataValidationErrors(): Promise<void> {
        await this.nextButton.click();
        await expect(this.page.getByText("Halaman 2 dari 4")).toBeVisible();
        await expect(this.page.getByText("Wajib diisi.").first()).toBeVisible();
    }

    async fillQuestionnaireAnswers(answerPrefix: string): Promise<void> {
        const answers = [
            `Alasan resign ${answerPrefix}`,
            `Beban kerja ${answerPrefix}`,
            `Jenjang karir ${answerPrefix}`,
            `Fasilitas ${answerPrefix}`,
            `Hubungan kerja ${answerPrefix}`,
            `Kompensasi ${answerPrefix}`,
            `Masukan divisi ${answerPrefix}`,
            `Masukan perusahaan ${answerPrefix}`,
        ];

        const fields = [
            "reason",
            "workload_feedback",
            "career_growth_feedback",
            "facility_welfare_feedback",
            "work_relationship_feedback",
            "compensation_feedback",
            "division_feedback",
            "company_feedback",
        ];

        for (const [index, field] of fields.entries()) {
            await this.page.locator(`#form\\.${field}`).fill(answers[index]);
        }
    }

    async goToClearanceStep(): Promise<void> {
        await this.nextButton.click();
        await expect(this.page.getByRole("heading", { name: "Exit Clearance" })).toBeVisible();
        await expect(this.page.getByText("Halaman 4 dari 4")).toBeVisible();
    }

    async fillClearanceAnswers(answer: string): Promise<void> {
        const fields = [
            "clearance_kartu_halo",
            "clearance_employee_debt",
            "clearance_uniform_return",
            "clearance_vehicle_return",
            "clearance_inventory_return",
            "clearance_account_deactivation",
            "clearance_receivable_data",
            "clearance_promotor_internal",
            "clearance_nota_pending",
            "clearance_stock_opname",
        ];

        for (const field of fields) {
            await this.page.locator(`#form\\.${field}`).fill(answer);
        }
    }

    async submit(): Promise<void> {
        await this.submitButton.click();
        await expect(this.page).toHaveURL(/exit-clearance\/progress\//, { timeout: 30_000 });
        await expect(this.progressPageTitle).toBeVisible();
        await expect(this.currentStatusLabel).toBeVisible();
        await expect(this.approvalFlowHeading).toBeVisible();
    }

    async submitRequest(input: {
        personalData: ExitClearancePersonalData;
        questionnaireAnswerPrefix: string;
        clearanceAnswer: string;
    }): Promise<void> {
        await this.gotoRequestForm();
        await this.goToPersonalDataStep();
        await this.fillPersonalData(input.personalData);
        await this.goToQuestionnaireStep();
        await this.fillQuestionnaireAnswers(input.questionnaireAnswerPrefix);
        await this.goToClearanceStep();
        await this.fillClearanceAnswers(input.clearanceAnswer);
        await this.submit();
    }

    async gotoApprovalPath(approvalPath: string): Promise<void> {
        await this.page.goto(approvalPath, { waitUntil: "domcontentloaded" });
        await expect(this.page.getByRole("heading", { name: "Permintaan Persetujuan" })).toBeVisible();
    }

    async rejectApproval(note: string): Promise<void> {
        await this.page.locator('#form\\.notes').fill(note);
        await this.page.getByRole("button", { name: "Tolak" }).click();
        await expect(this.page.getByText("Tahapan ini sudah diproses. Anda dapat menutup halaman ini.").first()).toBeVisible();
    }

    async approveApproval(note: string): Promise<void> {
        await this.page.locator('#form\\.notes').fill(note);
        await this.page.getByRole("button", { name: "Setujui" }).click();
        await expect(this.page.getByText("Tahapan ini sudah diproses. Anda dapat menutup halaman ini.").first()).toBeVisible();
    }

    async gotoProgressPath(progressPath: string): Promise<void> {
        await this.page.goto(progressPath, { waitUntil: "domcontentloaded" });
        await expect(this.progressPageTitle).toBeVisible();
    }

    async assertProgressPageShowsSubmission(submission: {
        applicantName: string;
        company: string;
        department: string;
        questionnaireAnswer: string;
        clearanceAnswer: string;
    }): Promise<void> {
        await expect(
            this.page.locator("span.font-medium.text-gray-900").filter({ hasText: submission.applicantName }).first()
        ).toBeVisible();
        await expect(this.page.getByText(submission.company, { exact: true })).toBeVisible();
        await expect(this.page.getByText(`(${submission.department})`).first()).toBeVisible();
        await expect(this.page.locator("span.font-mono").filter({ hasText: /^EXC-\d{5}$/ })).toBeVisible();
        await expect(
            this.page.locator("span.rounded-full").filter({ hasText: /pending|approved|rejected/i }).first()
        ).toBeVisible();

        await this.page.getByRole("button", { name: "Kuesioner" }).click();
        await expect(this.page.getByText(submission.questionnaireAnswer).first()).toBeVisible();

        await this.page.getByRole("button", { name: "Kliring" }).click();
        await expect(this.page.getByText(submission.clearanceAnswer).first()).toBeVisible();
    }

    async assertProgressPageShowsRejectedState(note: string): Promise<void> {
        await expect(this.page.locator("span.rounded-full").filter({ hasText: "Rejected" }).first()).toBeVisible();
        await expect(this.page.getByText(note).first()).toBeVisible();
    }

    async assertProgressPageShowsApprovedState(note: string): Promise<void> {
        await expect(this.page.locator("span.rounded-full").filter({ hasText: "Approved" }).first()).toBeVisible();
        await expect(this.page.getByText(note).first()).toBeVisible();
    }

    private async selectCompany(company: string): Promise<void> {
        await this.companyTrigger.click();
        await this.page.getByRole("textbox", { name: "Search", exact: true }).fill(company);
        const option = this.page
            .locator('[role="option"]:visible, li.fi-select-input-option:visible')
            .filter({ hasText: new RegExp(`^${this.escapeRegExp(company)}$`, "i") })
            .first();

        await expect(option).toBeVisible();
        await option.click();
    }

    private async selectDepartment(department: string): Promise<void> {
        await this.departmentTrigger.click();
        const option = this.page
            .locator('[role="option"]:visible, li.fi-select-input-option:visible')
            .filter({ hasText: new RegExp(`^${this.escapeRegExp(department)}$`, "i") })
            .first();

        await expect(option).toBeVisible();
        await option.click();
    }

    private escapeRegExp(value: string): string {
        return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    }
}
