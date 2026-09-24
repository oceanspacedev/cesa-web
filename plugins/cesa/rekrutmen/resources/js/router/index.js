import { createRouter, createWebHistory } from 'vue-router';
import { h } from 'vue';
import RequestManPowerView from '../views/RequestManPowerView.vue';
import JobPostingsView from '../views/JobPostingsView.vue';
import JobApplicationsView from '../views/JobApplicationsView.vue';
import RecruitmentProgressView from '../views/RecruitmentProgressView.vue';
import ConfigurationsView from '../views/ConfigurationsView.vue';
import { canVisitSection, firstAccessiblePath } from '../lib/permissions';

const AccessDeniedView = {
    render: () => h('div', {
        role: 'alert',
        class: 'mx-auto max-w-xl rounded-lg border border-zinc-200 bg-white p-6 text-sm text-zinc-700'
    }, 'Anda tidak memiliki akses ke modul Rekrutmen.')
};

const routes = [
    // Job Postings
    {
        path: '/admin/job-postings',
        alias: ['/rekrutmen/postings', '/rekrutmen'],
        name: 'postings',
        component: JobPostingsView,
        meta: { title: 'Lowongan Kerja', section: 'jobPostings' }
    },
    // Job Applications
    {
        path: '/admin/job-applications',
        alias: ['/rekrutmen/applications'],
        name: 'applications',
        component: JobApplicationsView,
        meta: { title: 'Data Pelamar', section: 'jobApplications' }
    },
    // Manpower Requests
    {
        path: '/admin/request-man-powers',
        alias: ['/rekrutmen/requests'],
        name: 'requests',
        component: RequestManPowerView,
        meta: { title: 'Permintaan FPTK', section: 'requestManPowers' }
    },
    // Recruitment Progress
    {
        path: '/admin/recruitment-progress',
        alias: ['/rekrutmen/progress'],
        name: 'progress',
        component: RecruitmentProgressView,
        meta: { title: 'Monitoring & Progress', section: 'recruitmentProgress' }
    },
    // Configurations
    {
        path: '/admin/configurations',
        alias: ['/rekrutmen/configurations', '/admin/rekrutmen/configurations'],
        name: 'configurations',
        component: ConfigurationsView,
        meta: { title: 'Master Data & Pengaturan', section: 'configurations' }
    },
    {
        path: '/rekrutmen/access-denied',
        name: 'access-denied',
        component: AccessDeniedView,
        meta: { title: 'Akses Ditolak' }
    },
    // Wildcard fallback
    {
        path: '/:pathMatch(.*)*',
        redirect: () => firstAccessiblePath()
    }
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior(to, from, savedPosition) {
        if (savedPosition) {
            return savedPosition;
        }
        return { top: 0 };
    }
});

router.beforeEach((to) => {
    if (to.meta.section && !canVisitSection(to.meta.section)) {
        return firstAccessiblePath();
    }
});

router.afterEach((to) => {
    document.title = (to.meta.title ? `${to.meta.title} - ` : '') + 'YourERP';
});

export default router;
