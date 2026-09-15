# Coding style

Projekt používá konzistentní, PSR-12 inspirovaný styl a zachovává kompatibilitu s PHP 7.4.

## PHP

- Aplikační PHP soubory používají `declare(strict_types=1);`.
- Odsazení tvoří 4 mezery; tabulátory se nepoužívají.
- Třídy a rozhraní mají stručný PHPDoc popisující jejich odpovědnost.
- Property bez nativního typu mají `@var`; kolekce a návratové struktury mají PHPDoc tam, kde signatura nestačí.
- Jedna deklarace a jeden příkaz patří na jeden řádek.
- Control-flow bloky používají složené závorky i pro jediný příkaz.
- Delší pole se zapisují víceřádkově, typicky s jednou položkou na řádek.
- HTTP controllery řeší vstup, autorizaci, CSRF, redirect a sestavení odpovědi. Business logika patří do service vrstvy a persistence do repository vrstvy.
- Import specifický pro zdroj nebo výrobce zůstává v `src/App/Csv/Plugin` a implementuje společný plugin kontrakt.
- Nový kód nesmí vyžadovat PHP vyšší než 7.4, dokud se nezmění minimální podporovaná verze projektu.

## Komentáře

Komentář má vysvětlovat důvod, kontrakt nebo neobvyklé rozhodnutí, ne pouze opakovat následující řádek kódu. U tříd zachovávejte existující metadata autora a copyrightu.

## Kontrola před commitem

Minimální kontrola syntaxe:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```
