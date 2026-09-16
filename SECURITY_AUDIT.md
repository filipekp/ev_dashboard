# EV Stats – bezpečnostní audit a hardening

Datum auditu: **16. 9. 2026**  
Rozsah: zdrojový kód dodaný v `public.zip`, PHP aplikace, šablony, JavaScript, CSS, importy, uploady, autentizace, autorizace, updater a základní provozní konfigurace.

> Tento dokument popisuje statický audit zdrojového kódu a provedené hardening úpravy. Není náhradou za externí penetrační test infrastruktury, konfigurace webserveru, databáze a hostingu.

## Shrnutí

Aplikace už před auditem používala několik správných základů: PDO, vypnuté emulované prepared statements, centrální HTML escaping, CSRF tokeny, hashovaná hesla a serverové kontroly přístupu k vozidlům. Audit tyto mechanismy zachoval a doplnil o ochrany, které předtím nebyly vynucené jednotně.

Nejdůležitější provedené změny:

- globální bezpečnostní HTTP hlavičky a Content Security Policy s nonce,
- zpřísnění PHP session a pravidelná regenerace session ID,
- rate limiting přihlášení a obnovy hesla,
- odstranění možnosti podvrhnout doménu v resetovacích/aktivačních odkazech přes `Host` hlavičku,
- centrální a přísnější validace uploadovaných souborů podle skutečného obsahu,
- limity proti ZIP/XLSX a PDF dekompresním bombám,
- ochrana CSV exportu proti spreadsheet/formula injection,
- hardening automatického updateru proti path traversal, symlinkům a nedůvěryhodným download URL,
- bezpečnější podávání příloh a dokumentů,
- odstranění staré duplicitní autentizační implementace,
- omezení veřejného demo loginu pouze na účet s rolí `user`,
- přesun sdíleného JavaScriptu do samostatných souborů a přeformátování CSS/kritických PHP částí.

## 1. SQL injection a databáze

### Stav po úpravě

- Připojení používá PDO s `PDO::ATTR_EMULATE_PREPARES => false`.
- Uživatelské hodnoty jsou v auditovaných repository/service/controller cestách předávány přes prepared statements a placeholdery.
- Dynamické seznamy `IN (...)` jsou generovány z interně vytvořených placeholderů.
- Dynamické názvy sloupců v `TripRepository` pocházejí pouze z pevného interního allowlistu, ne z HTTP vstupu.
- ID vozidel a uživatelů jsou před použitím převáděna na integer a současně se kontroluje oprávnění aktuálního uživatele.

### Důležité provozní doporučení

Databázový účet aplikace by měl mít pouze oprávnění, která aplikace potřebuje. Neměl by být MySQL/MariaDB `root` ani mít globální administrátorská práva.

## 2. XSS a vložení nežádoucího HTML/JavaScriptu

### Stav po úpravě

- Výstup šablon používá centrální `h()` / `htmlspecialchars(..., ENT_QUOTES, UTF-8)`.
- Přidána Content Security Policy. Běžný vložený `<script>` bez platného nonce se nespustí.
- Dynamické inline skripty, které musí zůstat v šablonách, dostávají nonce generovaný pro daný request.
- Sdílená logika UI byla přesunuta do `public/assets/app.js`; další JS pro správu uživatelů a vozidel je v `public/assets/pages/`.
- Bezpečnostní hlavičky obsahují mimo jiné `X-Content-Type-Options: nosniff` a `X-Frame-Options: DENY`.
- Soubory zobrazované inline dostávají restriktivní CSP `sandbox; default-src 'none'`.

CSP stále povoluje inline styly (`style-src 'unsafe-inline'`), protože aplikace používá existující inline stylové atributy. To neumožňuje spustit JavaScript, ale do budoucna lze CSP ještě zpřísnit odstraněním těchto inline stylů.

## 3. CSRF

- Stav měnící formuláře v auditovaných controllerech ověřují CSRF token server-side pomocí `hash_equals()`.
- Token je generován kryptograficky bezpečným `random_bytes()`.
- Po přihlášení, změně hesla a dalších změnách autentizačního stavu se CSRF token rotuje.
- Session cookie používá `SameSite=Lax` jako další ochrannou vrstvu.

## 4. Přihlášení, hesla a session

