<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/** Vytváří vysvětlitelné insighty nad provozními daty bez odesílání dat třetí straně. */
final class VehicleInsightService
{
    /** @var PDO */ private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** @param array<string,mixed> $vehicle @return array<int,array<string,string>> */
    public function build(array $vehicle): array
    {
        $id=(int)$vehicle['id']; $out=[];
        $q=$this->pdo->prepare('SELECT SUM(distance_km) km, SUM(consumed_kwh) kwh FROM trips WHERE vehicle_id=? AND started_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)'); $q->execute([$id]); $now=$q->fetch() ?: [];
        $q=$this->pdo->prepare('SELECT SUM(distance_km) km, SUM(consumed_kwh) kwh FROM trips WHERE vehicle_id=? AND started_at>=DATE_SUB(NOW(),INTERVAL 60 DAY) AND started_at<DATE_SUB(NOW(),INTERVAL 30 DAY)'); $q->execute([$id]); $prev=$q->fetch() ?: [];
        $km=(float)($now['km']??0); $pkm=(float)($prev['km']??0); $avg=$km>0?(float)($now['kwh']??0)/$km*100:0; $pavg=$pkm>0?(float)($prev['kwh']??0)/$pkm*100:0;
        if ($avg>0) { $delta=$pavg>0?($avg/$pavg-1)*100:0; $out[]=['icon'=>'⚡','title'=>'Efektivita za 30 dní','text'=>sprintf('Průměr %.1f kWh/100 km%s.', $avg, $pavg>0?sprintf(', změna %+.1f %% proti předchozím 30 dnům',$delta):'')]; }
        $q=$this->pdo->prepare('SELECT SUM(total_price) cost, SUM(quantity) qty FROM vehicle_energy_entries WHERE vehicle_id=? AND occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)'); $q->execute([$id]); $e=$q->fetch() ?: [];
        if ((float)($e['cost']??0)>0) $out[]=['icon'=>'💸','title'=>'Energie a palivo','text'=>sprintf('Za posledních 30 dní evidujete %.0f Kč za %.1f jednotek energie/paliva.',(float)$e['cost'],(float)$e['qty'])];
        $q=$this->pdo->prepare('SELECT COUNT(*) c FROM vehicle_reminders WHERE vehicle_id=? AND completed_at IS NULL AND due_date IS NOT NULL AND due_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)'); $q->execute([$id]); $due=(int)$q->fetchColumn();
        if ($due>0) $out[]=['icon'=>'🔧','title'=>'Blíží se termín','text'=>'V příštích 30 dnech máte '. $due .' aktivní servisní připomínku/připomínky.'];
        if (!$out) $out[]=['icon'=>'✦','title'=>'Sbírám data','text'=>'Po dalších jízdách, nabíjeních nebo tankováních se zde objeví personalizované trendy a upozornění.'];
        return $out;
    }
}
