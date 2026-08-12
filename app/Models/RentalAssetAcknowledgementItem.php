<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One asset on an AARF, carrying a SNAPSHOT of Section A as it read when the form was
 * generated.
 *
 * The snapshot is the point: the signed form states what was physically handed over.
 * Correcting a serial number or re-branding the asset afterwards must not rewrite a
 * document somebody already put their name to, so nothing here is rendered through
 * `asset()` — that relation exists to prove WHICH asset the line refers to, not to
 * supply the printed values.
 *
 * `direction` mirrors the parent's `type` and exists only so the uniqueness rule can stay
 * a database fact: UNIQUE(asset_inventory_id, direction) = an asset sits on at most one
 * receipt form and at most one return form, ever. A composite index cannot reach into the
 * parent table, which is why the value is copied down here rather than joined.
 */
class RentalAssetAcknowledgementItem extends Model
{
    protected $table = 'rental_asset_acknowledgement_items';

    /** The Section A fields, in the order the asset listing shows them. */
    public const SECTION_A_FIELDS = [
        'asset_tag', 'asset_name', 'asset_category', 'asset_type',
        'brand', 'model', 'serial_number',
    ];

    protected $fillable = [
        'rental_asset_acknowledgement_id', 'direction', 'asset_inventory_id',
        'asset_tag', 'asset_name', 'asset_category', 'asset_type',
        'brand', 'model', 'serial_number',
    ];

    public function acknowledgement()
    {
        return $this->belongsTo(RentalAssetAcknowledgement::class, 'rental_asset_acknowledgement_id');
    }

    public function asset()
    {
        return $this->belongsTo(AssetInventory::class, 'asset_inventory_id');
    }

    /**
     * Build a line from a live asset — the one place Section A is copied.
     *
     * `$direction` is required rather than defaulted: it is half of the unique index, and a
     * default would mean a caller that forgets it files a return line under the receipt slot
     * — which is exactly the state the index exists to make impossible.
     */
    public static function snapshotFrom(AssetInventory $asset, string $direction): array
    {
        $data = [
            'asset_inventory_id' => $asset->id,
            'direction' => $direction,
        ];

        foreach (self::SECTION_A_FIELDS as $field) {
            $data[$field] = $asset->{$field};
        }

        return $data;
    }

    /** "Brand Model", falling back to the type, for a one-line description. */
    public function description(): string
    {
        return trim($this->brand.' '.$this->model) ?: ($this->asset_type ?: '—');
    }
}
