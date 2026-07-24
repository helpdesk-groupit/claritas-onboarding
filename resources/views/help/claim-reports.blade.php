@extends('layouts.help')

@section('title', 'Claim Reports (Finance) — User Manual')
@section('manual-title', 'Claim Reports (Finance) — User Manual')
@section('manual-subtitle', 'How to read the approved-claim ledger, filter it by year, month, company or category, and export it for the accounting system.')

@section('content')
    @include('partials._user-manual-claimreports-body')
@endsection
