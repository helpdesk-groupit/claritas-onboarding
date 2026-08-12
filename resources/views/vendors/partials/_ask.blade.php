{{-- Ask AI — questions answered from THIS vendor's filed contracts and billing documents,
     and from nothing else.

     Two things this panel must always make visible, because the answer is only as good as
     what went into it:
      - which documents the assistant can see, and
      - which it cannot, and why. A document you can see on the page but that is silently
        left out of the context makes "that is not in our documents" a false answer.

     It renders into a floating offcanvas over the vendor profile, which shapes the layout:
     the panel is narrow and the thread has to be what fills it, so the scope collapses to
     a single line, and the panel carries its OWN "Read now" for an unread document — the
     button that does that on the document row is behind this panel's backdrop, so pointing
     at it would be pointing somewhere the operator cannot reach.

     Expects: $vendor, $askable (Collection of documents), $chatMessages, $askFocus,
     $canManage. --}}
@php
    $vndUsable = $askable->filter->hasAiText();
    $vndBlocked = $askable->reject->hasAiText();
    // A focus link from a document row pre-selects just that one; otherwise everything
    // readable is in scope, which is what "ask about this vendor" means. A focus key naming
    // a document that CANNOT be asked about falls back to everything — ticking nothing
    // would look like an empty scope rather than an unreadable document.
    $vndFocusKey = $askFocus && $vndUsable->contains(fn ($d) => $d->askKey() === $askFocus) ? $askFocus : null;

    $vndAiOn = config('vendors.ai.enabled', true);

    // What can be ASKED, as opposed to what can be READ. Only the scope selector and the
    // input are gated on it — the thread below is a record of what this assistant was
    // asked about a commercial document and what it answered, and it stays on the page
    // whether or not anything is currently readable. Hiding the history because a document
    // later became unreadable would quietly destroy the audit value of keeping it.
    $vndCanAsk = $vndAiOn && $vndUsable->isNotEmpty();

    // Matches the @checked below, so the bar's opening count is never a line the page has
    // to be corrected on by the first click.
    $vndInScope = $vndFocusKey ? 1 : $vndUsable->count();

    // Why the composer is dead, said in the composer rather than only above it. Ordered
    // most-specific first: "nothing filed" and "nothing read" are different problems with
    // different next moves, and collapsing them sends people to the wrong one.
    $vndNoAskReason = $askable->isEmpty() ? 'No documents are filed for this vendor yet'
        : (! $vndAiOn ? 'Document AI is switched off for vendors'
        : 'No document has been read yet');
@endphp

{{-- ── Toolbar ──────────────────────────────────────────────────────────────── --}}
<div class="vnd-ask-toolbar">
    @if($askable->isNotEmpty())
        {{-- Collapsed by default when there IS something to ask: in a panel this narrow the
             chips pushed the thread off the bottom, and the count is the part worth a
             glance. Defaulted OPEN when nothing is readable, because then this list — and
             the reason beside each row — is the only content the panel has. --}}
        <button class="vnd-ask-scopebar" type="button" data-bs-toggle="collapse"
                data-bs-target="#vndAskScope" aria-controls="vndAskScope"
                aria-expanded="{{ $vndCanAsk ? 'false' : 'true' }}">
            <i class="bi bi-file-earmark-text"></i>
            <span><strong data-vnd-ask-count>{{ $vndInScope }}</strong> of {{ $vndUsable->count() }} in scope</span>
            @if($vndBlocked->isNotEmpty())
                <span class="vnd-ask-scopebar-warn">{{ $vndBlocked->count() }} unavailable</span>
            @endif
            <i class="bi bi-chevron-down vnd-ask-chev"></i>
        </button>
    @endif

    @if($chatMessages->isNotEmpty())
    <form action="{{ route('vendors.ask.new-topic', $vendor) }}" method="POST">
        @csrf
        <button class="vnd-ask-tool" type="submit"
                title="Start a new topic — earlier questions stay on the page but stop being sent with the next one">
            New topic
        </button>
    </form>
    @endif
</div>

