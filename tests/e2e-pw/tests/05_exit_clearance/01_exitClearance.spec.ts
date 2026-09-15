import { test } from "../../setup";
import { CompanyManagementPage } from "../../pages/03_companyManagement";
import { ExitClearanceAdminPage } from "../../pages/07_exitClearanceAdmin";
import { ExitClearancePage, type ExitClearancePersonalData } from "../../pages/06_exitClearance";
import { loginWithCredentials } from "../../utils/admin";
import {
    createPublicExitClearanceRequest,
    getExitClearanceRequestMetadata,
    getUserResourcePermission,
    setUserResourcePermission,
    softDeleteExitClearanceApprover,
    softDeleteExitClearanceDepartment,
    upsertExitClearanceApprover,
    upsertExitClearanceDepartment,
} from "../../utils/exitClearance";

test.describe("Exit Clearance Public Flow E2E", () => {
    const buildPersonalData = (key: number): ExitClearancePersonalData => ({
        name: `E2E Exit Clearance ${key}`,
        email: `exit-clearance+${key}@example.com`,
        phone: `08123${key.toString().slice(-7)}`,
        position: "QA Engineer",
        placement: "Jakarta",
        company: `E2E Exit Clearance Company ${key}`,
        department: "HR",
        joinDate: "2022-01-10",
        departureDate: "2026-03-28",
    });

    test("Personal data step validates required fields", async ({ page }) => {
        const exitClearancePage = new ExitClearancePage(page);

        await exitClearancePage.gotoRequestForm();
        await exitClearancePage.goToPersonalDataStep();
        await exitClearancePage.expectPersonalDataValidationErrors();
    });

    test("Public form submission is visible from admin request detail", async ({ page, adminPage }) => {
        const exitClearancePage = new ExitClearancePage(page);
        const adminRequestPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const personalData = buildPersonalData(key);
        const questionnaireAnswer = `Alasan resign ${key}`;
        const clearanceAnswer = "Tidak ada";

        const companyPage = new CompanyManagementPage(adminPage);
        await companyPage.gotoCompaniesPage();
        await companyPage.createCompany({ name: personalData.company });

        await exitClearancePage.submitRequest({
            personalData,
            questionnaireAnswerPrefix: String(key),
            clearanceAnswer,
        });

        await exitClearancePage.assertProgressPageShowsSubmission({
            applicantName: personalData.name,
            company: personalData.company,
            department: personalData.department,
            questionnaireAnswer,
            clearanceAnswer,
        });

        const metadata = getExitClearanceRequestMetadata(personalData.email);

        await adminRequestPage.gotoRequestView(metadata.requestId);
        await adminRequestPage.assertRequestDetails({
            applicantName: personalData.name,
            company: personalData.company,
            email: personalData.email,
            uid: metadata.uid,
            status: "Pending",
        });
    });

    test("Approver can reject a submission and progress page reflects the rejected state", async ({ page, adminPage }) => {
        const exitClearancePage = new ExitClearancePage(page);
        const key = Date.now();
        const personalData = buildPersonalData(key);
        const rejectionNote = `Reject note ${key}`;

        const companyPage = new CompanyManagementPage(adminPage);
        await companyPage.gotoCompaniesPage();
        await companyPage.createCompany({ name: personalData.company, status: "false" });

        await exitClearancePage.submitRequest({
            personalData,
            questionnaireAnswerPrefix: String(key),
            clearanceAnswer: "Tidak ada",
        });

        const metadata = getExitClearanceRequestMetadata(personalData.email);

        if (! metadata.approvalPath) {
            throw new Error(`No approval path generated for ${personalData.email}`);
        }

        await exitClearancePage.gotoApprovalPath(metadata.approvalPath);
        await exitClearancePage.rejectApproval(rejectionNote);
        await exitClearancePage.gotoProgressPath(metadata.progressPath);
        await exitClearancePage.assertProgressPageShowsRejectedState(rejectionNote);
    });

    test("Approvers can approve until final and request becomes approved", async ({ page, adminPage }) => {
        const exitClearancePage = new ExitClearancePage(page);
        const adminRequestPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const personalData = buildPersonalData(key);
        const finalApprovalNote = `Approve note final ${key}`;

        const companyPage = new CompanyManagementPage(adminPage);
        await companyPage.gotoCompaniesPage();
        await companyPage.createCompany({ name: personalData.company });

        await exitClearancePage.submitRequest({
            personalData,
            questionnaireAnswerPrefix: String(key),
            clearanceAnswer: "Tidak ada",
        });

        const metadata = getExitClearanceRequestMetadata(personalData.email);

        if (metadata.approvalPaths.length === 0) {
            throw new Error(`No approval paths generated for ${personalData.email}`);
        }

        for (const [index, approvalPath] of metadata.approvalPaths.entries()) {
            const note =
                index === metadata.approvalPaths.length - 1
                    ? finalApprovalNote
                    : `Approve note ${index + 1} ${key}`;

            await exitClearancePage.gotoApprovalPath(approvalPath);
            await exitClearancePage.approveApproval(note);
        }

        await exitClearancePage.gotoProgressPath(metadata.progressPath);
        await exitClearancePage.assertProgressPageShowsApprovedState(finalApprovalNote);

        await adminRequestPage.gotoRequestView(metadata.requestId);
        await adminRequestPage.assertRequestDetails({
            applicantName: personalData.name,
            company: personalData.company,
            email: personalData.email,
            uid: metadata.uid,
            status: "Approved",
        });
    });

    test("Public request detail is only accessible to global admin users", async ({ page, adminPage, browser }) => {
        const adminRequestPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const requestName = `E2E Public Visibility ${key}`;
        const requestEmail = `exit-clearance-visibility+${key}@example.com`;
        const previousPermission = getUserResourcePermission("admin@example.com") ?? "global";
        const metadata = createPublicExitClearanceRequest({
            name: requestName,
            email: requestEmail,
            departmentCode: "HR",
            departmentName: "HR",
        });

        await adminRequestPage.gotoRequestView(metadata.requestId);
        await adminRequestPage.assertRequestDetails({
            applicantName: requestName,
            email: requestEmail,
            uid: metadata.uid,
            status: "Pending",
        });

        try {
            setUserResourcePermission("admin@example.com", "individual");

            const context = await browser.newContext();
            const scopedPage = await context.newPage();
            const scopedAdminPage = new ExitClearanceAdminPage(scopedPage);

            await loginWithCredentials(scopedPage, {
                email: "admin@example.com",
                password: "admin123",
            });

            await scopedAdminPage.gotoRequestViewExpectForbidden(metadata.requestId);
            await context.close();
        } finally {
            setUserResourcePermission("admin@example.com", previousPermission);
        }
    });
});

