{{-- Company timeline: previous companies (oldest first) sit ABOVE and are collapsed by default;
     the current company is always shown at the BOTTOM (time flows downward to "now").
     Expects $companyTimeline (Collection of EmployeeCompanyHistory). --}}
@php
    $tl = ($companyTimeline ?? collect());
    // Current = the open stint (no ended_on); fall back to the latest-started if none is open.
    $current = $tl->firstWhere('ended_on', null) ?? $tl->sortByDesc('started_on')->first();
    // Previous = everything else, OLDEST first, so the current stays at the bottom chronologically.
    $previous = $current
        ? $tl->reject(fn ($s) => $s->id === $current->id)->sortBy('started_on')->values()
        : collect();
    $tlId = uniqid('coTl');
@endphp
@if($current)
    <div class="mt-2">
        <div class="small fw-semibold text-muted mb-1"><i class="bi bi-clock-history me-1"></i>Company timeline</div>

        @if($previous->isNotEmpty())
            <button type="button" class="btn btn-link btn-sm p-0 mb-1 text-decoration-none"
                    data-bs-toggle="collapse" data-bs-target="#{{ $tlId }}"
                    aria-expanded="false" aria-controls="{{ $tlId }}">
                <i class="bi bi-chevron-down me-1"></i>Show previous {{ $previous->count() === 1 ? 'company' : 'companies' }} ({{ $previous->count() }})
            </button>
            {{-- Previous companies — collapsed by default, ABOVE the current --}}
            <div class="collapse" id="{{ $tlId }}">
                <ul class="list-unstyled small mb-0" style="border-left:2px solid #e2e8f0; padding-left:.85rem;">
                    @foreach($previous as $stint)
                        @include('hr.employees.partials._company-timeline-row', ['stint' => $stint])
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Current company — always visible, at the BOTTOM --}}
        <ul class="list-unstyled small mb-0" style="border-left:2px solid #e2e8f0; padding-left:.85rem;">
            @include('hr.employees.partials._company-timeline-row', ['stint' => $current])
        </ul>
    </div>
@endif
