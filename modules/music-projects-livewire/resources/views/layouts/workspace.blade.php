<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sessions · Music</title>
    @livewireStyles
    <style>
        :root{color-scheme:light dark;font-family:system-ui,sans-serif;background:#111827;color:#e5e7eb}body{margin:0}main{max-width:88rem;margin:auto;padding:clamp(1rem,3vw,3rem)}h1,h2,h3,p{margin-top:0}h1{font-size:clamp(1.8rem,3vw,2.5rem)}h2{font-size:1.2rem}label{display:block;margin-bottom:.4rem}input,select,textarea,button{font:inherit;box-sizing:border-box}input,select,textarea{max-width:100%;width:100%;border:1px solid #64748b;border-radius:.5rem;padding:.7rem;background:#111827;color:#f8fafc}button{border:1px solid #64748b;border-radius:.5rem;min-height:2.75rem;padding:.6rem 1rem;background:#1e293b;color:#f8fafc;cursor:pointer}button.primary{background:#a7f3d0;color:#052e16;border-color:#a7f3d0;font-weight:650}button:disabled{opacity:.45;cursor:default}button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,summary:focus-visible{outline:3px solid #6ee7b7;outline-offset:3px}a{color:#a7f3d0}.grid{display:grid;grid-template-columns:minmax(14rem,1fr) minmax(0,3fr);gap:1.5rem}.card{background:#1f2937;border:1px solid #475569;border-radius:1rem;padding:1.25rem;margin-bottom:1rem}.row{display:flex;gap:.7rem;align-items:center;flex-wrap:wrap}.row>*{max-width:100%}.field{margin-bottom:1rem}.muted{color:#cbd5e1;font-size:.9rem}.status{padding:1rem;border-left:4px solid #6ee7b7;background:#1f2937;margin-bottom:1rem}.problem{border-color:#fbbf24}ul{padding-left:1.25rem}li{margin-bottom:.75rem}summary{cursor:pointer;padding:.5rem 0}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:.7rem .4rem;border-bottom:1px solid #475569}.scroll{overflow:auto}[x-cloak]{display:none!important}@media(max-width:50rem){.grid{grid-template-columns:1fr}}
    </style>
</head>
<body><main>{{ $slot }}</main>@livewireScripts</body>
</html>
