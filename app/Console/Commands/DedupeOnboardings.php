<?php

namespace App\Console\Commands;

use App\Models\Onboarding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes duplicate onboarding records created by an accidental double/triple
 * submit of the "Add New Onboarding" form (see OnboardingController::store()).
 *
 * A "duplicate group" = onboardings with the SAME NRIC/Passport + company that
 * are still live (status pending/active) and have NOT yet been activated into an
 * Employee. The EARLIEST record (lowest id) is kept; the rest are deleted. Any
 * assets that were auto-assigned to a deleted record are returned to 'available'
 * first, and the child rows (personal/work/asset/AARF/tasks/logs) cascade away.
 *
 *   php artisan onboarding:dedupe                       # DRY RUN — report only
 *   php artisan onboarding:dedupe --apply               # actually delete extras
 *   php artisan onboarding:dedupe --nric=901231-14-5678 # narrow to one person
 *   php artisan onboarding:dedupe --company="Enlinea Sdn. Bhd."
 *
 * Always run without --apply first and review the output on the live server.
 */
class DedupeOnboardings extends Command
{
    protected $signature = 'onboarding:dedupe
        {--apply : Actually delete the duplicate records (default: dry run)}
        {--nric= : Restrict to a single NRIC/Passport}
        {--company= : Restrict to a single company}';

    protected $description = 'Find and remove duplicate onboarding records (keeps the earliest per NRIC+company)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $records = Onboarding::query()
            ->whereIn('status', ['pending', 'active'])
            ->whereDoesntHave('employee')          // never touch records already activated into an Employee
            ->with(['personalDetail', 'workDetail', 'assetAssignments.asset'])
            ->orderBy('id')
            ->get()
            // Only records that have BOTH a NRIC and a company — grouping is by that
            // pair, so blank-keyed records must never be collapsed into one "group".
            ->filter(fn ($o) => $o->personalDetail && $o->workDetail
                && trim((string) $o->personalDetail->official_document_id) !== ''
                && trim((string) $o->workDetail->company) !== '');

        // Optional filters
        if ($nric = $this->option('nric')) {
            $records = $records->filter(fn ($o) => $o->personalDetail->official_document_id === $nric);
        }
        if ($company = $this->option('company')) {
            $records = $records->filter(fn ($o) => $o->workDetail->company === $company);
        }

        // Group by NRIC + company (case-insensitive)
        $groups = $records->groupBy(fn ($o) => mb_strtolower(trim($o->personalDetail->official_document_id))
            .'|'.mb_strtolower(trim($o->workDetail->company)));

        $dupeGroups = $groups->filter(fn ($g) => $g->count() > 1);

        if ($dupeGroups->isEmpty()) {
            $this->info('No duplicate onboarding records found.');

            return self::SUCCESS;
        }

        $this->warn(($apply ? '[APPLY] ' : '[DRY RUN] ').'Duplicate onboarding groups found: '.$dupeGroups->count());
        $this->newLine();

        $totalDeleted = 0;
        $totalAssetsFreed = 0;

        foreach ($dupeGroups as $group) {
            $sorted = $group->sortBy('id')->values();
            $keep = $sorted->first();
            $remove = $sorted->slice(1);

            $name = $keep->personalDetail->full_name;
            $company = $keep->workDetail->company;

            $this->line("• <fg=cyan>{$name}</> @ {$company}  (NRIC {$keep->personalDetail->official_document_id})");
            $this->line("    KEEP   #{$keep->id}  created {$keep->created_at?->format('d/m/Y H:i')}");

            foreach ($remove as $ob) {
                $assets = $ob->assetAssignments
                    ->map(fn ($a) => $a->asset)
                    ->filter()
                    ->filter(fn ($asset) => $asset->status === 'assigned');

                $assetNote = $assets->isNotEmpty()
                    ? '  (frees '.$assets->count().' asset: '.$assets->pluck('asset_tag')->implode(', ').')'
                    : '';

                $this->line("    <fg=red>DELETE</> #{$ob->id}  created {$ob->created_at?->format('d/m/Y H:i')}{$assetNote}");

                if ($apply) {
                    DB::transaction(function () use ($ob, $assets, &$totalAssetsFreed) {
                        foreach ($assets as $asset) {
                            $asset->update([
                                'status' => 'available',
                                'assigned_employee_id' => null,
                                'asset_assigned_date' => null,
                            ]);
                            $asset->appendRemark("Released: duplicate onboarding #{$ob->id} removed via onboarding:dedupe.");
                            $totalAssetsFreed++;
                        }
                        $ob->delete(); // cascades personal/work/asset/AARF/tasks/edit-logs
                    });
                    $totalDeleted++;
                }
            }
            $this->newLine();
        }

        if ($apply) {
            $this->info("Done. Deleted {$totalDeleted} duplicate record(s); freed {$totalAssetsFreed} asset(s).");
        } else {
            $wouldDelete = $dupeGroups->sum(fn ($g) => $g->count() - 1);
            $this->info("Dry run only — nothing was changed. {$wouldDelete} record(s) would be deleted. Re-run with --apply to delete.");
        }

        return self::SUCCESS;
    }
}
