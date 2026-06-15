<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{$title ?? "Dj Smith Movies" }}</title>
    <meta name="description" content={{$description ?? "watch all your favourite Dj smith Movies online for a monthly subscription."}}>
    <link rel="icon" type="image/png" href="favicon-32x32.png">
    <link rel="apple-touch-icon" type="image/png"  href="">
    @vite(['resources/css/app.css','resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <livewire:header/>
    {{$slot}}
    <livewire:footer/>
    @livewireScripts
</body>
</html>
