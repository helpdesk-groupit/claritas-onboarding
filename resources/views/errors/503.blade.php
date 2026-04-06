@extends('layouts.app')

@section('title', '503 — Service Unavailable')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <h1 class="display-1 fw-bold text-secondary">503</h1>
            <h4 class="mb-3">Service Unavailable</h4>
            <p class="text-muted mb-4">We are currently performing maintenance. Please check back shortly.</p>
            <a href="{{ route('login') }}" class="btn btn-primary">Try Again</a>
        </div>
    </div>
</div>
@endsection
