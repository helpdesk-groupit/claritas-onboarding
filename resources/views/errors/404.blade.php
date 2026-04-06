@extends('layouts.app')

@section('title', '404 — Page Not Found')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <h1 class="display-1 fw-bold text-warning">404</h1>
            <h4 class="mb-3">Page Not Found</h4>
            <p class="text-muted mb-4">The page you are looking for does not exist or has been moved.</p>
            <a href="{{ url()->previous() }}" class="btn btn-outline-secondary me-2">Go Back</a>
            <a href="{{ route('login') }}" class="btn btn-primary">Home</a>
        </div>
    </div>
</div>
@endsection
