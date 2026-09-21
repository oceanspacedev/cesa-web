export function filterJobPostings(postings, { search = '', status = 'all', company = 'all' } = {}) {
  const query = String(search ?? '').trim().toLowerCase();

  return (Array.isArray(postings) ? postings : []).filter(posting => {
    if (!posting) return false;
    if (status === 'published' && !posting.is_published) return false;
    if (status === 'draft' && posting.is_published) return false;
    if (company !== 'all' && String(posting.company_id) !== String(company)) return false;
    if (!query) return true;

    return [
      posting.title,
      posting.company_name,
      posting.location,
      posting.description,
      posting.requirements,
    ].some(value => String(value ?? '').toLowerCase().includes(query));
  });
}
