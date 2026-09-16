<?php
$pageTitle = 'Digitální garáž pro každé auto';
$bodyClass = 'landing-body';
$showNavigation = false;
require __DIR__ . '/partials/header.php';
?>
<div class="landing-shell">
    <header class="landing-nav">
        <a class="landing-brand" href="index.php" aria-label="EV Stats – úvod">
            <span class="landing-brand-mark">⚡</span>
            <span><b>EV Stats</b><small>DIGITAL GARAGE</small></span>
        </a>
        <nav aria-label="Navigace landing page">
            <a href="#funkce">Funkce</a>
            <a href="#prehledy">Přehledy</a>
            <a href="#jak-to-funguje">Jak to funguje</a>
        </nav>
        <div class="landing-nav-actions">
            <a class="landing-link" href="login.php">Přihlásit se</a>
            <a class="landing-btn landing-btn-small" href="register.php">Vytvořit účet</a>
        </div>
    </header>

    <?php if ($flash): ?>
        <div class="landing-flash<?= ($flash['type'] ?? '') === 'error' ? ' is-error' : '' ?>"><?= h((string)$flash['message']) ?></div>
    <?php endif; ?>

    <main>
        <section class="landing-hero">
            <div class="landing-hero-copy">
                <span class="landing-kicker"><i></i> VŠECHNO O VAŠEM AUTĚ NA JEDNOM MÍSTĚ</span>
                <h1>Vaše auto.<br><em>Jeho příběh v datech.</em></h1>
                <p>EV Stats je moderní digitální garáž pro elektromobily, hybridy i spalovací auta. Jízdy, spotřeba, nabíjení, tankování, náklady, servis, dokumenty a dlouhodobé statistiky v jednom přehledném systému.</p>
                <div class="landing-hero-actions">
                    <a class="landing-btn" href="register.php">Začít zdarma <span>→</span></a>
                    <?php if ($demoEnabled): ?>
                        <a class="landing-btn landing-btn-ghost" href="demo-login.php"><span class="play-dot">▶</span> Vyzkoušet živé demo</a>
                    <?php endif; ?>
                </div>
                <div class="landing-trust-row">
                    <span>✓ Bez instalace</span>
                    <span>✓ PWA do mobilu</span>
                    <span>✓ Demo bez registrace</span>
                </div>
            </div>
            <div class="landing-hero-visual">
                <div class="landing-glow"></div>
                <figure class="landing-browser landing-browser-main">
                    <div class="landing-browser-bar"><span></span><span></span><span></span><small>EV Stats · Digital Garage</small></div>
                    <img src="assets/landing/dashboard.png" alt="Ukázka hlavního dashboardu EV Stats">
                </figure>
                <div class="landing-float-card landing-float-card-a"><small>PRŮM. SPOTŘEBA</small><strong>18,16</strong><span>kWh/100 km</span></div>
                <div class="landing-float-card landing-float-card-b"><small>NÁKLADY / KM</small><strong>0,65</strong><span>Kč/km</span></div>
            </div>
        </section>

        <section class="landing-stats-strip" aria-label="Hlavní vlastnosti">
            <div><strong>1</strong><span>místo pro celý provoz auta</span></div>
            <div><strong>7</strong><span>typů pohonu od BEV po CNG</span></div>
            <div><strong>AI</strong><span>vytěžování dokladů a faktur</span></div>
            <div><strong>∞</strong><span>vozidel v digitální garáži</span></div>
        </section>

        <section class="landing-section" id="funkce">
            <div class="landing-section-head">
                <span class="landing-kicker"><i></i> DIGITÁLNÍ GARÁŽ</span>
                <h2>Nejen graf spotřeby.<br><em>Kompletní provozní historie.</em></h2>
                <p>EV Stats spojuje telemetrii, provozní evidenci a dokumenty do jednoho kontextu. Podle typu pohonu zobrazuje jen metriky, které dávají pro dané vozidlo smysl.</p>
            </div>
            <div class="landing-feature-grid">
                <article><span>↗</span><h3>Jízdy a telemetrie</h3><p>Import jízd, trasy, rychlost, spotřeba, rekuperace, SoC a dlouhodobé trendy.</p></article>
                <article><span>⚡</span><h3>Nabíjení & tankování</h3><p>Elektřina, benzín, nafta i další paliva včetně ceny, lokality a nákladů na kilometr.</p></article>
                <article><span>◷</span><h3>Timeline vozidla</h3><p>Jízdy, servis, výdaje, dokumenty a další události v jedné chronologické historii.</p></article>
                <article><span>✦</span><h3>Dokumenty & AI</h3><p>Faktury a účtenky nejprve zpracují lokální parsery, neznámé formáty může vytěžit AI.</p></article>
                <article><span>⌁</span><h3>Servis a náklady</h3><p>Servisní historie, pojištění, pneumatiky, dálniční známky, další výdaje a připomínky.</p></article>
                <article><span>◈</span><h3>Více vozidel</h3><p>Jedna garáž pro elektromobil, rodinný spalovák i firemní flotilu s řízenými přístupy.</p></article>
            </div>
        </section>

        <section class="landing-showcase" id="prehledy">
            <div class="landing-showcase-copy">
                <span class="landing-kicker"><i></i> ANALYTIKA, KTERÁ DÁVÁ SMYSL</span>
                <h2>Z dat vznikne <em>skutečný přehled.</em></h2>
                <p>Měsíční nájezd, spotřeba, denní rytmus, energetická bilance, rychlostní pásma, pravidelné trasy i nákladové metriky. Ne jen tabulka – informace, ze kterých je na první pohled vidět, jak auto skutečně používáte.</p>
                <ul>
                    <li><b>Sezónní a meziroční pohled</b><span>Filtrace období a vývoj spotřeby v čase.</span></li>
                    <li><b>Energetická bilance</b><span>Domácí AC vs. veřejné DC a reálné náklady.</span></li>
                    <li><b>Chování na trasách</b><span>Opakované cesty, délka, spotřeba a rychlost.</span></li>
                    <li><b>SoH a dlouhodobý stav</b><span>Orientační vývoj baterie založený na dostupných datech.</span></li>
                </ul>
            </div>
            <figure class="landing-showcase-image">
                <img src="assets/landing/analytics.png" alt="Analytické grafy EV Stats">
            </figure>
        </section>

        <section class="landing-split-showcase">
            <figure><img src="assets/landing/operations.png" alt="Přehled nákladů a provozu vozidla"><figcaption><b>Provoz & náklady</b><span>Kolik vás auto skutečně stojí.</span></figcaption></figure>
            <div class="landing-split-copy">
                <span class="landing-kicker"><i></i> OD SOUBORU K INFORMACI</span>
                <h2>Import Hub pro různá auta i zdroje dat.</h2>
                <p>Podporované pluginy rozpoznají formát importu automaticky. Pokud se objeví nový formát, univerzální mapper dovolí ruční namapování sloupců a uloží vzorek pro budoucí nový plugin.</p>
                <figure class="landing-inline-shot"><img src="assets/landing/import-hub.png" alt="Import Hub EV Stats"></figure>
            </div>
        </section>

        <section class="landing-ai-section">
            <div class="landing-ai-copy">
                <span class="landing-kicker"><i></i> DOKUMENTOVÝ INBOX</span>
                <h2>Faktura dovnitř.<br><em>Data ven.</em></h2>
                <p>Powerpass/Elli, ČEZ Futurego a E.ON Drive mají vlastní lokální parsery. Dokument se nejprve zobrazí ke kontrole a až potom se potvrzená data propíšou do provozní evidence. Pro neznámé formáty lze zapnout OpenAI nebo Gemini.</p>
                <div class="landing-security-note"><span>🛡</span><div><b>Kontrola před zápisem</b><small>AI návrh nikdy nemusí automaticky znamenat změnu vašich dat.</small></div></div>
            </div>
            <figure class="landing-ai-shot"><img src="assets/landing/documents.png" alt="Dokumentový inbox a fotografie vozidla"></figure>
        </section>

        <section class="landing-steps" id="jak-to-funguje">
            <div class="landing-section-head compact">
                <span class="landing-kicker"><i></i> ZAČÍT JE JEDNODUCHÉ</span>
                <h2>Od registrace k prvnímu přehledu ve třech krocích.</h2>
            </div>
            <div class="landing-step-grid">
                <article><span>01</span><h3>Vytvořte účet</h3><p>Registrace je ověřena e-mailem. Po aktivaci získáte vlastní oddělenou garáž.</p></article>
                <article><span>02</span><h3>Přidejte vozidlo</h3><p>Ručně nebo importem. EV Stats rozlišuje typ pohonu a přizpůsobí mu metriky.</p></article>
                <article><span>03</span><h3>Nahrajte data</h3><p>Importujte telemetrii, přidejte náklady, servis a dokumenty. Přehledy se dopočítají automaticky.</p></article>
            </div>
        </section>

        <section class="landing-demo-panel">
            <div>
                <span class="landing-kicker"><i></i> READ-ONLY DEMO</span>
                <h2>Nejdřív se podívejte dovnitř.</h2>
                <p>Demo účet obsahuje připravené jízdy, nabíjení a náklady. Můžete procházet dashboard, grafy, timeline i provozní přehledy. Uploady, editace a další zápisy jsou z bezpečnostních důvodů vypnuté.</p>
            </div>
            <div class="landing-demo-actions">
                <?php if ($demoEnabled): ?><a class="landing-btn" href="demo-login.php">Otevřít demo <span>→</span></a><?php endif; ?>
                <a class="landing-link-arrow" href="register.php">Nebo si vytvořit vlastní účet →</a>
            </div>
        </section>

        <section class="landing-final-cta">
            <span class="landing-brand-mark">⚡</span>
            <h2>Jedna garáž. Všechna data.<br><em>Váš vlastní příběh auta.</em></h2>
            <p>Přestaňte skládat historii vozidla z několika aplikací, tabulek a účtenek.</p>
            <div class="landing-hero-actions centered">
                <a class="landing-btn" href="register.php">Vytvořit účet <span>→</span></a>
                <a class="landing-btn landing-btn-ghost" href="login.php">Už mám účet</a>
            </div>
        </section>
    </main>

    <footer class="landing-footer">
        <a class="landing-brand" href="index.php"><span class="landing-brand-mark">⚡</span><span><b>EV Stats</b><small>DIGITAL GARAGE</small></span></a>
        <p>Digitální garáž pro elektromobily, hybridy i spalovací auta.</p>
        <div><a href="login.php">Přihlášení</a><a href="register.php">Registrace</a></div>
        <small>created by: © 2026 Pavel Filípek · <a href="https://www.filipek-czech.cz" target="_blank" rel="noopener noreferrer">filipek-czech.cz</a></small>
    </footer>
</div>
</body>
</html>
