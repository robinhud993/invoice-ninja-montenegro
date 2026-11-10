# Publishing this fork on GitHub

This checklist is for publishing the repository safely and with correct
attribution. It assumes you publish from the repository root.

## 1. Pre-flight checks

Never publish the following:

- `.env.docker` or any real environment file.
- Certificate files, passphrases, API tokens, or customer data.
- `backups/`, `exports/`, `storage/` runtime data, generated PDFs.
- Flutter admin backup directories under `storage/app/`.
- Personal deployment notes or local IDE helper files.
- Large generated source maps and unused Flutter bundles.

The repository `.gitignore` and `.dockerignore` already exclude these. Confirm
before the first commit:

```bash
git status --short
git status --ignored --short
```

If a secret was ever committed in a previous repository, rotate it. Removing a
file from the latest commit is not enough because it remains in the history.

## 2. Initialize the repository

```bash
git init -b main
git add -A
git status --short
```

Review the staged file list. It must not contain `.env.docker`, `backups/`,
`exports/`, `storage/app/flutter-admin-backup-*`, or any certificate.

Run a basic secret scan on the staged files:

```bash
git grep -n -I -E "BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|BEGIN CERTIFICATE|APP_KEY=base64:[A-Za-z0-9+/=]{20,}|AWS_SECRET_ACCESS_KEY|STRIPE_SECRET|SENTRY_LARAVEL_DSN=https?://"
```

The test values in `.env.ci` and `.env.travis` are not production credentials,
but review them before publishing.

Commit:

```bash
git commit -m "Invoice Ninja Montenegro EFI fiscalization fork"
```

## 3. Create the GitHub repository

Use the GitHub web interface:

1. Create a new repository, for example `invoice-ninja-montenegro`.
2. Choose Public or Private.
3. Do not initialize it with a README, license, or `.gitignore`, because this
   repository already has them.

Or use the GitHub CLI after installing and authenticating it:

```bash
winget install --id GitHub.cli
gh auth login
gh repo create <owner>/invoice-ninja-montenegro --public --source=. --remote=origin --push
```

If you prefer to push manually:

```bash
git remote add origin https://github.com/<owner>/invoice-ninja-montenegro.git
git push -u origin main
```

## 4. Verify after publishing

- Open the repository file list and confirm that `.env.docker`, `backups/`,
  `exports/`, and storage data are absent.
- Confirm that `LICENSE`, `NOTICE`, and `README.md` are present.
- Check the GitHub Security tab and secret scanning alerts.
- Confirm the README renders both the English and Montenegrin/Serbian sections.
- Confirm that the repository description makes clear this is an unofficial
  fork.
- Enable branch protection or require pull requests if other people will
  contribute.

## 5. Attribution and license

This repository is a modified version of Invoice Ninja and is distributed under
the Elastic License 2.0. Keep the following files unchanged in substance:

- `LICENSE`
- `NOTICE`

The README and NOTICE state that this is an unofficial fork, that it is not
affiliated with Invoice Ninja LLC, and that upstream copyrights remain with
their owners. Do not remove those statements.

## 6. Keeping the fork in sync with upstream

Add the upstream remote for reference:

```bash
git remote add upstream https://github.com/invoiceninja/invoiceninja.git
git fetch upstream
```

Merge or rebase upstream changes deliberately. Re-run the Montenegro tests and
the PDF checks after every upstream merge.

## 7. Optional: use a private repository first

If you are unsure about the content, publish as a Private repository first,
inspect the file list and the rendered README, then switch it to Public.
