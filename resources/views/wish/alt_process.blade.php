@extends('layouts.app')

@section('content')
<style>
  :root{--bg:#0b1020;--card:#121731;--muted:#9aa4c7;--acc:#4f7cff;--ok:#22c55e;--err:#ef4444}
  body{background:var(--bg);color:#e6ebff}
  .wrap{max-width:980px;margin:auto;display:grid;grid-template-columns:1fr 1fr;gap:18px}
  .card{background:var(--card);border:1px solid #222a52;border-radius:18px;padding:18px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
  .h{font-size:18px;margin:0 0 12px}
  .muted{color:var(--muted);font-size:13px}
  .row{display:flex;gap:10px}
  .btn{border:0;border-radius:12px;padding:10px 14px;background:var(--acc);color:#fff;cursor:pointer}
  .btn.secondary{background:transparent;border:1px solid #2a3470}
  .btn.full{width:100%}
  .input{background:#0e1330;border:1px solid #222a52;border-radius:12px;color:#e6ebff;padding:10px 12px;width:100%}
  .links a{color:#cdd6ff;text-decoration:none;border:1px solid #2a3470;border-radius:10px;padding:8px 10px}
  .alert{border-radius:12px;padding:10px 12px;margin-bottom:10px}
  .alert.ok{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.35)}
  .alert.err{background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.35)}
  @media (max-width:980px){.wrap{grid-template-columns:1fr}}
</style>

<div class="wrap">
  <div class="card" dir="rtl">
    @if(session('status'))
      <div class="alert ok">{{ session('status') }}</div>
    @endif

    <h3 class="h">معالجة قيود Wish (ALT) حسب التاريخ</h3>

    <form method="get" action="{{ route('wish.alt_process.index') }}" class="row" style="margin-bottom:10px">
      <input type="date" name="date" class="input" value="{{ $date }}">
      <button class="btn">تعيين</button>
    </form>

    <div class="row links" style="margin-bottom:16px">
      <a href="{{ route('wish.alt_process.index', ['date'=>now()->toDateString()]) }}">اليوم</a>
      <a href="{{ route('wish.alt_process.index', ['date'=>now()->subDay()->toDateString()]) }}">أمس</a>
    </div>

    <form method="post" action="{{ route('wish.alt_process.run') }}">
      @csrf
      <input type="hidden" name="date" value="{{ $date }}">
      <button class="btn full">بدء المعالجة</button>
    </form>

    <p class="muted" style="margin-top:8px">
      يعالج فقط السطور ذات <code>row_status=VALID</code> و<code>SERVICE=TOPUP</code> و<code>credit != NULL</code> و<code>debit = NULL</code>.
      يزيد <code>balances.mb_wish_lb</code> بمقدار <code>credit</code> ثم يعلّم السطر <code>PROCESSED</code>.
    </p>
  </div>

  <div class="card" dir="rtl">
    <h3 class="h">ملاحظات سريعة</h3>
    <ul class="muted" style="margin:0;padding-inline-start:18px">
      <li>تأكد أن جدول <code>wish_rows_alt</code> يحوي الأعمدة: <code>op_date, row_status, service, debit, credit</code>.</li>
      <li>تأكد أن <code>balances</code> يحوي صفاً بمزوّد <code>mb_wish_lb</code>.</li>
      <li>إن لم تكن قيمة <code>PROCESSED</code> موجودة في <code>row_status</code> أضفها في migration أو بدّلها إلى <code>INVALID</code>.</li>
    </ul>
  </div>
</div>
@endsection
