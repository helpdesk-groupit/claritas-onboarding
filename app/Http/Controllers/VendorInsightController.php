<?php

namespace App\Http\Controllers;

use App\Jobs\SummariseVendorDocument;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorChatMessage;
use App\Models\VendorContract;
use App\Services\VendorDocumentInsightService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The AI half of the vendor profile: the per-document summary, and the assistant that
 * answers questions about that vendor's filed contracts and billing documents.
 *
 * Two gates, kept apart on purpose even though VENDOR_ROLES makes them the same set
 * today: READING a summary and asking a question is canViewVendors(); triggering a
 * (re)reading of a document — which spends money and rewrites a stored column — is
 * canManageVendors().
 *
 * Every document id here arrives from the request, so every one of them is scoped back
 * to the vendor in the URL. Without that, a crafted id would ground one vendor's
 * assistant in another vendor's contracts, on a page whose whole premise is that it shows
 * you one vendor.
 */
class VendorInsightController extends Controller
{
    private function authorizeView(): void
    {
        if (! Auth::user()->canViewVendors()) {
            abort(403);
        }
    }

    private function authorizeManage(): void
    {
        if (! Auth::user()->canManageVendors()) {
            abort(403, 'No permission to manage vendors.');
        }
    }

    // ── Summaries ─────────────────────────────────────────────────────────────

    public function summariseContract(Request $request, Vendor $vendor, VendorContract $contract)
    {
        return $this->summarise($request, $vendor, $contract, 'contracts');
    }

    public function summariseBilling(Request $request, Vendor $vendor, VendorBillingDocument $document)
    {
        return $this->summarise($request, $vendor, $document, 'billing');
    }

    /**
     * Queue a fresh reading of an already-stored document.
     *
     * Clears the previous reading first rather than leaving it up until the new one lands:
     * a summary shown next to a "reading it again" state is a summary somebody will act on
     * without knowing it is the one being replaced.
     */
    private function summarise(Request $request, Vendor $vendor, VendorContract|VendorBillingDocument $document, string $tab)
    {
        $this->authorizeManage();
        $this->assertBelongs($vendor, $document);

        // The assistant panel carries its own copy of this button, because the document row
        // is behind the panel's backdrop. Pressed from there, `from=ask` brings the operator
        // back to the panel — returning them to the tab underneath would read as the panel
        // having closed itself in answer to being used.
        $back = redirect()->route('vendors.show', array_filter([
            'vendor' => $vendor,
            'tab' => $tab,
            'ask' => $request->input('from') === 'ask' ? 1 : null,
        ]));

        if (blank($document->file_path)) {
            return $back->with('error', 'There is no uploaded document to read.');
        }

        $document->resetDocumentInsight();
        $document->save();

        SummariseVendorDocument::dispatchFor($document);

        return $back->with('success', 'Reading the document — the summary appears on this row shortly.');
    }

    /**
     * Poll target for rows sitting at `pending`.
     *
     * Deliberately returns ONLY each document's status, not its rendered summary. The page
     * reloads once a reading finishes rather than patching itself, so what is on screen is
     * always exactly what the server rendered from the record — the alternative risks the
     * page and the database disagreeing about a document's state, which is the one thing
     * this feature must never do. It also keeps a repeating poll small, and means the
     * transcription never travels for a status check.
     */
    public function insights(Vendor $vendor)
    {
        $this->authorizeView();

        // Only the two columns the poll reads — the transcript column is a large text blob
        // and there is no reason to pull it here.
        $vendor->load([
            'contracts' => fn ($q) => $q->select('id', 'vendor_id', 'ai_status'),
            'billingDocuments' => fn ($q) => $q->select('id', 'vendor_id', 'ai_status'),
        ]);

        $documents = $vendor->askableDocuments()->map(fn ($document) => [
            'key' => $document->askKey(),
            'status' => $document->ai_status,
        ])->values();

        return response()->json([
            'pending' => $documents->where('status', 'pending')->count(),
            'documents' => $documents,
        ]);
    }

    // ── The assistant ─────────────────────────────────────────────────────────

