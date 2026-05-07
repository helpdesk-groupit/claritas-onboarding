@extends('layouts.help')

@section('title', 'My Tickets — User Manual')
@section('manual-title', 'My Tickets — User Manual')
@section('manual-subtitle', 'How to raise tickets, track them, and chat with the person handling them.')

@section('content')
    @include('partials._user-manual-tickets-body')
@endsection
