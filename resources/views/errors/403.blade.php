@extends('layouts.app')

@section('title', '403 — Forbidden')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <h1 class="display-1 fw-bold text-danger">403</h1>
            <h4 class="mb-3">Access Denied</h4>
            <p class="text-muted mb-4">You do not have permission to access this resource.</p>
            <a href="{{ url()->previous() }}" class="btn btn-outline-secondary me-2">Go Back</a>
            <a href="{{ route('login') }}" class="btn btn-primary">Home</a>
        </div>
    </div>
</div>
@endsection
