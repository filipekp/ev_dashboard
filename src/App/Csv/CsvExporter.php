<?php

declare(strict_types=1);
namespace App\Csv;

use App\Repository\TripRepository;
use RuntimeException;

final class CsvExporter
{
    /** @var CsvPluginRegistry */ private $registry;
    /** @var TripRepository */ private $trips;
    public function __construct(CsvPluginRegistry $registry, TripRepository $trips){$this->registry=$registry;$this->trips=$trips;}

    /** @param array<string,mixed> $vehicle */
    public function stream(array $vehicle, string $period, string $year): void
    {
        [$period,$from,$to,$suffix]=$this->period($period,$year);
        $vehicleId=(int)$vehicle['id']; $format=$this->trips->dominantSourceFormat($vehicleId,$from,$to);
        if(!$this->registry->has($format)) throw new RuntimeException('Pro zdrojový formát "'.$format.'" není dostupný exportní plugin.');
        $plugin=$this->registry->get($format);
        $safeVin=preg_replace('/[^A-Z0-9_-]/i','',(string)($vehicle['vin']??''));
        $filename='tripStatistics_'.($safeVin?:'vehicle').$suffix.'.csv';
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Cache-Control: no-store, no-cache, must-revalidate');
        $out=fopen('php://output','wb'); if(!$out)return; fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,$plugin->exportHeaders());
        foreach($this->trips->findForExport($vehicleId,$from,$to) as $trip) fputcsv($out,$plugin->exportRow($trip)); fclose($out);
    }

    /** @return array{0:string,1:?string,2:?string,3:string} */
    private function period(string $period,string $year): array
    {
        if(preg_match('/^\d{4}-\d{2}$/',$period)){ $from=$period.'-01 00:00:00'; $d=new \DateTime($from);$d->modify('+1 month'); return [$period,$from,$d->format('Y-m-d H:i:s'),'_'.$period]; }
        if($period==='year'&&preg_match('/^\d{4}$/',$year)){return [$period,$year.'-01-01 00:00:00',((int)$year+1).'-01-01 00:00:00','_'.$year];}
        return ['all',null,null,''];
    }
}
