# Optional single-file installer (.exe)

```powershell
php artisan inventory:build-site-kit --profile=... --exe
```

Wraps the generated kit zip into `SITE-<ID>-runner-setup.exe` (next to the zip) using Windows IExpress. It extracts to `%TEMP%`, runs `INSTALL_THIS_PC_RUNNER_ONLY.cmd`, then deletes the extracted files.

- Needs Windows with `iexpress.exe`. If missing, the command prints `exe.status = skipped` and still builds the folder kit and zip.
- The site token is embedded: one exe per site. Treat the exe like a secret and do not email or post it.
- The exe is unsigned, so Windows SmartScreen will warn. Sign it with the organisation code-signing certificate before wide distribution.
- Official kits and exes are built only on Supermicro.