- Hesla jsou ukládána pomocí `password_hash(..., PASSWORD_DEFAULT)`, nikoli v čitelné podobě.
- Nově nastavovaná hesla mají konfigurovatelnou minimální délku; výchozí hodnota je 12 znaků.
- Při úspěšném loginu se starší hash automaticky rehashuje, pokud to PHP doporučí.
- Login a obnova hesla mají server-side rate limit podle zdrojové IP adresy.
- Reset token má 256 bitů náhodnosti, v databázi je uložen pouze jeho SHA-256 hash, má expiraci a po použití se zneplatní.
- Session používá strict mode, pouze cookies, `HttpOnly`, `SameSite=Lax` a při HTTPS také `Secure`.
- Session ID se regeneruje při změně autentizačního stavu a periodicky během dlouhé relace.
- Relace má server-side idle timeout.
- V produkčním prostředí se `APP_DEBUG` v konfiguraci vynuceně vypne.

## 5. Host header poisoning resetovacích odkazů

Původní fallback uměl při chybějícím `APP_BASE_URL` sestavit resetovací nebo aktivační odkaz z HTTP `Host` hlavičky. To mohlo při nesprávně nakonfigurovaném proxy/webserveru vést k odeslání odkazu s doménou útočníka.

Po úpravě:

- v `production` je pro generování veřejných odkazů povinné explicitní `APP_BASE_URL`,
- fallback je povolen jen mimo produkci a jen pro `localhost` / `127.0.0.1`,
- výsledná URL se validuje.

