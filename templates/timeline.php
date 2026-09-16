<?php
$pageTitle = 'Timeline';
$showNavigation = true;
$navTitle = 'Timeline';
require __DIR__ . '/partials/header.php';
?>
<main class="wrap revolution-wrap"><section class="page-hero compact-hero"><div><?php if($vehiclePhoto):?><img class="hero-car-thumb" src="media.php?view=<?= (int)$vehiclePhoto['id']?>" alt=""><?php endif;?></div><div><span class="eyebrow">ŽIVOT VOZIDLA</span><h1><?=h($vehicle['name'])?> Timeline</h1><p>Jízdy, energie, servis a náklady v jedné chronologii.</p></div><a class="btn primary" href="import.php">＋ Přidat / importovat</a></section>
<section class="insight-strip"><?php foreach($insights as $i):?><article class="insight-card"><span><?=$i['icon']?></span><div><b><?=h($i['title'])?></b><p><?=h($i['text'])?></p></div></article><?php endforeach;?></section>
<section class="timeline-shell"><div class="timeline-list"><?php foreach($events as $e): $meta=['trip'=>['🚗','Jízda'],'energy'=>['⚡','Energie / palivo'],'service'=>['🔧','Servis'],'expense'=>['💳','Náklad']][$e['type']];?><article class="timeline-event type-<?=h($e['type'])?>"><div class="timeline-dot"><?=$meta[0]?></div><div class="timeline-body"><small><?=h(date('d.m.Y H:i',strtotime($e['event_at'])))?> · <?=$meta[1]?></small><h3><?=h((string)($e['label']?:$meta[1]))?></h3><p><?=h((string)($e['detail']??''))?></p></div><strong><?php if($e['type']==='trip'):?><?=cz((float)$e['value'],1)?> km<?php elseif($e['value']!==null):?><?=cz((float)$e['value'],0)?> Kč<?php endif;?></strong></article><?php endforeach;?><?php if(!$events):?><div class="empty-state"><b>Timeline je zatím prázdná</b><p>Importujte jízdu, fakturu nebo přidejte provozní záznam.</p><a class="btn primary" href="import.php">Otevřít Import Hub</a></div><?php endif;?></div></section></main>

<?php require __DIR__ . '/partials/footer.php'; ?>
