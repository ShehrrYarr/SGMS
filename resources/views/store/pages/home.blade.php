@extends('layouts.store')

@section('content')
    @include('store.themes.'.storefront()->theme.'.home')
@endsection
