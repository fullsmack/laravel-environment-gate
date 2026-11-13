<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $showForm ? __('environment-gate::gate.title') : __('environment-gate::gate.not_found_title') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f7fafc; --fg: #1a202c; --muted: #718096; --border: #cbd5e0; --accent: #1a202c; --accent-fg: #fff; --error: #c53030; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111827; --fg: #f3f4f6; --muted: #9ca3af; --border: #374151; --accent: #f3f4f6; --accent-fg: #111827; --error: #f87171; } }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body { display: flex; align-items: center; justify-content: center; padding: 16px; background: var(--bg); color: var(--fg); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
        main { width: 100%; max-width: 22rem; text-align: center; }
        h1 { margin: 0 0 .5rem; font-size: 1.125rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
        p { margin: 0 0 1.5rem; line-height: 1.5; }
        form { display: flex; flex-direction: column; gap: .75rem; text-align: left; }
        label { font-size: .875rem; color: var(--muted); }
        input { width: 100%; padding: .625rem .75rem; font: inherit; color: inherit; background: transparent; border: 1px solid var(--border); border-radius: .375rem; }
        input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        button { padding: .625rem .75rem; font: inherit; font-weight: 600; color: var(--accent-fg); background: var(--accent); border: 0; border-radius: .375rem; cursor: pointer; }
        .error { margin: 0; color: var(--error); font-size: .875rem; }
    </style>
</head>
<body>
<main>
    @if ($showForm)
        <h1>{{ __('environment-gate::gate.title') }}</h1>
        <p>{{ __('environment-gate::gate.locked_message') }}</p>

        <form method="POST" action="{{ $action }}">
            <input type="hidden" name="redirect" value="{{ $redirect }}">
            <label for="environment-gate-code">{{ __('environment-gate::gate.code_label') }}</label>
            <input id="environment-gate-code" name="code" type="password" autocomplete="current-password" required autofocus
                   @if ($error) aria-invalid="true" aria-describedby="environment-gate-error" @endif>
            @if ($error)
                <p class="error" id="environment-gate-error" role="alert">{{ __('environment-gate::gate.errors.'.$error) }}</p>
            @endif
            <button type="submit">{{ __('environment-gate::gate.submit') }}</button>
        </form>
    @else
        <h1>404 | {{ __('environment-gate::gate.not_found_title') }}</h1>
    @endif
</main>
</body>
</html>
