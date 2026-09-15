# EV Dashboard

EV Dashboard je webová aplikace pro evidenci vozidel, import jízd z CSV exportů, sledování spotřeby, nákladů, provozní historie a technického stavu vozidel. Systém podporuje elektromobily i další typy pohonu a umožňuje správu více uživatelů, rolí a přiřazení vozidel.

Aplikace je určená pro provoz na běžném PHP hostingu s databází MariaDB nebo MySQL.

## Hlavní funkce

- správa vozidel a jejich technických údajů,
- správa uživatelů, rolí a oprávnění,
- přiřazování vozidel konkrétním uživatelům,
- dashboard se statistikami jízd, spotřeby a nákladů,
- import jízd z CSV exportů,
- automatické rozpoznání podporovaného CSV formátu,
- automatické rozpoznání vozidla podle VIN v názvu CSV souboru,
- evidence nabíjení, tankování, servisu a ostatních provozních nákladů,
- kniha jízd,
- připomínky podle data nebo stavu kilometrů,
- výpočet provozních nákladů a ceny za kilometr,
- sledování State of Health baterie,
- uživatelský profil a změna hesla,
- obnova zapomenutého hesla,
- aktualizace aplikace z GitHubu,
- ochrana citlivých souborů mimo veřejný webroot.

## Požadavky

- PHP 7.4 nebo novější,
- rozšíření PDO MySQL,
- MariaDB 10.5+ nebo MySQL 8+,
- webserver Apache nebo Nginx,
- rozšíření ZipArchive pro aktualizace z GitHubu,
- cURL nebo povolené allow_url_fopen pro stahování aktualizací,
- pro odesílání obnovy hesla funkční PHP mail() nebo lokální MTA.

Preferovaný document root je adresář public/.

Pokud hosting neumožňuje nastavit document root na public/, je v kořeni projektu připraven .htaccess, který požadavky interně směruje do public/ a zároveň blokuje přímý přístup k citlivým adresářům.

## Instalace

### 1. Nahrání souborů

Nahrajte celý obsah projektu na server.

Doporučené nastavení webserveru:

    DocumentRoot /cesta/k/projektu/public

Pokud používáte sdílený hosting bez možnosti změnit document root, nahrajte projekt do cílového adresáře webu. Kořenový .htaccess zajistí směrování požadavků do adresáře public/.

### 2. Vytvoření databáze

Vytvořte prázdnou databázi v MariaDB nebo MySQL.

Poté importujte schéma:

    SOURCE sql/schema.sql;

Na hostingu lze stejný soubor importovat například přes phpMyAdmin nebo jiný databázový nástroj.

### 3. Nastavení konfigurace

Zkopírujte ukázkový konfigurační soubor:

    cp .env.example .env

V souboru .env nastavte připojení k databázi a základní URL aplikace:

    DB_HOST=localhost
    DB_NAME=ev_dashboard
    DB_USER=database_user
    DB_PASS=database_password

    APP_BASE_URL=https://ev.example.cz
    APP_DEBUG=0

Pokud chcete používat obnovu hesla e-mailem, nastavte také odesílatele:

    MAIL_FROM=noreply@example.cz

Pro aktualizace z GitHubu lze nastavit repozitář a větev:

    UPDATE_REPOSITORY=filipekp/ev_dashboard
    UPDATE_BRANCH=dev

Soubor .env nikdy necommitujte do repozitáře a nenechávejte jej veřejně dostupný.

### 4. První spuštění

Otevřete aplikaci v prohlížeči.

Při první instalaci vás systém provede vytvořením prvního administrátorského účtu.

Po vytvoření administrátora se můžete přihlásit a začít přidávat vozidla, uživatele a importovat data.

## Doporučená struktura nasazení

Veřejně dostupný by měl být pouze adresář:

    public/

Ostatní adresáře obsahují zdrojový kód, konfiguraci, SQL soubory, šablony nebo uložené soubory a neměly by být přímo dostupné z internetu.

Důležité adresáře:

- public/ – veřejné HTTP vstupní body aplikace,
- src/ – aplikační logika,
- templates/ – šablony,
- sql/ – databázové schéma a migrace,
- storage/ – neveřejně uložené uživatelské soubory,
- .updates/ – zálohy vytvořené aktualizátorem,
- .env – lokální konfigurace prostředí.

## Uživatelské role

Systém rozlišuje několik typů uživatelů.

### Administrátor

Administrátor má plný přístup k systému.

Může:

- spravovat uživatele,
- vytvářet a upravovat vozidla,
- přiřazovat vozidla uživatelům,
- importovat data,
- zobrazovat dashboardy všech vozidel,
- spravovat provozní evidenci,
- spouštět aktualizace aplikace.

### Manager

Manager může pracovat s vozidly a jejich přiřazením, ale nemá plná administrátorská oprávnění.

Může:

- spravovat vozidla,
- přiřazovat vozidla uživatelům,
- zobrazovat data dostupných vozidel,
- pracovat s provozní evidencí.

Nemůže vytvářet ani mazat uživatelské účty a nemůže mazat vozidla.

