import { isApprovedManPowerRequest, isPendingManPowerRequest } from './requestManPowerStatus.js';

const normalizeSearch = value => String(value ?? '').trim().toLowerCase();
const matchesSearch = (values, query) => !query || values.some(value => String(value ?? '').toLowerCase().includes(query));

export function filterManPowerRequests(requests, { search = '', status = 'all' } = {}) {
  const query = normalizeSearch(search);

  return (Array.isArray(requests) ? requests : []).filter(request => {
    if (!request) return false;
    if (status === 'approved' && !isApprovedManPowerRequest(request)) return false;
    if (status === 'pending' && !isPendingManPowerRequest(request)) return false;

    return matchesSearch([
      request.id, request.request_number,
      request.posisi_dibutuhkan, request.position_name, request.position_title,
      request.division_name, request.department, request.division?.name,
      request.business_entity_name, request.company_name,
      request.lokasi_penempatan, request.branch, request.location,
      request.nama_pengaju,
    ], query);
  });
}

export function filterJobApplications(applications, { search = '', stage = 'all', match = 'all', jobId = null } = {}) {
  const query = normalizeSearch(search);

  return (Array.isArray(applications) ? applications : []).filter(application => {
    if (!application) return false;
    if (jobId !== null && jobId !== undefined && jobId !== '' &&
      String(application.job_posting_id) !== String(jobId) && String(application.job_posting?.id) !== String(jobId)) return false;

    if (stage === 'rejected') {
      if (application.status !== 'rejected') return false;
    } else if (stage !== 'all') {
      if (application.status === 'rejected') return false;
      const currentStage = application.current_stage_id || application.stage?.id || 1;
      if (String(currentStage) !== String(stage)) return false;
    }

    const hasAiResult = application.ai_screening_status === 'completed' && application.ai_match_score !== null && application.ai_match_score !== undefined;
    if (match === 'recommended' && (!hasAiResult || !(application.ai_match_score >= 75))) return false;
    if (match === 'considered' && (!hasAiResult || !(application.ai_match_score >= 50 && application.ai_match_score < 75))) return false;
    if (match === 'not_suitable' && (!hasAiResult || !(application.ai_match_score < 50))) return false;

    return matchesSearch([
      application.full_name, application.email, application.phone, application.whatsapp_number,
      application.job_posting?.title, application.job_posting?.company_name,
    ], query);
  });
}
