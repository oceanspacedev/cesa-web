import { defineStore } from 'pinia';
import axios from 'axios';

export const useRekrutmenStore = defineStore('rekrutmen', {
  state: () => ({
    requests: [],
    postings: [],
    companies: [],
    applications: [],
    stages: [],
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
      if (this.requests.length && !search && !force) return this.requests;
      this.loading.requests = true;
      try {
        const res = await axios.get('/rekrutmen/api/requests', { params: { search } });
        if (res.data) {
          this.requests = Array.isArray(res.data.data) ? res.data.data : (Array.isArray(res.data) ? res.data : []);
        }
      } catch (err) {
        console.error('Failed fetching requests', err);
      } finally {
        this.loading.requests = false;
      }
      return this.requests;
    },

    async approveRequest(id) {
      try {
        const res = await axios.post(`/rekrutmen/api/requests/${id}/approve`);
        await this.fetchRequests('', true);
        return res.data;
      } catch (err) {
        console.error('Failed to approve request', err);
        throw err;
      }
    },

    async rejectRequest(id) {
      try {
        const res = await axios.post(`/rekrutmen/api/requests/${id}/reject`);
        await this.fetchRequests('', true);
        return res.data;
      } catch (err) {
        console.error('Failed to reject request', err);
        throw err;
      }
    },

    async holdRequest(id, reason) {
      try {
        const res = await axios.post(`/rekrutmen/api/requests/${id}/hold`, { reason });
        await this.fetchRequests('', true);
        return res.data;
      } catch (err) {
        console.error('Failed to hold request', err);
        throw err;
      }
    },

    async fetchPostings(search = '', force = false) {
      if (this.postings.length && !search && !force) return this.postings;
      this.loading.postings = true;
      try {
        const res = await axios.get('/rekrutmen/api/job-postings', { params: { search } });
        if (res.data) {
          this.postings = Array.isArray(res.data.data) ? res.data.data : (Array.isArray(res.data) ? res.data : []);
          if (res.data.companies && Array.isArray(res.data.companies)) {
            this.companies = res.data.companies;
          }
        }
      } catch (err) {
        console.error('Failed fetching postings', err);
      } finally {
        this.loading.postings = false;
      }
      return this.postings;
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

    async togglePublishPosting(id) {
      const posting = this.postings.find(p => p.id === id);
      if (posting) {
        posting.is_published = !posting.is_published;
      }
      try {
        const res = await axios.patch(`/rekrutmen/api/job-postings/${id}/publish`);
        await this.fetchPostings('', true);
        return res.data;
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
        await this.fetchPostings('', true);
        return res.data;
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
        await this.fetchPostings('', true);
        return res.data;
      } catch (err) {
        console.error('Failed to update job posting', err);
        throw err;
      }
    },

    async deleteJobPosting(id) {
      try {
        const res = await axios.delete(`/rekrutmen/api/job-postings/${id}`);
        await this.fetchPostings('', true);
        return res.data;
      } catch (err) {
        console.error('Failed to delete job posting', err);
        throw err;
      }
    },

    async fetchApplications(params = {}, force = false) {
      const search = typeof params === 'string' ? params : (params.search || '');
      const jobId = typeof params === 'object' ? (params.job_id || '') : '';

      if (this.applications.length && !search && !jobId && !force) {
        return this.applications;
      }

      this.loading.applications = true;
      try {
        const res = await axios.get('/rekrutmen/api/applications', {
          params: { search, job_id: jobId }
        });
        if (res.data) {
          this.applications = res.data.applications || [];
          this.stages = res.data.stages || [];
          this.activeJob = res.data.active_job || null;
        }
      } catch (err) {
        console.error('Failed fetching applications', err);
      } finally {
        this.loading.applications = false;
      }
      return this.applications;
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

    async analyzeCandidateWithAi(appId) {
      try {
        const res = await axios.post(`/rekrutmen/api/applications/${appId}/analyze-ai`);
        const updated = res.data.application;
        const app = this.applications.find(a => a.id === appId);
        if (app && updated) {
          app.ai_match_score = updated.ai_match_score;
          app.ai_recommendation = updated.ai_recommendation;
          app.ai_summary = updated.ai_summary;
          app.ai_analyzed_at = updated.ai_analyzed_at;
        }
        return res.data;
      } catch (err) {
        console.error('Failed to analyze candidate with AI', err);
        throw err;
      }
    },

    async batchAnalyzeWithAi(jobId = null, onProgress = null) {
      const chunkSize = 8;
      let offset = 0;
      let totalProcessed = 0;
      let total = null;
      let lastRes = null;

      try {
        while (true) {
          const res = await axios.post('/rekrutmen/api/applications/batch-analyze-ai', {
            job_id: jobId,
            force: true,
            chunk_size: chunkSize,
            offset: offset,
          });

          lastRes = res.data;
          totalProcessed += res.data.count || 0;
          total = res.data.total;

          if (onProgress) {
            onProgress({ processed: totalProcessed, total, offset });
          }

          if (!res.data.has_more) {
            break;
          }
          offset = res.data.next_offset;
        }

        await this.fetchApplications(jobId ? { job_id: jobId } : {}, true);
        return {
          ...lastRes,
          message: `Berhasil menyelesaikan screening AI untuk ${totalProcessed} dari ${total} kandidat!`,
        };
      } catch (err) {
        console.error('Failed batch AI analysis', err);
        throw err;
      }
    },

    async syncCandidateCvs() {
      try {
        const res = await axios.post('/rekrutmen/api/applications/sync-cvs');
        await this.fetchApplications('', true);
        return res.data;
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
          if (res.data.stages) {
            this.stages = res.data.stages;
          }
        }
      } catch (err) {
        console.error('Failed fetching configurations', err);
      } finally {
        this.loading.configurations = false;
      }
      return this.configurations;
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

    async reorderStages(stageIds) {
      if (Array.isArray(this.stages) && this.stages.length) {
        const idMap = new Map(stageIds.map((id, idx) => [Number(id), idx]));
        this.stages.sort((a, b) => {
          const orderA = idMap.has(Number(a.id)) ? idMap.get(Number(a.id)) : 999;
          const orderB = idMap.has(Number(b.id)) ? idMap.get(Number(b.id)) : 999;
          return orderA - orderB;
        });
        this.stages.forEach((s, idx) => {
          s.order_column = idx + 1;
        });
      }

      const res = await axios.post('/rekrutmen/api/stages/reorder', {
        stage_ids: stageIds,
      });

      if (res.data?.stages && Array.isArray(this.stages)) {
        const currentStagesMap = new Map(this.stages.map(s => [s.id, s]));
        this.stages = res.data.stages.map(serverStage => {
          const current = currentStagesMap.get(serverStage.id) || {};
          return {
            ...current,
            ...serverStage,
            order_column: serverStage.order_column,
          };
        });
      }
      return res.data;
    }
  }
});