**Produkční `.env` proto musí obsahovat například:**

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_BASE_URL=https://evstats.example.cz
```

## 6. Autorizace a oddělení dat uživatelů

Auditované cesty pro dashboardy, exporty, přílohy, dokumenty, fotografie, provoz vozidla a importy provádějí server-side kontrolu oprávnění. Nestačí tedy změnit `vehicle_id` nebo jiné ID v URL/formuláři.

Aplikace používá:

- kontrolu `canAccessVehicle`,
- hierarchii administrátor → správce vozidel → řidič,
- časově omezený detail scope při změnách přiřazení vozidla,
- kontrolu vozidla také při podávání uložených dokumentů a médií.

Veřejný demo login je nyní povolen pouze pro účet s rolí `user`. Pouhé omylem nastavené e-mailové spojení na administrátora už nedokáže vytvořit demo administrátorskou relaci.

## 7. Uploady, dokumenty a importy

Byla přidána společná třída `UploadValidator`.

Kontroly zahrnují:

- `UPLOAD_ERR_OK`,
- `is_uploaded_file()` – soubor musí skutečně pocházet z HTTP uploadu,
- maximální velikost souboru,
- MIME detekci pomocí `finfo`, ne důvěru v MIME poslané prohlížečem,
- allowlist povolených typů,
- kontrolu rozměrů obrázků a maximálního počtu pixelů,
- náhodné serverové názvy uložených souborů,
- bezpečné očištění původního názvu,
- ukládání do `storage/` mimo veřejný webroot,
- explicitní zákaz přímého webového přístupu do `storage/` pomocí `.htaccess`,
- restriktivní souborová oprávnění po uložení.

### XLSX / ZIP

Parser kontroluje:

- maximální počet ZIP entries,
- maximální nekomprimovanou velikost jednotlivé položky,
- kompresní poměr,
- velikost výsledku po rozbalení.

Tím se snižuje riziko ZIP/decompression bomb DoS útoků.

### PDF

Flate streamy mají limit maximální dekomprimované velikosti. Parser tak nezkouší bez omezení nafukovat útočně vytvořený PDF stream.

## 8. CSV export a spreadsheet injection

Textové hodnoty, které začínají `=`, `+`, `-` nebo `@`, jsou před exportem escapovány. Tím se omezuje riziko, že Excel/LibreOffice vyhodnotí uživatelský text jako vzorec po otevření exportovaného CSV.

## 9. Automatický updater

Updater je dostupný pouze administrátorovi a POST operace je chráněna CSRF tokenem.

Doplněné kontroly:

- pouze HTTPS GitHub/API/codeload domény na allowlistu,
- omezení cURL protokolů na HTTPS,
- kontrola výsledné URL i po redirectu,
- limit počtu položek a celkové/individuální rozbalené velikosti ZIPu,
- zákaz absolutních cest a `..` path traversal,
- odmítnutí symlink entries, pokud je umí běžící `ZipArchive` reportovat.

## 10. HTTP security headers

Aplikace nově centrálně posílá mimo jiné:

- `Content-Security-Policy`,
- `X-Content-Type-Options: nosniff`,
- `X-Frame-Options: DENY`,
- `Referrer-Policy: strict-origin-when-cross-origin`,
- restriktivní `Permissions-Policy`,
- `Cross-Origin-Opener-Policy: same-origin`,
- při HTTPS v produkci také HSTS.

HSTS má smysl pouze na doméně, která je skutečně a trvale dostupná přes HTTPS.

## 11. PWA a cache

Service worker cachuje pouze statické assety. Auditovaná verze necachuje autentizované HTML stránky, API odpovědi ani uživatelská data. Do statického cache byl doplněn nový `assets/app.js`.

## 12. Třetí strany a soukromí

### Google Analytics

Aplikace může posílat údaje o návštěvnosti do Google Analytics. Administrátor je z analytiky vynechán podle existujícího požadavku, ostatní uživatelé nikoli.

### AI vytěžování dokumentů

Pokud je v `.env` aktivován provider OpenAI nebo Gemini, obsah nahraných dokumentů může být odeslán vybranému poskytovateli za účelem vytěžení údajů. Pokud je `AI_PROVIDER=none`, tato externí AI cesta se nepoužívá.

Na landing page ani v zásadách soukromí proto není vhodné tvrdit, že „data nikdy neopouštějí server“, pokud je AI nebo externí analytika aktivní.

## 13. Co lze pravdivě komunikovat na landing page

Doporučená formulace:

> **Vaše data chráníme ve více vrstvách.** Přístup k vozidlům a dokumentům ověřujeme na serveru podle uživatelských oprávnění, databázové dotazy používají parametrizované SQL, formuláře chráníme proti CSRF a uživatelský obsah před zobrazením escapujeme. Přihlášení je chráněno rate limitingem a bezpečnými session cookies. Nahrané dokumenty kontrolujeme podle skutečného typu a ukládáme je pod náhodnými názvy mimo veřejný webový prostor. Aplikace používá Content Security Policy a další bezpečnostní HTTP hlavičky.

Lze také uvést:

> Hesla neukládáme v čitelné podobě – ukládáme pouze bezpečný jednosměrný hash.

### Co bez další infrastruktury netvrdit

Netvrdit například:

- „100% bezpečné“ nebo „nelze hacknout“,
- „end-to-end šifrování“,
- „zero-knowledge“,
- „databáze je šifrovaná“, pokud to není skutečně zajištěno hostingem/storage vrstvou,
- „nikdo včetně provozovatele nemá k datům přístup“,
- „data nikdy neopouštějí náš server“, pokud je aktivní Google Analytics nebo AI provider.

## 14. Provozní checklist před produkcí

1. Vynutit HTTPS pro celou aplikaci.
2. Nastavit `APP_ENV=production`, `APP_DEBUG=false` a přesné HTTPS `APP_BASE_URL`.
3. Uchovávat `.env` mimo verzovací systém a pravidelně rotovat DB/API/reCAPTCHA tajné klíče.
4. Použít databázového uživatele s minimálními potřebnými oprávněními.
5. Zálohovat databázi i `storage/` a pravidelně ověřovat obnovu ze zálohy.
6. Zajistit, aby `storage/` a `.env` nebyly přímo dostupné z webu také konfigurací hostingu; `.htaccess` je druhá vrstva, ne jediná.
7. Pravidelně instalovat bezpečnostní aktualizace PHP, webserveru, DB a aplikace.
8. Sledovat aplikační/webserver logy a neúspěšná přihlášení/importy.
9. U e-mailové domény nastavit SPF, DKIM a DMARC.
10. Před veřejným marketingovým tvrzením o šifrování dat at-rest ověřit možnosti konkrétního hostingu.
11. Pro vysokou míru jistoty před větším veřejným nasazením provést externí penetrační test běžící produkční instance.

## 15. Nové bezpečnostní konfigurační volby

`.env.example` obsahuje:

```dotenv
SESSION_IDLE_SECONDS=43200
LOGIN_RATE_LIMIT_ATTEMPTS=10
LOGIN_RATE_LIMIT_WINDOW=900
PASSWORD_RESET_RATE_LIMIT_ATTEMPTS=5
PASSWORD_RESET_RATE_LIMIT_WINDOW=3600
MINIMUM_PASSWORD_LENGTH=12
```

Hodnoty lze podle provozu zpřísnit. Příliš agresivní limity ale mohou blokovat více legitimních uživatelů za jednou sdílenou IP adresou.

## 16. Ověření provedené v rámci této úpravy

Po změnách je potřeba vždy minimálně spustit:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/app.js
node --check public/assets/pages/users.js
node --check public/assets/pages/vehicles.js
node --check public/service-worker.js
```

CSS byl po přeformátování znovu parsován přes PostCSS. Současně byly provedeny statické grep kontroly pro chybějící CSRF v POST controllerech, přímé výpisy `$_GET`/`$_POST`, nechráněné inline skripty a podezřelé systémové PHP funkce.
