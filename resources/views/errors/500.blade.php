@extends('layouts.app')

@section('title', '500 — Server Error')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <h1 class="display-1 fw-bold text-danger">500</h1>
            <h4 class="mb-3">Server Error</h4>
            <p class="text-muted mb-4">Something went wrong on our end. Please try again later.</p>
            <a href="{{ url()->previous() }}" class="btn btn-outline-secondary me-2">Go Back</a>
            <a href="{{ route('login') }}" class="btn btn-primary">Home</a>
        </div>
    </div>
</div>
@endsection
