<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - {{ config('app.name') }}</title>
    <style>
        :root {
            --bg: #f4f6f8;
            --panel: #ffffff;
            --border-soft: #e8edf3;
            --field-border: #c9d3df;
            --text: #182230;
            --muted: #667085;
            --blue: #1d4ed8;
            --red: #b42318;
            --red-soft: #fff0ed;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        main {
            width: min(420px, calc(100% - 32px));
            padding: 24px;
            background: var(--panel);
            border: 1px solid var(--border-soft);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        }

        h1 {
            margin: 0 0 18px;
            font-size: 22px;
            line-height: 1.2;
            overflow-wrap: anywhere;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-size: 14px;
            font-weight: 700;
        }

        input[type=email],
        input[type=password] {
            width: 100%;
            min-height: 38px;
            padding: 8px 10px;
            border: 1px solid var(--field-border);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            font: inherit;
            font-size: 16px;
        }

        .row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 14px 0;
            font-weight: 400;
        }

        button {
            width: 100%;
            min-height: 38px;
            padding: 9px 13px;
            border: 1px solid transparent;
            border-radius: 8px;
            background: var(--blue);
            color: #fff;
            font: inherit;
            font-size: 14px;
            font-weight: 750;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .08);
        }

        button:hover { filter: brightness(.96); }

        input:focus-visible,
        button:focus-visible {
            outline: 2px solid var(--blue);
            outline-offset: 2px;
        }

        .error {
            margin-top: 6px;
            padding: 8px 10px;
            border: 1px solid #fecaca;
            border-radius: 8px;
            background: var(--red-soft);
            color: #7f1d1d;
            font-size: 14px;
        }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>
    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
        @error('email') <div class="error" id="email-error" role="alert">{{ $message }}</div> @enderror

        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
        @error('password') <div class="error" id="password-error" role="alert">{{ $message }}</div> @enderror

        <label class="row">
            <input type="checkbox" name="remember" value="1">
            <span>Remember this browser</span>
        </label>

        <button type="submit">Login</button>
    </form>
</main>
</body>
</html>
