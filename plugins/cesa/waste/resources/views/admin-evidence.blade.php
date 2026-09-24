@foreach ($report->latestVersion?->events ?? [] as $event)
    <section class="mb-6">
        <h3 class="mb-2 font-semibold">Kejadian {{ $event->sequence + 1 }} — {{ $event->reason }}</h3>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse ($event->evidences as $evidence)
                <figure>
                    <a href="{{ route('waste.admin.evidence', ['evidence' => $evidence->getKey()]) }}" target="_blank" rel="noopener noreferrer">
                        <img src="{{ route('waste.admin.evidence', ['evidence' => $evidence->getKey()]) }}" alt="Bukti kejadian {{ $event->sequence + 1 }}, foto {{ $loop->iteration }}" loading="lazy" class="w-full rounded-lg border object-contain">
                    </a>
                    <figcaption class="mt-1 text-sm text-gray-600">Foto {{ $loop->iteration }}</figcaption>
                </figure>
            @empty
                <p>Belum ada foto pada kejadian ini.</p>
            @endforelse
        </div>
    </section>
@endforeach
