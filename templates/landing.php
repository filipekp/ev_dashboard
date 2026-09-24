<?php
$pageTitle = 'Digitální garáž pro každé auto';
$bodyClass = 'landing-body ev-landing-v2';
$showNavigation = false;
require __DIR__ . '/partials/header.php';
?>
<div class="landing2-shell">
    <header class="landing2-nav">
        <a class="landing2-brand" href="index.php" aria-label="EV Stats – úvod">
            <span class="landing2-brand-mark"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span>
            <span class="landing2-brand-copy"><b>EV Stats</b><small>DIGITAL GARAGE</small></span>
        </a>

        <nav class="landing2-nav-links" aria-label="Navigace landing page">
            <a href="#funkce">Funkce</a>
            <a href="#prehledy">Přehledy</a>
            <a href="#import">Import & AI</a>
            <a href="#jak-to-funguje">Jak to funguje</a>
        </nav>

        <div class="landing2-nav-actions">
            <a class="landing2-login" href="login.php">Přihlásit se</a>
            <a class="landing2-btn landing2-btn-small" href="register.php">Vytvořit účet</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="landing2-flash<?= ($flash['type'] ?? '') === 'error' ? ' is-error' : '' ?>"><?= h((string)$flash['message']) ?></div>
    <?php endif; ?>

    <main>
        <section class="landing2-hero">
            <div class="landing2-hero-copy">
                <span class="landing2-kicker"><i></i> DIGITÁLNÍ GARÁŽ PRO KAŽDÝ DEN</span>
                <h1>Všechno o vašem autě.<br><em>V jednom kokpitu.</em></h1>
                <p>EV Stats propojuje jízdy, spotřebu, nabíjení a tankování, servis, náklady, dokumenty i online data vozidla. Místo několika aplikací dostanete jednu dlouhodobou historii auta.</p>
                <div class="landing2-hero-actions">
                    <a class="landing2-btn" href="register.php">Začít zdarma <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    <?php if ($demoEnabled): ?>
                        <a class="landing2-btn landing2-btn-ghost" href="demo-login.php"><i class="bi bi-play-fill" aria-hidden="true"></i> Vyzkoušet demo</a>
                    <?php endif; ?>
                </div>
                <div class="landing2-trust-row">
                    <span><i class="bi bi-check2"></i> Bez instalace</span>
                    <span><i class="bi bi-phone"></i> PWA do mobilu</span>
                    <span><i class="bi bi-shield-check"></i> Data pod kontrolou</span>
                </div>
            </div>

            <div class="landing2-hero-visual" aria-label="Ukázka EV Stats dashboardu">
                <div class="landing2-orbit landing2-orbit-a"></div>
                <div class="landing2-orbit landing2-orbit-b"></div>
                <figure class="landing2-app-frame landing2-app-frame-hero">
                    <img src="assets/landing/dashboard-v2.png" alt="Dashboard vozidla v novém EV Stats kokpitu">
                </figure>
                <div class="landing2-float landing2-float-left">
                    <span class="landing2-float-icon is-teal"><i class="bi bi-graph-up-arrow"></i></span>
                    <div><small>PRŮM. SPOTŘEBA</small><strong>17,92</strong><span>kWh / 100 km</span></div>
                </div>
                <div class="landing2-float landing2-float-right">
                    <span class="landing2-float-icon is-violet"><i class="bi bi-car-front-fill"></i></span>
                    <div><small>JÍZDY CELKEM</small><strong>16</strong><span>v aktivním období</span></div>
                </div>
            </div>
        </section>

        <section class="landing2-proof" aria-label="Hlavní možnosti EV Stats">
            <div><span class="landing2-proof-icon"><i class="bi bi-car-front"></i></span><p><b>Jedna garáž</b><small>Elektromobil, hybrid i spalovací auto</small></p></div>
            <div><span class="landing2-proof-icon"><i class="bi bi-broadcast-pin"></i></span><p><b>Connected car</b><small>Online data z podporovaných vozidel</small></p></div>
            <div><span class="landing2-proof-icon"><i class="bi bi-file-earmark-text"></i></span><p><b>Doklady & AI</b><small>Vytěžení faktur a účtenek</small></p></div>
            <div><span class="landing2-proof-icon"><i class="bi bi-bar-chart-line"></i></span><p><b>Dlouhodobý přehled</b><small>Spotřeba, náklady, trasy a historie</small></p></div>
        </section>

        <section class="landing2-section" id="funkce">
            <div class="landing2-section-head">
                <div>
                    <span class="landing2-kicker"><i></i> VŠE KOLEM VOZIDLA</span>
                    <h2>Ne jen statistika spotřeby.<br><em>Skutečná digitální garáž.</em></h2>
                </div>
                <p>Každá část aplikace používá stejný kokpit, stejný kontext vozidla a stejnou historii. Přepínáte jen pohled, ne aplikaci.</p>
            </div>

            <div class="landing2-feature-grid">
                <article class="is-wide">
                    <span class="landing2-feature-icon"><i class="bi bi-speedometer2"></i></span>
                    <h3>Dashboard vozidla</h3>
                    <p>Nájezd, spotřeba, dojezd, doba jízdy, rychlé akce a soukromá historie v jednom přehledu.</p>
                </article>
                <article>
                    <span class="landing2-feature-icon is-teal"><i class="bi bi-signpost-split"></i></span>
                    <h3>Jízdy a trasy</h3>
                    <p>Automatické i ruční jízdy, pravidelné trasy, rychlostní pásma a dlouhodobé trendy.</p>
                </article>
                <article>
                    <span class="landing2-feature-icon is-orange"><i class="bi bi-lightning-charge"></i></span>
                    <h3>Energie a palivo</h3>
                    <p>Nabíjení, tankování, lokality, ceny, náklady na 100 km a reálná energetická bilance.</p>
                </article>
                <article>
                    <span class="landing2-feature-icon is-cyan"><i class="bi bi-clock-history"></i></span>
                    <h3>Timeline</h3>
                    <p>Jízdy, servis, výdaje, dokumenty a další události v jediné chronologii.</p>
                </article>
                <article>
                    <span class="landing2-feature-icon is-pink"><i class="bi bi-receipt"></i></span>
                    <h3>Servis a náklady</h3>
                    <p>Servisní zásahy, pojištění, pneumatiky, dálniční známky a celkové náklady vlastnictví.</p>
                </article>
                <article>
                    <span class="landing2-feature-icon"><i class="bi bi-stars"></i></span>
                    <h3>Smart Insights</h3>
                    <p>Efektivita, pravidelné dojíždění, opakované trasy a další pohledy odvozené z historie.</p>
                </article>
            </div>
        </section>

        <section class="landing2-showcase landing2-showcase-garage" id="prehledy">
            <div class="landing2-showcase-copy">
                <span class="landing2-kicker"><i></i> GARÁŽ NA PRVNÍ POHLED</span>
                <h2>Jedno místo pro jedno auto<br><em>i celou garáž.</em></h2>
                <p>Souhrn nájezdu, provozu a TCO doplňuje karta každého vozidla s aktuální spotřebou a rychlým vstupem do detailu.</p>
                <ul class="landing2-check-list">
                    <li><i class="bi bi-check2"></i><span><b>Aktivní vozidlo vždy po ruce</b><small>Přepínání bez ztráty kontextu.</small></span></li>
                    <li><i class="bi bi-check2"></i><span><b>Roční ekonomika garáže</b><small>Náklady, nájezd a vlastnictví pohromadě.</small></span></li>
                    <li><i class="bi bi-check2"></i><span><b>Fotografie a identita auta</b><small>VIN, SPZ, technické údaje i vlastní fotografie.</small></span></li>
                </ul>
            </div>
            <figure class="landing2-app-frame landing2-app-frame-large">
                <img src="assets/landing/garage-v2.png" alt="Moje garáž v EV Stats">
            </figure>
        </section>

        <section class="landing2-analytics">
            <div class="landing2-section-head landing2-section-head-centered">
                <div>
                    <span class="landing2-kicker"><i></i> ANALYTIKA BEZ TABULKOVÉHO CHAOSU</span>
                    <h2>Z provozních dat vznikne<br><em>čitelný obraz používání auta.</em></h2>
                </div>
                <p>Trend spotřeby, denní rytmus, energetická bilance i spotřeba podle rychlostních pásem. Všechny metriky používají stejná filtrovaná data.</p>
            </div>

            <figure class="landing2-app-frame landing2-app-frame-full">
                <img src="assets/landing/analytics-v2.png" alt="Analytika EV Stats – měsíční nájezd, energetická bilance a rychlostní pásma">
            </figure>

            <div class="landing2-analytics-row">
                <figure class="landing2-app-frame landing2-analytics-card">
                    <img src="assets/landing/insights-v2.png" alt="Smart Insights v EV Stats">
                </figure>
                <figure class="landing2-app-frame landing2-analytics-card is-route">
                    <img src="assets/landing/routes-v2.png" alt="Pravidelné trasy a dojíždění v EV Stats">
                </figure>
            </div>
        </section>

        <section class="landing2-showcase landing2-showcase-timeline">
            <figure class="landing2-app-frame landing2-app-frame-large">
                <img src="assets/landing/timeline-v2.png" alt="Timeline vozidla v EV Stats">
            </figure>
            <div class="landing2-showcase-copy">
                <span class="landing2-kicker"><i></i> ŽIVOT VOZIDLA V ČASE</span>
                <h2>Historie, která dává<br><em>jednotlivým záznamům kontext.</em></h2>
                <p>V timeline vedle sebe uvidíte jízdy, energii, servis i náklady. Nemusíte vzpomínat, co se kolem auta dělo před půl rokem.</p>
                <div class="landing2-mini-cards">
                    <div><i class="bi bi-car-front-fill"></i><span><b>Jízdy</b><small>odkud, kam a kolik km</small></span></div>
                    <div><i class="bi bi-lightning-charge-fill"></i><span><b>Energie</b><small>nabíjení i tankování</small></span></div>
                    <div><i class="bi bi-wrench-adjustable"></i><span><b>Servis</b><small>zásahy a provozní události</small></span></div>
                    <div><i class="bi bi-file-earmark-text"></i><span><b>Doklady</b><small>vazba na originální dokument</small></span></div>
                </div>
            </div>
        </section>

        <section class="landing2-import" id="import">
            <div class="landing2-import-copy">
                <span class="landing2-kicker"><i></i> JEDEN VSTUP PRO RŮZNÉ ZDROJE</span>
                <h2>Import Hub dostane data<br><em>z auta i z dokladů.</em></h2>
                <p>CSV/XLSX telemetrie, faktury, účtenky, ruční jízdy, nabíjení, tankování i servis. Každý typ záznamu má vlastní vstup, ale zapisuje se do stejné historie vozidla.</p>
                <div class="landing2-import-tags">
                    <span>CSV / XLSX</span><span>PDF</span><span>Obrázky</span><span>JSON</span><span>Ruční záznam</span><span>Connected API</span>
                </div>
            </div>
            <figure class="landing2-app-frame landing2-app-frame-full">
                <img src="assets/landing/import-hub-v2.png" alt="Import Hub EV Stats">
            </figure>
        </section>

        <section class="landing2-data-grid">
            <article class="landing2-data-card">
                <div class="landing2-data-copy">
                    <span class="landing2-kicker"><i></i> CONNECTED CAR</span>
                    <h3>Online data bez ukládání klíče v prohlížeči.</h3>
                    <p>Podporované konektory synchronizují telemetrii na serveru. Credential zůstává šifrovaný a jeho stav máte pod kontrolou v nastavení vozidla.</p>
                </div>
                <figure class="landing2-app-frame landing2-app-frame-crop">
                    <img src="assets/landing/connected-v2.png" alt="Nastavení MyŠkoda Public API v EV Stats">
                </figure>
            </article>

            <article class="landing2-data-card">
                <div class="landing2-data-copy">
                    <span class="landing2-kicker"><i></i> ARCHIV DOKUMENTŮ</span>
                    <h3>Originální doklad zůstává dohledatelný.</h3>
                    <p>Opakované vytěžení používá stále stejný bezpečně uložený originál. U záznamu je vidět typ, provider, stav i použitý extractor.</p>
                </div>
                <figure class="landing2-app-frame landing2-app-frame-crop">
                    <img src="assets/landing/documents-v2.png" alt="Archiv dokumentů EV Stats">
                </figure>
            </article>
        </section>

        <section class="landing2-steps" id="jak-to-funguje">
            <div class="landing2-section-head">
                <div>
                    <span class="landing2-kicker"><i></i> ZAČÍT JE JEDNODUCHÉ</span>
                    <h2>Od účtu k prvnímu přehledu<br><em>ve třech krocích.</em></h2>
                </div>
                <p>Nemusíte mít podporované connected auto. EV Stats funguje i s ručními záznamy nebo importy ze souborů.</p>
            </div>
            <div class="landing2-step-grid">
                <article><span>01</span><div><h3>Vytvořte účet</h3><p>Po ověření e-mailu získáte vlastní oddělenou digitální garáž.</p></div></article>
                <article><span>02</span><div><h3>Přidejte vozidlo</h3><p>Vyberte typ pohonu, doplňte základní údaje a případně připojte podporované API.</p></div></article>
                <article><span>03</span><div><h3>Nahrajte historii</h3><p>Importujte jízdy a doklady nebo začněte zapisovat nové události. Přehledy se dopočítají automaticky.</p></div></article>
            </div>
        </section>

        <section class="landing2-demo-panel">
            <div>
                <span class="landing2-kicker"><i></i> READ-ONLY DEMO</span>
                <h2>Nejdřív si projděte hotovou garáž.</h2>
                <p>Demo obsahuje připravené jízdy, nabíjení, náklady i analytiku. Procházet můžete téměř celou aplikaci, zápisy a uploady jsou vypnuté.</p>
            </div>
            <div class="landing2-demo-actions">
                <?php if ($demoEnabled): ?><a class="landing2-btn" href="demo-login.php">Otevřít demo <i class="bi bi-arrow-right"></i></a><?php endif; ?>
                <a class="landing2-text-link" href="register.php">Vytvořit vlastní účet</a>
            </div>
        </section>

        <section class="landing2-final-cta">
            <span class="landing2-brand-mark"><i class="bi bi-lightning-charge-fill"></i></span>
            <span class="landing2-kicker"><i></i> EV STATS · DIGITAL GARAGE</span>
            <h2>Jedna garáž. Všechna data.<br><em>Celý příběh vašeho auta.</em></h2>
            <p>Jízdy, energie, náklady, servis a dokumenty už nemusí žít v několika různých aplikacích.</p>
            <div class="landing2-hero-actions landing2-actions-centered">
                <a class="landing2-btn" href="register.php">Vytvořit účet <i class="bi bi-arrow-right"></i></a>
                <a class="landing2-btn landing2-btn-ghost" href="login.php">Už mám účet</a>
            </div>
        </section>
    </main>

    <footer class="landing2-footer">
        <a class="landing2-brand" href="index.php">
            <span class="landing2-brand-mark"><i class="bi bi-lightning-charge-fill"></i></span>
            <span class="landing2-brand-copy"><b>EV Stats</b><small>DIGITAL GARAGE</small></span>
        </a>
        <p>Digitální garáž pro elektromobily, hybridy i spalovací auta.</p>
        <div class="landing2-footer-links"><a href="login.php">Přihlášení</a><a href="register.php">Registrace</a></div>
        <small>created by: © 2026 Pavel Filípek · <a href="https://www.filipek-czech.cz" target="_blank" rel="noopener noreferrer">filipek-czech.cz</a></small>
    </footer>
</div>
</body>
</html>
