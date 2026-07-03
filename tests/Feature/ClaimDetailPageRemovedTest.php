<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ClaimDetailPageRemovedTest extends TestCase
{
    /**
     * The standalone claim detail page and the recall (cancel) action were retired:
     * the My Claims accordion + Preview/View Report cover viewing, and a submitted
     * claim is final (manager/HR approve or reject — no self-recall).
     */
    public function test_show_and_cancel_routes_no_longer_exist(): void
    {
        $this->assertFalse(Route::has('user.claims.show'), 'The claim detail page route should be removed.');
        $this->assertFalse(Route::has('user.claims.cancel'), 'The recall (cancel) route should be removed.');
    }

    public function test_my_claims_list_route_still_exists(): void
    {
        $this->assertTrue(Route::has('user.claims.index'));
    }
}