{{-- ── Scope ────────────────────────────────────────────────────────────────── --}}
@if($askable->isNotEmpty())
<div class="collapse {{ $vndCanAsk ? '' : 'show' }}" id="vndAskScope">
    <div class="vnd-ask-scope mb-3">
        @if($vndUsable->isNotEmpty())
            <div class="vnd-label mb-2">Documents in scope</div>
            <div class="d-flex flex-wrap gap-2" data-vnd-ask-scope>
                @foreach($vndUsable as $vndDoc)
                    <label class="vnd-ask-chip">
                        {{-- Shown but inert when the AI is off: dropping them would hide what
                             the assistant CAN see behind a switch, and the panel's whole job
                             is to say what went into an answer. --}}
                        <input type="checkbox" value="{{ $vndDoc->askKey() }}"
                               @checked($vndFocusKey === null || $vndFocusKey === $vndDoc->askKey())
                               @disabled(! $vndAiOn)>
                        <span>{{ $vndDoc->aiLabel() }}</span>
                        @if($vndDoc->ai_status === 'partial')
                            <span class="vnd-ask-partial" title="Only part of this document was transcribed">partial</span>
                        @endif
                    </label>
                @endforeach
            </div>
            @if($vndAiOn)
            <div class="vnd-ask-scope-actions mt-2">
                <button type="button" class="vnd-ai-ask" data-vnd-ask-all>Select all</button>
                <button type="button" class="vnd-ai-ask" data-vnd-ask-none>Select none</button>
            </div>
            @endif
        @endif

        {{-- Listed whenever anything is unavailable, and it matters MOST when nothing is
             readable: "nothing has been read" is not an explanation, and the operator's next
             move — press a button, change provider, or upload a file at all — depends on
             the reason. --}}
        @if($vndBlocked->isNotEmpty())
        <div class="vnd-ask-blocked {{ $vndUsable->isNotEmpty() ? 'mt-3 pt-3 vnd-ask-blocked-sep' : '' }}">
            <div class="vnd-label mb-2">Not available to the assistant</div>
            @foreach($vndBlocked as $vndDoc)
                @php
                    // Worth another try whenever there IS a file and nothing is already
                    // running against it — `skipped` and `disabled` included, since both say
                    // more about the provider configured at the time than about the document.
                    $vndCanRead = $canManage && filled($vndDoc->file_path) && $vndDoc->ai_status !== 'pending';
                    $vndReadUrl = $vndDoc instanceof \App\Models\VendorContract
                        ? route('vendors.contracts.summarise', [$vendor, $vndDoc])
                        : route('vendors.billing.summarise', [$vendor, $vndDoc]);
                @endphp
                <div class="vnd-ask-blocked-row">
                    <div>
                        <strong>{{ $vndDoc->aiLabel() }}</strong>
                        <div class="vnd-ask-blocked-why">{{ $vndDoc->aiUnavailableReason() }}.</div>
                    </div>
                    @if($vndCanRead)
                        {{-- The panel's own copy of the row's Re-summarise, for the reason in
                             the header comment: that row is behind this panel's backdrop. It
                             posts `from=ask` so the redirect comes back HERE and not to the
                             tab underneath. --}}
                        <form action="{{ $vndReadUrl }}" method="POST">
                            @csrf
                            <input type="hidden" name="from" value="ask">
                            <button class="vnd-ask-read" type="submit"><i class="bi bi-robot me-1"></i>Read now</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endif

@if(! $vndAiOn)
    <div class="alert alert-secondary py-2 mb-3" style="font-size:12.5px;">
        <i class="bi bi-robot me-1"></i>Document AI is switched off for vendors
        (<code>VENDOR_AI_ENABLED</code>), so no new questions can be asked.
    </div>
@endif

