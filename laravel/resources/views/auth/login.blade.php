<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - {{ config('app.name') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Arial, sans-serif; background: #f7f8fb; color: #1f2937; }
        main { width: min(420px, calc(100% - 32px)); background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; }
        h1 { margin: 0 0 18px; font-size: 22px; }
        label { display: block; font-weight: 700; margin: 14px 0 6px; }
        input[type=email], input[type=password] { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px 12px; }
        .row { display: flex; align-items: center; gap: 8px; margin: 14px 0; }
        button { width: 100%; border: 0; border-radius: 8px; background: #2563eb; color: #fff; padding: 10px 12px; cursor: pointer; }
        .error { color: #b91c1c; font-size: 14px; margin-top: 6px; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>
    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
        @error('email') <div class="error">{{ $message }}</div> @enderror

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>
        @error('password') <div class="error">{{ $message }}</div> @enderror

        <label class="row">
            <input type="checkbox" name="remember" value="1">
            <span>Remember this browser</span>
        </label>

        <button type="submit">Login</button>
    </form>
</main>
</body>
</html>
