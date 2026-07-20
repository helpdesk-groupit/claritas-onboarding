@extends('layouts.help')

@section('title', 'Team Claims — User Manual')
@section('manual-title', 'Team Claims — User Manual')
@section('manual-subtitle', 'How to review, approve, and reject the expense claims your team routes to you.')

@section('content')
    @include('partials._user-manual-teamclaims-body')
@endsection
