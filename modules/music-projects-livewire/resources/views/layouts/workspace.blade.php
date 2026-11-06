<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sessions · Music</title>
    @livewireStyles
    <style>body{margin:0;background:#111827}main{max-width:88rem;margin:auto;padding:clamp(1rem,3vw,3rem)}</style>
</head>
<body><main>{{ $slot }}</main>@livewireScripts</body>
</html>