{{-- ── Thread ───────────────────────────────────────────────────────────────── --}}
<div class="vnd-ask-thread" data-vnd-ask-thread>
    @forelse($chatMessages as $vndMsg)
        @if($vndMsg->isDivider())
            <div class="vnd-ask-divider"><span>New topic</span></div>
        @else
            @php $vndFailed = (bool) ($vndMsg->context_json['failed'] ?? false); @endphp
            <div class="vnd-ask-msg vnd-ask-{{ $vndMsg->role }} {{ $vndFailed ? 'vnd-ask-failed' : '' }}">
                <div class="vnd-ask-who">
                    @if($vndMsg->isAssistant())
                        <i class="bi bi-robot me-1"></i>Assistant
                    @else
                        <i class="bi bi-person me-1"></i>{{ $vndMsg->author?->name ?? 'Someone' }}
                    @endif
                    <span class="vnd-ask-when">{{ fmt_datetime($vndMsg->created_at) }}</span>
                </div>
                <div class="vnd-ask-body">{!! $vndMsg->html() !!}</div>

                @if($vndMsg->isAssistant() && ! empty($vndMsg->context_json['used']))
                    <div class="vnd-ask-cites">
                        <i class="bi bi-file-earmark-text me-1"></i>Read from:
                        {{ implode(' · ', $vndMsg->context_json['used']) }}
                    </div>
                @endif
                @if($vndMsg->isAssistant() && ! empty($vndMsg->context_json['excluded']))
                    <div class="vnd-ask-cites vnd-ask-cites-warn">
                        <i class="bi bi-exclamation-triangle me-1"></i>Not read for this answer:
                        @foreach($vndMsg->context_json['excluded'] as $vndEx)
                            {{ $vndEx['label'] }} ({{ $vndEx['reason'] }}){{ ! $loop->last ? ' · ' : '' }}
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    @empty
        <div class="vnd-ask-empty">
            <i class="bi bi-chat-left-dots"></i>
            @if($askable->isEmpty())
                {{-- Not "nothing has been read": nothing has been FILED, and the next move is
                     to upload a contract or an invoice, not to press a read button. --}}
                <div>No contracts or billing documents are filed for this vendor yet.</div>
                <div class="vnd-ask-empty-sub">
                    File one on the <a href="{{ route('vendors.show', [$vendor, 'tab' => 'contracts']) }}">Contracts</a>
                    or <a href="{{ route('vendors.show', [$vendor, 'tab' => 'billing']) }}">Billing</a> tab
                    and the assistant can answer from it.
                </div>
            @elseif($vndAiOn && $vndUsable->isEmpty())
                @php
                    // Built here rather than with a directive glued to the sentence: Blade
                    // only treats @ as a directive when it is not preceded by a word
                    // character, so "reason@if(...)" would compile through as literal text
                    // and leave the matching @endif unbalanced — a ViewException at render.
                    $vndUnreadHint = 'Each one is listed above with the reason'
                        .($canManage ? ' — press Read now on any of them.' : '.');
                @endphp
                <div>None of this vendor's documents have been read yet.</div>
                <div class="vnd-ask-empty-sub">{{ $vndUnreadHint }}</div>
            @else
                <div>Nothing has been asked about this vendor yet.</div>
                {{-- Offered only when they would actually work — a suggestion that bounces is
                     worse than no suggestion. --}}
                @if($vndCanAsk)
                <div class="vnd-ask-suggest">
                    <button type="button" class="vnd-ask-seed">When does our contract with them expire, and how much notice do we have to give?</button>
                    <button type="button" class="vnd-ask-seed">What are we obliged to do under this contract that we might overlook?</button>
                    <button type="button" class="vnd-ask-seed">Do the invoices on file match the rate and terms in the contract?</button>
                </div>
                @endif
            @endif
        </div>
    @endforelse
</div>

{{-- ── Ask ──────────────────────────────────────────────────────────────────── --}}
@if($vndCanAsk)
<form class="vnd-ask-form mt-3" data-vnd-ask-form data-vnd-ask-url="{{ route('vendors.ask', $vendor) }}">
    @csrf
    <div class="input-group">
        <input type="text" class="form-control" name="question" maxlength="2000" autocomplete="off"
               placeholder="Ask about these contracts and invoices…" data-vnd-ask-input>
        <button class="btn btn-primary" type="submit" data-vnd-ask-send>
            <i class="bi bi-send me-1"></i>Ask
        </button>
    </div>
    <div class="vnd-ask-hint mt-2" data-vnd-ask-hint>
        Answers come only from the ticked documents, never from general knowledge. The assistant quotes the
        document and names it; where a recorded field disagrees with it, it flags both. Check anything you act
        on against the document itself.
    </div>
</form>
@else
{{-- The composer stays, disabled, with the reason in it. A chat panel whose input simply
     is not there reads as broken; the reason has to be where the person is looking, not
     only in a notice further up. Deliberately carries no data-vnd-ask-form, so the script
     still finds nothing to bind and no-ops exactly as before. --}}
<div class="vnd-ask-form mt-3">
    <div class="input-group">
        <input type="text" class="form-control" placeholder="{{ $vndNoAskReason }}" disabled>
        <button class="btn btn-primary" type="button" disabled><i class="bi bi-send me-1"></i>Ask</button>
    </div>
</div>
@endif
