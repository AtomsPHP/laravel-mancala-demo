<!doctype html>
@php
    $mancalaBoot = [
        'gameId' => request()->route('game'),
        'mode' => request()->boolean('observe') ? 'observe' : 'player',
        'atomsEndpoint' => config('atoms.endpoint'),
        'lobbyRefreshMs' => config('mancala.lobby_refresh_ms'),
        'stoneDropMs' => config('mancala.stone_drop_ms'),
        'reconnectMs' => config('mancala.reconnect_ms'),
    ];
@endphp
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4e7cb">
    <meta name="description" content="A live multiplayer Mancala game powered by Laravel and Atoms.">
    <title>Mancala, alive — Atoms + Laravel</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
    <script>
        window.__MANCALA__ = {{ Illuminate\Support\Js::from($mancalaBoot) }};
    </script>
</body>
</html>
