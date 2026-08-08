<?php

namespace Tests\Unit;

use App\Support\Automation\Adapters\ImapAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Where a sweep's offset lands in IMAP's page-and-position addressing.
 *
 * This is the one provider whose offset correctness has no HTTP surface to
 * assert against — Gmail and Graph requests can be inspected, an IMAP fetch
 * cannot — and it is also the one where a mistake is invisible: rounding an
 * offset to a page boundary silently skips real messages, the pass that
 * follows believes it read them, and nothing ever comes back (the next run
 * starts from the newest again). Hence a unit test on the arithmetic itself.
 */
class ImapPageCursorTest extends TestCase
{
    public function test_a_first_pass_starts_at_page_one_and_drops_nothing(): void
    {
        $this->assertSame(['page' => 1, 'drop' => 0], ImapAdapter::pageCursor(0, 10));
    }

    public function test_an_offset_on_a_page_boundary_starts_the_next_page_cleanly(): void
    {
        $this->assertSame(['page' => 2, 'drop' => 0], ImapAdapter::pageCursor(10, 10));
        $this->assertSame(['page' => 51, 'drop' => 0], ImapAdapter::pageCursor(500, 10));
    }

    public function test_an_offset_mid_page_keeps_the_page_and_drops_only_its_remainder(): void
    {
        // The failure this guards: rounding 14 up to page 2 would skip messages
        // 11-14, and rounding down to page 1 would re-read the whole page. It
        // must land inside the page and discard exactly four.
        $this->assertSame(['page' => 2, 'drop' => 4], ImapAdapter::pageCursor(14, 10));
        $this->assertSame(['page' => 4, 'drop' => 7], ImapAdapter::pageCursor(37, 10));
    }

    public function test_the_cursor_never_addresses_before_the_beginning(): void
    {
        // A negative offset is nonsense, but page 0 would be a webklex error and
        // a negative drop would splice from the END of the batch — quietly
        // returning the wrong messages rather than failing.
        $this->assertSame(['page' => 1, 'drop' => 0], ImapAdapter::pageCursor(-5, 10));
    }

    public function test_a_zero_page_size_cannot_divide_by_zero(): void
    {
        $this->assertSame(['page' => 6, 'drop' => 0], ImapAdapter::pageCursor(5, 0));
    }
}
