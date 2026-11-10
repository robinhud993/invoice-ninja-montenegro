# Docker deploy, setup and Montenegro fiscalization guide

This guide covers three things for this repository:

1. Deploying and running the local Docker Compose stack.
2. Completing first-run application setup.
3. Configuring and operating the Montenegro EFI (elektronska fiskalizacija) standard.

The examples assume Windows PowerShell unless a block is labelled `bash`. On Linux and macOS use `docker compose` directly and translate the `docker compose ... exec` calls the same way.

## Table of contents

- [1. Prerequisites](#1-prerequisites)
- [2. Repository layout for Docker](#2-repository-layout-for-docker)
- [3. Prepare the environment file](#3-prepare-the-environment-file)
- [4. Build and start the stack](#4-build-and-start-the-stack)
- [5. Day to day operations](#5-day-to-day-operations)
- [6. First-run application setup](#6-first-run-application-setup)
- [7. Montenegro overview and endpoints](#7-montenegro-overview-and-endpoints)
- [8. Configure Montenegro fiscalization](#8-configure-montenegro-fiscalization)
- [9. Register the fiscal device (ENU / TCR)](#9-register-the-fiscal-device-enu--tcr)
- [10. Fiscalize invoices](#10-fiscalize-invoices)
- [11. Advance (avans) invoices](#11-advance-avans-invoices)
- [12. Multiple ENUs](#12-multiple-enus)
- [13. Cash deposits and withdrawals](#13-cash-deposits-and-withdrawals)
- [14. Invoice, fee and VAT conventions](#14-invoice-fee-and-vat-conventions)
- [15. Offline delivery, exports and retention](#15-offline-delivery-exports-and-retention)
- [16. Reporting and certificate monitoring](#16-reporting-and-certificate-monitoring)
- [17. Command and schedule reference](#17-command-and-schedule-reference)
- [18. Testing](#18-testing)
- [19. Troubleshooting](#19-troubleshooting)

## 1. Prerequisites

- Docker Engine with the Compose v2 plugin.
- At least 4 GB of free RAM for the stack (MySQL and the app each cap at 1 GB).
- A signing certificate issued for the taxpayer by the Montenegrin Tax Administration (Poreska uprava), delivered as PKCS#12 (.p12 / .pfx) or PEM. This is only needed when you register and fiscalize against the CIS (Central Information System).
- Optional: the Flutter admin bundle is already checked into `public/`. See `tools/build_flutter_admin.sh` and `tools/build_flutter_admin.ps1` if you ever need to rebuild it. This repository does not contain the Flutter source.

## 2. Repository layout for Docker

| Path | Purpose |
| --- | --- |
| `docker-compose.yml` | The whole stack: `app`, `worker`, `scheduler`, `db`, `redis`, and the opt-in `backup` service. |
| `Dockerfile` | Multi-stage build. Stage 1 runs Composer, stage 2 is `php:8.3-apache` with Chromium for PDF. Apache listens on container port 8080 and runs as `www-data`. |
| `.env.docker` / `.env.docker.example` | All runtime configuration and secrets. `.env.docker` is git ignored. |
| `docker/entrypoint.sh` | Container start sequence: migrations, Flutter admin self-heal, reference data seeding, storage link and cache clears. |
| `docker/php/php.ini` | PHP runtime overrides. |
| `docker/mysql/init/01-create-test-database.sh` | Creates the `ninja_test` database used by the test suite. |
| `docker/backup/*` | The opt-in backup service (its own image, `invoiceninja-backup:latest`). |
| `app/Services/EDocument/Standards/Montenegro/` | The EFI implementation: settings, certificate handling, IKOF, XML signing, SOAP client, exporters, reporting. |

The `app` image is baked at build time. Editing PHP files requires a rebuild (see section 5). Only `storage` is a named volume, so changing code means rebuilding the image, not just restarting.

## 3. Prepare the environment file

Copy the example and generate an application key.

```powershell
Copy-Item .env.docker.example .env.docker
docker run --rm php:8.3-cli php -r "echo 'base64:'.base64_encode(random_bytes(32));"
```

Paste the generated value into `APP_KEY` in `.env.docker`. Also review these keys:

| Key | Default | Meaning |
| --- | --- | --- |
| `APP_URL` | `http://localhost:9080` | Must match the host port you use. |
| `APP_FORWARD_PORT` | `9080` | Host port mapped to the app container. |
| `DB_FORWARD_PORT` | `13306` | Host port mapped to MySQL. |
| `REDIS_FORWARD_PORT` | `13079` | Host port mapped to Redis. |
| `DB_*` | `ninja` database, user `ninja` | Primary database. |
| `DB_*3` | `ninja_test` on connection `db-ninja-03` | Test database. |
| `RUN_SEEDER` | `1` | Seed reference data (languages, currencies, countries, timezones, designs) on a fresh database. |
| `FORCE_FLUTTER_ADMIN` | `1` | Keep accounts pointed at the Flutter admin bundle that ships in this repo. |
| `REACT_APP_ENABLED` | `false` | The React admin is not present in this repo. |

Montenegro specific keys (all optional, they have safe defaults):

| Key | Default | Meaning |
| --- | --- | --- |
| `MONTENEGRO_FISCALIZATION_URL` | `https://efi.tax.gov.me/fs-v1` | Production CIS endpoint. |
| `MONTENEGRO_FISCALIZATION_TEST_URL` | `https://efitest.tax.gov.me/fs-v1` | Test CIS endpoint. |
| `MONTENEGRO_FISCALIZATION_TEST_MODE` | `true` | Default for the per-company test switch. The per-company setting is authoritative at runtime. |
| `MONTENEGRO_FISCAL_LOG_RETENTION_DAYS` | `3650` | Fiscal records are protected from deletion for this many days. `0` keeps them forever. |
| `MONTENEGRO_FISCAL_LOG_EXPORT_LIMIT` | `50000` | Maximum rows returned by a JSON fiscal journal export. |

The host ports `9080`, `13306` and `13079` are deliberately non-default. If they collide on your machine, change the `*_FORWARD_PORT` values and `APP_URL`.

Never commit `.env.docker`. It holds the application key and database credentials.

## 4. Build and start the stack

```powershell
# Build the app image and start every default service
docker compose --env-file .env.docker up -d --build
```

Start only the application services after the first build:

```powershell
docker compose --env-file .env.docker up -d
```

Check status:

```powershell
docker compose --env-file .env.docker ps
```

Expected services:

| Service | Role | Container port | Host port |
| --- | --- | --- | --- |
| `app` | Apache and PHP, runs migrations on start. | 8080 | 9080 |
| `worker` | `queue:work redis`, executes fiscalization jobs. | n/a | n/a |
| `scheduler` | `schedule:work`, runs the cron jobs. | n/a | n/a |
| `db` | MySQL 8. | 3306 | 13306 |
| `redis` | Redis 7 (cache, sessions, queue). | 6379 | 13079 |

On first start the `app` entrypoint waits for MySQL, runs `php artisan migrate --force`, seeds reference data when the `timezones` table is empty, creates the storage link and clears caches. The `worker` and `scheduler` start after `app` reports healthy and do not run migrations.

Application URL: `http://localhost:9080`.

### Optional backup service

The backup service is behind the `backup` profile, so it never starts with the normal stack.

```powershell
# One-off backup now
docker compose --env-file .env.docker --profile backup run --rm backup once

# Run the scheduled backup loop
docker compose --env-file .env.docker --profile backup up -d backup

# List backups
docker compose --env-file .env.docker --profile backup run --rm backup list

# Restore latest (needs root inside the container)
docker compose --env-file .env.docker --profile backup run --rm --user root backup restore latest
```

## 5. Day to day operations

```powershell
# Tail logs
docker compose --env-file .env.docker logs -f app
docker compose --env-file .env.docker logs -f worker scheduler

# Shell into the app container
docker compose --env-file .env.docker exec app bash

# Run an artisan command
docker compose --env-file .env.docker exec app php artisan about

# Restart a single service
docker compose --env-file .env.docker restart worker

# Rebuild after changing PHP code and recreate the app services
docker compose --env-file .env.docker build app
docker compose --env-file .env.docker up -d --force-recreate app worker scheduler

# Stop the stack, keep data volumes
docker compose --env-file .env.docker down

# Stop and delete data (destructive: removes the database and Redis volumes)
docker compose --env-file .env.docker down -v
```

Because the application image is baked, `restart` alone does not pick up code changes. Rebuild and recreate as shown above. `app`, `worker` and `scheduler` share the same image, so recreating all three keeps them in sync.

## 6. First-run application setup

A fresh database has no account. Visiting `http://localhost:9080/` redirects to `/setup`. You can finish setup in the browser, or create an account from the CLI:

```powershell
docker compose --env-file .env.docker exec app php artisan ninja:create-account --email=admin@example.com --password='changeme!'
```

Then open `http://localhost:9080/login` and sign in. The company created in this step is the tenant you will configure for Montenegro.

To get API credentials for the commands below, open the admin UI and go to Settings, then API Tokens, or read them from the `company_tokens` table:

```powershell
docker compose --env-file .env.docker exec app php artisan tinker --execute "echo App\Models\CompanyToken::query()->first()->token;"
```

All Montenegro API endpoints are admin-only and require both headers:

```
X-API-TOKEN: <company token>
X-API-SECRET: <API secret from config('ninja.api_secret')>
```

Note: when `API_SECRET` is empty, which is the default in this Docker stack, the secret check is skipped and only a valid `X-API-TOKEN` is required.

### Interactive test console

For hands-on testing there is a standalone diagnostic page at:

```
http://localhost:9080/montenegro-fiscalization-test
```

It is a self-contained Blade console with no build step. It lets you:

- Load a certificate (PKCS#12 or PEM) and store the company settings.
- See the live status, the missing settings and the certificate expiry.
- Register a fiscal device against the CIS (`registerTCR`) and list the local ENU registry. The CIS has no list operation, so an ENU code comes from registering here or from the PU portal.
- Send a cash deposit or withdrawal.
- Fiscalize a selected issued invoice with a chosen document kind, then poll the fiscal journal for the IIC and JIKR and open the PU verification link.
- Assign an ENU to an invoice and link an advance invoice.
- View the aggregated report.

Paste the company API token into the console on first use; it is stored in the browser only. The page shows a red warning and asks for confirmation whenever the company is in production mode. Remove or restrict this route before exposing the instance publicly.

## 7. Montenegro overview and endpoints

Montenegro EFI fiscalizes every invoice against the CIS. Each fiscalized document gets:

- IIC (IKOF): a locally computed identifier, `MD5(RSA-SHA256(payload))`.
- IICSignature: the RSA-SHA256 signature of that payload.
- JIKR (FIC): the confirmation code returned by the CIS.

The payload is the pipe joined string `TIN|IssueDateTime|InvNum|BusinUnitCode|TCRCode|SoftCode|TotPrice`.

Supported document kinds:

| Kind | Meaning |
| --- | --- |
| `create` | Regular invoice fiscalization. |
| `advance` | Advance (avans) invoice, paid by the ADVANCE pay method. |
| `corrective` | Corrective invoice referencing the original document. |
| `credit` | Storno or credit note referencing the original document. |
| `debit` | Debit note referencing the original document. |
| `baddebt` | Bad debt declaration referencing the original document. |

All endpoints are under `http://localhost:9080/api/v1`.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `einvoice/montenegro/setup` | Upload or replace the certificate and store all settings. |
| GET | `einvoice/montenegro/status` | Current configuration, missing settings and certificate health. |
| POST | `einvoice/montenegro/disconnect` | Switch the company back to EN16931. |
| POST | `einvoice/montenegro/register_tcr` | Register a fiscal device and store the returned TCR code. |
| POST | `einvoice/montenegro/cash_deposit` | Report a cash deposit or withdrawal. |
| GET | `einvoice/montenegro/enus` | List registered ENUs. |
| POST | `einvoice/montenegro/enus` | Create or update an ENU. |
| POST | `einvoice/montenegro/enus/delete` | Delete an ENU. |
| GET | `einvoice/montenegro/export` | ZIP of signed XML for invoices that were never fiscalized. |
| GET | `einvoice/montenegro/fiscal_logs` | Paginated fiscal journal. |
| GET | `einvoice/montenegro/fiscal_logs/export` | Fiscal journal as CSV or JSON. |
| GET | `einvoice/montenegro/report` | Aggregated fiscalization reporting. |
| GET | `invoices/{invoice}/{action}` | Invoice actions: `fiscalize`, `set_enu`, `link_advance`. |

`{invoice}` is the hashed invoice id (the `hashed_id` field returned by the API).

## 8. Configure Montenegro fiscalization

Configuration lives on the company settings bag and is written through the `setup` endpoint. The `setup` call also connects the standard: it sets `e_invoice_type` to `MONTENEGRO` and `enable_e_invoice` to true unless you pass `enable=false`.

### Settings reference

| API field | Setting key | Default | Notes |
| --- | --- | --- | --- |
| `tin` | `montenegro_tin` | empty | 8 digit PIB or 13 digit JMB. Falls back to the company `id_number` or `vat_number`. |
| `tcr_code` | `montenegro_tcr_code` | empty | CIS assigned ENU code, format `ab123ab123`. |
| `business_unit_code` | `montenegro_business_unit_code` | empty | CIS assigned business unit code, same format. |
| `operator_code` | `montenegro_operator_code` | empty | CIS assigned operator code, same format. |
| `software_code` | `montenegro_software_code` | empty | CIS assigned software code, same format. |
| `maintainer_code` | `montenegro_maintainer_code` | empty | Optional, max 10 characters. |
| `is_in_vat` | `montenegro_is_in_vat` | `false` | Enables VAT reporting. It is also inferred true when the company has a `vat_number`. |
| `test_mode` | `montenegro_test_mode` | `true` | Sends to the test CIS endpoint. Set false for production. |
| `invoice_type` | `montenegro_invoice_type` | `NONCASH` | `CASH` or `NONCASH` default for regular invoices. |
| `payment_method` | `montenegro_payment_method` | `ACCOUNT` | Default EFI pay method. See the enum list below. |
| `advance_marker` | `montenegro_advance_marker` | `ADVANCE` | Marker read from the invoice `custom_value1` to flag an advance. |
| `reverse_charge` | `montenegro_reverse_charge` | `false` | Reverse charge flag. |
| `auto_fiscalize` | `montenegro_auto_fiscalize` | `true` | Fiscalize automatically when an invoice or credit is sent. |
| `require_buyer_tin` | `montenegro_require_buyer_tin` | `true` | Rejects non-cash fiscalization without a buyer tax number. |
| `simplified_invoice` | `montenegro_simplified_invoice` | `false` | Legacy flag, no longer emitted (removed in v5 on 1.10.2021). |
| `bank_account` | `montenegro_bank_account` | empty | Bank account reported as `BankAccNum` and on `ACCOUNT` pay methods as `BankAcc`. |
| `comp_card` | `montenegro_comp_card` | empty | Company card number reported as `CompCard` on `COMPANY` pay methods. |
| `self_iss_type` | `montenegro_self_iss_type` | empty | Self-issuing type (`AGREEMENT`, `DOMESTIC`, `ABROAD`, `SELF`, `OTHER`) reported as `TypeOfSelfIss`. |

Registration code format is exactly `ab123ab123`: two lowercase letters, three digits, two lowercase letters, three digits.

`payment_method` accepts one of: `BANKNOTE`, `CARD`, `CHECK`, `SVOUCHER`, `COMPANY`, `ORDER`, `ADVANCE`, `ACCOUNT`, `FACTORING`, `COMPENSATION`, `TRANSFER`, `WAIVER`, `KIND`, `OTHER`.

### Upload the certificate and set the codes

The certificate is stored in the encrypted company columns `e_invoice_certificate` and `e_invoice_certificate_passphrase`. The endpoint accepts PKCS#12 (.p12, .pfx) and PEM bundles, up to 5 MB.

```bash
curl -X POST "http://localhost:9080/api/v1/einvoice/montenegro/setup" \
  -H "X-API-TOKEN: $TOKEN" \
  -H "X-API-SECRET: $SECRET" \
  -F "certificate=@/path/to/taxpayer.p12" \
  -F "certificate_passphrase=secret" \
  -F "tin=12345678" \
  -F "tcr_code=ab123cd456" \
  -F "business_unit_code=ab123cd456" \
  -F "operator_code=ab123cd456" \
  -F "software_code=ab123cd456" \
  -F "maintainer_code=" \
  -F "is_in_vat=true" \
  -F "test_mode=true" \
  -F "invoice_type=NONCASH" \
  -F "payment_method=ACCOUNT" \
  -F "advance_marker=ADVANCE" \
  -F "auto_fiscalize=true" \
  -F "require_buyer_tin=true" \
  -F "simplified_invoice=false"
```

Notes:

- Omitting `certificate` keeps the stored certificate. Changing only `certificate_passphrase` re-validates the stored certificate against the new passphrase and rejects the update if it fails.
- Pass `remove_certificate=true` to clear the certificate and passphrase.
- Pass `enable=false` to store settings but disconnect the standard.

### Status and disconnecting

```bash
curl "http://localhost:9080/api/v1/einvoice/montenegro/status" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET"
```

The response includes `connected`, `configured`, `missing` (the list of settings that block fiscalization), `has_certificate`, `certificate_valid`, and certificate expiry fields (`certificate_expires_at`, `certificate_days_remaining`, `certificate_expired`, `certificate_expiring_soon`, `certificate_warning_days`, and the full `certificate` block).

```bash
curl -X POST "http://localhost:9080/api/v1/einvoice/montenegro/disconnect" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET" \
  -H "Content-Type: application/json" \
  -d '{"remove_certificate": false}'
```

An expired certificate does not block fiscalization by itself; it is reported through the status endpoint and the system health probe. Replace it before it expires.

### Generating a test certificate

For local testing you can create a self signed PKCS#12 certificate. It will not be accepted by the real CIS, but it exercises the local signing path.

```bash
openssl req -x509 -newkey rsa:2048 -keyout key.pem -out cert.pem -days 365 -nodes -subj "/C=ME/O=Test/CN=Test Taxpayer"
openssl pkcs12 -export -out taxpayer.p12 -inkey key.pem -in cert.pem -passout pass:secret
```

## 9. Register the fiscal device (ENU / TCR)

Registration returns a TCR code from the CIS and stores it as the company `montenegro_tcr_code`.

```bash
curl -X POST "http://localhost:9080/api/v1/einvoice/montenegro/register_tcr" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "tcr_internal_id": "1",
    "business_unit_code": "ab123cd456",
    "software_code": "ab123cd456",
    "maintainer_code": "ab123cd456",
    "valid_from": "2026-01-01",
    "valid_to": null,
    "type": "REGULAR"
  }'
```

`type` is `REGULAR` or `VENDING`. `tcr_internal_id` is required.

## 10. Fiscalize invoices

### Automatic

When `montenegro_auto_fiscalize` is true (the default), issuing an invoice or sending a credit note queues fiscalization automatically. The job runs on the `worker` service through Redis. An invoice marked as an advance is automatically fiscalized as `advance`.

### Manual

Use the invoice action endpoint. `{invoice}` is the hashed invoice id.

```bash
HEADERS=(-H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET")

# Regular invoice
curl "http://localhost:9080/api/v1/invoices/$INVOICE/fiscalize" "${HEADERS[@]}"

# Corrective invoice referencing the original
curl "http://localhost:9080/api/v1/invoices/$CORRECTIVE/fiscalize?kind=corrective" "${HEADERS[@]}"

# Credit note
curl "http://localhost:9080/api/v1/invoices/$CORRECTIVE/fiscalize?kind=credit" "${HEADERS[@]}"

# Debit note
curl "http://localhost:9080/api/v1/invoices/$CORRECTIVE/fiscalize?kind=debit" "${HEADERS[@]}"

# Bad debt declaration
curl "http://localhost:9080/api/v1/invoices/$CORRECTIVE/fiscalize?kind=baddebt" "${HEADERS[@]}"

# Advance invoice, when auto fiscalization is disabled
curl "http://localhost:9080/api/v1/invoices/$ADVANCE/fiscalize?kind=advance" "${HEADERS[@]}"
```

The action returns HTTP 400 with a message when:

- The company is not on the Montenegro standard.
- The invoice is already fiscalized (a GUID exists).
- A fiscalization for the same invoice is already in progress (a pending log updated within the last 10 minutes).
- A corrective kind is requested and the original fiscalized document cannot be found.

### Corrective invoices

For `corrective`, `credit`, `debit` and `baddebt`, the controller resolves the original document through `Invoice::montenegroCorrectiveReference()`. It uses the invoice's own fiscal record, or the parent referenced by `backup.parent_invoice_id`. Create the correcting invoice, set its parent reference to the original (hashed id), then call `fiscalize` with the appropriate `kind`.

### Credit notes

Credit notes have no `fiscalize` action of their own. They fiscalize automatically on the SENT event when `montenegro_auto_fiscalize` is enabled, with the entity type `credit`.

### Immutability and resends

- Once a fiscal record with an IIK exists, the invoice is locked. It cannot be deleted or archived; use a corrective document instead. The update endpoint also rejects edits to a locked invoice.
- A resend reuses the existing ordinal, issue date and IKOF instead of reserving a new fiscal number.
- Subsequent delivery is applied automatically for invoices that were issued while offline or after a service failure: 2 days for a network outage, 5 days for a service failure, bound book or technical error. The `ninja:montenegro-fiscalization` cron retries them every 30 minutes within that window.

## 11. Advance (avans) invoices

An invoice is treated as an advance when either:

- `backup.document_type` is `ADVANCE`, or
- the invoice `custom_value1` equals the configured `advance_marker` (default `ADVANCE`, case insensitive).

Fiscalize it with `kind=advance` (or rely on automatic fiscalization, which detects the marker). When the final invoice is issued, link the fiscalized advance so it is reported as a separate ADVANCE pay method:

```bash
curl "http://localhost:9080/api/v1/invoices/$FINAL_INVOICE/link_advance?advance_invoice_id=$ADVANCE_HASHED_ID" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET"
```

Linking fails if the advance invoice has not been fiscalized yet (no JIKR). Linking stores `advance_iic` and `advance_amount` on the final invoice backup, and the amount is reported from a separate ADVANCE pay method.

## 12. Multiple ENUs

A company can register several fiscal devices. Resolution order for an invoice is:

1. The invoice backup override (set by `set_enu`).
2. The company default ENU (`is_default`).
3. The legacy company settings (`montenegro_tcr_code`, and so on).

Create and list ENUs:

```bash
curl -X POST "http://localhost:9080/api/v1/einvoice/montenegro/enus" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Front desk",
    "tcr_code": "ab123cd456",
    "business_unit_code": "ab123cd456",
    "operator_code": "ab123cd456",
    "software_code": "ab123cd456",
    "maintainer_code": "",
    "is_default": true,
    "is_active": true
  }'

curl "http://localhost:9080/api/v1/einvoice/montenegro/enus" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET"
```

Assign an ENU to a single invoice before it is fiscalized:

```bash
curl "http://localhost:9080/api/v1/invoices/$INVOICE/set_enu?enu_id=$ENU_HASHED_ID" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET"
```

Only one ENU can be the default; setting a new default clears the previous one. Deleting an ENU uses `POST einvoice/montenegro/enus/delete` with `{"id": "<hashed id>"}`.

## 13. Cash deposits and withdrawals

```bash
curl -X POST "http://localhost:9080/api/v1/einvoice/montenegro/cash_deposit" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET" \
  -H "Content-Type: application/json" \
  -d '{
    "operation": "INITIAL",
    "cash_amount": 2000,
    "tcr_code": "ab123cd456",
    "change_datetime": "2026-09-22T10:00:00+02:00"
  }'
```

`operation` is `INITIAL` or `WITHDRAW`. `cash_amount` must be zero or greater. The response contains `FCDC` on success. Each attempt is recorded in `montenegro_cash_deposits` with status `sent` or `failed`, including the error code and message.

## 14. Invoice, fee and VAT conventions

The EFI document builder reads a few existing custom fields. Keep this mapping in mind when preparing invoices.

| Field | Placement | Meaning |
| --- | --- | --- |
| Supply date or period start | Invoice `custom_value3` (date) | Emitted as the supply period start. |
| Supply date or period end | Invoice `custom_value4` (date) | Emitted as the supply period end. When absent the date is a single supply date. |
| Fee type | Line item `custom_value3` | `PACK`, `BOTTLE`, `COMMISSION`, or `OTHER`. A line marked this way is reported as an EFI fee, not as an item. |
| VAT exemption | Line item `custom_value4` | `VAT_CL26`, `VAT_CL27`, `VAT_CL28` (imports), `VAT_CL29` (temporary imports), or `VAT_CL30` (special). |
| Advance marker | Invoice `custom_value1` | Matches `advance_marker` to flag an advance invoice. |

A non-cash invoice without a buyer tax number is rejected when `require_buyer_tin` is true. The buyer tax number is read from the client `vat_number`, then `id_number`.

## 15. Offline delivery, exports and retention

### Signed XML for manual delivery

When the ENU cannot reach the CIS, export the signed `RegisterInvoiceRequest` messages and deliver them manually.

```bash
curl "http://localhost:9080/api/v1/einvoice/montenegro/export?subsequent_delivery=NOINTERNET" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET" -o montenegro-export.zip
```

Pass `invoice_ids` as a comma separated list of hashed ids to export specific invoices. Without it, up to 500 unfiscalized issued invoices are exported. The export is ordinal safe: an existing open fiscal record reuses the same ordinal, issue date and IKOF.

`subsequent_delivery` is one of `NOINTERNET`, `BOUNDBOOK`, `SERVICE`, `TECHNICALERROR`.

### Fiscal journal export

The fiscal journal is one row per fiscalization attempt, including IIC, JIKR, fiscal number, totals and errors.

```bash
# CSV download
curl "http://localhost:9080/api/v1/einvoice/montenegro/fiscal_logs/export?format=csv" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET" -o fiscal-journal.csv

# JSON, bounded by MONTENEGRO_FISCAL_LOG_EXPORT_LIMIT
curl "http://localhost:9080/api/v1/einvoice/montenegro/fiscal_logs/export?format=json&date_from=2026-01-01" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET"

# Paginated list
curl "http://localhost:9080/api/v1/einvoice/montenegro/fiscal_logs?per_page=50&status=failed" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET"
```

Both endpoints accept `date_from`, `date_to`, `status` and `kind` filters.

### Retention

Fiscal records are legal documents. They are protected from deletion for `MONTENEGRO_FISCAL_LOG_RETENTION_DAYS` (default 10 years). The scheduled `ninja:montenegro-log-retention` command purges only records older than the window, in chunks, and supports a dry run.

```powershell
docker compose --env-file .env.docker exec app php artisan ninja:montenegro-log-retention --dry-run
docker compose --env-file .env.docker exec app php artisan ninja:montenegro-log-retention --days=3650
```

## 16. Reporting and certificate monitoring

### Report

```bash
curl "http://localhost:9080/api/v1/einvoice/montenegro/report?date_from=2026-01-01&group_by=day" \
  -H "X-API-TOKEN: $TOKEN" -H "X-API-SECRET: $SECRET"
```

The payload contains:

- `totals`: records, fiscalized, unfiscalized, `success_rate` and amounts.
- `by_kind`, `by_status`, `by_subseq_deliv_type`: per bucket records, fiscalized, unfiscalized and amounts.
- `backlog`: open rows (no JIKR, pending or failed), split into `within_window` and `outside_window` against the 5 day maximum retry window, plus the oldest age.
- `timeline`: day or month buckets when `group_by` is `day` or `month`.

Filters: `date_from`, `date_to`, `group_by`, `status`, `kind`.

### Certificate expiry monitoring

Certificate health is available in two places:

- `GET einvoice/montenegro/status`: per company detail, including days remaining and an expanding soon flag with a 30 day warning window.
- The system health probe `GET api/v1/health_check` (self host) returns a `montenegro` block with company counts, expired and expiring counts, and an `alerts` list. Certificates expiring within 30 days or already expired set that block's `is_okay` to false. This does not flip the global `system_health` flag.

## 17. Command and schedule reference

Artisan commands:

| Command | Options | Purpose |
| --- | --- | --- |
| `ninja:create-account` | `--email=`, `--password=` | Create the first account and company. |
| `ninja:montenegro-fiscalize` | `{invoice?}`, `--company=`, `--all` | Queue fiscalization for one invoice or all issued invoices without a JIKR. |
| `ninja:montenegro-export` | `--company=` (required), `--output=` | ZIP of signed XML for unfiscalized invoices. |
| `ninja:montenegro-export-logs` | `--company=` (required), `--from=`, `--to=`, `--status=`, `--kind=`, `--format=csv\|json`, `--output=` | Export the fiscal journal. |
| `ninja:montenegro-log-retention` | `--company=`, `--days=`, `--dry-run` | Purge fiscal records past the retention window. |

Scheduled jobs (run by the `scheduler` service):

| Name | Cadence | Purpose |
| --- | --- | --- |
| `montenegro-fiscalization-job` | Every 30 minutes | Retry invoices that were issued without a JIKR, within the legal window. |
| `montenegro-log-retention-job` | Weekly | Purge fiscal records past the retention window. |

## 18. Testing

The test suite runs against the `ninja_test` database on connection `db-ninja-03`.

```powershell
# Montenegro, certificate and system health suites
docker compose --env-file .env.docker exec -T app php artisan test --filter "Montenegro|CertificateTest|SystemHealthTest"

# Everything
docker compose --env-file .env.docker exec -T app php artisan test
```

Note: `tests/Unit/Chart/AnalyticsTestDataVerificationTest` cannot pass inside Docker because `ninja:create-analytics-test-data` refuses to run when `config('ninja.is_docker')` is true.

## 19. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| `/` redirects to `/setup` | No account exists. Run section 6. |
| `/login` returns 500 | There is no account row, so `routes/client.php` dereferences null. Create the account first. |
| `Montenegro fiscalization is not configured. Missing: ...` | Read `GET einvoice/montenegro/status`, then set the missing values listed in `missing`. |
| `a buyer tax number is required for non-cash invoices` | The client has no `vat_number` or `id_number`, or set `require_buyer_tin` to false for consumer sales. |
| `Invoice is already fiscalized` | The invoice has a GUID and a JIKR. Use a corrective document instead. |
| `Fiscalization is already in progress` | A pending fiscal record updated within the last 10 minutes exists. Wait, or inspect `einvoice/montenegro/fiscal_logs`. |
| `Fiscalized invoices cannot be deleted or archived` | The invoice is locked for legal reasons. Issue a corrective invoice. |
| Fiscalization never runs | Check `montenegro_auto_fiscalize`, then confirm the `worker` service is running and the queue is Redis: `docker compose ... logs -f worker`. |
| Certificate cannot be read | Verify the file is PKCS#12 or PEM and the passphrase is correct. The setup endpoint returns 422 when the certificate cannot be opened. |
| Certificate expiry warnings | Inspect `GET einvoice/montenegro/status` and the health probe `montenegro` block. Replace the certificate before it expires. |
| Changes to PHP code have no effect | The image is baked. Rebuild and recreate: `docker compose ... build app` then `up -d --force-recreate app worker scheduler`. |
| Pending migrations | The `app` entrypoint runs migrations. If it did not, run `docker compose ... exec app php artisan migrate --force`. |
| Port already in use | Change `APP_FORWARD_PORT`, `DB_FORWARD_PORT` or `REDIS_FORWARD_PORT` in `.env.docker` and update `APP_URL`. |

## Security notes

- Never commit `.env.docker`. It holds `APP_KEY`, the database password, and the certificate passphrase is stored encrypted in the database.
- The certificate and passphrase are stored in encrypted company columns. Only the status endpoint surfaces certificate metadata, never the key material.
- All Montenegro endpoints require an admin token. The fiscalization job must run as a real user context to resolve the company correctly, which is why it runs on the worker.
- Certificate passphrases and private keys are never written to logs. Error messages reference the certificate only by status, not by content.

### Turning off debug mode for production

`.env.docker` ships with debug enabled for local work:

```
APP_ENV=local
APP_DEBUG=true
LOG_LEVEL=debug
```

For production set them to:

```
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=error
```

Environment values are read when the container starts, so apply the change by recreating the containers and clearing caches:

```powershell
docker compose --env-file .env.docker up -d --force-recreate app worker scheduler
docker compose --env-file .env.docker exec app php artisan optimize:clear
```

Verify:

```powershell
docker compose --env-file .env.docker exec app php artisan about
```

The output must show `Environment: production` and `Debug Mode: OFF`. With debug off, a server error returns a generic 500 page with no stack trace in the browser; the details are still written to `storage/logs/laravel.log`.

Optional production caching. This makes the app faster but must be re-run after any `.env` or config change:

```powershell
docker compose --env-file .env.docker exec app php artisan optimize
```
