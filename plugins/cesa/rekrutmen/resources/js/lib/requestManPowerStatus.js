export function manPowerRequestStatusKey(request) {
    const raw = String(request?.raw_status ?? '').trim().toLowerCase();

    if (['pending', 'approved', 'rejected', 'hold'].includes(raw)) {
        return raw;
    }

    const label = String(request?.status || request?.approval_status || '').toLowerCase();

    if (label.includes('approv') || label.includes('setuju')) {
        return 'approved';
    }

    if (label.includes('reject') || label.includes('tolak')) {
        return 'rejected';
    }

    if (label.includes('hold') || label.includes('tahan')) {
        return 'hold';
    }

    if (label.includes('pending') || label.includes('menunggu')) {
        return 'pending';
    }

    return raw;
}

export function isPendingManPowerRequest(request) {
    return manPowerRequestStatusKey(request) === 'pending';
}

export function isApprovedManPowerRequest(request) {
    return manPowerRequestStatusKey(request) === 'approved';
}
