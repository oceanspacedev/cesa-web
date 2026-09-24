const sectionPaths = [
  ['jobPostings', '/admin/job-postings'],
  ['jobApplications', '/admin/job-applications'],
  ['requestManPowers', '/admin/request-man-powers'],
  ['recruitmentProgress', '/admin/recruitment-progress'],
  ['configurations', '/admin/configurations'],
];

const configurationSections = [
  'divisions',
  'pipelines',
  'approvers',
  'ai',
  'whatsapp',
  'mailSettings',
  'mailTemplates',
];

export function readPermissions() {
  const root = document.getElementById('rekrutmen-app') || document.getElementById('app');

  try {
    const permissions = JSON.parse(root?.dataset.permissions || '{}');
    return permissions && typeof permissions === 'object' && !Array.isArray(permissions) ? permissions : {};
  } catch {
    return {};
  }
}

export function hasPermission(resource, ability = 'viewAny', permissions = readPermissions()) {
  return permissions?.[resource]?.[ability] === true;
}

export function canAccessRecord(resource, ability, record, permissions = readPermissions()) {
  if (!hasPermission(resource, ability === 'view' ? 'viewAny' : ability, permissions)) {
    return false;
  }

  const recordAbility = `can_${ability}`;
  return record?.[recordAbility] === undefined || record[recordAbility] === true;
}

export function canVisitSection(section, permissions = readPermissions()) {
  if (section === 'configurations') {
    return configurationSections.some((resource) =>
      hasPermission(resource, resource === 'ai' || resource === 'whatsapp' ? 'manage' : 'viewAny', permissions)
    );
  }

  return hasPermission(section, 'viewAny', permissions);
}

export function firstAccessiblePath(permissions = readPermissions()) {
  return sectionPaths.find(([section]) => canVisitSection(section, permissions))?.[1] || '/rekrutmen/access-denied';
}

export function firstAccessibleConfigurationTab(permissions = readPermissions()) {
  const tabs = [
    ['divisions', 'divisions', 'viewAny'],
    ['stages', 'pipelines', 'viewAny'],
    ['approvers', 'approvers', 'viewAny'],
    ['ai', 'ai', 'manage'],
    ['mail_gateway', 'mailSettings', 'viewAny'],
    ['whatsapp_gateway', 'whatsapp', 'manage'],
    ['mail_templates', 'mailTemplates', 'viewAny'],
  ];

  return tabs.find(([, resource, ability]) => hasPermission(resource, ability, permissions))?.[0] || null;
}