    /**
     * Answer a question about this vendor's documents.
     *
     * The question is stored BEFORE the model is called, so a failed or slow answer still
     * leaves a record of what was asked — the thread is a trail, not a transcript of
     * successes. A failure is written back as an assistant turn saying what went wrong
     * rather than vanishing, because a question that silently produced nothing reads as
     * though the documents had no answer.
     */
    public function ask(Request $request, Vendor $vendor)
    {
        $this->authorizeView();

        $data = $request->validate([
            'question' => 'required|string|max:2000',
            'documents' => 'nullable|array',
            'documents.*' => 'string|max:40',
        ]);

        $vendor->load(['contracts', 'billingDocuments.contract']);

        $scope = $this->scopedDocuments($vendor, $data['documents'] ?? null);

        if ($scope->isEmpty()) {
            return $this->askResponse($request, $vendor, null,
                'Select at least one of this vendor\'s documents to ask about.');
        }

        if (! config('vendors.ai.enabled', true)) {
            return $this->askResponse($request, $vendor, null,
                'Document AI is switched off, so there is nothing to ask. A superadmin can enable it.');
        }

        // Refused BEFORE anything is written. A question against documents that have no
        // transcription could never be answered, and recording it would leave the thread —
        // which is the record of what this assistant was actually asked and told — full of
        // turns that were never about a document at all.
        //
        // The full $scope still goes to the service, not just the readable part: a ticked
        // document that could not be read has to come back NAMED in the answer's excluded
        // list, or an answer that never saw it looks like one that read it and found nothing.
        if ($scope->filter->hasAiText()->isEmpty()) {
            return $this->askResponse($request, $vendor, null,
                'None of the selected documents have been read yet, so there is nothing to answer from. '
                .'The Ask tab lists why each one is unavailable.');
        }

        $history = VendorChatMessage::contextFor($vendor);

        $question = VendorChatMessage::create([
            'vendor_id' => $vendor->id,
            'user_id' => Auth::id(),
            'role' => VendorChatMessage::ROLE_USER,
            'content' => $data['question'],
        ]);

        // Never let the assistant's own answer become part of what it was asked.
        $result = VendorDocumentInsightService::answer(
            $vendor,
            $scope,
            $history,
            $data['question'],
            $vendor->name
        );

        $reply = VendorChatMessage::create([
            'vendor_id' => $vendor->id,
            // The assistant is nobody's user. Null keeps "who said this" honest.
            'user_id' => null,
            'role' => VendorChatMessage::ROLE_ASSISTANT,
            'content' => $result['answer'] ?? $result['error'],
            'context_json' => [
                'used' => $result['used'],
                'excluded' => $result['excluded'],
                'failed' => $result['answer'] === null,
            ],
            'model' => $result['answer'] === null ? null : $this->modelName(),
        ]);

        return $this->askResponse($request, $vendor, $reply, null, $question);
    }

    /**
     * Start a new topic: a visible marker the context builder stops at.
     *
     * Not a delete. The thread is a record of what the assistant was asked about a
     * commercial document and what it said back, so nothing leaves it — this only bounds
     * what the NEXT question carries, which is the actual problem with a long shared thread.
     */
    public function newTopic(Vendor $vendor)
    {
        $this->authorizeView();

        $last = VendorChatMessage::where('vendor_id', $vendor->id)->latest('id')->first();

        // Two dividers in a row say nothing, and an empty thread has no topic to end.
        if (! $last || $last->isDivider()) {
            return redirect()->route('vendors.show', [$vendor, 'ask' => 1]);
        }

        VendorChatMessage::create([
            'vendor_id' => $vendor->id,
            'user_id' => Auth::id(),
            'role' => VendorChatMessage::ROLE_DIVIDER,
            'content' => 'New topic',
        ]);

        return redirect()->route('vendors.show', [$vendor, 'ask' => 1])
            ->with('success', 'New topic started — earlier questions stay on the page but are no longer sent with the next one.');
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * The documents this question may see, resolved from the submitted keys.
     *
     * The keys are client-supplied, so each is matched against THIS vendor's own loaded
     * collections — an id belonging to another vendor simply never matches and is dropped,
     * rather than being fetched and checked afterwards.
     *
     * @param  list<string>|null  $keys  null = every document that can be asked about
     * @return \Illuminate\Support\Collection<int,VendorContract|VendorBillingDocument>
     */
    private function scopedDocuments(Vendor $vendor, ?array $keys)
    {
        $all = $vendor->askableDocuments();

        if ($keys === null || $keys === []) {
            return $all;
        }

        $wanted = array_flip($keys);

        // askKey() lives on the models (HasDocumentInsight), so the format the scope chips
        // emit and the format resolved here cannot drift apart.
        return $all->filter(fn ($d) => isset($wanted[$d->askKey()]))->values();
    }

    private function assertBelongs(Vendor $vendor, VendorContract|VendorBillingDocument $document): void
    {
        // Both ids come from the URL, so without this a document could be re-read — and
        // its row rewritten — through any other vendor's route.
        abort_unless($document->vendor_id === $vendor->id, 404);
    }

    /** Which model answered, for the turn's provenance. Never the key. */
    private function modelName(): ?string
    {
        try {
            $setting = \App\Models\ClaudeApiSetting::current();

            return $setting->isActive() ? $setting->model : config('claims.ocr.model');
        } catch (\Throwable) {
            return null;
        }
    }

    /** JSON for the in-page assistant; a redirect for a no-JS submit. */
    private function askResponse(Request $request, Vendor $vendor, ?VendorChatMessage $reply, ?string $error = null, ?VendorChatMessage $question = null)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => $error,
                'question' => $question ? [
                    'content' => $question->content,
                    'author' => Auth::user()->name ?? 'You',
                    'at' => fmt_datetime($question->created_at),
                ] : null,
                'answer' => $reply ? [
                    'html' => $reply->html(),
                    'used' => $reply->context_json['used'] ?? [],
                    'excluded' => $reply->context_json['excluded'] ?? [],
                    'failed' => (bool) ($reply->context_json['failed'] ?? false),
                    'at' => fmt_datetime($reply->created_at),
                ] : null,
            ], $error ? 422 : 200);
        }

        $back = redirect()->route('vendors.show', [$vendor, 'ask' => 1]);

        return $error ? $back->with('error', $error) : $back;
    }
}
