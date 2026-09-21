import { defineStore } from 'pinia';
import axios from 'axios';
import { fetchPaginatedCollection } from '../lib/paginatedCollection.js';

const postingsRequests = new WeakMap();

export const useRekrutmenStore = defineStore('rekrutmen', {
  state: () => ({
    requests: [],
    postings: [],
    postingsSearch: null,
    companies: [],
    applications: [],
    applicationsParams: { search: '', job_id: '', pipeline_id: '' },
    collectionQueries: { requests: null, applications: null },
    loadedCollections: { requests: null, applications: null },
    errors: { requests: '', applications: '' },
    aiScreeningProgress: null,
    stages: [],
    pipelines: [],
    activeJob: null,
    progressData: null,
    progressReport: null,
    configurationsData: null,
    configurations: null,
    loading: {
      requests: false,
      postings: false,
      applications: false,
      progress: false,
      configurations: false,
    }
  }),

  actions: {
    async fetchRequests(search = '', force = false) {
      return fetchPaginatedCollection(this, {
        collection: 'requests',
        url: '/rekrutmen/api/requests',
        params: { search: String(search ?? '').trim() },
        recordsKey: 'data',
        force,
        errorMessage: 'Daftar FPTK belum berhasil dimuat seluruhnya. Silakan coba lagi.',
      });
    },

    async refreshRequestsAfterMutation(result) {
      try {
        await this.fetchRequests('', true);
        return result;
      } catch {
        return {
          ...result,
          refresh_warning: 'Perubahan sudah tersimpan, tetapi daftar FPTK gagal dimuat ulang. Klik Segarkan untuk mencoba lagi.',
        };
      }
    },

    async approveRequest(id) {
      try {
        const res = await axios.post(`/rekrutmen/api/requests/${id}/approve`);
        return await this.refreshRequestsAfterMutation(res.data);
      } catch (err) {
        console.error('Failed to approve request', err);
        throw err;
      }
    },

    async rejectRequest(id) {
      try {
        const res = await axios.post(`/rekrutmen/api/requests/${id}/reject`);
        return await this.refreshRequestsAfterMutation(res.data);
      } catch (err) {
        console.error('Failed to reject request', err);
        throw err;
      }
    },

    async holdRequest(id, reason) {
      try {
        const res = await axios.post(`/rekrutmen/api/requests/${id}/hold`, { reason });
        return await this.refreshRequestsAfterMutation(res.data);
      } catch (err) {
        console.error('Failed to hold request', err);
        throw err;
      }
    },

    async fetchPostings(search = '', force = false) {
      const normalizedSearch = String(search ?? '').trim();
      const pendingRequest = postingsRequests.get(this);

      if (!force && pendingRequest?.search === normalizedSearch) return pendingRequest.promise;
      if (!force && !pendingRequest && this.postingsSearch === normalizedSearch) return this.postings;

      const request = { search: normalizedSearch, promise: null };
      postingsRequests.set(this, request);
      this.loading.postings = true;

      request.promise = (async () => {
        try {
          const postings = [];
          let companies;
          let pipelines;
          let page = 1;
          let lastPage = 1;

          do {
            const { data } = await axios.get('/rekrutmen/api/job-postings', {
              params: { search: normalizedSearch, page, per_page: 100 },
            });

            postings.push(...(Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : [])));

            if (page === 1) {
              companies = data?.companies;
              pipelines = data?.pipelines;
            }

            lastPage = Number(data?.last_page) || 1;
            page++;
          } while (page <= lastPage);

          if (postingsRequests.get(this) === request) {
            this.postings = postings;
            this.postingsSearch = normalizedSearch;

            if (Array.isArray(companies)) {
              this.companies = companies;
            }
            if (Array.isArray(pipelines)) {
              const currentPipelines = new Map(this.pipelines.map(pipeline => [String(pipeline.id), pipeline]));
              this.pipelines = pipelines.map(pipeline => ({
                ...currentPipelines.get(String(pipeline.id)),
                ...pipeline,
              }));
            }
          }

          return postings;
        } catch (err) {
          console.error('Failed fetching postings', err);
          throw err;
        } finally {
          if (postingsRequests.get(this) === request) {
            postingsRequests.delete(this);
            this.loading.postings = false;
          }
        }
      })();

      return request.promise;
    },

    async fetchCompanies() {
      if (this.companies && this.companies.length) return this.companies;
      try {
        const res = await axios.get('/rekrutmen/api/companies');
        if (res.data) {
          this.companies = Array.isArray(res.data) ? res.data : (res.data.data || []);
        }
      } catch (err) {
        console.error('Failed fetching companies', err);
      }
      return this.companies;
    },

    async refreshPostingsAfterMutation(result) {
      this.postingsSearch = null;
      try {
        await this.fetchPostings('', true);
        return result;
      } catch {
        return {
          ...result,
          refresh_warning: 'Perubahan sudah tersimpan, tetapi daftar lowongan gagal dimuat ulang. Klik Segarkan untuk mencoba lagi.',
        };
      }
    },

    async togglePublishPosting(id) {
      const posting = this.postings.find(p => p.id === id);
      if (posting) {
        posting.is_published = !posting.is_published;
      }
      try {
        const res = await axios.patch(`/rekrutmen/api/job-postings/${id}/publish`);
        return await this.refreshPostingsAfterMutation(res.data);
      } catch (err) {
        console.error('Failed to toggle publish status', err);
        throw err;
      }
    },

    async createJobPosting(payload) {
      try {
        let res;
        if (payload instanceof FormData) {
          res = await axios.post('/rekrutmen/api/job-postings', payload, {
            headers: { 'Content-Type': 'multipart/form-data' }
          });
        } else {
          res = await axios.post('/rekrutmen/api/job-postings', payload);
        }
        return await this.refreshPostingsAfterMutation(res.data);
      } catch (err) {
        console.error('Failed to create job posting', err);
        throw err;
      }
    },

    async updateJobPosting(id, payload) {
      try {
        let res;
        if (payload instanceof FormData) {
          res = await axios.post(`/rekrutmen/api/job-postings/${id}`, payload, {
            headers: { 'Content-Type': 'multipart/form-data' }
          });
        } else {
          res = await axios.put(`/rekrutmen/api/job-postings/${id}`, payload);
        }
        return await this.refreshPostingsAfterMutation(res.data);
      } catch (err) {
        console.error('Failed to update job posting', err);
        throw err;
      }
    },

    async deleteJobPosting(id) {
      try {
        const res = await axios.delete(`/rekrutmen/api/job-postings/${id}`);
        return await this.refreshPostingsAfterMutation(res.data);
      } catch (err) {
        console.error('Failed to delete job posting', err);
        throw err;
      }
    },

    async fetchApplications(params = {}, force = false) {
      const query = typeof params === 'string' ? { search: params } : (params ?? {});
      this.applicationsParams = {
        search: String(query.search ?? '').trim(),
        job_id: String(query.job_id ?? '').trim(),
        pipeline_id: String(query.pipeline_id ?? '').trim(),
      };

      return fetchPaginatedCollection(this, {
        collection: 'applications',
        url: '/rekrutmen/api/applications',
        params: { ...this.applicationsParams },
        recordsKey: 'applications',
        force,
        errorMessage: 'Daftar pelamar belum berhasil dimuat seluruhnya. Silakan coba lagi.',
        onClear: () => {
          this.stages = [];
          this.activeJob = null;
        },
        onLoaded: data => {
          this.stages = data.stages || [];
          this.activeJob = data.active_job || null;
        },
      });
    },

    async moveStage(appId, newStageId) {
      return this.updateApplicationStage(appId, newStageId);
    },

    async updateApplicationStage(appId, newStageId) {
      const app = this.applications.find(a => String(a.id) === String(appId));

      if (String(newStageId) === 'rejected') {
        if (app) {
          app.status = 'rejected';
        }
        try {
          const res = await axios.patch(`/rekrutmen/api/applications/${appId}/stage`, {
            stage_id: 'rejected'
          });
          return res.data || { success: true };
        } catch (err) {
          console.error('Failed to reject candidate', err);
          throw err;
        }
      }

      const stage = this.stages.find(s => String(s.id) === String(newStageId));
      if (app) {
        app.current_stage_id = parseInt(newStageId);
        if (app.status === 'rejected') {
          app.status = 'in_progress';
        }
        if (stage) {
          app.stage = { id: stage.id, name: stage.name, color: stage.color };
        }
      }
      try {
        const res = await axios.patch(`/rekrutmen/api/applications/${appId}/stage`, {
          stage_id: parseInt(newStageId)
        });
        return res.data || { success: true };
      } catch (err) {
        console.error('Failed to update stage', err);
        throw err;
      }
    },

    async batchRejectApplications(ids) {
      try {
        const res = await axios.post('/rekrutmen/api/applications/batch-reject', {
          ids: ids
        });
        ids.forEach(id => {
          const app = this.applications.find(a => String(a.id) === String(id));
          if (app) {
            app.status = 'rejected';
          }
        });
        return res.data || { success: true };
      } catch (err) {
        console.error('Failed to batch reject applications', err);
        throw err;
      }
    },

    async updateApplicationStatus(appId, newStatus) {
      const app = this.applications.find(a => a.id === appId);
      if (app) {
        app.status = newStatus;
      }
      try {
        await axios.patch(`/rekrutmen/api/applications/${appId}/status`, {
          status: newStatus
        });
      } catch (err) {
        console.error('Failed to update status', err);
      }
    },

    async analyzeCandidateWithAi(appId, force = false) {
      try {
        const res = await axios.post(`/rekrutmen/api/applications/${appId}/analyze-ai`, { force });
        const updated = res.data.application;
        const app = this.applications.find(a => Number(a.id) === Number(appId));
        if (app && updated) {
          Object.assign(app, updated);
        }
        return res.data;
      } catch (err) {
        console.error('Failed to analyze candidate with AI', err);
        throw err;
      }
    },

    async batchAnalyzeWithAi(jobId = null, applicationIds = null, force = false) {
      const payload = { job_id: jobId, force };
      if (Array.isArray(applicationIds) && applicationIds.length) {
        payload.application_ids = [...applicationIds];
      }
      const res = await axios.post('/rekrutmen/api/applications/batch-analyze-ai', payload);
      return res.data;
    },

    async fetchAiScreeningStatus(jobId = null, applicationIds = [], signal = undefined) {
      const params = { job_id: jobId };
      if (applicationIds.length) params.ids = applicationIds.slice(0, 200);
      const res = await axios.get('/rekrutmen/api/applications/ai-status', { params, signal, timeout: 10000 });
      if (signal?.aborted) return null;
      this.aiScreeningProgress = res.data;
      const updates = new Map((res.data.applications || []).map(app => [String(app.id), app]));
      this.applications.forEach(app => {
        const update = updates.get(String(app.id));
        if (update) Object.assign(app, update);
      });
      return res.data;
    },

    async syncCandidateCvs() {
      try {
        const res = await axios.post('/rekrutmen/api/applications/sync-cvs');
        try {
          await this.fetchApplications(this.applicationsParams, true);
          return res.data;
        } catch {
          return {
            ...res.data,
            refresh_warning: 'Sinkronisasi selesai, tetapi daftar pelamar gagal dimuat ulang. Silakan coba lagi.',
          };
        }
      } catch (err) {
        console.error('Failed syncing candidate CVs', err);
        throw err;
      }
    },

    async fetchProgressReport(force = false) {
      if (this.progressReport && !force) return this.progressReport;
      this.loading.progress = true;
      try {
        const res = await axios.get('/rekrutmen/api/progress-report');
        if (res.data) {
          this.progressData = res.data;
          this.progressReport = res.data;
        }
      } catch (err) {
        console.error('Failed fetching progress report', err);
      } finally {
        this.loading.progress = false;
      }
      return this.progressReport;
    },

    async fetchConfigurations(force = false) {
      if (this.configurations && !force) return this.configurations;
      this.loading.configurations = true;
      try {
        const res = await axios.get('/rekrutmen/api/configurations');
        if (res.data) {
          this.configurationsData = res.data;
          this.configurations = res.data;
          if (res.data.pipelines) {
            this.pipelines = res.data.pipelines;
          }
        }
      } catch (err) {
        console.error('Failed fetching configurations', err);
      } finally {
        this.loading.configurations = false;
      }
      return this.configurations;
    },

    async createPipeline(payload) {
      const res = await axios.post('/rekrutmen/api/pipelines', payload);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async updatePipeline(id, payload) {
      const res = await axios.put(`/rekrutmen/api/pipelines/${id}`, payload);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async deletePipeline(id) {
      const res = await axios.delete(`/rekrutmen/api/pipelines/${id}`);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async createDivision(payload) {
      const res = await axios.post('/rekrutmen/api/divisions', payload);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async updateDivision(id, payload) {
      const res = await axios.put(`/rekrutmen/api/divisions/${id}`, payload);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async deleteDivision(id) {
      const res = await axios.delete(`/rekrutmen/api/divisions/${id}`);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async createStage(payload) {
      const res = await axios.post('/rekrutmen/api/stages', payload);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async updateStage(id, payload) {
      const res = await axios.put(`/rekrutmen/api/stages/${id}`, payload);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async deleteStage(id) {
      const res = await axios.delete(`/rekrutmen/api/stages/${id}`);
      await this.fetchConfigurations(true);
      return res.data;
    },

    async reorderStages(stageIds, pipelineId = null) {
      const payload = { stage_ids: stageIds };
      if (pipelineId) {
        payload.pipeline_id = pipelineId;
        payload.rekrutmen_pipeline_id = pipelineId;
      }

      const res = await axios.post('/rekrutmen/api/stages/reorder', payload);

      if (Array.isArray(res.data?.stages)) {
        const currentStages = new Map(this.stages.map(stage => [String(stage.id), stage]));
        const returnedStageIds = new Set(res.data.stages.map(stage => String(stage.id)));
        this.stages = [
          ...this.stages.filter(stage => !returnedStageIds.has(String(stage.id))),
          ...res.data.stages.map(serverStage => ({
            ...currentStages.get(String(serverStage.id)),
            ...serverStage,
          })),
        ];
      }
      await this.fetchConfigurations(true);
      return res.data;
    }
  }
});
