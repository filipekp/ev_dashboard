# EV Stats – PHP 7.4 + MariaDB

Dashboard pro statistiky elektromobilů z CSV exportu. Tato PHP 7.4 kompatibilní verze rozšiřuje původní dashboard o uživatele, role, přiřazení vozidel, profil, obnovu hesla a State of Health baterie.

## Požadavky

- PHP 7.4 s rozšířením PDO MySQL
- MariaDB 10.5+ / MySQL 8+
- webserver Apache nebo Nginx
- pro e-mailovou obnovu hesla funkční PHP `mail()` / lokální MTA

Preferovaný document root je adresář `public/`. Pokud jej na sdíleném hostingu (např. WEDOS) nelze nastavit, je v kořeni projektu připraven `.htaccess`, který interně směruje požadavky do `public/` a blokuje přímý přístup k citlivým adresářům.

## Nová instalace

1. Vytvořte databázi a spusťte `sql/schema.sql`.
2. Zkopírujte `.env.example` na `.env` a nastavte databázi.
3. Nastavte `APP_BASE_URL`, např. `https://ev.example.cz`.
4. Nastavte `MAIL_FROM`, pokud chcete odesílat odkazy pro obnovu hesla e-mailem.
5. Otevřete aplikaci. `setup.php` vytvoří prvního administrátora.

## Upgrade z v2

Před upgradem udělejte zálohu databáze a spusťte:

```sql
SOURCE sql/migrate_v3.sql;
```

Potom nahraďte soubory aplikace verzí v3 a doplňte nové proměnné do `.env`.

## Role

- `admin` – správa uživatelů, vozidel, přiřazení, importů a všech dashboardů.
- `manager` – správa vozidel a jejich přiřazení uživatelům, přístup ke všem vozidlům; nemůže vytvářet ani mazat uživatelské účty a nemůže mazat vozidla.
- `user` – běžný řidič; vidí pouze přiřazená vozidla.

Ve správě uživatelů lze otevřít detail každého účtu a pomocí checkboxů přiřadit libovolný počet vozidel.

## Profil a změna hesla

Každý přihlášený uživatel má stránku `profile.php`, kde může změnit jméno, e-mail a vlastní heslo. Změna hesla vyžaduje současné heslo.

## Zapomenuté heslo

Na přihlašovací stránce je odkaz **Zapomenuté heslo?**. Postup:

1. Uživatel zadá e-mail.
2. Aplikace vytvoří kryptograficky náhodný token; v databázi se ukládá pouze SHA-256 hash tokenu.
3. Token platí 60 minut a po použití se zneplatní.
4. Odkaz vede na `reset-password.php`, kde uživatel nastaví nové heslo.

Aplikace schválně neprozrazuje, zda e-mail v systému existuje.

Pro lokální vývoj lze nastavit `APP_DEBUG=1`. Pokud odesílání e-mailů není nastavené, resetovací odkaz se zobrazí přímo na stránce. **Na produkci musí být `APP_DEBUG=0`.**

Administrátor může ve správě uživatelů také vytvořit a odeslat nový resetovací odkaz.

## State of Health (SoH)

V dashboardu je nová karta **STATE OF HEALTH**.

Priorita zdroje je:

1. **BMS / diagnostika** – hodnotu lze ručně uložit u vozidla v poli „SoH z diagnostiky (%)“. Toto je preferovaný zdroj.
2. **Orientační odhad z jízd** – pokud BMS hodnota není zadána, aplikace využije posledních až 30 vhodných jízd bez veřejného nabíjení, s poklesem SoC alespoň 10 procentních bodů. Pro každou jízdu odhadne využitelnou kapacitu `spotřeba kWh / pokles SoC` a zobrazí medián alespoň tří realistických vzorků.

Odhad z CSV není ekvivalent BMS měření. Výrobce vozu může SoC, rezervy baterie a spotřebu počítat jinak, takže pro servisní rozhodnutí používejte hodnotu z diagnostiky/BMS.

U vozidla jsou nyní dvě kapacity:

- **Aktuálně využitelná kapacita** – používá se pro odhad dojezdu a přepočty energie.
- **Nominální kapacita nového vozu** – používá se jako referenční kapacita pro výpočet SoH.

## Bezpečnost

- hesla přes `password_hash()` / `password_verify()`
- CSRF tokeny pro formuláře
- session cookie `HttpOnly`, `SameSite=Lax` a `Secure` při HTTPS
- reset token je v databázi pouze jako hash
- jednorázové a expirované reset tokeny se nedají znovu použít

## Hlavní soubory

