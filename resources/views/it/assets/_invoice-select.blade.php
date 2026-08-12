{{-- "Which invoice did this asset arrive on?" — the picker that links an asset to a
     document in its vendor's billing register.

     ONE partial for all four call sites (Add-Asset modal + edit form, × company/rental
     panel), so the two screens that assign kit to an invoice cannot come to offer different
     documents. Options are every vendor's invoices, narrowed to the picked vendor in the
     browser off data-vendor — the same no-AJAX device the vendor auto-fill already uses, so
     the page can never show a document for a vendor that has since been deleted.

     Expects:
       $id            element id (unique per panel)
       $invoiceOptions  VendorBillingDocument collection (all vendors')
       $vendorSelect  id of the vendor <select> this one follows
       $ownership     'company' | 'rental' — which panel this copy lives in
       $selected      currently linked document id, or ''
       $disabled      true when this panel is the hidden one — BOTH panels carry a select of
                      this name, so the hidden one must not submit (same device as vendor_id
                      and invoice_documents[]). --}}
<label class="form-label fw-semibold">Invoice it arrived on</label>
<select name="origin_billing_document_id" id="{{ $id }}" class="form-select js-invoice-picker"
        data-vendor-select="{{ $vendorSelect }}"
        data-ownership="{{ $ownership }}"
        {{ $disabled ? 'disabled' : '' }}>
    <option value="">— Not linked to a registered invoice —</option>
    @foreach($invoiceOptions as $inv)
        <option value="{{ $inv->id }}" data-vendor="{{ $inv->vendor_id }}"
                {{ (string) $selected === (string) $inv->id ? 'selected' : '' }}>{{ $inv->optionLabel() }}</option>
    @endforeach
</select>
<div class="form-text text-muted small">
    Groups this asset under that invoice on the vendor profile. Pick the vendor first.
</div>

@once
<script nonce="{{ $cspNonce ?? '' }}">
// ── Invoice picker ───────────────────────────────────────────────────────────
// Only the picked vendor's invoices may be chosen, so the list is filtered as the vendor
// select changes. CSP: bound with addEventListener, never an inline onchange.
(function () {
    // The picker only submits when its OWN ownership panel is the visible one. Both panels
    // carry a select of this name, so a picker re-enabled for a picked vendor while its panel
    // is hidden would submit a second value for one field.
    function panelIsOn(picker) {
        var form = picker.form;
        var checked = form ? form.querySelector('input[name="ownership_type"]:checked') : null;
        // No radios on the page (a role that cannot edit Section C) — leave it as rendered.
        return checked ? checked.value === picker.dataset.ownership : true;
    }

    function sync(picker, vendorChanged) {
        var vendorSelect = document.getElementById(picker.dataset.vendorSelect);
        var vendorId = vendorSelect ? vendorSelect.value : '';

        Array.prototype.forEach.call(picker.options, function (opt) {
            if (!opt.value) return;                       // the "not linked" option always stays
            var match = opt.dataset.vendor === vendorId;
            // hidden alone is not enough — a hidden option is still reachable by keyboard in
            // some browsers, and the server would then refuse the save.
            opt.hidden = !match;
            opt.disabled = !match;
        });

        // A selection that no longer belongs to the picked vendor is cleared rather than left
        // to fail validation on submit. Only when the VENDOR moved, though: merely opening an
        // asset must never blank a link that is already stored.
        if (vendorChanged && picker.selectedOptions.length && picker.selectedOptions[0].disabled) {
            picker.value = '';
        }

        // No vendor, no invoice — there is nothing to choose from and the server refuses it.
        picker.disabled = !panelIsOn(picker) || !vendorId;
    }

    var pickers = document.querySelectorAll('.js-invoice-picker');

    pickers.forEach(function (picker) {
        var vendorSelect = document.getElementById(picker.dataset.vendorSelect);
        if (vendorSelect) {
            vendorSelect.addEventListener('change', function () { sync(picker, true); });
        }
        sync(picker, false);
    });

    // The ownership toggle swaps which panel is live; re-run so the picker that just became
    // visible is not left disabled, and the one that was hidden stops submitting.
    document.querySelectorAll('input[name="ownership_type"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            pickers.forEach(function (picker) { sync(picker, false); });
        });
    });
})();
</script>
@endonce
