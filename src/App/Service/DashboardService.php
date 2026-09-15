<?php

declare(strict_types=1);
namespace App\Service;

use DateTime;
use PDO;

/**
 * Třída DashboardService.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class DashboardService
{
    /** @var PDO */ private $pdo;
    public function __construct(PDO $pdo){$this->pdo=$pdo;}

    /** @param array<string,mixed> $vehicle @param array<string,mixed> $query @return array<string,mixed> */
    public function build(array $vehicle,array $query): array
    {
        $vehicleId=(int)$vehicle['id'];
        $months=$this->pdo->prepare('SELECT DISTINCT DATE_FORMAT(started_at, "%Y-%m") m FROM trips WHERE vehicle_id=? ORDER BY m');
        $months->execute([$vehicleId]); $monthOptions=$months->fetchAll(PDO::FETCH_COLUMN); $years=[];
        foreach($monthOptions as $m){$y=substr((string)$m,0,4);if(!in_array($y,$years,true))$years[]=$y;}
        $period=(string)($query['period']??'all'); $selectedYear=(string)($query['year']??''); if(preg_match('/^\d{4}-\d{2}$/',$period))$selectedYear=substr($period,0,4);
        if(!in_array($selectedYear,$years,true))$selectedYear=$years?(string)end($years):date('Y');
        $params=[$vehicleId];$where='vehicle_id = ?';
        if(preg_match('/^\d{4}-\d{2}$/',$period)){$from=$period.'-01 00:00:00';$dt=new DateTime($from);$dt->modify('+1 month');$where.=' AND started_at >= ? AND started_at < ?';$params[]=$from;$params[]=$dt->format('Y-m-d H:i:s');}
        elseif($period==='year'){$where.=' AND started_at >= ? AND started_at < ?';$params[]=$selectedYear.'-01-01 00:00:00';$params[]=((int)$selectedYear+1).'-01-01 00:00:00';}else{$period='all';}

        $summaryQ=$this->pdo->prepare("SELECT COUNT(*) trip_count,COALESCE(SUM(distance_km),0) total_km,COALESCE(SUM(consumed_kwh),0) total_kwh,COALESCE(SUM(driving_minutes),0) drive_min,COALESCE(SUM(travel_minutes),0) travel_min,COALESCE(SUM(short_trip),0) short_trips,COALESCE(SUM(public_charging_stops),0) public_stops,COALESCE(SUM(public_charge_soc_gained),0) public_soc,MIN(start_odometer_km) odo_min,MAX(end_odometer_km) odo_max,MIN(end_soc) min_soc,SUM(CASE WHEN start_soc IS NOT NULL OR end_soc IS NOT NULL OR public_charging_stops>0 OR public_charge_soc_gained>0 THEN 1 ELSE 0 END) charge_rows,SUM(CASE WHEN start_address<>'' OR end_address<>'' THEN 1 ELSE 0 END) location_rows,SUM(CASE WHEN electricity_cost IS NOT NULL OR total_cost IS NOT NULL THEN 1 ELSE 0 END) cost_rows,COALESCE(SUM(electricity_cost),0) electricity_cost_total FROM trips WHERE $where");
        $summaryQ->execute($params);$summary=$summaryQ->fetch()?:[];
        $tripCount=(int)($summary['trip_count']??0);$totalKm=(float)($summary['total_km']??0);$totalKwh=(float)($summary['total_kwh']??0);$avgCons=$totalKm>0?$totalKwh/$totalKm*100:0;$driveMin=(int)($summary['drive_min']??0);$travelMin=(int)($summary['travel_min']??0);$avgSpeed=$driveMin>0?$totalKm/($driveMin/60):0;$range=$avgCons>0?(float)$vehicle['battery_kwh']/$avgCons*100:0;$shortTrips=(int)($summary['short_trips']??0);$chargeDataAvailable=(int)($summary['charge_rows']??0)>0;$locationDataAvailable=(int)($summary['location_rows']??0)>0;$costDataAvailable=(int)($summary['cost_rows']??0)>0;$costTotal=(float)($summary['electricity_cost_total']??0);$publicStops=(int)($summary['public_stops']??0);$publicSoc=(float)($summary['public_soc']??0);$publicKwh=$publicSoc/100*(float)$vehicle['battery_kwh'];$homeKwh=max(0,$totalKwh-$publicKwh);$chargeTotal=max(.001,$homeKwh+$publicKwh);$homePct=$homeKwh/$chargeTotal*100;$publicPct=$publicKwh/$chargeTotal*100;$odoMin=$summary['odo_min']!==null?(float)$summary['odo_min']:0;$odoMax=$summary['odo_max']!==null?(float)$summary['odo_max']:0;$minSoc=$summary['min_soc']!==null?(float)$summary['min_soc']:null;
        $monthLabels=[];$monthKm=[];$monthCons=[];$q=$this->pdo->prepare("SELECT DATE_FORMAT(started_at,'%Y-%m') m,SUM(distance_km) km,SUM(consumed_kwh) kwh FROM trips WHERE $where GROUP BY m ORDER BY m");$q->execute($params);foreach($q->fetchAll() as $r){$monthLabels[]=substr($r['m'],5,2).'/'.substr($r['m'],0,4);$monthKm[]=round((float)$r['km'],1);$monthCons[]=round((float)$r['km']>0?(float)$r['kwh']/(float)$r['km']*100:0,1);}
        $hourData=array_fill(0,24,0);$q=$this->pdo->prepare("SELECT HOUR(started_at) h,COUNT(*) c FROM trips WHERE $where GROUP BY h");$q->execute($params);foreach($q->fetchAll() as $r)$hourData[(int)$r['h']]=(int)$r['c'];
        $bandLabels=['Město (<40 km/h)','Okresky (40–65 km/h)','Rychlé okresky (65–85 km/h)','Dálnice (>85 km/h)'];$bandValues=array_fill(0,4,0.0);$q=$this->pdo->prepare("SELECT CASE WHEN avg_speed_kmh<40 THEN 0 WHEN avg_speed_kmh<65 THEN 1 WHEN avg_speed_kmh<=85 THEN 2 ELSE 3 END band,SUM(distance_km) km,SUM(consumed_kwh) kwh FROM trips WHERE $where GROUP BY band");$q->execute($params);foreach($q->fetchAll() as $r){$idx=(int)$r['band'];$km=(float)$r['km'];$bandValues[$idx]=round($km>0?(float)$r['kwh']/$km*100:0,1);}
        $routes=[];$q=$this->pdo->prepare("SELECT start_address,end_address,COUNT(*) c,SUM(distance_km) km,SUM(consumed_kwh) kwh FROM trips WHERE $where AND (start_address<>'' OR end_address<>'') GROUP BY start_address,end_address ORDER BY c DESC,km DESC LIMIT 10");$q->execute($params);foreach($q->fetchAll() as $r){$key=\App\View::displayRoute($r['start_address'],$r['end_address']);$routes[$key]=['count'=>(int)$r['c'],'km'=>(float)$r['km'],'kwh'=>(float)$r['kwh']];}
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM trips WHERE $where AND distance_km>=80");$q->execute($params);$longTripCount=(int)$q->fetchColumn();$q=$this->pdo->prepare("SELECT * FROM trips WHERE $where AND distance_km>=80 ORDER BY started_at DESC LIMIT 100");$q->execute($params);$longTrips=$q->fetchAll();
        $monthsForYear=array_values(array_filter($monthOptions,static function($m)use($selectedYear){return substr((string)$m,0,4)===$selectedYear;}));$homeLabel=(string)($vehicle['home_label']??'');
        $perPage=25;$historyPage=max(1,(int)($query['page']??1));$historyPages=max(1,(int)ceil($tripCount/$perPage));if($historyPage>$historyPages)$historyPage=$historyPages;$historyOffset=($historyPage-1)*$perPage;$q=$this->pdo->prepare("SELECT * FROM trips WHERE $where ORDER BY started_at DESC LIMIT ".(int)$perPage." OFFSET ".(int)$historyOffset);$q->execute($params);$historyTrips=$q->fetchAll();
        $nominalKwh=(float)($vehicle['battery_nominal_kwh']?:$vehicle['battery_kwh']);$sohManual=$vehicle['soh_manual_pct']!==null?(float)$vehicle['soh_manual_pct']:null;$sohSamples=[];$q=$this->pdo->prepare('SELECT consumed_kwh,start_soc,end_soc FROM trips WHERE vehicle_id=? AND public_charging_stops=0 AND consumed_kwh>0 AND start_soc IS NOT NULL AND end_soc IS NOT NULL AND (start_soc-end_soc)>=10 ORDER BY started_at DESC LIMIT 30');$q->execute([$vehicleId]);foreach($q->fetchAll() as $r){$drop=(float)$r['start_soc']-(float)$r['end_soc'];if($drop<=0)continue;$cap=(float)$r['consumed_kwh']/($drop/100);$pct=$nominalKwh>0?$cap/$nominalKwh*100:0;if($pct>=60&&$pct<=110)$sohSamples[]=$pct;}sort($sohSamples);$sohEstimated=null;if(count($sohSamples)>=3){$n=count($sohSamples);$sohEstimated=$n%2?$sohSamples[intdiv($n,2)]:($sohSamples[$n/2-1]+$sohSamples[$n/2])/2;}$soh=$sohManual??$sohEstimated;$sohSource=$sohManual!==null?'BMS / diagnostika':($sohEstimated!==null?'orientační odhad z jízd':'nedostatek dat');$sohClass=$soh===null?'':($soh>=90?'green':($soh>=80?'orange':'pink'));
        return get_defined_vars();
    }
}
