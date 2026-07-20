@extends('layouts.help')

@section('title', 'My Claims — User Manual')
@section('manual-title', 'My Claims — User Manual')
@section('manual-subtitle', 'How to file an expense claim, attach receipts, submit it for approval, and get reimbursed.')

@section('content')
    @include('partials._user-manual-claims-body')
@endsection
