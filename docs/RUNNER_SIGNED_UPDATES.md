# Runner signed updates

The runner self-update copies `packages\runner\current` from the branch share and runs its
`bootstrap_update_runner.ps1` as SYSTEM. Without a signature, anyone with write access to the share
could run code on every runner. Updates are therefore signed with an offline RSA-3072 key.

## Key generation (Supermicro build host only)

```powershell
php artisan inventory:update-keygen          # --force to replace (invalidates every issued kit)
```

- Private key: `storage/app/inventory/keys/update-signing-private.pem` (config `inventory.update_signing_key_path`, git-ignored, never printed).
- Public key (.NET RSA XML): `update-signing-public.xml` next to it; printed on generation.
- Back up the private key offline (encrypted removable media, two copies). Losing it means a re-install of every runner to rotate; leaking it means anyone can sign updates.

## What the build does

`inventory:build-site-kit` writes `files[]` (`path`, `sha256`, `size`) for every file in the runner package
(except the manifest and signature) into `runner-manifest.json` and signs those exact bytes
(`runner-manifest.sig`, base64 RSA-SHA256 PKCS#1 v1.5). With a key, the kit also contains
`runner/config/update-public-key.xml` and `requireSignedUpdates: true`. Without a key the build still
succeeds, prints a WARNING, and sets `signed: false`.

## Runner policy (`runner_main.ps1`)

The staged copy (in the admin-only `update-staging`) is verified before the apply task is created:
signature, every hash, no missing and no unlisted files, no `..`/rooted paths. On failure the staged copy
is deleted, state becomes `update_rejected`, the command ACK is `failed` ("update package signature invalid").

| Key installed | `requireSignedUpdates` | Result |
|---|---|---|
| yes | any | must verify, else rejected |
| no | true | rejected |
| no | false/absent | applied with a logged warning (legacy runners) |

## Rollout

New kits ship the key and `requireSignedUpdates=true`. Already-deployed runners have no verifier and stay
on warn-only until they receive one bridge update: publish a signed package (it carries the key); the old
runner applies it unverified, and `bootstrap_update_runner.ps1` installs the key and sets
`requireSignedUpdates=true`. From the next update on, verification is enforced. An installed key is never
overwritten by a package.

## Rotation

Run `inventory:update-keygen --force`, rebuild kits, and re-install runners from the new kit (the installed
key is deliberately not replaceable by an update). Until a runner is re-installed it keeps trusting the old key.

## Not covered

- Trust in the initial install: the kit zip/launcher must come from the build host through a trusted channel.
- A compromised build host (it holds the signing key).
- Local tampering with `config\` on current-user installs: that user has Modify there (runner rewrites its config),
  so a malicious local user could replace the key or clear the flag. The script directory stays admin-only.
- Manual `FORCE_UPDATE_THIS_PC_RUNNER.cmd` runs the bootstrap directly without the runner's check.