- `public/index.php` – dashboard
- `public/users.php` – správa uživatelů a přiřazení vozidel
- `public/vehicles.php` – správa vozidel a SoH
- `public/profile.php` – vlastní profil a změna hesla
- `public/forgot-password.php` – žádost o reset hesla
- `public/reset-password.php` – nové heslo
- `sql/migrate_v3.sql` – migrace z v2
- `sql/migrate_v4.sql` – podpora nových CSV polí
- `sql/migrate_v5.sql` – výchozí vozidlo uživatele


## PHP 7.4 kompatibilita

Tato varianta nepoužívá konstrukce zavedené až v PHP 8, zejména union typy, návratový typ `never`, `str_contains()` ani `str_starts_with()`. Arrow funkce a operátor `??=` zůstávají použity, protože jsou součástí PHP 7.4.

> PHP 7.4 je již mimo oficiální bezpečnostní podporu. Pokud hosting později umožní PHP 8.1+, doporučuje se přejít na novější PHP.

## WEDOS / sdílený hosting bez změny DocumentRoot

Nahrajte celý obsah projektu do adresáře subdomény. Kořenový `.htaccess` zajistí interní přesměrování do `public/`, takže URL zůstane např. `https://ev.example.cz/` bez `/public`.

Po nahrání:

1. zkopírujte `.env.example` na `.env`,
2. nastavte přihlašovací údaje MariaDB a `APP_BASE_URL`,
3. importujte `sql/schema.sql` (nová instalace) nebo příslušnou migraci,
4. otevřete web a dokončete vytvoření administrátora přes `setup.php`.

## Podpora Citigo iV CSV (v4)

Aplikace nyní automaticky rozpozná dva typy exportu MyŠkoda:

1. novější plný export (např. Elroq) s adresami, SoC, GPS a údaji o nabíjení,
2. export Citigo iV se sloupci `End of trip`, `Mileage in km`, náklady, průměrnou spotřebou, pomocnou spotřebou a rekuperací.

Pro existující databázi nejdříve spusťte:

```sql
sql/migrate_v4.sql
```

Potom lze CSV Citigo iV nahrát přes stejný formulář **Nahrát CSV**. Formát se detekuje automaticky.

U Citigo iV exportu se:

- začátek jízdy dopočítá jako `End of trip - Travel time in minutes`,
- spotřebovaná energie dopočítá z `Mileage in km × Average electric consumption / 100`,
- tachometr převezme ze `Start mileage in km` a `End mileage in km`,
- náklady, cena elektřiny, pomocná spotřeba a rekuperace uloží do databáze,
- adresy, GPS, SoC a veřejné nabíjení zobrazí jako nedostupné, protože je CSV Citigo iV neobsahuje,
- odhad SoH z jízd nelze provést bez SoC; stále lze zadat manuální SoH z BMS/diagnostiky ve správě vozidla.

Pokud název nahraného CSV obsahuje 17znakový VIN, aplikace navíc zkontroluje, zda odpovídá právě vybranému vozidlu. Tím se snižuje riziko importu dat do špatného auta.

## Automatické rozpoznání vozidla při importu

Import nyní používá VIN z originálního názvu exportu (např. `tripStatistics_TMBZZZAAZLD804710.csv`).

- Pokud VIN v databázi existuje, CSV se automaticky importuje k tomuto vozidlu bez ohledu na právě otevřené vozidlo v dashboardu.
- Pokud VIN neexistuje, vozidlo se automaticky založí, přiřadí aktuálně přihlášenému uživateli a po importu se zobrazí modal pro doplnění názvu, kapacity baterie, SoH a domácí lokality.
- Pro Citigo iV se předvyplní název `Škoda Citigo iV` a využitelná kapacita 32,3 kWh. Hodnoty je možné v modalu upravit.
- Pokud byl CSV soubor přejmenovaný a VIN v názvu chybí, import použije aktuálně vybrané vozidlo stejně jako dříve.
- Pokud už vozidlo s rozpoznaným VIN existuje, ale uživatel k němu nemá přístup, import se z bezpečnostních důvodů zastaví a vozidlo se automaticky nepřiřadí.

Tato změna nevyžaduje novou databázovou migraci oproti `migrate_v4.sql`.


## Výchozí vozidlo uživatele (v5)

Každý uživatel si může v **Můj profil → Výchozí vozidlo** zvolit vůz, který se má automaticky otevřít po přihlášení. Pokud výchozí vozidlo není nastavené nebo k němu už uživatel nemá přístup, aplikace použije první dostupné vozidlo. Ruční přepnutí vozidla v dashboardu platí pro aktuální relaci.

Pro existující databázi spusťte po `migrate_v4.sql` také:

```sql
sql/migrate_v5.sql
```

U nové instalace je změna již součástí `sql/schema.sql`.
