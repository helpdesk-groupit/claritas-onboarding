@extends('layouts.app')
@section('title', 'AI Finance Chatbot')
@section('page-title', 'AI Finance Assistant')

@section('content')
@include('accounting.partials.nav')
<div class="row g-4" style="height:calc(100vh - 220px);min-height:500px;">
    {{-- Sessions sidebar --}}
    <div class="col-md-3 d-flex flex-column" style="height:100%;">
        <div class="card flex-grow-1 d-flex flex-column">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Sessions</h6>
                <form method="POST" action="{{ route('accounting.ai.chat-new-session') }}">@csrf<button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i></button></form>
            </div>
            <div class="card-body p-0 flex-grow-1" style="overflow-y:auto;">
                <div class="list-group list-group-flush" style="font-size:13px;">
                    @foreach($sessions ?? [] as $sess)
                    <a href="{{ route('accounting.ai.chatbot', ['session' => $sess->id]) }}"
                       class="list-group-item list-group-item-action {{ ($currentSession->id ?? null) == $sess->id ? 'active' : '' }}">
                        <div class="fw-bold">{{ $sess->title ?? 'Chat ' . $sess->id }}</div>
                        <small class="text-muted">{{ $sess->updated_at->diffForHumans() }}</small>
                    </a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Chat area --}}
    <div class="col-md-9 d-flex flex-column" style="height:100%;">
        <div class="card flex-grow-1 d-flex flex-column">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-robot me-1"></i>{{ $currentSession->title ?? 'Finance Assistant' }}</h6>
                <span class="badge bg-secondary">Powered by AI</span>
            </div>
            <div class="card-body flex-grow-1 p-3" id="chatMessages" style="overflow-y:auto;background:#f8f9fa;">
                @foreach($messages ?? [] as $msg)
                <div class="d-flex mb-3 {{ $msg->role === 'user' ? 'justify-content-end' : 'justify-content-start' }}">
                    <div class="p-2 rounded-3 {{ $msg->role === 'user' ? 'bg-primary text-white' : 'bg-white border' }}" style="max-width:75%;font-size:14px;">
                        @if($msg->role === 'assistant')
                            <i class="bi bi-robot me-1 text-muted"></i>
                        @endif
                        {!! nl2br(e($msg->content)) !!}
                        <div class="text-end mt-1" style="font-size:11px;opacity:0.7;">{{ $msg->created_at->format('H:i') }}</div>
                    </div>
                </div>
                @endforeach
                @if(empty($messages) || count($messages ?? []) === 0)
                <div class="text-center text-muted py-5">
                    <i class="bi bi-chat-dots" style="font-size:3rem;"></i>
                    <p class="mt-2">Ask me about your financial data!</p>
                    <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                        <button class="btn btn-sm btn-outline-primary suggestion" data-text="What is our total revenue this month?">Revenue this month</button>
                        <button class="btn btn-sm btn-outline-primary suggestion" data-text="Show me outstanding invoices">Outstanding invoices</button>
                        <button class="btn btn-sm btn-outline-primary suggestion" data-text="What is our current cash balance?">Cash balance</button>
                        <button class="btn btn-sm btn-outline-primary suggestion" data-text="Who are our top 5 customers by revenue?">Top customers</button>
                        <button class="btn btn-sm btn-outline-primary suggestion" data-text="Generate a trial balance summary">Trial balance</button>
                    </div>
                </div>
                @endif
            </div>
            @if(isset($currentSession))
            <div class="card-footer">
                <form method="POST" action="{{ route('accounting.ai.chat-send') }}" id="chatForm" class="d-flex gap-2">
                    @csrf
                    <input type="hidden" name="session_id" value="{{ $currentSession->id }}">
                    <input type="text" name="message" id="chatInput" class="form-control" placeholder="Ask about revenue, invoices, expenses, cash flow..." autocomplete="off" required>
                    <button type="submit" class="btn btn-primary" id="sendBtn"><i class="bi bi-send"></i></button>
                </form>
            </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight;

    document.querySelectorAll('.suggestion').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('chatInput').value = this.dataset.text;
            document.getElementById('chatForm').submit();
        });
    });
});
</script>
@endpush
@endsection
