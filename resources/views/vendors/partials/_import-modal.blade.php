{{--
    Upload step of the vendor import.

    Deliberately says what happens NEXT ("nothing is saved until you confirm"), because the
    only thing that makes a bulk importer safe to press is knowing there is a review in
    between — otherwise the natural assumption is that picking a file writes 200 vendors.
--}}
<div class="modal fade" id="vendorImportModal" tabindex="-1" aria-labelledby="vendorImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="{{ route('vendors.import.upload') }}" method="POST" enctype="multipart/form-data" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title" id="vendorImportModalLabel">
                    <i class="bi bi-file-earmark-arrow-up me-2"></i>Import Vendors
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3" style="font-size:13px;">
                    Upload the vendor list you already keep. The importer reads the column headings and the
                    values to work out which column is which &mdash; you then check its reading on the next
                    screen and correct anything it got wrong. <strong>Nothing is saved until you confirm there.</strong>
                </p>

                <label for="vendorImportFile" class="form-label fw-semibold" style="font-size:13px;">Vendor list</label>
                <input type="file" name="import_file" id="vendorImportFile" required
                       accept=".xlsx,.xlsm,.csv,.txt"
                       class="form-control form-control-sm @error('import_file') is-invalid @enderror">
                @error('import_file')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text" style="font-size:12px;">
                    Excel workbook (.xlsx) or CSV, up to 10&nbsp;MB. Older .xls files need re-saving as .xlsx first.
                </div>

                <ul class="mt-3 mb-0 ps-3 text-muted" style="font-size:12px;">
                    <li>Your headings do not have to match ours &mdash; &ldquo;Company Name&rdquo;, &ldquo;Supplier&rdquo;, &ldquo;SSM No.&rdquo;, &ldquo;PIC&rdquo; and the like are all recognised.</li>
                    <li>A title or blank rows above the headings are fine; the header row is found by reading the sheet.</li>
                    <li>A vendor already registered under the same name is left alone unless you choose otherwise.</li>
                </ul>
            </div>
            <div class="modal-footer justify-content-between">
                <a href="{{ route('vendors.import.template') }}" class="btn btn-sm btn-link text-decoration-none px-0">
                    <i class="bi bi-download me-1"></i>Download a template
                </a>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload me-1"></i>Read the file</button>
                </div>
            </div>
        </form>
    </div>
</div>

@if($errors->has('import_file') || session('import_reopen'))
    {{-- A rejected upload must re-open the modal it came from: bouncing back to the directory
         with an error banner and a closed modal reads as though the button did nothing.
         Keyed on its OWN flash flag, not on `error` — every other action on this page
         (a blocked delete, a failed toggle) flashes `error` too, and opening the import
         modal over one of those would be answering a question nobody asked.
         CSP blocks inline handlers, so this runs from a nonce-protected block. --}}
    <script nonce="{{ $cspNonce ?? '' }}">
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('vendorImportModal');
            if (el && window.bootstrap) { bootstrap.Modal.getOrCreateInstance(el).show(); }
        });
    </script>
@endif
