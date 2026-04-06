{{-- Accounting Module Sub-Navigation --}}
@php
    $accActive = fn($pattern) => request()->routeIs($pattern) ? 'active' : '';
@endphp
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <nav class="d-flex flex-wrap gap-1" style="font-size:13px;">
            <a href="{{ route('accounting.dashboard') }}" class="btn btn-sm {{ request()->routeIs('accounting.dashboard') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="{{ route('accounting.executive-dashboard') }}" class="btn btn-sm {{ request()->routeIs('accounting.executive-dashboard') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-bar-chart-line me-1"></i>Executive
            </a>
            <a href="{{ route('accounting.chart-of-accounts.index') }}" class="btn btn-sm {{ request()->routeIs('accounting.chart-of-accounts.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-diagram-3 me-1"></i>CoA
            </a>
            <a href="{{ route('accounting.journal-entries.index') }}" class="btn btn-sm {{ request()->routeIs('accounting.journal-entries.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-journal-text me-1"></i>GL
            </a>
            <a href="{{ route('accounting.customers.index') }}" class="btn btn-sm {{ request()->routeIs('accounting.customers.*') || request()->routeIs('accounting.invoices.*') || request()->routeIs('accounting.customer-payments.*') || request()->routeIs('accounting.credit-notes.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-cash-stack me-1"></i>AR
            </a>
            <a href="{{ route('accounting.vendors.index') }}" class="btn btn-sm {{ request()->routeIs('accounting.vendors.*') || request()->routeIs('accounting.bills.*') || request()->routeIs('accounting.vendor-payments.*') || request()->routeIs('accounting.purchase-orders.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-cart me-1"></i>AP
            </a>
            <a href="{{ route('accounting.banking.index') }}" class="btn btn-sm {{ request()->routeIs('accounting.banking.*') || request()->routeIs('accounting.bank-transfers.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-bank me-1"></i>Banking
            </a>
            <a href="{{ route('accounting.tax.index') }}" class="btn btn-sm {{ request()->routeIs('accounting.tax.*') || request()->routeIs('accounting.tax-returns.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-percent me-1"></i>Tax
            </a>
            <a href="{{ route('accounting.fixed-assets.index') }}" class="btn btn-sm {{ request()->routeIs('accounting.fixed-assets.*') || request()->routeIs('accounting.asset-categories.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-building-gear me-1"></i>Assets
            </a>
            <a href="{{ route('accounting.budgets.index') }}" class="btn btn-sm {{ request()->routeIs('accounting.budgets.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-piggy-bank me-1"></i>Budgets
            </a>
            <a href="{{ route('accounting.reports.trial-balance') }}" class="btn btn-sm {{ request()->routeIs('accounting.reports.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>Reports
            </a>
            <a href="{{ route('accounting.ai.invoice-scanner') }}" class="btn btn-sm {{ request()->routeIs('accounting.ai.*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-robot me-1"></i>AI Tools
            </a>
            @if(Auth::user()->canManageAccounting())
            <a href="{{ route('accounting.settings') }}" class="btn btn-sm {{ request()->routeIs('accounting.settings*') ? 'btn-primary' : 'btn-outline-secondary' }}">
                <i class="bi bi-gear me-1"></i>Settings
            </a>
            @endif
        </nav>
    </div>
</div>
