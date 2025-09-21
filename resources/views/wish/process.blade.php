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
  .uploader{border:1.5px dashed #334099;border-radius:14px;padding:16px;text-align:center;background:#0e1330}
  .uploader.drag{background:#0d132e;border-color:#4f7cff}
  .file-name{font-size:13px;color:#c9d3ff;margin-top:8px;word-break:break-all}
  .progress{height:10px;background:#0b1030;border-radius:999px;margin-top:10px;overflow:hidden;border:1px solid #2a3470}
  .bar{height:100%;width:0%;background:linear-gradient(90deg,#4f7cff,#7aa2ff)}
  pre{white-space:pre-wrap;background:#0b1030;border:1px solid #2a3470;border-radius:12px;padding:10px;color:#cfe1ff;max-height:300px;overflow:auto}
  @media (max-width:980px){.wrap{grid-template-columns:1fr}}
</style>

<div class="wrap">
  <!-- بطاقة اختيار التاريخ وتشغيل المعالجة -->
  <div class="card" dir="rtl">
    @if(session('status'))
      <div class="alert ok">{{ session('status') }}</div>
    @endif
    <h3 class="h">معالجة قيود Wish حسب التاريخ</h3>

    <form method="get" action="{{ route('wish.process.index') }}" class="row" style="margin-bottom:10px">
      <input type="date" name="date" class="input" value="{{ $date }}">
      <button class="btn">تعيين</button>
    </form>

    <div class="row links" style="margin-bottom:16px">
      <a href="{{ route('wish.process.index', ['date'=>now()->toDateString()]) }}">اليوم</a>
      <a href="{{ route('wish.process.index', ['date'=>now()->subDay()->toDateString()]) }}">أمس</a>
    </div>

    <form method="post" action="{{ route('wish.process.run') }}">
      @csrf
      <input type="hidden" name="date" value="{{ $date }}">
      <button class="btn full">بدء المعالجة</button>
    </form>

    <p class="muted" style="margin-top:8px">يعالج فقط الصفوف ذات <code>row_status=VALID</code>.</p>
  </div>

  <!-- بطاقة رفع Excel إلى API -->
  <div class="card" dir="rtl">
    <h3 class="h">رفع كشف Wish (USD) — Excel</h3>
    <p class="muted">سيتم الإرسال إلى <code>/api/wish/usd/batches</code> بهيدر <code>Accept: application/json</code>.</p>

    <div id="drop" class="uploader" tabindex="0">
      <div>اسحب الملف إلى هنا أو</div>
      <div style="margin-top:8px">
        <button id="pick" type="button" class="btn secondary">اختر ملف</button>
      </div>
      <div id="fname" class="file-name" style="display:none"></div>

      <div class="progress" style="display:none" id="pwrap"><div class="bar" id="pbar"></div></div>
      <div id="msg" style="margin-top:10px"></div>
    </div>

    <div class="row" style="margin-top:12px">
      <button id="send" class="btn full" disabled>رفع الملف الآن</button>
    </div>

    <input id="file" type="file" accept=".xlsx,.xls" style="display:none">
  </div>
</div>

<script>
(function(){
  // مسار نسبي لتجنب Mixed Content
  const apiUrl = "/api/wish/usd/batches";

  const drop = document.getElementById('drop');
  const pick = document.getElementById('pick');
  const fileInput = document.getElementById('file');
  const sendBtn = document.getElementById('send');
  const fname = document.getElementById('fname');
  const pwrap = document.getElementById('pwrap');
  const pbar  = document.getElementById('pbar');
  const msg   = document.getElementById('msg');

  let theFile = null;

  function setFile(f){
    theFile = f || null;
    if (theFile){
      fname.style.display = 'block';
      fname.textContent = theFile.name + " (" + Math.round(theFile.size/1024) + " KB)";
      sendBtn.disabled = false;
      msg.innerHTML = "";
    } else {
      fname.style.display = 'none';
      sendBtn.disabled = true;
    }
  }

  pick.addEventListener('click', ()=> fileInput.click());
  fileInput.addEventListener('change', (e)=> setFile(e.target.files[0]));

  ['dragenter','dragover'].forEach(ev=> drop.addEventListener(ev, e=>{
    e.preventDefault(); e.stopPropagation(); drop.classList.add('drag');
  }));
  ['dragleave','drop'].forEach(ev=> drop.addEventListener(ev, e=>{
    e.preventDefault(); e.stopPropagation(); drop.classList.remove('drag');
  }));
  drop.addEventListener('drop', e=>{
    const f = e.dataTransfer.files && e.dataTransfer.files[0];
    setFile(f);
  });

  // نستخدم XMLHttpRequest لأجل شريط التقدم
  sendBtn.addEventListener('click', function(){
    if(!theFile){ return; }
    msg.innerHTML = "";
    pwrap.style.display = 'block';
    pbar.style.width = '0%';
    sendBtn.disabled = true;

    const fd = new FormData();
    fd.append('file', theFile);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl, true);
    xhr.setRequestHeader('Accept','application/json'); // مهم

    xhr.upload.onprogress = function(e){
      if(e.lengthComputable){
        const pct = Math.round((e.loaded / e.total) * 100);
        pbar.style.width = pct + '%';
      }
    };

    xhr.onreadystatechange = function(){
      if(xhr.readyState === 4){
        sendBtn.disabled = false;

        let body = xhr.responseText;
        // حاول JSON
        try { body = JSON.stringify(JSON.parse(body), null, 2); } catch(_) {}

        if(xhr.status >= 200 && xhr.status < 300){
          msg.innerHTML = '<div class="alert ok">تم الرفع بنجاح</div><pre>'+escapeHtml(body)+'</pre>';
        } else {
          msg.innerHTML = '<div class="alert err">فشل الرفع ('+xhr.status+')</div><pre>'+escapeHtml(body)+'</pre>';
        }
      }
    };

    xhr.onerror = function(){
      sendBtn.disabled = false;
      msg.innerHTML = '<div class="alert err">خطأ شبكة أثناء الرفع</div>';
    };

    xhr.send(fd);
  });

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#039;' }[m]));
  }
})();
</script>
@endsection
