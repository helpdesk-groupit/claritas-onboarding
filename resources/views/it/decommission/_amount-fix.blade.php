{{--
    Inline correction for an OCR-read (or unread) quotation/receipt amount.

    OCR pre-fills the figure but a human owns it — it feeds the Finance report, so it must be
    fixable without re-uploading the document. Submitting the field empty CLEARS the amount, and
    the report then points at the reproduced document rather than stating RM 0.00.

    Requires: $batch, $field ('quotation'|'receipt'), $value (float|null).
    Plain form POST — no inline handlers (CSP blocks them project-wide).
--}}
<form action="{{ route('ewaste.amount', $batch) }}" method="POST" class="d-flex align-items-center gap-1 mt-1">
    @csrf
    <input type="hidden" name="field" value="{{ $field }}">
    <label class="visually-hidden" for="amt-{{ $field }}-{{ $batch->id }}">{{ ucfirst($field) }} amount in RM</label>
    <span class="small text-muted">RM</span>
    <input type="number" step="0.01" min="0.01" name="amount"
           id="amt-{{ $field }}-{{ $batch->id }}"
           value="{{ $value !== null ? number_format((float) $value, 2, '.', '') : '' }}"
           placeholder="not set"
           class="form-control form-control-sm" style="max-width:130px;">
    <button type="submit" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-check2 me-1"></i>{{ $value !== null ? 'Correct' : 'Set' }}
    </button>
    <span class="form-text ms-1 mb-0">Empty = clear</span>
</form>
