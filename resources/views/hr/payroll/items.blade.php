@extends('layouts.app')
@section('title', 'Payroll Items')
@section('page-title', 'Payroll Items')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>Earnings & Deductions</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addItemModal"><i class="bi bi-plus-lg me-1"></i>Add Item</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th class="text-center">Statutory</th>
                        <th class="text-center">Recurring</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                    <tr>
                        <td><code>{{ $item->code }}</code></td>
                        <td>{{ $item->name }}</td>
                        <td><span class="badge bg-{{ $item->type === 'earning' ? 'success' : 'danger' }}">{{ ucfirst($item->type) }}</span></td>
                        <td class="text-center">{!! $item->is_statutory ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' !!}</td>
                        <td class="text-center">{!! $item->is_recurring ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' !!}</td>
                        <td class="text-center"><span class="badge bg-{{ $item->is_active ? 'success' : 'secondary' }}">{{ $item->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary edit-item-btn"
                                data-id="{{ $item->id }}"
                                data-name="{{ $item->name }}"
                                data-code="{{ $item->code }}"
                                data-type="{{ $item->type }}"
                                data-is_statutory="{{ $item->is_statutory }}"
                                data-is_recurring="{{ $item->is_recurring }}"
                                data-is_active="{{ $item->is_active }}"
                                data-bs-toggle="modal" data-bs-target="#editItemModal">
                                <i class="bi bi-pencil"></i>
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No payroll items configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('hr.payroll.items.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Add Payroll Item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Code</label><input type="text" name="code" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Type</label>
                        <select name="type" class="form-select" required>
                            <option value="earning">Earning</option>
                            <option value="deduction">Deduction</option>
                        </select>
                    </div>
                    <div class="form-check mb-2"><input type="checkbox" name="is_statutory" value="1" class="form-check-input" id="addStatutory"><label class="form-check-label" for="addStatutory">Statutory</label></div>
                    <div class="form-check mb-2"><input type="checkbox" name="is_recurring" value="1" class="form-check-input" id="addRecurring" checked><label class="form-check-label" for="addRecurring">Recurring</label></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="editItemForm">
            @csrf @method('PUT')
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Edit Payroll Item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Code</label><input type="text" name="code" id="editCode" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" id="editName" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Type</label>
                        <select name="type" id="editType" class="form-select" required>
                            <option value="earning">Earning</option>
                            <option value="deduction">Deduction</option>
                        </select>
                    </div>
                    <div class="form-check mb-2"><input type="checkbox" name="is_statutory" value="1" class="form-check-input" id="editStatutory"><label class="form-check-label" for="editStatutory">Statutory</label></div>
                    <div class="form-check mb-2"><input type="checkbox" name="is_recurring" value="1" class="form-check-input" id="editRecurring"><label class="form-check-label" for="editRecurring">Recurring</label></div>
                    <div class="form-check mb-2"><input type="checkbox" name="is_active" value="1" class="form-check-input" id="editActive"><label class="form-check-label" for="editActive">Active</label></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Update</button></div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.querySelectorAll('.edit-item-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('editItemForm').action = '{{ url("hr/payroll/items") }}/' + btn.dataset.id;
        document.getElementById('editCode').value = btn.dataset.code;
        document.getElementById('editName').value = btn.dataset.name;
        document.getElementById('editType').value = btn.dataset.type;
        document.getElementById('editStatutory').checked = btn.dataset.is_statutory === '1';
        document.getElementById('editRecurring').checked = btn.dataset.is_recurring === '1';
        document.getElementById('editActive').checked = btn.dataset.is_active === '1';
    });
});
</script>
@endpush
@endsection
