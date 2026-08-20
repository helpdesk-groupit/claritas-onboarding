<?php

namespace App\Console\Commands;

use App\Models\AssetInventory;
use App\Models\VendorContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backfill `file_hash` / `rental_contract_document_hashes` for documents uploaded before
 * those columns existed.
 *
 * The Contracts tab's "linked assets" panel (VendorContract::matchedAssets()) works by
 * comparing a contract's file hash against each rental asset's own uploaded copy — but a
 * hash is only ever computed at UPLOAD time, in AssetController and VendorContractController.
 * Every document already on disk before this feature shipped has no hash yet, so without
 * this command the panel would show nothing for exactly the contracts and assets that were
 * on file when it was asked for. Safe to re-run: it only fills what is missing.
 */
class HashContractDocuments extends Command
{
    protected $signature = 'vendors:hash-contract-documents
                            {--dry-run : Report what would be hashed without writing}';

    protected $description = 'Backfill file hashes for already-uploaded vendor contracts and asset rental-contract documents';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        [$contractsHashed, $contractsMissing] = $this->backfillContracts($dry);
        [$assetsHashed, $assetFilesHashed, $assetFilesMissing] = $this->backfillAssets($dry);

        $this->newLine();
        $this->line(($dry ? '[dry] ' : '')."{$contractsHashed} contract(s) hashed".($contractsMissing ? ", {$contractsMissing} missing their file on disk" : '').'.');
        $this->line(($dry ? '[dry] ' : '')."{$assetFilesHashed} asset document(s) hashed across {$assetsHashed} asset(s)".($assetFilesMissing ? ", {$assetFilesMissing} missing their file on disk" : '').'.');

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int} [hashed, missing] */
    private function backfillContracts(bool $dry): array
    {
        $hashed = 0;
        $missing = 0;

        VendorContract::whereNotNull('file_path')->whereNull('file_hash')
            ->chunkById(100, function ($contracts) use ($dry, &$hashed, &$missing) {
                foreach ($contracts as $contract) {
                    $hash = VendorContract::hashStoredFile($contract->file_path);

                    if (! $hash) {
                        $this->warn("  contract #{$contract->id} ({$contract->title}) — file not found on disk: {$contract->file_path}");
                        $missing++;

                        continue;
                    }

                    if (! $dry) {
                        $contract->forceFill(['file_hash' => $hash])->saveQuietly();
                    }
                    $hashed++;
                }
            });

        return [$hashed, $missing];
    }

    /** @return array{0:int,1:int,2:int} [assets touched, files hashed, files missing] */
    private function backfillAssets(bool $dry): array
    {
        $assetsTouched = 0;
        $filesHashed = 0;
        $filesMissing = 0;

        AssetInventory::whereNotNull('rental_contract_documents')
            ->chunkById(100, function ($assets) use ($dry, &$assetsTouched, &$filesHashed, &$filesMissing) {
                foreach ($assets as $asset) {
                    $paths = $asset->rental_contract_documents ?? [];
                    $hashes = $asset->rental_contract_document_hashes ?? [];
                    $changed = false;

                    foreach ($paths as $path) {
                        if (array_key_exists($path, $hashes)) {
                            continue;
                        }

                        if (! Storage::disk('local')->exists($path)) {
                            $this->warn("  asset {$asset->asset_tag} — file not found on disk: {$path}");
                            $filesMissing++;

                            continue;
                        }

                        $hashes[$path] = hash_file('sha256', Storage::disk('local')->path($path));
                        $filesHashed++;
                        $changed = true;
                    }

                    if ($changed) {
                        $assetsTouched++;
                        if (! $dry) {
                            $asset->forceFill(['rental_contract_document_hashes' => $hashes])->saveQuietly();
                        }
                    }
                }
            });

        return [$assetsTouched, $filesHashed, $filesMissing];
    }
}
