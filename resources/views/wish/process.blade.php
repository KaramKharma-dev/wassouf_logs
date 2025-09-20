@extends('layouts.app')

@section('content')
<div class="container" style="max-width:520px">
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <form method="get" action="{{ route('wish.process.index') }}" class="mb-3">
        <label class="form-label">التاريخ</label>
        <div class="d-flex gap-2">
            <input type="date" name="date" class="form-control" value="{{ $date }}">
            <button class="btn btn-outline-secondary" type="submit">تعيين</button>
            <a class="btn btn-outline-dark" href="{{ route('wish.process.index', ['date'=>now()->toDateString()]) }}">اليوم</a>
            <a class="btn btn-outline-dark" href="{{ route('wish.process.index', ['date'=>now()->subDay()->toDateString()]) }}">أمس</a>
        </div>
    </form>

    <form method="post" action="{{ route('wish.process.run') }}">
        @csrf
        <input type="hidden" name="date" value="{{ $date }}">
        <button class="btn btn-primary w-100">بدء المعالجة</button>
    </form>
</div>
@endsection