test.describe("Exit Clearance Admin Configuration E2E", () => {
    test("Soft-deleted department moves to archived tab and exposes archived actions", async ({ adminPage }) => {
        const adminConfigPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const departmentName = `E2E Department ${key}`;

        upsertExitClearanceDepartment({
            code: `E2E-DEP-${key}`,
            name: departmentName,
            createdByEmail: "admin@example.com",
        });

        await adminConfigPage.gotoDepartmentsConfiguration();
        await adminConfigPage.assertRecordVisible(departmentName);
        softDeleteExitClearanceDepartment(`E2E-DEP-${key}`);
        await adminConfigPage.gotoDepartmentsConfiguration();
        await adminConfigPage.assertRecordNotVisible(departmentName);
        await adminConfigPage.switchToArchivedTab();
        await adminConfigPage.assertRecordVisible(departmentName);
    });

    test("Archived department can be restored back to the main tab", async ({ adminPage }) => {
        const adminConfigPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const departmentName = `E2E Restore Department ${key}`;
        const departmentCode = `E2E-DEP-RESTORE-${key}`;

        upsertExitClearanceDepartment({
            code: departmentCode,
            name: departmentName,
            createdByEmail: "admin@example.com",
        });

        softDeleteExitClearanceDepartment(departmentCode);

        await adminConfigPage.gotoDepartmentsConfiguration();
        await adminConfigPage.assertRecordNotVisible(departmentName);
        await adminConfigPage.switchToArchivedTab();
        await adminConfigPage.assertRecordVisible(departmentName);
        await adminConfigPage.restoreArchivedRecord(departmentName);
        await adminConfigPage.assertRecordNotVisible(departmentName);
        await adminConfigPage.gotoDepartmentsConfiguration();
        await adminConfigPage.assertRecordVisible(departmentName);
    });

    test("Archived department can be force deleted from the archived tab", async ({ adminPage }) => {
        const adminConfigPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const departmentName = `E2E Force Delete Department ${key}`;
        const departmentCode = `E2E-DEP-FORCE-${key}`;

        upsertExitClearanceDepartment({
            code: departmentCode,
            name: departmentName,
            createdByEmail: "admin@example.com",
        });

        softDeleteExitClearanceDepartment(departmentCode);

        await adminConfigPage.gotoDepartmentsConfiguration();
        await adminConfigPage.switchToArchivedTab();
        await adminConfigPage.assertRecordVisible(departmentName);
        await adminConfigPage.forceDeleteArchivedRecord(departmentName);
        await adminConfigPage.assertRecordNotVisible(departmentName);
        await adminConfigPage.gotoDepartmentsConfiguration();
        await adminConfigPage.assertRecordNotVisible(departmentName);
    });

    test("Soft-deleted approver moves to archived tab and exposes archived actions", async ({ adminPage }) => {
        const adminConfigPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const approverName = `E2E Approver ${key}`;

        upsertExitClearanceApprover({
            name: approverName,
            email: `e2e-approver-${key}@example.com`,
            title: "QA Manager",
            createdByEmail: "admin@example.com",
        });

        await adminConfigPage.gotoApproversConfiguration();
        await adminConfigPage.assertRecordVisible(approverName);
        softDeleteExitClearanceApprover(`e2e-approver-${key}@example.com`);
        await adminConfigPage.gotoApproversConfiguration();
        await adminConfigPage.assertRecordNotVisible(approverName);
        await adminConfigPage.switchToArchivedTab();
        await adminConfigPage.assertRecordVisible(approverName);
    });

    test("Archived approver can be restored back to the main tab", async ({ adminPage }) => {
        const adminConfigPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const approverName = `E2E Restore Approver ${key}`;
        const approverEmail = `e2e-restore-approver-${key}@example.com`;

        upsertExitClearanceApprover({
            name: approverName,
            email: approverEmail,
            title: "QA Manager",
            createdByEmail: "admin@example.com",
        });

        softDeleteExitClearanceApprover(approverEmail);

        await adminConfigPage.gotoApproversConfiguration();
        await adminConfigPage.assertRecordNotVisible(approverName);
        await adminConfigPage.switchToArchivedTab();
        await adminConfigPage.assertRecordVisible(approverName);
        await adminConfigPage.restoreArchivedRecord(approverName);
        await adminConfigPage.assertRecordNotVisible(approverName);
        await adminConfigPage.gotoApproversConfiguration();
        await adminConfigPage.assertRecordVisible(approverName);
    });

    test("Archived approver can be force deleted from the archived tab", async ({ adminPage }) => {
        const adminConfigPage = new ExitClearanceAdminPage(adminPage);
        const key = Date.now();
        const approverName = `E2E Force Delete Approver ${key}`;
        const approverEmail = `e2e-force-delete-approver-${key}@example.com`;

        upsertExitClearanceApprover({
            name: approverName,
            email: approverEmail,
            title: "QA Manager",
            createdByEmail: "admin@example.com",
        });

        softDeleteExitClearanceApprover(approverEmail);

        await adminConfigPage.gotoApproversConfiguration();
        await adminConfigPage.switchToArchivedTab();
        await adminConfigPage.assertRecordVisible(approverName);
        await adminConfigPage.forceDeleteArchivedRecord(approverName);
        await adminConfigPage.assertRecordNotVisible(approverName);
        await adminConfigPage.gotoApproversConfiguration();
        await adminConfigPage.assertRecordNotVisible(approverName);
    });
});
