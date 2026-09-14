import { expect, type Page } from "@playwright/test";

export class ExitClearanceAdminPage {
    readonly page: Page;

    constructor(page: Page) {
        this.page = page;
    }

    async gotoRequestView(requestId: number): Promise<void> {
        await this.page.goto(`/admin/requests/${requestId}`);
        await expect(this.page).toHaveURL(new RegExp(`/admin/requests/${requestId}$`));
    }

    async gotoRequestViewExpectForbidden(requestId: number): Promise<void> {
        const response = await this.page.goto(`/admin/requests/${requestId}`, {
            waitUntil: "domcontentloaded",
        });

        expect(response).not.toBeNull();
        expect([403, 404]).toContain(response?.status());
    }

    async gotoDepartmentsConfiguration(): Promise<void> {
        await this.page.goto("/admin/exit-clearance/configurations/departments");
        await expect(this.page).toHaveURL(/exit-clearance\/configurations\/departments/);
        await expect(this.page.locator("table, div.fi-ta-empty-state").first()).toBeVisible();
    }

    async gotoApproversConfiguration(): Promise<void> {
        await this.page.goto("/admin/exit-clearance/configurations/approvers");
        await expect(this.page).toHaveURL(/exit-clearance\/configurations\/approvers/);
        await expect(this.page.locator("table, div.fi-ta-empty-state").first()).toBeVisible();
    }

    async switchToArchivedTab(): Promise<void> {
        await this.switchToTab(/Diarsipkan|Archived/i);
    }

    async restoreArchivedRecord(recordName: string): Promise<void> {
        const row = await this.findRow(recordName);
        await this.rowActionLocator(row, /restore|pulihkan/i).click();
        await this.confirmDialogAction(/restore|pulihkan/i);
        await this.expectSuccessToast();
    }

    async forceDeleteArchivedRecord(recordName: string): Promise<void> {
        const row = await this.findRow(recordName);
        await this.rowActionLocator(row, /force delete|hapus selamanya/i).click();
        await this.confirmDialogAction(/delete|hapus/i);
        await this.expectSuccessToast();
    }

    private async switchToTab(label: RegExp): Promise<void> {
        await this.page
            .locator('[role="tab"], button, a')
            .filter({ hasText: label })
            .first()
            .click();
    }

    async assertRecordVisible(recordName: string): Promise<void> {
        await this.searchRecord(recordName);
        await expect(this.rowLocator(recordName)).toBeVisible();
    }

    async assertRecordNotVisible(recordName: string): Promise<void> {
        await this.searchRecord(recordName);
        await expect(this.rowLocator(recordName)).toHaveCount(0);
    }

    async assertArchivedActionsVisible(recordName: string): Promise<void> {
        const row = await this.findRow(recordName);
        await expect(this.rowActionLocator(row, /restore|pulihkan/i)).toBeVisible();
        await expect(this.rowActionLocator(row, /force delete|hapus selamanya/i)).toBeVisible();
    }

    async assertArchivedActionsHidden(recordName: string): Promise<void> {
        const row = await this.findRow(recordName);
        await expect(this.rowActionLocator(row, /restore|pulihkan/i)).toHaveCount(0);
        await expect(this.rowActionLocator(row, /force delete|hapus selamanya/i)).toHaveCount(0);
    }

    async assertRequestDetails(input: {
        applicantName: string;
        company?: string;
        email: string;
        uid: string;
        status: string;
    }): Promise<void> {
        await expect(this.page.getByText(input.applicantName).first()).toBeVisible();
        if (input.company) {
            await expect(this.page.getByText(input.company, { exact: true })).toBeVisible();
        }
        await expect(this.page.getByText(input.email).first()).toBeVisible();
        await expect(this.page.getByText(input.uid).first()).toBeVisible();
        await expect(this.page.getByText(input.status).first()).toBeVisible();
    }

    private async searchRecord(keyword: string): Promise<void> {
        const searchInput = this.page.locator(".fi-input.fi-input-has-inline-prefix").nth(1);
        await searchInput.fill(keyword);
    }

    private rowLocator(recordName: string) {
        return this.page.getByRole("row").filter({ hasText: recordName });
    }

    private async findRow(recordName: string) {
        await this.searchRecord(recordName);
        const row = this.rowLocator(recordName).first();

        await expect(row).toBeVisible();

        return row;
    }

    private rowActionLocator(row: ReturnType<ExitClearanceAdminPage["rowLocator"]>, name: RegExp) {
        return row.locator("button, a").filter({ hasText: name }).first();
    }

    private async confirmDialogAction(name: RegExp): Promise<void> {
        await this.page.getByRole("dialog").getByRole("button", { name }).last().click();
    }

    private async expectSuccessToast(): Promise<void> {
        await expect(this.page.locator("h3.fi-no-notification-title, .fi-toast-message-success").first()).toBeVisible();
    }
}