### Uživatel

Běžný uživatel vidí pouze vozidla, která mu byla přiřazena.

Může:

- zobrazit dashboard přiřazených vozidel,
- pracovat s vlastními dostupnými daty,
- změnit svůj profil,
- nastavit si výchozí vozidlo.

## Správa vozidel

U každého vozidla lze evidovat zejména:

- název vozidla,
- VIN,
- typ pohonu,
- kapacitu baterie,
- nominální kapacitu baterie,
- ručně zadaný State of Health,
- domácí lokalitu,
- další technické a provozní údaje.

Podporované typy pohonu:

- BEV,
- PHEV,
- HEV,
- PETROL,
- DIESEL,
- LPG,
- CNG.

EV specifické údaje se používají zejména pro elektromobily a plug-in hybridy. Provozní evidence je společná pro všechny typy vozidel.

## Dashboard

Dashboard poskytuje přehled o provozu vozidla.

Zobrazuje například:

- seznam jízd,
- celkové kilometry,
- spotřebu,
- náklady,
- statistiky za období,
- odhad dojezdu,
- informace o baterii,
- State of Health,
- související provozní údaje.

Uživatel může přepínat mezi vozidly, ke kterým má oprávnění. V profilu si také může nastavit výchozí vozidlo, které se automaticky otevře po přihlášení.

## Import CSV

Aplikace umožňuje importovat jízdy z CSV exportů.

Import:

- automaticky rozpozná podporovaný formát CSV,
- zpracuje jednotlivé řádky do jednotného interního modelu,
- podle možností doplní spotřebu, vzdálenost, čas, stav nabití a další údaje,
- kontroluje VIN v názvu souboru, pokud je dostupné,
- při nalezení známého VIN přiřadí data ke správnému vozidlu,
- při neznámém VIN může založit nové vozidlo,
- při chybějícím VIN použije aktuálně vybrané vozidlo.

Pokud je v názvu CSV souboru obsažen 17znakový VIN, systém jej použije pro identifikaci vozidla.

Pokud vozidlo s daným VIN existuje, ale uživatel k němu nemá přístup, import se z bezpečnostních důvodů zastaví.

## Podporované CSV formáty

Aplikace podporuje pluginový systém pro CSV formáty.

Součástí projektu jsou například pluginy pro:

- novější exporty MyŠkoda,
- exporty Škoda Citigo iV.

Jednotlivé pluginy zajišťují:

- rozpoznání CSV podle hlavičky,
- návrh názvu vozidla při automatickém založení,
- převod řádku CSV do interního modelu jízd,
- export dat zpět do CSV.

Díky pluginové architektuře lze přidávat další značky a formáty bez zásahu do hlavního importeru, dashboardu nebo databázové vrstvy.

## State of Health baterie

Systém umožňuje sledovat stav baterie, tedy State of Health.

Používají se dva možné zdroje:

1. ručně zadaná hodnota z BMS nebo diagnostiky,
2. orientační odhad z jízd, pokud je dostupných dostatek vhodných dat.

Preferovaným zdrojem je vždy hodnota z diagnostiky nebo BMS.

Orientační odhad z jízd pracuje s dostupnými hodnotami spotřeby a poklesu stavu nabití. Tento výpočet je pouze informativní, protože výrobci mohou rozdílně počítat rezervy baterie, SoC a dostupnou kapacitu.

Pro servisní rozhodnutí používejte hodnotu z diagnostiky nebo BMS.

## Provozní evidence

Stránka provozní evidence umožňuje spravovat kompletní provozní historii vozidla.

Obsahuje:

- nabíjení,
- tankování,
- servisní záznamy,
- servisní přílohy,
- ostatní provozní náklady,
- připomínky,
- knihu jízd,
- souhrny nákladů,
- výpočet ceny za kilometr.

### Nabíjení a tankování

U záznamu lze evidovat například:

- datum,
- stav tachometru,
- množství energie nebo paliva,
- cenu,
- místo,
- poznámku.

### Servisní historie

Servisní záznamy umožňují ukládat:

- datum servisu,
- stav kilometrů,
- typ úkonu,
- cenu,
- poznámku,
- servisní přílohy.

Servisní přílohy se ukládají mimo veřejný webroot do adresáře storage/service/.

Přístup k přílohám kontroluje aplikace podle oprávnění uživatele k danému vozidlu.

### Ostatní provozní náklady

Do evidence lze zadávat také další náklady, například:

- pojištění,
- dálniční známky,
- pneumatiky,
- parkování,
- mytí,
- příslušenství,
- jiné provozní výdaje.

### Připomínky

Připomínky lze nastavit podle:

- konkrétního data,
- stavu kilometrů,
- kombinace data a kilometrů.

Hodí se například pro servisní intervaly, STK, výměnu pneumatik nebo kontrolu pojištění.

### Kniha jízd

Kniha jízd umožňuje evidovat a klasifikovat jízdy.

Podporované klasifikace:

- soukromá,
- služební,
- dojíždění,
- ostatní.

## Profil uživatele

Každý přihlášený uživatel má vlastní profil.

