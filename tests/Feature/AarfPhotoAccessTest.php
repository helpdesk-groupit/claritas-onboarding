<?php

namespace Tests\Feature;

use App\Models\Aarf;
use App\Models\AssetAssignment;
use App\Models\AssetInventory;
use App\Models\Onboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AarfPhotoAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_aarf_photo_streams_only_via_the_matching_token_and_own_assets(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('asset_photos/demo/pic.jpg', 'FAKE-JPEG-BYTES');

        $onboarding = Onboarding::factory()->create();
        $asset = AssetInventory::factory()->create(['asset_photos' => ['asset_photos/demo/pic.jpg']]);
        AssetAssignment::create([
            'onboarding_id' => $onboarding->id,
            'asset_inventory_id' => $asset->id,
            'assigned_date' => now()->toDateString(),
            'status' => 'assigned',
        ]);
        $aarf = Aarf::create([
            'onboarding_id' => $onboarding->id,
            'aarf_reference' => 'AARF-TEST-1',
            'acknowledgement_token' => Str::random(64),
        ]);

        // Anonymous visitor with the correct token can stream the photo (no login).
        $this->get(route('aarf.photo', [$aarf->acknowledgement_token, $asset->id, 0]))->assertOk();

        // A wrong token exposes nothing.
        $this->get(route('aarf.photo', [Str::random(64), $asset->id, 0]))->assertNotFound();

        // Out-of-range photo index → 404.
        $this->get(route('aarf.photo', [$aarf->acknowledgement_token, $asset->id, 9]))->assertNotFound();

        // An asset NOT assigned on this AARF is not reachable via its token.
        $foreign = AssetInventory::factory()->create(['asset_photos' => ['asset_photos/demo/pic.jpg']]);
        $this->get(route('aarf.photo', [$aarf->acknowledgement_token, $foreign->id, 0]))->assertNotFound();
    }
}
