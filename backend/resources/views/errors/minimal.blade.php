<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>{{ $code }} {{ $title ?? 'Something went wrong' }} | SKULOS</title>
<style>
  :root{--accent:#E8A33D;--bg:#EEF1F6;--panel:#FFFFFF;--ink:#0B1420;--mut:rgba(11,20,32,.60);--line:rgba(11,20,32,.10)}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;padding:2rem 1.25rem;
    background:radial-gradient(1100px 550px at 50% -10%,color-mix(in srgb,var(--accent) 30%,transparent),transparent),var(--bg);
    font-family:'Manrope',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:var(--ink)}
  .card{width:100%;max-width:26rem;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:2.4rem 2rem;text-align:center;box-shadow:0 8px 30px -12px rgba(15,23,35,.25)}
  .brand{font-weight:800;font-size:1.15rem;letter-spacing:-.01em;margin-bottom:1.6rem}
  .brand em{font-style:normal;color:var(--accent)}
  .code{font-weight:800;font-size:3.2rem;line-height:1;color:var(--accent)}
  h1{font-size:1.2rem;font-weight:800;margin:.8rem 0 .5rem}
  p{color:var(--mut);line-height:1.6;margin:0 0 1.6rem}
  a.btn{display:inline-block;background:var(--accent);color:#241503;font-weight:700;text-decoration:none;padding:.7rem 1.4rem;border-radius:10px}
  a.btn:hover{filter:brightness(.96)}
</style>
</head>
<body>
  <div class="card">
    <div class="brand">SK<em>U</em>LOS</div>
    <div class="code">{{ $code }}</div>
    <h1>{{ $title ?? 'Something went wrong' }}</h1>
    <p>{{ $message }}</p>
    <a class="btn" href="{{ $homeUrl ?? url('/') }}">{{ $actionLabel ?? 'Go back' }}</a>
  </div>
</body>
</html>