V profilu může:

- změnit jméno,
- změnit e-mail,
- změnit heslo,
- vybrat výchozí vozidlo.

Změna hesla vyžaduje zadání současného hesla.

Pokud výchozí vozidlo není nastavené nebo k němu už uživatel nemá přístup, systém automaticky použije první dostupné vozidlo.

## Obnova zapomenutého hesla

Na přihlašovací stránce je dostupná obnova hesla.

Postup:

1. Uživatel zadá e-mailovou adresu.
2. Systém vytvoří kryptograficky náhodný token.
3. Do databáze se uloží pouze SHA-256 hash tokenu.
4. Token je časově omezený.
5. Po použití se token zneplatní.
6. Uživatel si nastaví nové heslo.

Aplikace z bezpečnostních důvodů neprozrazuje, zda zadaný e-mail v systému existuje.

Pro lokální vývoj lze nastavit:

    APP_DEBUG=1

Pokud není nakonfigurované odesílání e-mailů, může se v debug režimu resetovací odkaz zobrazit přímo na stránce.

Na produkci musí být vždy nastaveno:

    APP_DEBUG=0

## Aktualizace aplikace

Administrátor může spustit aktualizaci aplikace přes stránku:

    public/update.php

Aktualizátor:

1. stáhne ZIP balíček z GitHubu,
2. zkontroluje strukturu balíčku,
3. vytvoří zálohu spravovaných souborů,
4. spustí dosud neprovedené databázové migrace,
5. synchronizuje aplikační adresáře,
6. nepřepisuje soubor .env.

Aktualizace nikdy nemaže uživatelské servisní přílohy ve storage/service/.

Pro správnou funkci aktualizátoru musí mít PHP proces právo zapisovat do adresáře aplikace.

## Bezpečnost

Aplikace používá několik bezpečnostních mechanismů:

- hashování hesel přes password_hash(),
- ověřování hesel přes password_verify(),
- CSRF tokeny u formulářů,
- session cookie s příznaky HttpOnly a SameSite=Lax,
- příznak Secure při HTTPS,
- resetovací tokeny uložené pouze jako hash,
- časově omezené resetovací odkazy,
- kontrolu oprávnění při přístupu k vozidlům,
- kontrolu oprávnění při stahování servisních příloh,
- blokování přístupu k neveřejným adresářům přes .htaccess.

## Architektura

Projekt je rozdělen do několika vrstev.

    public/
        HTTP vstupní body aplikace

    src/App/Http/Controller/
        zpracování HTTP požadavků

    src/App/Service/
        aplikační a business logika

    src/App/Repository/
        práce s databází

    src/App/Csv/
        obecná importní a exportní infrastruktura

    src/App/Csv/Plugin/
        pluginy pro konkrétní CSV formáty

    templates/
        prezentační šablony

    sql/
        databázové schéma a migrace

    storage/
        neveřejné uživatelské soubory

Controllery neobsahují SQL ani složitou business logiku. Tyto části jsou oddělené do service a repository vrstev.

## CSV pluginy

Každý CSV plugin implementuje rozhraní pro práci s konkrétním formátem exportu.

Plugin typicky zajišťuje:

- identifikaci formátu CSV,
- návrh výchozích údajů vozidla,
- převod CSV řádku do interního modelu,
- definici exportní hlavičky,
- převod interní jízdy zpět do CSV.

Pluginy jsou načítány automaticky z adresáře:

    src/App/Csv/Plugin/

Nový formát proto stačí přidat jako samostatnou třídu pluginu.

Zjednodušený příklad pluginu:
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
            return $this->hasColumns($header, ['Start Date', 'End Date', 'Distance']);
        }

        public function inspect(?string $vin): array
        {
            return [
                'suggested_name' => 'Example Vehicle',
                'battery_kwh' => 75.0,
                'battery_nominal_kwh' => 75.0,
            ];
        }

        public function parse(array $row): ?array
        {
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

## Důležitý princip datového modelu

Databázová tabulka jízd slouží jako společný normalizovaný model.

Značkově specifické názvy sloupců, odlišné formáty dat, zvláštnosti exportů nebo výpočty specifické pro konkrétní vozidlo patří pouze do CSV pluginu.

Neměly by být přímo v controllerech, repository ani dashboardu.

## Testování

Projekt používá testovací frameworky:

- PHPUnit,
- Behat.

Spuštění testů závisí na konkrétní lokální konfiguraci projektu a vývojového prostředí.

Obvykle se používají příkazy podobné:

    vendor/bin/phpunit

    vendor/bin/behat

## Provozní doporučení

- Pravidelně zálohujte databázi.
- Pravidelně zálohujte adresář storage/.
- Soubor .env uchovávejte mimo veřejný přístup.
- Na produkci mějte APP_DEBUG=0.
- Pro veřejný provoz používejte HTTPS.
- Před aktualizací aplikace proveďte zálohu databáze i souborů.
- Neměňte již jednou provedené databázové migrace; pro změny vytvářejte nové migrační soubory.

## Licence

Doplňte podle licence projektu.
