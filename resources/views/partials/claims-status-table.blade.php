{{--
    Reusable claims listing table, used inside status tabs.
    Variables:
      $rows         → collection of ExpenseClaim
      $showEmployee → bool, show an Employee column (Team page). Default false.
      $showView     → bool, show a "view month" link (My Claims). Default false.
      $showReport   → bool, show a "View report" link (Team approval history, #4). Default false.
      $emptyText    → empty-state message.
--}}
@php $showEmployee = $showEmployee ?? false; $showView = $showView ?? false; $showReport = $showReport ?? false; @endphp
@if($rows->count() > 0)
<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Claim No.</th>
                @if($showEmployee)<th>Employee</th>@endif
                <th>Event</th>
                <th>Period</th>
                <th class="text-end">Items</th>
                <th class="text-end">Total (w/ GST)</th>
                <th>Status</th>
                <th class="text-nowrap">Updated</th>
                @if($showView || $showReport)<th></th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $c)
            <tr>
                <td class="fw-semibold text-nowrap">{{ $c->claim_number }}</td>
                @if($showEmployee)<td>{{ $c->employee->full_name ?? '—' }}</td>@endif
                <td>{{ $c->eventName() ?? '—' }}</td>
                <td class="text-nowrap">{{ \Carbon\Carbon::create($c->year, $c->month)->format('M Y') }}</td>
                <td class="text-end">{{ $c->item_count }}</td>
                <td class="text-end">RM {{ number_format($c->total_with_gst, 2) }}</td>
                <td><span class="badge bg-{{ $c->statusBadge()['class'] }}">{{ $c->statusBadge()['label'] }}</span></td>
                <td class="text-nowrap text-muted">{{ optional($c->updated_at)->format('d/m/Y') }}</td>
                @if($showReport)
                <td class="text-end">
                    <a href="{{ route('user.claims.report-print', $c) }}" target="_blank" class="btn btn-sm btn-outline-primary py-0" title="View report"><i class="bi bi-printer me-1"></i>Report</a>
                </td>
                @elseif($showView)
                <td class="text-end">
                    <a href="{{ route('user.claims.index', ['month' => $c->month, 'year' => $c->year]) }}" class="btn btn-sm btn-outline-primary py-0" title="View this month"><i class="bi bi-eye"></i></a>
                </td>
                @endif
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@else
<div class="text-center text-muted py-4">
    <i class="bi bi-inbox" style="font-size:1.8rem;"></i>
    <p class="mt-2 mb-0">{{ $emptyText ?? 'No claims here.' }}</p>
</div>
@endif
