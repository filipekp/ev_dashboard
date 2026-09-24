# EV Stats

EV Stats je webová aplikace pro kompletní evidenci, analytiku a dlouhodobou správu vozidel. Původně vznikla pro detailní sledování elektromobilů, dnes ale podporuje také plug-in hybridy, hybridy i klasická spalovací vozidla.

Cílem projektu je sjednotit na jednom místě **jízdy, spotřebu, nabíjení a tankování, náklady, servis, dokumenty, fotografie, připomínky, analytiku, importy a nově také online telemetrii vozidla**.

Aktuální vývojová větev zároveň obsahuje základ architektury **Connected Car / AutoSync**, na kterém mohou navazovat další online integrace, Anomaly Engine a EV Stats Copilot.

---

## Obsah

- [Hlavní funkce](#hlavní-funkce)
- [Podporované typy vozidel](#podporované-typy-vozidel)
- [Connected Car / AutoSync](#connected-car--autosync)
- [Telemetry a Event model](#telemetry-a-event-model)
- [Dashboard a analytika](#dashboard-a-analytika)
- [Import jízd](#import-jízd)
- [Manuální mapování neznámých importů](#manuální-mapování-neznámých-importů)
- [Dokumentové centrum a AI vytěžování](#dokumentové-centrum-a-ai-vytěžování)
- [Provozní evidence](#provozní-evidence)
- [Timeline](#timeline)
- [Uživatelé, role a oprávnění](#uživatelé-role-a-oprávnění)
- [Registrace a demo](#registrace-a-demo)
- [PWA](#pwa)
- [Bezpečnost](#bezpečnost)
- [Požadavky](#požadavky)
- [Instalace](#instalace)
- [Aktualizace existující instalace](#aktualizace-existující-instalace)
- [Konfigurace `.env`](#konfigurace-env)
- [MyŠkoda Public API](#myškoda-public-api)
- [Automatická synchronizace přes cron](#automatická-synchronizace-přes-cron)
- [Docker](#docker)
- [Architektura projektu](#architektura-projektu)
- [Databázové migrace](#databázové-migrace)
- [Vývoj nového importního pluginu](#vývoj-nového-importního-pluginu)
- [Vývoj nového OEM konektoru](#vývoj-nového-oem-konektoru)
- [Testování a kontrola syntaxe](#testování-a-kontrola-syntaxe)
- [Provozní doporučení](#provozní-doporučení)
- [Roadmap](#roadmap)
- [Licence](#licence)
- [Autor](#autor)

---

## Hlavní funkce

### Digitální garáž

- více vozidel pod jedním účtem,
- fotografie vozidel,
- výchozí vozidlo uživatele,
- VIN a registrační značka,
- technické a provozní parametry,
- podpora různých typů pohonu,
- individuální dashboard každého vozidla,
- souhrnný Garage dashboard napříč vozidly.

### Jízdy a spotřeba

- import jízd z CSV/XLSX,
- pluginová architektura importů,
- automatická detekce podporovaného formátu,
- automatické rozpoznání vozidla podle VIN,
- ruční přidávání a editace jízd,
- stránkovaná historie,
- filtrování podle období a roku,
- export jízd zpět do CSV,
- spotřeba energie nebo paliva podle typu pohonu,
- klasifikace jízd pro knihu jízd.

### Analytika

- celková vzdálenost,
- počet jízd,
- průměrná spotřeba,
- meziroční statistiky,
- sezónní spotřeba,
- vývoj nákladů na kilometr,
- porovnání vozidel,
- provozní náklady,
- TCO včetně odpisu/depreciace,
- odhad State of Health baterie,
- jednoduché personalizované insighty.

### Provoz vozidla

- nabíjení,
- tankování,
- servisní historie,
- ostatní výdaje,
- připomínky podle data i kilometrů,
- servisní přílohy,
- kniha jízd,
- chronologická Timeline.

### Dokumentové centrum

- upload PDF a obrázků,
- ukládání originálů mimo veřejný webroot,
- SHA-256 deduplikace,
- lokální parsery poskytovatelů,
- AI fallback přes OpenAI nebo Gemini,
- opakované vytěžení dokumentu,
- možnost vynutit lokální parser nebo AI,
- historie jednotlivých pokusů o vytěžení,
- náhled dat před potvrzením,
- bezpečné vytvoření provozních záznamů až po potvrzení uživatelem.

### Connected Car / AutoSync

- pluginová architektura přímých OEM konektorů podle výrobce vozidla,
- první plnohodnotný konektor pro oficiální **MyŠkoda Public API**,
- integrační widget přímo v editaci konkrétního vozidla,
- šifrované ukládání API credentials,
- kontrola VIN vráceného výrobcem proti vozidlu v EV Stats,
- ruční synchronizace i automatický AutoSync přes CLI/cron,
- respektování API rate limitů a retry intervalů,
- normalizované snapshoty telemetrie,
- provider-agnostický event stream,
- historie synchronizačních běhů,
- Connected Car události v Timeline,
- registry připravený pro další OEM konektory.

### Administrace a provozní monitoring

- správa uživatelů,
- hierarchie administrátor → správce vozidel → řidič,
- přiřazování vozidel,
- monitoring importních běhů,
- přehled selhaných importů,
- monitoring dokumentových importů,
- monitoring neznámých importních formátů,
- updater aplikace z GitHubu.

---

## Podporované typy vozidel

Aplikace používá jednotný model vozidla a podle typu pohonu upravuje zobrazené metriky.

Podporované hodnoty:

| Typ | Význam |
|---|---|
| `BEV` | bateriový elektromobil |
| `PHEV` | plug-in hybrid |
| `HEV` | hybrid |
| `PETROL` | benzín |
| `DIESEL` | nafta |
| `LPG` | LPG |
| `CNG` | CNG |

Elektromobily mohou pracovat s hodnotami SoC, kapacitou baterie, kWh a SoH. Spalovací vozidla používají litry, objem nádrže a spotřebu paliva.

---

# Connected Car / AutoSync

Connected Car je postavený jako **pluginová vrstva přímých konektorů automobilek**. Analytika, Timeline a budoucí Anomaly Engine neznají formát konkrétního OEM API; pracují pouze s normalizovanou telemetrií a eventy.

Aktuálně jsou zapojené přímé OEM konektory pro **Škoda, Kia, Tesla, Audi a Volkswagen**. Škoda používá uživatelský API key, Kia používá Pleos **Private User My Vehicle API key (Client ID + Client Secret)**, Tesla používá Tesla Fleet API OAuth a Audi/Volkswagen používají Volkswagen Group EU Data Act Data Hub. Kód není svázaný s jedním agregátorem a další značku lze doplnit registrací další implementace `VehicleConnectorInterface`.

```text
Škoda Public API      Kia / Pleos       Tesla Fleet API     další OEM
       │                    │                  │              │
       └────────────────────┴──────────┬───────┴──────────────┘
                                       ▼
                         VehicleConnectorRegistry
                                       │
                                       ▼
                         VehicleConnectorInterface
                                       │
                               OEM normalizer
                                       │
                         ┌─────────────┴─────────────┐
                         ▼                           ▼
                 Telemetry snapshots             Events
                         │                           │
                         └─────────────┬─────────────┘
                                       ▼
                          Timeline / Insights /
                          budoucí Anomaly Engine
```

### Aktuálně implementovaný tok

1. Správce otevře **Vozidla → Upravit vozidlo**.
2. EV Stats podle výrobce vybere dostupné konektory z `VehicleConnectorRegistry`.
3. EV Stats podle značky nabídne odpovídající konektor: MyŠkoda Public API, Kia Connect / Pleos, Tesla Fleet API nebo VAG Data Hub pro Audi/Volkswagen.
4. Škoda a VAG používají API credential, Kia a Tesla používají OAuth flow. Kia navíc používá samostatný data-sharing consent přes Pleos.
5. Credentials/tokeny se zašifrují aplikačním master key `VEHICLE_CREDENTIALS_KEY`; plaintext se do databáze neukládá.
6. EV Stats ověří VIN a oprávnění/souhlas pro konkrétní vozidlo.
7. OEM odpověď se převede do společného telemetry modelu.
8. Snapshot se uloží idempotentně; ze změn proti předchozímu snapshotu mohou vzniknout doménové eventy.
9. Odometr z online zdroje může zvýšit aktuální stav vozidla, nikdy jej však nesnižuje.
10. Další synchronizaci řídí `next_sync_at`, expirace tokenu, API rate-limit metadata a případný retry plán.

### Bezpečnost Connected Car

- API klíče/tokeny se neukládají plaintextem.
- Šifrovací master key je pouze v `.env` a **nesmí** být uložen v databázi ani repozitáři.
- UI po uložení zobrazuje pouze maskovaný hint (např. poslední 4 znaky), ne skutečný secret.
- Credentials se neposílají zpět do HTML/JavaScriptu.
- Server vždy ověřuje přístup uživatele ke konkrétnímu vozidlu.
- Konektor Škoda ověřuje shodu VIN.
- Kia i Tesla OAuth používají server-side `state` kontrolu; EV Stats nevidí heslo uživatele k účtu výrobce.
- Pleos access/refresh tokeny jsou šifrované; při refreshi se jednorázový refresh token atomicky nahradí novým.
- Při odvolání Kia data-sharing souhlasu callback odstraní Kia telemetrii/eventy daného vozidla podle podmínek Pleos Vehicle Data API.
- Synchronizace ukládá auditní běhy a bezpečně zpracovává expiraci credentialu, rate limit a dočasné chyby API.
- Remote commands jsou v první verzi záměrně vypnuté; konektor je read-only.

---

## Telemetry a Event model

Migrace `sql/migrate_v20.sql` vytvořila původní Connected Car foundation. Migrace `sql/migrate_v21.sql` ji převádí na přímý per-vehicle OEM connector model. Starou aplikovanou migraci v20 **neupravujte ani nemažte**; migrační runner kontroluje její checksum.

### `vehicle_connector_connections`

Jedno šifrované propojení konkrétního vozidla s konkrétním OEM konektorem.

Ukládá například:

- interní `vehicle_id` a vlastníka spojení,
- `provider` / ID konektoru,
- externí VIN/ID a název,
- capability metadata,
- **šifrované credentials**, jejich fingerprint a maskovaný hint,
- expiraci credentialu,
- stav spojení a poslední chybu,
- poslední/další synchronizaci,
- `Retry-After`,
- aktuální rate-limit kvótu a reset,
- provider-specific metadata bez secretů.

### `vehicle_telemetry_snapshots`

Normalizovaná časová řada telemetrie.

Aktuálně podporovaná pole:

- `soc_pct`,
- `range_km`,
- `odometer_km`,
- `latitude`,
- `longitude`,
- `is_charging`,
- `is_plugged_in`,
- `charging_power_kw`,
- `battery_temperature_c`,
- `fuel_level_pct`,
- čas pozorování,
- zdroj,
- raw JSON poskytovatele.

Každý snapshot má fingerprint, který omezuje opakované ukládání stejného stavu.

### `vehicle_events`

Normalizovaný event stream vozidla. Události jsou provider-agnostické a obsahují typ, čas, závažnost, titulek, zdroj, datový payload a idempotentní event key.

Aktuální projector umí například:

- začátek/konec nabíjení,
- připojení/odpojení nabíjecího kabelu,
- SoC pod 20 %,
- dosažení 80 % během nabíjení,
- tisícikilometrové milníky odometru.

### `vehicle_sync_runs`

Audit synchronizačních běhů. Ukládá connection, spouštějícího uživatele, trigger (`manual`, `cron`, `webhook`, `initial`), status, počty snapshotů/eventů, chybu a časy začátku/konce.

---

## Dashboard a analytika

### Dashboard vozidla

Dashboard zobrazuje metriky odpovídající typu pohonu daného vozidla.

Dashboard používá cockpit rozhraní s fixní horní lištou, kompaktní pravou navigací a rychlým vyhledáváním `Ctrl/Cmd + K`. Podporuje světlý, tmavý i automatický režim podle systému.

Typicky obsahuje:

- statistiku jízd,
- vzdálenost,
- spotřebu,
- provozní náklady,
- cenu na kilometr,
- bateriová data u BEV/PHEV,
- SoH,
- grafy po měsících,
- historii jízd,
- interaktivní Trip Explorer mapu pro jízdy se souřadnicemi nebo OEM GPS telemetrií, včetně startu, cíle, odvozených zastávek a nabíjení s dostupnou polohou,
- insighty.

### Garage dashboard

Garage dashboard agreguje data napříč všemi vozidly, ke kterým má uživatel přístup.

Aktuálně počítá například:

- porovnání vozidel,
- počet jízd,
- vzdálenost,
- spotřebu,
- provozní náklady,
- depreciaci,
- celkové TCO,
- provozní cenu za kilometr,
- TCO za kilometr,
- meziroční statistiky,
- měsíční vývoj ceny/km,
- sezónní spotřebu.

TCO je kompletní pouze tehdy, pokud má vozidlo vyplněnou pořizovací cenu, datum pořízení a aktuální hodnotu.

---

## State of Health baterie

EV Stats umožňuje evidovat dvě kategorie SoH:

1. ručně zadanou hodnotu z BMS nebo diagnostiky,
2. orientační odhad z dostupných jízdních dat.

Preferovaným zdrojem pro servisní nebo technické rozhodování je vždy BMS/diagnostika.

Odhad ze spotřeby a změny SoC je pouze analytická pomůcka, protože výrobci pracují různě s použitelnou kapacitou, rezervami baterie a reportovaným SoC.

---

# Import jízd

EV Stats používá pluginový importní systém.

Import může:

- rozpoznat konkrétní formát,
- načíst CSV nebo podporované XLSX,
- normalizovat data do interního modelu,
- rozpoznat vozidlo podle VIN,
- zabránit importu do cizího vozidla,
- při oprávněném scénáři založit nové vozidlo,
- logovat běh importu,
- přeskočit duplicitní data.

### Aktuální importní pluginy

Projekt obsahuje například:

- `SkodaMySkodaPlugin`,
- `SkodaCitigoIvPlugin`,
- `KiaConnectPlugin`,
- `KiaConnectXlsxPlugin`,
- generický CSV mapper jako fallback pro neznámé formáty.

Architektura je rozdělena tak, aby značkově specifické chování nebylo rozptýlené po controllerech nebo dashboardu.

---

## Manuální mapování neznámých importů

Pokud importní soubor nerozpozná žádný známý plugin, EV Stats může nabídnout druhý krok s ručním namapováním sloupců.

Typický postup:

1. uživatel nahraje CSV/XLSX,
2. systém načte hlavičku a několik ukázkových řádků,
3. uživatel přiřadí zdrojové sloupce k interním polím,
4. minimálně je nutné namapovat začátek jízdy a vzdálenost,
5. import se zpracuje jednotným mapperem,
6. originální soubor je archivován jako vzorek neznámého formátu,
7. importní běh je zaznamenán pro administrátorský monitoring.

Originální vzorky jsou uloženy mimo veřejný webroot v:

```text
storage/import-samples/
```

Tato funkce umožňuje používat EV Stats i s dosud nepodporovaným formátem a současně sbírat vzorky pro vývoj budoucích nativních pluginů.

---

# Dokumentové centrum a AI vytěžování

Dokumentové centrum slouží pro účtenky, faktury za nabíjení, servisní faktury a další dokumenty související s vozidlem.

## Bezpečný workflow

```text
Upload
  ↓
validace typu a velikosti
  ↓
SHA-256 deduplikace
  ↓
lokální provider parser
  ↓
AI fallback, pokud je potřeba
  ↓
normalizovaný náhled
  ↓
ruční kontrola uživatelem
  ↓
potvrzení
  ↓
provozní evidence
```

AI sama bez potvrzení uživatele nevytváří finanční položky v provozní evidenci.

### Lokální parsery

Aktuálně projekt obsahuje například:

- `PowerpassElliInvoiceParser`,
- `CezFuturegoInvoiceParser`,
- `EonDriveInvoiceParser`,
- `JsonDocumentParser`.

Lokální parser je preferovaný před externí AI, pokud daný dokument spolehlivě rozpozná.

### Opakované vytěžení

Již uložený dokument lze znovu vytěžit. Každý nový pokus vytváří nový `document_import_run`, takže je zachována historie zpracování.

Lze použít:

- automatický režim,
- lokální parser,
- AI extrakci.

Po potvrzení nového vytěžení lze nahradit dřívější provozní položky vytvořené ze stejného dokumentu.

### OpenAI

Konfigurace:

```dotenv
AI_PROVIDER=openai
OPENAI_API_KEY=replace_me
OPENAI_MODEL=gpt-5.6-luna
```

### Google Gemini

Konfigurace:

```dotenv
AI_PROVIDER=gemini
GEMINI_API_KEY=replace_me
GEMINI_MODEL=gemini-3.8-flash
```

Gemini adaptér má interní fallback mechanismus pro dočasně nedostupné modely. U stavů jako timeout, rate limit nebo vybrané 5xx chyby zkusí další kompatibilní model.

Pro úplné vypnutí externí AI:

```dotenv
AI_PROVIDER=none
```

### Ukládání dokumentů

Originály jsou uloženy mimo veřejný webroot, typicky v:

```text
storage/documents/
```

Fotografie vozidel jsou uloženy odděleně v:

```text
storage/vehicle-media/
```

---

# Provozní evidence

Provozní evidence slučuje dlouhodobý životní cyklus vozidla.

## Nabíjení a tankování

Lze evidovat například:

- datum a čas,
- stav tachometru,
- množství energie nebo paliva,
- jednotku,
- cenu,
- místo nebo stanici,
- poznámku.

## Servis

Servisní záznam může obsahovat:

- datum,
- stav kilometrů,
- kategorii,
- název úkonu,
- servis/providera,
- cenu,
- poznámku,
- přílohy.

Servisní přílohy jsou uloženy mimo veřejný webroot.

## Ostatní výdaje

Například:

- pojištění,
- dálniční známky,
- pneumatiky,
- parkování,
- mytí,
- příslušenství,
- ostatní provozní výdaje.

## Připomínky

Připomínka může být navázána na:

- datum,
- stav kilometrů,
- kombinaci obou hodnot.

Použitelné například pro:

- servis,
- STK,
- výměnu pneumatik,
- pojištění,
- pravidelné kontroly.

## Kniha jízd

Jízdy lze klasifikovat například jako:

- soukromé,
- služební,
- dojíždění,
- ostatní.

---

## Timeline

Timeline skládá více datových zdrojů do jedné chronologie.

Aktuálně zahrnuje:

- jízdy,
- nabíjení/tankování,
- servis,
- ostatní náklady,
- Connected Car události.

Díky tomu lze zobrazit historii vozidla jako jeden časový životopis místo několika oddělených tabulek.

---

# Uživatelé, role a oprávnění

EV Stats používá hierarchický model uživatelů.

```text
Administrátor
    │
    ├── Správce vozidel A
    │       ├── Řidič 1
    │       └── Řidič 2
    │
    └── Správce vozidel B
            └── Řidič 3
```

Interní role jsou:

- `admin`,
- `manager`,
- `user`.

## Administrátor

Má plná systémová oprávnění.

Může například:

- spravovat všechny uživatele,
- vytvářet správce vozidel,
- spravovat všechna vozidla,
- přiřazovat vozidla,
- sledovat globální import monitoring,
- provádět aktualizace aplikace.

## Správce vozidel (`manager`)

Může spravovat vlastní skupinu vozidel a podřízené řidiče podle pravidel oprávnění.

Veřejně registrovaný uživatel dostává ve výchozím stavu právě roli správce vozidel.

## Řidič (`user`)

Pracuje pouze s vozidly, která mu byla zpřístupněna nebo přiřazena podle oprávnění aplikace.

### Server-side autorizace

Oprávnění se nekontrolují pouze v UI. Každý důležitý přístup k vozidlu, dokumentu, médiu nebo importu je ověřován také na serveru.

---

# Registrace a demo

## Veřejná registrace

Registrace je dvoufázová.

Formulář obsahuje:

- jméno a příjmení,
- e-mail,
- heslo,
- heslo znovu.

Postup:

1. uživatel odešle formulář,
2. proběhne Google reCAPTCHA v3,
3. vytvoří se neaktivovaný účet,
4. na e-mail přijde potvrzovací odkaz,
5. po potvrzení se účet aktivuje,
6. administrátor může obdržet oznámení o nové registraci.

## Veřejné demo

Landing page umožňuje vstoupit do read-only demo účtu.

Demo:

- používá syntetická data,
- nemá použitelné heslo,
- přihlašuje se přes samostatný demo endpoint,
- nesmí měnit data,
- nesmí uploadovat soubory.

Bootstrap aplikace centrálně blokuje zapisující HTTP metody demo uživatele.

---

# PWA

EV Stats obsahuje základ instalovatelné Progressive Web App.

Součástí jsou:

- `manifest.webmanifest`,
- `service-worker.js`,
- mobilní navigace,
- instalace aplikace z podporovaného mobilního prohlížeče.

Service worker je záměrně omezen na statické assety a nemá cachovat autentizované HTML, API odpovědi ani uživatelská data.

---

# Bezpečnost

Bezpečnost je navržena ve více vrstvách.

Podrobný audit je uložen v:

```text
SECURITY_AUDIT.md
```

## Hlavní ochrany

- `password_hash()` / `password_verify()`,
- automatický rehash starších hesel,
- minimální délka nového hesla,
- kryptografické CSRF tokeny,
- CSRF ochrana zapisujících formulářů,
- parametrizované SQL dotazy,
- escapování výstupu,
- session strict mode,
- `HttpOnly`, `SameSite=Lax` a při HTTPS `Secure`,
- regenerace session ID,
- server-side idle timeout,
- login rate limiting,
- password reset rate limiting,
- reset tokeny uložené pouze jako SHA-256 hash,
- bezpečné server-side kontroly přístupu k vozidlům,
- oddělení dat podle uživatelských oprávnění,
- bezpečná validace uploadů podle skutečného MIME typu,
- limity velikosti obrázků, ZIP/XLSX a PDF dekomprese,
- náhodné názvy uložených souborů,
- uploady mimo veřejný webroot,
- CSV spreadsheet injection ochrana,
- ochrany updateru proti path traversal a nebezpečným ZIP souborům,
- Content Security Policy,
- `X-Content-Type-Options: nosniff`,
- `X-Frame-Options: DENY`,
- `Referrer-Policy`,
- `Permissions-Policy`,
- HSTS v produkci přes HTTPS.

## Google Analytics

Google Analytics je integrován do společné hlavičky aplikace. Přihlášený administrátor je z měření vynechán.

Pokud provozujete aplikaci veřejně, zohledněte analytiku v zásadách ochrany soukromí.

## Externí AI

Pokud je aktivní OpenAI nebo Gemini, dokument může být za účelem vytěžení odeslán příslušnému externímu poskytovateli.

Proto není správné tvrdit, že „data nikdy neopouštějí server“, pokud je externí AI aktivní.

## Connected Car

Credentials OEM konektorů jsou šifrovány pomocí aplikačního master key `VEHICLE_CREDENTIALS_KEY`. EV Stats ukládá do databáze pouze ciphertext, fingerprint a maskovaný hint. Master key musí zůstat pouze v `.env`, nesmí být commitnut do Git repozitáře a při jeho ztrátě nelze uložené credentials dešifrovat.

Každý OEM konektor má vlastní credential schéma. U MyŠkoda Public API se používá API klíč vytvořený uživatelem pro konkrétní vozidlo; EV Stats nevyžaduje heslo k účtu MyŠkoda.

---

# Požadavky

Doporučené minimum:

- PHP **7.4+**,
- MariaDB 10.5+ nebo MySQL 8+,
- Apache nebo Nginx,
- PDO MySQL,
- cURL,
- ZipArchive,
- mbstring,
- XML,
- GD,
- `fileinfo`,
- HTTPS pro produkční provoz.

Pro e-mailové funkce je potřeba funkční `mail()` nebo odpovídající mail transport na serveru.

Pro updater musí mít PHP proces právo zapisovat do aplikačních adresářů.

---

# Instalace

## 1. Nahrání projektu

Naklonujte nebo nahrajte projekt na server.

```bash
git clone https://github.com/filipekp/ev_dashboard.git
cd ev_dashboard
```

Preferovaný document root:

```text
/cesta/k/projektu/public
```

Pokud hosting neumí změnit document root, kořenový `.htaccess` směruje požadavky do `public/` a zároveň chrání neveřejné části projektu.

## 2. Vytvoření databáze

Vytvořte prázdnou MySQL/MariaDB databázi s `utf8mb4`.

Pro novou instalaci importujte:

```text
sql/schema.sql
```

Například:

```bash
mysql -u ev_stats -p ev_stats < sql/schema.sql
```

Schéma obsahuje také aktuální Connected Car v20 tabulky.

## 3. Konfigurace prostředí

Zkopírujte:

```bash
cp .env.example .env
```

Následně nastavte minimálně databázi a URL aplikace.

```dotenv
APP_ENV=production
APP_DEBUG=0
APP_BASE_URL=https://ev.example.cz

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ev_stats
DB_USER=ev_stats
DB_PASS=change_me
```

Soubor `.env` nikdy necommitujte do repozitáře.

## 4. Oprávnění adresářů

Webserver musí mít možnost zapisovat alespoň do provozních adresářů používaných pro uploady, rate-limit data, updater a další runtime soubory.

Typicky:

```bash
chown -R www-data:www-data storage .updates
```

Konkrétní práva upravte podle hostingu a bezpečnostní politiky serveru.

## 5. První spuštění

Otevřete aplikaci v prohlížeči.

Pokud ještě není vytvořen administrátor, aplikace nabídne první setup.

Po vytvoření administrátorského účtu lze začít přidávat vozidla, uživatele a importovat data.

---

# Aktualizace existující instalace

Při aktualizaci vždy zálohujte:

- databázi,
- `.env`,
- celý `storage/`,
- případně stávající aplikační adresář.

Aplikace obsahuje vlastní updater dostupný administrátorovi přes:

```text
update.php
```

Updater může podle konfigurace používat:

- poslední publikovaný GitHub Release,
- nebo vývojovou větev.

Konfigurace:

```dotenv
UPDATE_REPOSITORY=filipekp/ev_dashboard
UPDATE_CHANNEL=release
UPDATE_BRANCH=dev
```

Možné hodnoty `UPDATE_CHANNEL`:

```text
release
```

nebo:

```text
dev
```

Updater spouští dosud neprovedené databázové migrace a nepřepisuje `.env`.

## Přechod na OEM Connector framework v5.1

Pokud aktualizujete instalaci, ve které už byla aplikována Connected Car migrace v20, **neměňte ani nemažte** `sql/migrate_v20.sql`. Nová migrace `sql/migrate_v21.sql`:

- odstraní starou account/link vrstvu agregátoru,
- převede tabulku connection na `vehicle_connector_connections`,
- doplní šifrované credentials, expirace, scheduling a rate-limit metadata,
- zachová historické telemetry/event záznamy jako legacy Connected Car historii,
- připraví databázi na přímé OEM konektory.

Doporučený upgrade bez závislosti na webovém UI:

```bash
docker compose exec ev-dashboard php bin/migrate.php --status
docker compose exec ev-dashboard php bin/migrate.php
```

Před připojením prvního OEM API vygenerujte master key:

```bash
docker compose exec ev-dashboard php bin/generate-vehicle-credentials-key.php
```

Výstup `VEHICLE_CREDENTIALS_KEY=...` vložte do `.env` a kontejner/aplikaci restartujte.

---

# Konfigurace `.env`

## Aplikace

```dotenv
APP_ENV=production
APP_NAME="EV Stats"
APP_BASE_URL=https://ev.example.cz
APP_DEBUG=0
SESSION_NAME=ev_stats_session
```

V produkci musí být `APP_BASE_URL` explicitně nastavené na správnou HTTPS doménu.

## Databáze

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=ev_stats
DB_USER=ev_stats
DB_PASS=change_me
```

Používejte samostatného DB uživatele pouze s oprávněními nezbytnými pro aplikaci.

## Session a rate limiting

```dotenv
SESSION_IDLE_SECONDS=43200
LOGIN_RATE_LIMIT_ATTEMPTS=10
LOGIN_RATE_LIMIT_WINDOW=900
PASSWORD_RESET_RATE_LIMIT_ATTEMPTS=5
PASSWORD_RESET_RATE_LIMIT_WINDOW=3600
MINIMUM_PASSWORD_LENGTH=12
```

## E-mail

```dotenv
MAIL_FROM=noreply@example.cz
```

## Registrace

```dotenv
REGISTRATION_PARENT_ADMIN_ID=1
REGISTRATION_ADMIN_NOTIFY_EMAIL=
REGISTRATION_VERIFICATION_MINUTES=1440
```

Veřejně registrovaný uživatel se vytváří jako správce vozidel a je podřízen administrátorovi z `REGISTRATION_PARENT_ADMIN_ID`.

## Google reCAPTCHA v3

```dotenv
RECAPTCHA_SITE_KEY=replace_with_public_site_key
RECAPTCHA_SECRET_KEY=replace_with_private_secret_key
RECAPTCHA_MINIMUM_SCORE=0.5
RECAPTCHA_EXPECTED_HOSTNAME=
```

Na produkci doporučujeme nastavit také očekávaný hostname.

## Demo

```dotenv
DEMO_ENABLED=true
DEMO_USER_EMAIL=demo@evstats.local
```

## Updater

```dotenv
UPDATE_REPOSITORY=filipekp/ev_dashboard
UPDATE_CHANNEL=release
UPDATE_BRANCH=dev
```

## AI

OpenAI:

```dotenv
AI_PROVIDER=openai
OPENAI_API_KEY=
OPENAI_MODEL=gpt-5.6-luna
```

Gemini:

```dotenv
AI_PROVIDER=gemini
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.8-flash
```

Bez externí AI:

```dotenv
AI_PROVIDER=none
```

## OEM Connected Car konektory

Master key pro šifrování credentialů vygenerujte CLI utilitou, ne ručním heslem:

```bash
php bin/generate-vehicle-credentials-key.php
```

Do `.env` vložte výstup:

```dotenv
VEHICLE_CREDENTIALS_KEY=base64_32_random_bytes

SKODA_API_URL=https://public.api.connect.skoda-auto.cz
SKODA_API_TIMEOUT_SECONDS=30
SKODA_SYNC_INTERVAL_SECONDS=600
SKODA_RATE_LIMIT_RESERVE=3

# Kia Europe Vehicle Data API / Pleos Private User
# Client ID a Client Secret se NEzadávají do .env; každý uživatel je uloží šifrovaně u svého Kia vozidla.
KIA_PLEOS_API_URL=https://api.pleos.ai
KIA_PLEOS_TIMEOUT_SECONDS=30
KIA_PLEOS_SYNC_INTERVAL_SECONDS=900

# Volitelné pouze pro kompatibilitu se starým Business User OAuth flow:
KIA_PLEOS_CLIENT_ID=
KIA_PLEOS_CLIENT_SECRET=
KIA_PLEOS_LOGIN_REDIRECT_URI=
KIA_PLEOS_CONSENT_REDIRECT_URI=
KIA_PLEOS_SHARING_END_TOKEN=
KIA_PLEOS_LANGUAGE=cs

# Tesla Fleet API
TESLA_CLIENT_ID=
TESLA_CLIENT_SECRET=
TESLA_AUTHORIZATION_URL=https://auth.tesla.com/oauth2/v3/authorize
TESLA_TOKEN_URL=https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token
TESLA_API_URL=https://fleet-api.prd.eu.vn.cloud.tesla.com
TESLA_REDIRECT_URI=
TESLA_SCOPES="openid offline_access vehicle_device_data vehicle_location"
TESLA_API_TIMEOUT_SECONDS=30
TESLA_SYNC_INTERVAL_SECONDS=1800

# Volkswagen Group EU Data Act Data Hub (Audi / Volkswagen)
VAG_DATA_HUB_VEHICLE_DATA_URL_TEMPLATE=
VAG_DATA_HUB_CLIENT_ID=
VAG_DATA_HUB_CLIENT_SECRET=
VAG_DATA_HUB_TOKEN_URL=https://idp.onebusinessid.com/auth/realms/organisation-user-id/protocol/openid-connect/token
VAG_DATA_HUB_TOKEN_SCOPE=audience_marketplace-portal
VAG_DATA_HUB_TIMEOUT_SECONDS=30
VAG_DATA_HUB_SYNC_INTERVAL_SECONDS=900
```

`VEHICLE_CREDENTIALS_KEY` po prvním ostrém nasazení bez plánované migrace credentialů neměňte. Rotace tohoto key vyžaduje bezpečné přešifrování uložených credentials.

---

# MyŠkoda Public API

První nativní OEM plugin je určen pro vozidla s výrobcem `SKODA` / `ŠKODA`.

## Připojení vozidla

1. V aplikaci MyŠkoda vytvořte Public API klíč a povolte v něm konkrétní vozidlo.
2. V EV Stats zkontrolujte, že vozidlo má správně vyplněný VIN a výrobce Škoda.
3. Otevřete **Vozidla → Upravit**.
4. V sekci **Connected Car** vložte API klíč.
5. Zvolte **Otestovat a připojit**.
6. EV Stats načte stav přes `/api/v1/vehicles/{VIN}`, ověří VIN a uloží první normalizovaný snapshot.
7. Po úspěchu lze používat ruční synchronizaci nebo cron AutoSync.

Konektor ukládá a používá mimo jiné informaci o expiraci API key a `RateLimit-*` hlavičky. Výchozí AutoSync interval je 10 minut, ale scheduler může další sync odložit podle zbývající kvóty nebo `Retry-After`.

První verze je **read-only**. Ovládání nabíjení/klimatizace z EV Stats není zatím povoleno.

---

# Kia Europe Vehicle Data API / Pleos

Kia konektor je určený pro **Private Users / vlastní vozidlo**. Každý uživatel EV Stats si v Pleos Playground vytvoří svůj **My Vehicle API key** a do EV Stats zadá pouze jeho `Client ID` a `Client Secret`. Server EV Stats už nepotřebuje společný Kia Client ID/Secret pro běžné připojení vozidla.

## Získání přístupových údajů

1. Otevřete `https://pleos.ai/playground/vehicle-data-api` a přihlaste se do Pleos Playground.
2. V části **My Vehicle Data API** si vyžádejte / vytvořte My Vehicle API key pro značku Kia.
3. Zkopírujte `Client ID` a `Client Secret`. Pleos upozorňuje, že tyto údaje nemají být sdílené s jinými osobami.
4. Pokud jste vozidlo do Kia Connect přidali až po vydání API key, je podle Pleos potřeba starý klíč zrušit a vytvořit nový, aby se nové VIN dostalo do seznamu dostupných vozidel.

## Připojení v EV Stats

1. U vozidla s výrobcem `KIA` otevřete **Vozidla → Upravit**.
2. V části Connected Car klikněte na **Získat přístupové údaje**, pokud je ještě nemáte.
3. Vyplňte **Client ID** a **Client Secret**.
4. Zvolte **Otestovat a připojit**.
5. EV Stats odešle Client ID/Secret výhradně na oficiální endpoint `POST /v1/auth/personal-token` s hlavičkou `Brand: kia`, získá access token a následně přes `GET /v1/vehicles/consent` ověří, že API key obsahuje VIN vozidla.
6. Při každé další synchronizaci EV Stats vydá nový access token z uloženého My Vehicle API key; samotný access token se dlouhodobě neukládá. Client ID a Client Secret jsou v databázi šifrované pomocí `VEHICLE_CREDENTIALS_KEY`.
7. Konektor následně čte podle dostupnosti modelu `/batteries`, `/locations`, `/driving`, `/powertrains` a `/status`. Nedostupné modelové endpointy jsou zpracované jako částečně chybějící capability a nemusí shodit celý sync.

Legacy Business User OAuth endpointy zůstávají v kódu kvůli kompatibilitě se staršími instalacemi, ale nové připojení Kia je v UI vždy přes uživatelský Client ID + Client Secret.

### Přidání další automobilky

Další OEM konektor se přidává jako samostatný plugin implementující `VehicleConnectorInterface`; telemetry/event/analytická vrstva se kvůli další značce nemění.

---

# Automatická synchronizace přes cron

Ruční synchronizace je dostupná v Connected Car UI.

Pro automatický AutoSync lze spouštět CLI:

```bash
php /cesta/k/ev-stats/bin/sync-vehicles.php
```

Výstup je JSON se souhrnem běhu.

### Synchronizace konkrétní connection

```bash
php /cesta/k/ev-stats/bin/sync-vehicles.php --connection=12
```

### Příklad cronu každých 15 minut

```cron
*/15 * * * * /usr/bin/php /var/www/ev-stats/bin/sync-vehicles.php >> /var/log/evstats-sync.log 2>&1
```

Doporučený interval závisí na provider API limitech, cenovém modelu a požadované čerstvosti dat.

---

# Docker

Projekt obsahuje `Dockerfile` a `docker-compose.yml`.

Aktuální image vychází z Ubuntu 20.04 a instaluje Apache + PHP 7.4 s potřebnými rozšířeními.

## Build

```bash
docker compose build
```

## Spuštění

```bash
docker compose up -d
```

Výchozí lokální port:

```text
http://localhost:8090
```

Compose předpokládá existenci externí Docker sítě:

```text
proclient
```

Pokud neexistuje:

```bash
docker network create proclient
```

Databáze není v aktuálním `docker-compose.yml` definována jako součást projektu; očekává se dostupná MySQL/MariaDB instance připojená do stejné sítě nebo dosažitelná podle hodnot v `.env`.

---

# Architektura projektu

```text
public/
    veřejné HTTP entrypointy
    assets/
    manifest.webmanifest
    service-worker.js

src/
    App/
        Http/Controller/
        Service/
        Repository/
        Csv/
        Import/
        Document/
        Integration/
        Vehicle/

templates/
    stránky
    partials/

sql/
    schema.sql
    migrate_v*.sql

storage/
    neveřejné runtime soubory
    dokumenty
    média
    servisní přílohy
    importní vzorky

bin/
    CLI utility
```

## Vrstvy

### Controller

`src/App/Http/Controller/`

Řeší:

- HTTP request,
- autentizaci,
- CSRF,
- základní validaci vstupu,
- redirect/render.

Controller by neměl obsahovat složité SQL nebo značkově specifickou business logiku.

### Service

`src/App/Service/`

Obsahuje aplikační a business logiku.

Například:

- `DashboardService`,
- `AnalyticsService`,
- `VehicleOperationService`,
- `DocumentImportService`,
- `VehicleConnectorService`,
- `VehicleSyncService`,
- `VehicleTelemetryEventProjector`,
- `VehicleTimelineService`,
- `VehicleInsightService`.

### Repository

`src/App/Repository/`

Zapouzdřuje databázové operace.

Například:

- `VehicleRepository`,
- `TripRepository`,
- `VehicleOperationRepository`,
- `DocumentRepository`,
- `VehicleDataRepository`,
- `IntegrationImportRunRepository`.

### Integrations

`src/App/Integration/`

Externí a generické integrační mechanismy. OEM Connected Car konektory jsou izolované po jednotlivých výrobcích:

```text
Integration/
    GenericCsvMapper.php
    TabularFileReader.php
    Vehicle/
        VehicleConnectorInterface.php
        VehicleConnectorRegistry.php
        VehicleConnectorException.php
        RefreshableVehicleConnectorInterface.php
        RevocableVehicleConnectorInterface.php
        Skoda/
            SkodaConnector.php
            SkodaPublicApiClient.php
            SkodaVehicleNormalizer.php
        Kia/
            KiaConnector.php
            KiaPleosApiClient.php
            KiaVehicleNormalizer.php
        Tesla/
            TeslaConnector.php
            TeslaFleetApiClient.php
            TeslaVehicleNormalizer.php
        Vag/
            AbstractVagDataHubConnector.php
            VagDataHubClient.php
            VagDataHubNormalizer.php
        Audi/
            AudiDataHubConnector.php
        Volkswagen/
            VolkswagenDataHubConnector.php
```

Citlivé credentials řeší samostatná bezpečnostní vrstva `src/App/Security/CredentialCipher.php`.

---

# Databázové migrace

Schéma nové instalace je v:

```text
sql/schema.sql
```

Průběžné upgrady jsou v:

```text
sql/migrate_v2.sql
sql/migrate_v3.sql
...
sql/migrate_v20.sql
sql/migrate_v21.sql
```

Migrační soubory po vydání neupravujte. Každá další databázová změna má dostat nové číslo migrace.

Vestavěný updater vede evidenci již aplikovaných migrací a spouští pouze nové.

## CLI migrátor

Migrace lze spustit i bez funkčního webového rozhraní. To je užitečné zejména v Dockeru nebo v situaci, kdy nová verze aplikace vyžaduje databázovou migraci ještě před prvním HTTP requestem.

Z kořene projektu:

```bash
php bin/migrate.php
```

Pouze kontrola stavu bez změny databáze:

```bash
php bin/migrate.php --status
```

Nápověda:

```bash
php bin/migrate.php --help
```

Pro aktuální `docker-compose.yml`, kde se aplikační služba jmenuje `ev-dashboard`:

```bash
docker compose exec ev-dashboard php bin/migrate.php --status
docker compose exec ev-dashboard php bin/migrate.php
```

Pokud si název služby v `docker-compose.yml` změníte, nahraďte `ev-dashboard` odpovídajícím názvem.

CLI migrátor:

- nepoužívá webový bootstrap ani session,
- načte pouze konfiguraci a databázovou vrstvu,
- používá tabulku `schema_migrations`,
- spouští pouze dosud neaplikované `migrate_*.sql`,
- kontroluje SHA-256 checksum již aplikovaných migrací,
- odmítne pokračovat, pokud byla stará aplikovaná migrace dodatečně změněna,
- používá procesní zámek proti souběžnému spuštění.

---

# Vývoj nového importního pluginu

CSV pluginy jsou v:

```text
src/App/Csv/Plugin/
```

Nový plugin má řešit pouze specifika daného exportního formátu.

Nemá měnit dashboard ani interní databázový model.

Zjednodušený příklad:

```php
<?php

declare(strict_types=1);

namespace App\Csv\Plugin;

final class ExampleVehiclePlugin extends AbstractCsvVehiclePlugin
{
    public function id(): string
    {
        return 'example_vehicle';
    }

    public function label(): string
    {
        return 'Example Vehicle';
    }

    public function supports(array $header): bool
    {
        return $this->hasColumns($header, [
            'Start Date',
            'End Date',
            'Distance',
        ]);
    }

    public function inspect(?string $vin): array
    {
        return [
            'suggested_name' => 'Example Vehicle',
        ];
    }

    public function parse(array $row): ?array
    {
        // Převod do interního normalizovaného modelu jízdy.
        return null;
    }

    public function exportHeaders(): array
    {
        return ['Start Date', 'End Date', 'Distance'];
    }

    public function exportRow(array $trip): array
    {
        return [];
    }
}
```

Pro značkově odlišné XLSX formáty lze vytvořit samostatný `TripImportPluginInterface` plugin obdobně jako `KiaConnectXlsxPlugin`.

---

# Vývoj nového OEM konektoru

Connected Car vrstva používá kontrakt:

```text
src/App/Integration/Vehicle/VehicleConnectorInterface.php
```

Každý výrobce má mít vlastní podadresář a vlastní API klient/normalizer. Doporučený tvar:

```text
Vehicle/
    VehicleConnectorInterface.php
    VehicleConnectorRegistry.php
    VehicleConnectorException.php

    Skoda/
        SkodaConnector.php
        SkodaPublicApiClient.php
        SkodaVehicleNormalizer.php

    Tesla/
        TeslaConnector.php
        TeslaFleetApiClient.php
        TeslaVehicleNormalizer.php

    Bmw/
        BmwCarDataConnector.php
        BmwCarDataClient.php
        BmwVehicleNormalizer.php
```

Konektor zodpovídá pouze za vendor-specific část:

1. určí podporované výrobce,
2. popíše credential schema a capabilities,
3. autentizuje se vůči OEM API,
4. načte stav konkrétního vozidla,
5. ověří bezpečnostně důležité identity (typicky VIN),
6. převede odpověď do společného normalizovaného snapshotu,
7. předá scheduleru metadata jako expirace credentialu, rate limit a další sync.

Registrace nového pluginu se provádí v `Application::vehicleConnectors()`. Zbytek aplikace používá `VehicleConnectorRegistry`, takže `VehicleSyncService`, `VehicleDataRepository`, Timeline ani budoucí Anomaly Engine nemusí znát formát dané automobilky.

### Credentialy

Nové konektory nesmí ukládat API key, access token, refresh token ani heslo do logu, flash zprávy, HTML nebo plaintext databázového sloupce. Secret se před uložením předává přes `CredentialCipher`. OAuth konektory mají ukládat pouze tokeny, které skutečně potřebují; uživatelské heslo k automobilce se nemá ukládat, pokud OEM podporuje OAuth/API key flow.

### Normalizovaný snapshot

Konektor vrací jednotnou strukturu obsahující `external_id`, `vin`, `observed_at`, `capabilities`, `telemetry` a raw odpověď. V `telemetry` jsou podle dostupnosti například SoC, dojezd, odometr, poloha, charging state/power, teplota baterie a fuel level. Chybějící hodnoty zůstávají `null`; normalizer si je nesmí domýšlet.

---

# Testování a kontrola syntaxe

Projekt obsahuje historickou přípravu pro PHPUnit/Behat podle konkrétní vývojové konfigurace.

Před nasazením změn doporučujeme minimálně PHP syntax kontrolu:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

JavaScript:

```bash
node --check public/assets/app.js
```

Dále doporučujeme ručně otestovat:

- login/logout,
- registraci a ověření e-mailu,
- oprávnění rolí,
- uploady,
- import CSV/XLSX,
- generický mapper,
- dokumentové vytěžování,
- opakované vytěžení,
- export CSV,
- updater,
- Connected Car Link flow,
- ruční synchronizaci,
- CLI synchronizaci.

---

# Provozní doporučení

Před veřejným produkčním provozem:

1. používejte HTTPS,
2. nastavte `APP_ENV=production`,
3. nastavte `APP_DEBUG=0`,
4. nastavte přesnou `APP_BASE_URL`,
5. nepovolujte veřejný přístup k `.env`, `storage/`, `src/`, `sql/` ani `.updates/`,
6. pravidelně zálohujte databázi,
7. pravidelně zálohujte `storage/`,
8. otestujte obnovu ze zálohy,
9. používejte DB účet s minimálními oprávněními,
10. pravidelně aktualizujte PHP, webserver a databázi,
11. rotujte API/reCAPTCHA/DB secrets,
12. sledujte neúspěšná přihlášení a importní chyby,
13. nakonfigurujte SPF, DKIM a DMARC pro odesílací doménu,
14. u produkčního Connected Car provozu sledujte OEM API limity, expirace credentialů a chyby synchronizace,
15. před větším veřejným nasazením proveďte externí penetrační test.

---

# Roadmap

Connected Car v5 foundation vytváří datový základ pro další dvě velké vrstvy.

## 1. Anomaly Engine

Plánované využití `vehicle_telemetry_snapshots`, jízd a provozních dat například pro:

- neobvyklý růst spotřeby,
- nezvyklý pokles SoC při stání,
- změnu reálného dojezdu,
- podezřelé změny nabíjení,
- odchylky proti vlastnímu dlouhodobému normálu vozidla,
- prediktivní upozornění.

## 2. EV Stats Copilot

AI vrstva nad vlastním datovým modelem vozidla.

Příklady budoucích dotazů:

- „Proč mi poslední měsíc vzrostla spotřeba?“
- „Kolik mě auto stálo od koupě?“
- „Jak se vyvíjí baterie?“
- „Co mě čeká v příštích šesti měsících?“
- „Najdi neobvyklé provozní náklady.“

Copilot má vycházet z vypočtených a autorizovaných dat EV Stats, nikoli nahrazovat datovou vrstvu generickým chatbotem.

## 3. Další plánované směry

- další přímé OEM Connected Car konektory (Tesla, BMW, Kia/Hyundai, Volkswagen/Audi),
- notification engine,
- automatizační pravidla,
- Vehicle Passport,
- predikce budoucích nákladů,
- Energy Brain / chytré nabíjení,
- integrace FVE a wallboxů,
- rozšířená evidence pneumatik,
- inteligentní klasifikace knihy jízd.

---

# Důležitý princip projektu

EV Stats má udržovat **jeden normalizovaný interní model** a izolovat specifika jednotlivých výrobců, providerů, CSV souborů a AI služeb do samostatných adaptérů a pluginů.

To platí pro:

- importy jízd,
- dokumentové parsery,
- Connected Car API,
- budoucí automatizace.

Díky tomu lze přidávat nové značky a zdroje dat bez přepisování dashboardu, analytiky a databázové logiky.

---

# Licence

EV Stats je poskytován pod vlastní licencí **EV Stats Non-Commercial Source License 1.0**.

Zdrojový kód je zdarma k použití, studiu, úpravám a nekomerční redistribuci. Komerční použití není bez samostatného písemného souhlasu autora dovoleno.

Za komerční použití se považuje zejména:

- prodej aplikace nebo odvozeného produktu,
- poskytování aplikace jako placené služby nebo SaaS,
- zahrnutí aplikace do placeného produktu či služby,
- použití primárně za účelem komerčního prospěchu nebo finanční odměny,
- použití právnickou nebo podnikající osobou v rámci její komerční činnosti, pokud nebyla sjednána jiná licence.

Pro komerční použití kontaktujte autora a domluvte individuální smluvní a licenční podmínky.

**Autor:** Pavel Filípek  
**Kontakt:** https://www.filipek-czech.cz

Úplné licenční podmínky jsou v souboru [`LICENSE`](LICENSE).

> Poznámka: protože licence omezuje komerční použití, jde terminologicky o **source-available** licenci, nikoli o Open Source licenci podle definice Open Source Initiative (OSI).

---

## Autor

**Pavel Filípek**  
© 2026

EV Stats

## Telemetrické jízdy, nabíjení a deduplikace (v5.2)

Migrace `migrate_v22.sql` rozšiřuje OEM telemetrii o odvozenou provozní historii:

- dokončené pohybové bloky se ukládají jako jízdy se zdrojem `telemetry_*`;
- import jízd porovnává kromě zdrojového hashe také tachometr, vzdálenost a časový překryv, takže CSV může doplnit přesnější data do již existující telemetrické jízdy místo vytvoření duplicity;
- dokončené nabíjecí relace se ukládají do `vehicle_energy_entries` včetně SoC, času a odhadnuté energie;
- výchozí cena elektřiny se nastavuje u vozidla a automaticky se použije pro telemetrické nabíjení;
- importovaná faktura za nabíjení se nejprve pokusí časově a množstvím spárovat s telemetrickou relací; při shodě doplní cenu do existujícího záznamu;
- pokud relace není propojena s fakturou, lze cenu za jednotku upravit přímo v provozní historii.

Energie odvozená pouze z OEM snapshotů je označena jako odhad. Primárně se počítá ze změny SoC a využitelné kapacity baterie, při nedostupném SoC se použije integrace dostupného nabíjecího výkonu. Přesnost časů jízd odpovídá intervalu synchronizace OEM konektoru.

## Škoda parkingPosition, klima a průběžné nabíjení (v5.3)

Migrace `migrate_v23.sql` rozšiřuje normalizovanou telemetrii o parkovací adresu a stavy klimatizace/topení/ventilace.

- `parkingPosition.gpsCoordinates` a `parkingPosition.formattedAddress` se ukládají do telemetry snapshotu a používají se jako výchozí/cílové místo automaticky odvozené jízdy;
- jízda je detekována prvním přírůstkem tachometru a uzavře se až po více než 2 hodinách bez další změny odometru; krátké zastávky proto zůstávají součástí jedné jízdy;
- budoucí predikční timestampy jako `estimatedReachOfTargetTemperatureAt` se nepoužívají jako čas snapshotu;
- dashboard zobrazuje stav `airConditioning`, `auxiliaryHeating`, `activeVentilation` a vyhřívání předního/zadního okna, pokud je výrobce poskytne;
- OEM nabíjecí relace se založí v `vehicle_energy_entries` už při zahájení nabíjení a během dalších synchronizací se aktualizuje SoC, odhad kWh a cena;
- výchozí cena elektřiny vozidla se použije automaticky; ručně zadaná cena se telemetrií nepřepisuje a spárovaná faktura má nejvyšší prioritu a nahradí odhad přesnými údaji.

## Živé OEM jízdy (v5.5)

Migrace `migrate_v25.sql` přidává stav jízdy `active/completed` a vazbu na telemetry connection/snapshoty.

- při prvním přírůstku odometru se jízda založí okamžitě jako `active` a zobrazí se v historii s označením `LIVE`;
- každý další AutoSync aktualizuje stejný řádek jízdy: konec, vzdálenost, čas, průměrnou rychlost, cílovou lokaci, SoC a odhad spotřeby;
- po více než 2 hodinách bez dalšího přírůstku odometru se jízda přepne na `completed` a dostane finální `canonical_key` pro deduplikaci s CSV/importy;
- každé volání AutoSync CRONu nejdřív provede lifecycle kontrolu všech aktivních konektorů bez ohledu na `next_sync_at`/`retry_after`; jízda se proto může uzavřít i bez nového OEM requestu, a při skutečné synchronizaci se kontrola zopakuje před i po síťovém volání;
- pokud nový přírůstek dorazí až po více než 2 hodinách od posledního pohybu, stará jízda se nejprve uzavře a nový přírůstek založí novou jízdu;
- vznikají provider-agnostické Connected Car události `trip_started` a `trip_completed`.

## Oprava LIVE jízd a rekonstrukce telemetry historie (v5.6)

Migrace `migrate_v26.sql` opravuje dvě slabiny původního v5.5 lifecycle modelu.

- `vehicle_telemetry_snapshots.last_seen_at` uchovává čas posledního potvrzení identického OEM snapshotu. Pokud automobil stojí několik hodin a fingerprint se nemění, řádek se neduplikuje, ale `last_seen_at` se při každém syncu posune. Při následném zvýšení odometru tak lze nový trip ukotvit na poslední skutečně potvrzené stání místo ranního snapshotu.
- lifecycle už neprochází znovu všechny staré pohybové segmenty. Každá jízda si pamatuje `telemetry_last_snapshot_id` a další AutoSync zpracuje pouze nové přírůstky odometru. Dokončená ranní jízda se proto nemůže znovu připojit k odpolednímu pohybu.
- pokud mezi posledním přírůstkem aktivní jízdy a novým přírůstkem uplynou více než 2 hodiny, stará jízda se před zpracováním nového pohybu dokončí a vznikne nový `LIVE` záznam.
- první nový přírůstek odometru vždy vytvoří `LIVE` jízdu okamžitě; další přírůstky aktualizují tentýž řádek až do 2hodinového timeoutu.
- u historických snapshotů, které ještě `last_seen_at` neměly, je více než 2hodinová mezera mezi přírůstky konzervativně považována za hranici nové jízdy. Přesný čas odjezdu v takovém historickém intervalu není známý, proto se nepoužije falešné několikahodinové trvání.

Migrace vytvoří frontu `vehicle_trip_rebuild_queue` pro konektory s odometrovou telemetrií. `php bin/migrate.php` se ji pokusí zpracovat okamžitě: čistě telemetry jízdy vytvořené chybnou verzí se znovu sestaví z `vehicle_telemetry_snapshots`. CSV/importované/ruční jízdy se nemažou; pokud byly dříve spárované s telemetrií, pouze se odpojí stará projekční metadata a při rebuild procesu se znovu správně spárují. Pokud okamžitý rebuild některého konektoru selže, položka zůstane ve frontě a další `/cron/sync-vehicles.php` ji zkusí znovu.
