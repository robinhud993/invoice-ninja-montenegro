# Invoice Ninja: Montenegro EFI fiscalization (community fork)

[English](#english) | [Crnogorski / Srpski](#crnogorski--srpski-latinica)

> **Unofficial community fork.** This repository is a modified version of
> [Invoice Ninja](https://github.com/invoiceninja/invoiceninja) by Invoice Ninja LLC
> and its contributors. It is **not** an official Invoice Ninja release and is
> **not affiliated with, endorsed by, or supported by Invoice Ninja LLC**.
> "Invoice Ninja" and the Invoice Ninja logo are trademarks of Invoice Ninja LLC.
> The upstream copyright, license, and notices remain with their respective owners.
>
> This modified version is distributed under the **Elastic License 2.0 (ELv2)**.
> See [LICENSE](LICENSE) and [NOTICE](NOTICE). In line with ELv2, this README and
> the NOTICE file contain prominent notices that the software has been modified.


## Crnogorski | Srpski (latinica)

### Šta je ovaj repozitorijum

Invoice Ninja v5 fork sa kompletnom **crnogorskom EFI
fiskalizacijom** (Poreska uprava, CIS), Docker okruženjem za razvoj i
produkciju, poboljšanjima PDF generisanja i prikaza valute potrebnim za
svakodnevni rad u Crnoj Gori.

Kod u ovom stablu odgovara Invoice Ninja verziji iz `VERSION.txt` 5.13.43.
Flutter admin portal je verzije 5.0.194+194.

### Autorska prava i licenca

Ovaj projekat ne bi postojao bez Invoice Ninja projekta. Sve zasluge za
originalnu aplikaciju pripadaju upstream projektu i njegovoj zajednici:

- Upstream repozitorijum: <https://github.com/invoiceninja/invoiceninja>
- Veb sajt: <https://www.invoiceninja.com>
- Self-hosted informacije: <https://www.invoiceninja.org>
- Dokumentacija: <https://invoiceninja.github.io>
- Forum podrške: <https://forum.invoiceninja.com>
- Discord: <https://discord.gg/ZwEdtfCwXA>

Softver je licenciran pod **Elastic License 2.0 (ELv2)**. Kompletni uslovi su u
[LICENSE](LICENSE). ELv2 dozvoljava korišćenje, izmenu i distribuciju, ali ne
dozvoljava pružanje softvera trećim licima kao hostovane ili upravljane usluge i
zahtjeva da licence, autorska prava i ostala obaveštenja ostanu netaknuti. Ako
postavljate ovaj fork, odgovorni ste za poštovanje ELv2.

### Šta je dodato ili izmenjeno

#### Crnogorska EFI fiskalizacija

- Kompletan tok fiskalizacije prema crnogorskom CIS-u preko SOAP 1.1, sa
  odvojenim test i produkcionim okruženjem.
- XML digitalni potpis (`XML-DSig`, exclusive C14N, SHA-256, RSA-SHA256, X.509)
  preko `robrichards/xmlseclibs` biblioteke.
- IKOF (`IIC`) kao `MD5(RSA-SHA256(payload))`, JIKR (`FIC`) iz odgovora CIS-a i
  lokalni QR kod za verifikaciju.
- Dokumenti: redovne fakture, proforma i kopija, korektivni i storno dokumenti,
  avansi, naknadna isporuka i blagajnički depoziti.
- Načini plaćanja, PDV i oslobođenja, fee totals, same-tax totals i raspodela
  avansa.
- TCR i ENU registracija, fiskalni brojači i dodjela ordinala, fiskalni audit log,
  izvoz žurnala i idempotentno ponovno slanje.
- Automatska fiskalizacija pri slanju fakture, ručna fiskalizacija, korektivne
  reference i test konzola za CIS.
- Podešavanja kompanije: TIN, TCR, šifra poslovne jedinice, operator, softver i
  maintainer kod, PDV status, test režim, tip fakture, način plaćanja, marker
  avansa, reverse charge, auto-fiskalizacija, obavezan TIN kupca, pojednostavljena
  faktura, bankovni račun, kartica kompanije i tip self-issued fakture.
- Šifrovano čuvanje sertifikata (PKCS#12 ili PEM), provera passphrase i uklanjanje
  sertifikata.
- Fiskalni podaci na PDF-u preko `$montenegro_fiscalization` promenljive i
  pojedinačnih polja kao što su IKOF, JIKR, ordinal, mode, fiskalno vreme, TCR i
  operator.

#### Docker razvojno i produkciono okruženje

- `docker-compose.yml` sa aplikacijom, MySQL 8, Redis 7, queue worker-om,
  scheduler-om i opcionim backup profilom.
- Health check-ovi, named volumes, resource limits, rad kao non-root korisnik i
  Chromium za PDF renderovanje.
- PowerShell skripte za deploy, backup i restore u `scripts/` folderu.

#### PDF generisanje i performanse

- Veći CPU limit za app kontejner, da Chromium može da koristi više jezgara.
- Detekcija praznog PDF-a sada provjerava PDF header umjesto 12 KB threshold-a,
  čime se izbjegava nepotrebno višestruko renderovanje.
- Konfigurabilni Chromium virtual-time budget (`SNAPPDF_VIRTUAL_TIME_BUDGET`).
- Opciono negativno keširanje neuspešnih image fetch-eva
  (`PDF_IMAGE_NEGATIVE_CACHE_TTL`).
- Sakriveni Invoice Ninja whitelabel logo se više ne fetch-uje kada je nalog
  white-label.

#### Prikaz valute

- Podrazumijevana pozicija simbola za Euro i Crnu Goru je posle iznosa, na primjer
  `1.000,00 €`.
- Migracija `2026_11_09_000001_set_montenegro_currency_symbol_position.php`
  primenjuje podrazumijevano podešavanje na postojeće baze.
- Separatori se podešavaju odvojeno u podešavanjima valute, tako da drugi locale
  i dalje može da koristi `1,000.00 €` ako je tako konfigurisan.

#### Dokumentacija i testovi

- Kompletni lokalni vodič: [`docs/docker-montenegro-fiscalization.md`](docs/docker-montenegro-fiscalization.md).
- Feature testovi za CG tokove, uključujući PDF izlaz.
- Komanda za testove:

```bash
docker compose --env-file .env.docker exec -T app php artisan test --filter Montenegro
```

### Brzi start sa Docker-om

Preduslovi: Docker Engine ili Docker Desktop sa Compose v2 i dovoljno memorije za
Chromium i MySQL (preporučeno 4 GB ili više).

```bash
cp .env.docker.example .env.docker
# Generisati APP_KEY i postaviti jake lozinke za bazu u .env.docker.
docker compose --env-file .env.docker up -d --build
```

Na Windows-u pomoćna skripta kreira `.env.docker` sa generisanim `APP_KEY` i
lozinkama za bazu:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1
```

Podrazumevani lokalni portovi iz `.env.docker.example`:

| Servis | URL ili port |
| --- | --- |
| Aplikacija | <http://localhost:9080> |
| MySQL | `127.0.0.1:13306` |
| Redis | `127.0.0.1:13079` |

Kreiranje prvog administrator naloga:

```bash
docker compose --env-file .env.docker exec app php artisan ninja:create-account --email=admin@example.com --password='change-me-now'
```

Kompletno podešavanje, uključujući sertifikat, TCR/ENU registraciju, test režim i
API primjere, nalazi se u
[`docs/docker-montenegro-fiscalization.md`](docs/docker-montenegro-fiscalization.md).

### Podešavanje crnogorske fiskalizacije

1. Postaviti državu kompanije na Crnu Goru i valutu na EUR.
2. Otvoriti Montenegro fiskalizaciju i uneti TIN, TCR kod, šifru poslovne
   jedinice, šifru operatora, softver i maintainer kod.
3. Učitati sertifikat poreskog obveznika. Sertifikat se čuva šifrovano, a
   passphrase se nikada ne upisuje kao plain text.
4. Ostaviti **test režim** uključen dok se test CIS tok ne proveri.
5. Registrovati TCR/ENU, zatim fiskalizovati test fakturu.
6. Preći u produkcioni režim tek kada su test tok, sertifikat i PDF izlaz
   provereni.

Nikada ne fiskalizovati produkcione dokumente na test okruženju i nikada ne
ponavljati fiskalizaciju fakture koja već ima JIKR. Dupli fiskalni dokument nije
bezopasno ponavljanje.

### Ponovno buildovanje Flutter admin portala

Izvor admin portala je `admin-portal` checkout koji koristi
`tools/build_flutter_admin.ps1`. Očekivana Flutter verzija je 3.41.9.

```powershell
powershell -ExecutionPolicy Bypass -File tools\build_flutter_admin.ps1 `
  -SkipCodegen -SkipAnalyze -Flavor foss `
  -FlutterExe "C:\path\to\flutter\bin\flutter.bat"

docker compose --env-file .env.docker build app
docker compose --env-file .env.docker up -d --force-recreate app worker scheduler
```

Posle deploy-a novog bundle-a uraditi hard refresh admin UI-ja, da bi se zamijenio
stari service-worker keš.

### Bezbijednost i usklađenost

- `.env.docker`, sertifikati, passphrase, API tokeni, backup-i, export-i i
  storage podaci se ne commit-uju u ovaj repozitorijum. Tako treba i da ostane.
- Funkcija crnogorske fiskalizacije je implementaciona pomoć, ne sertifikat i ne
  garancija poreske usklađenosti. Poreski obveznik ostaje odgovoran za ispravnu
  fiskalizaciju i čuvanje zakonom propisane dokumentacije.
- Projekat nije povezan sa Poreskom upravom Crne Gore niti sa Invoice Ninja LLC.
- Kompletan tok testirati u CIS test okruženju pre produkcije.
- Bezbednosne probleme u ovom forku prijaviti maintainerima forka. Za upstream
  probleme pratiti upstream security policy.

### Higijena repozitorijuma

Sledeće namerno nije dio version control-a:

- `.env.docker` i drugi lokalni environment fajlovi.
- `backups/`, `exports/`, storage upload-i, logovi i generisani PDF-ovi.
- Flutter admin backup direktorijumi u `storage/app/`.
- Lične deployment bilješke i lokalni IDE helper fajlovi.
- Veliki generisani `.map` fajlovi i neiskorišćeni profile Flutter bundle.
- Uvezena regulatorna specifikacija. Zvaničnu tehničku specifikaciju za
  crnogorsku EFI fiskalizaciju preuzeti iz zvaničnih kanala Poreske uprave Crne
  Gore.

Vidjeti [`.gitignore`](.gitignore) i [`.dockerignore`](.dockerignore).

### Zahvalnost

- [Hillel Coren](https://hillelcoren.com/)
- [David Bomba](https://github.com/turbo124)
- [Benjamin Beganović](https://github.com/beganovich)
- [Svi Invoice Ninja saradnici](https://github.com/invoiceninja/invoiceninja/graphs/contributors)
- Maintaineri Laravel-a, MySQL-a, Redis-a, Chromium-a, Snappdf-a,
  `robrichards/xmlseclibs`, `endroid/qr-code` i ostalih open-source zavisnosti
  navedenih u `composer.lock` i `package-lock.json`.



## English

### What this repository is

A self-hosted Invoice Ninja v5 fork with end-to-end **Montenegro EFI digital
fiscalization** (Poreska uprava, CIS), a reproducible Docker stack, and a set of
PDF and currency-formatting improvements needed for day-to-day use in Montenegro.

The code in this tree corresponds to Invoice Ninja `VERSION.txt` 5.13.43. The
bundled Flutter admin portal reports version 5.0.194+194.

### Attribution and license

This project would not exist without Invoice Ninja. All credit for the original
application belongs to the upstream project and its community:

- Upstream repository: <https://github.com/invoiceninja/invoiceninja>
- Website: <https://www.invoiceninja.com>
- Self-hosted information: <https://www.invoiceninja.org>
- Documentation: <https://invoiceninja.github.io>
- Support forum: <https://forum.invoiceninja.com>
- Discord: <https://discord.gg/ZwEdtfCwXA>

The software is licensed under the **Elastic License 2.0 (ELv2)**. The full terms
are in [LICENSE](LICENSE). ELv2 allows use, modification, and distribution, but it
does not allow providing the software to third parties as a hosted or managed
service, and it requires keeping the license, copyright, and other notices intact.
If you deploy this fork, you are responsible for complying with ELv2.

### What was added or changed

#### Montenegro EFI fiscalization

- End-to-end fiscalization flow against the Montenegrin CIS over SOAP 1.1, with
  separate test and production endpoints.
- Enveloped XML digital signature (`XML-DSig`, exclusive C14N, SHA-256,
  RSA-SHA256, X.509 key info) using `robrichards/xmlseclibs`.
- IKOF (`IIC`) generation as `MD5(RSA-SHA256(payload))`, JIKR (`FIC`) handling
  from the CIS response, and local QR verification codes.
- Invoice and document flows: regular invoices, proforma and copy documents,
  corrective and storno documents, advances (avans), subsequent delivery
  (`naknadna isporuka`), and cash deposits.
- Payment methods, VAT and exempt handling, fee totals, same-tax totals, and
  advance allocation.
- TCR and ENU registration, fiscal counters and ordinal assignment, fiscal audit
  log, journal export, and idempotent re-sends.
- Automatic fiscalization on invoice send, manual fiscalization, corrective
  references, and a test console for the CIS.
- Company settings for TIN, TCR, business unit, operator, software and
  maintainer codes, VAT status, test mode, invoice type, payment method,
  advance marker, reverse charge, auto-fiscalize, buyer TIN requirement,
  simplified invoice, bank account, company card, and self-issued invoice type.
- Encrypted certificate storage (PKCS#12 or PEM) with passphrase validation and
  certificate removal.
- Fiscal data printed on PDFs through the `$montenegro_fiscalization` variable
  and individual fields such as IKOF, JIKR, ordinal, mode, fiscal time, TCR and
  operator code.

#### Docker development and deployment stack

- `docker-compose.yml` with the application, MySQL 8, Redis 7, a queue worker,
  a scheduler, and an optional backup profile.
- Health checks, named volumes, resource limits, non-root execution, and
  Chromium for server-side PDF rendering.
- Windows PowerShell helpers for deploy, backup, and restore in `scripts/`.

#### PDF generation and performance

- App container CPU limit raised so Chromium can use multiple cores.
- Blank-PDF detection now checks the PDF header instead of relying on a 12 KB
  size threshold, avoiding unnecessary repeat renders.
- Configurable Chromium virtual-time budget (`SNAPPDF_VIRTUAL_TIME_BUDGET`).
- Optional negative caching for failed image fetches
  (`PDF_IMAGE_NEGATIVE_CACHE_TTL`).
- Hidden Invoice Ninja whitelabel image is no longer fetched when the account is
  white-labelled.

#### Currency formatting

- Default symbol position for Euro and Montenegro is after the amount, for
  example `1.000,00 €`.
- Migration `2026_11_09_000001_set_montenegro_currency_symbol_position.php`
  applies the default to existing databases.
- The separators are controlled separately by the currency settings, so a
  different locale can still use `1,000.00 €` if configured that way.

#### Documentation and tests

- Full local guide: [`docs/docker-montenegro-fiscalization.md`](docs/docker-montenegro-fiscalization.md).
- Feature tests for the Montenegro flows, including PDF output.
- Test command:

```bash
docker compose --env-file .env.docker exec -T app php artisan test --filter Montenegro
```

### Quick start with Docker

Requirements: Docker Engine or Docker Desktop with Compose v2, and enough memory
for Chromium and MySQL (4 GB or more is recommended).

```bash
cp .env.docker.example .env.docker
# Generate an application key and set strong database passwords in .env.docker.
docker compose --env-file .env.docker up -d --build
```

On Windows, the helper script creates `.env.docker` with a generated `APP_KEY`
and database passwords:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1
```

Default local ports from `.env.docker.example`:

| Service | URL or port |
| --- | --- |
| Application | <http://localhost:9080> |
| MySQL | `127.0.0.1:13306` |
| Redis | `127.0.0.1:13079` |

Create the first administrator account:

```bash
docker compose --env-file .env.docker exec app php artisan ninja:create-account --email=admin@example.com --password='change-me-now'
```

The full setup, including certificate loading, TCR/ENU registration, test mode,
and API examples, is in [`docs/docker-montenegro-fiscalization.md`](docs/docker-montenegro-fiscalization.md).

### Configure Montenegro fiscalization

1. Set the company country to Montenegro and the currency to EUR.
2. Open the Montenegro fiscalization settings and enter the TIN, TCR code,
   business unit code, operator code, software code, and maintainer code.
3. Load the taxpayer certificate. It is stored encrypted; the passphrase is
   never written back as plain text.
4. Keep **test mode** enabled until the test CIS flow is verified.
5. Register the TCR/ENU, then fiscalize a test invoice.
6. Switch to production mode only after the test flow, certificate, and PDF
   output have been verified.

Never fiscalize production documents against the test environment, and never
re-fiscalize an invoice that already has a JIKR. A duplicate fiscal document is
not a harmless retry.

### Rebuild the Flutter admin portal

The admin portal source is the `admin-portal` checkout used by
`tools/build_flutter_admin.ps1`. The expected Flutter version is 3.41.9.

```powershell
powershell -ExecutionPolicy Bypass -File tools\build_flutter_admin.ps1 `
  -SkipCodegen -SkipAnalyze -Flavor foss `
  -FlutterExe "C:\path\to\flutter\bin\flutter.bat"

docker compose --env-file .env.docker build app
docker compose --env-file .env.docker up -d --force-recreate app worker scheduler
```

Hard-refresh the admin UI after deploying a new bundle so the old service-worker
cache is replaced.

### Security and compliance

- `.env.docker`, certificates, passphrases, API tokens, backups, exports, and
  storage data are not committed to this repository. Keep it that way.
- The Montenegro fiscalization feature is an implementation aid, not a
  certification or a guarantee of tax compliance. The taxpayer remains
  responsible for correct fiscalization and for keeping the legally required
  records.
- This project is not affiliated with the Tax Administration of Montenegro or
  with Invoice Ninja LLC.
- Test the complete flow in the CIS test environment before using it in
  production.
- Report security issues in this fork to the fork maintainers. For upstream
  security issues, follow the upstream security policy.

### Repository hygiene

The following are intentionally excluded from version control:

- `.env.docker` and other local environment files.
- `backups/`, `exports/`, storage uploads, logs, and generated PDFs.
- Flutter admin backup directories under `storage/app/`.
- Personal deployment notes and local IDE helper files.
- Large generated `.map` files and the unused profile Flutter bundle.
- The bundled regulatory specification. Obtain the official Montenegro EFI
  technical specification from the Tax Administration of Montenegro.

See [`.gitignore`](.gitignore) and [`.dockerignore`](.dockerignore).

### Credits

- [Hillel Coren](https://hillelcoren.com/)
- [David Bomba](https://github.com/turbo124)
- [Benjamin Beganović](https://github.com/beganovich)
- [All Invoice Ninja contributors](https://github.com/invoiceninja/invoiceninja/graphs/contributors)
- The maintainers of Laravel, MySQL, Redis, Chromium, Snappdf,
  `robrichards/xmlseclibs`, `endroid/qr-code`, and the other open-source
  dependencies listed in `composer.lock` and `package-lock.json`.

---
