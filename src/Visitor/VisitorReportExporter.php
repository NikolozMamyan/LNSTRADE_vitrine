<?php

declare(strict_types=1);

namespace App\Visitor;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Axis;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class VisitorReportExporter
{
    public function write(array $report, string $filename): void
    {
        $book = new Spreadsheet();
        try {
            $book->getProperties()->setCreator('LNSTRADE')->setTitle('Statistiques des visiteurs');
            $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
            $period = 'Du '.$report['chart'][0]['date'].' au '.$report['chart'][$report['chartDays'] - 1]['date'].' · Heure de Paris';
            $filter = $report['invalidIp'] ? 'IP invalide : '.$report['ip'].' (aucun résultat)' : ($report['ip'] ?: 'Toutes les adresses IP');
            $context = $period.' · '.$filter;
            $summary = $book->getActiveSheet()->setTitle('Synthèse');
            $this->prepareSheet($summary, 'LNSTRADE — Statistiques des visiteurs', $context, ['Indicateur', 'Valeur'], [48, 94]);
            $this->writeRows($summary, [
                ['Début de période', $report['chart'][0]['date']],
                ['Fin de période', $report['chart'][$report['chartDays'] - 1]['date']],
                ['Nombre de jours', $report['chartDays']],
                ['Filtre IP', $filter],
                ['Export généré le (heure de Paris)', $report['updatedAt']->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i:s')],
                ['Pages consultées', $report['periodViews']],
                ['Sessions démarrées sur la période', $report['periodSessions']],
                ['Pages consultées aujourd’hui', $report['todayViews']],
                ['Sessions actives au moment de l’export', $report['activeCount']],
                ['Moyenne de pages par jour', $report['averageDailyViews']],
                ['Pages avec campagne identifiée', $report['campaignViews']],
                ['Part des pages avec campagne', $report['campaignShare'] / 100],
                ['Jour le plus actif', $report['peakDay']['date'] ?? 'Aucune visite'],
                ['Pages du jour le plus actif', $report['peakDay']['views'] ?? 0],
                ['Sources / supports distincts', count($report['trafficSources'])],
                ['Campagnes / sources / supports distincts', count(array_filter($report['trafficCampaigns'], static fn (array $row): bool => '' !== $row['campaign']))],
            ]);
            $summary->getStyle('B16')->getNumberFormat()->setFormatCode('#,##0.0');
            $summary->getStyle('B18')->getNumberFormat()->setFormatCode('0.0%');
            $notes = [
                'Les pourcentages mesurent la part des pages consultées, pas la rentabilité financière ni les conversions.',
                'Chaque page est attribuée à la source d’entrée de sa session. Toute campagne renseignée est comptée, payante ou non.',
                'Sessions estimées à partir de l’IP et du navigateur, sans nouveau cookie ; nouvelle session après 30 minutes d’inactivité.',
                'Sessions actives : au moins une page chargée dans les 5 dernières minutes. Robots connus et préchargements exclus.',
                'Les pages sont comptées à leur date de consultation ; les sessions à leur date de démarrage. Une session peut commencer avant la période.',
                'Les tableaux Sources et Campagnes contiennent toutes les lignes. Leurs graphiques présentent les 10 premières lignes par nombre de pages.',
            ];
            if (0 === $report['days']) {
                $notes[] = 'Filtre « Tout l’historique » : cet export reprend les 30 derniers jours du graphique, comme dans l’admin.';
            }
            foreach ($notes as $index => $note) {
                $row = 25 + $index;
                $summary->mergeCells('A'.$row.':B'.$row)->setCellValue('A'.$row, $note);
                $summary->getStyle('A'.$row)->getAlignment()->setWrapText(true);
                $summary->getStyle('A'.$row)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('666666'));
                $summary->getRowDimension($row)->setRowHeight(32);
            }

            $daily = $book->createSheet()->setTitle('Évolution');
            $this->prepareSheet($daily, 'Évolution quotidienne des visites', $context, [
                'Date', 'Pages consultées', 'Sessions démarrées', 'Pages avec campagne', 'Part des campagnes',
                'Part des pages de la période', 'Source principale', 'Support principal', 'Pages de la source principale', 'Part de la source principale',
            ], [15, 19, 21, 23, 22, 26, 30, 24, 28, 27]);
            $dailyRows = [];
            foreach ($report['chart'] as $day) {
                $top = $day['topSource'];
                $dailyRows[] = [
                    Date::PHPToExcel(new \DateTimeImmutable($day['date'])), $day['views'], $day['sessions'], $day['campaignViews'],
                    $day['campaignShare'] / 100, $day['periodShare'] / 100,
                    null === $top ? '' : $this->sourceLabel($top['source']), $top['medium'] ?? '', $top['views'] ?? 0, ($top['share'] ?? 0) / 100,
                ];
            }
            $this->writeRows($daily, $dailyRows);
            $lastDay = 6 + count($dailyRows);
            $daily->getStyle('A7:A'.$lastDay)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            foreach (['E', 'F', 'J'] as $column) {
                $daily->getStyle($column.'7:'.$column.$lastDay)->getNumberFormat()->setFormatCode('0.0%');
            }
            $this->addChart($daily, 'Pages et sessions par jour', ['B' => 'FF5535', 'C' => '7561BF'], $lastDay, 'L6', 'Z23');
            $this->addChart($daily, 'Part quotidienne des pages avec campagne', ['E' => '23A26D'], $lastDay, 'L25', 'Z42', percentage: true);

            foreach (['sources' => ['Sources', $report['trafficSources']], 'campaigns' => ['Campagnes', $report['trafficCampaigns']]] as $kind => [$name, $contributions]) {
                $sheet = $book->createSheet()->setTitle($name);
                $isCampaign = 'campaigns' === $kind;
                $headers = $isCampaign
                    ? ['Campagne', 'Source', 'Support', 'Pages consultées', 'Part du trafic', 'Total des pages de la période']
                    : ['Source', 'Support', 'Pages consultées', 'Part du trafic', 'Total des pages de la période'];
                $this->prepareSheet($sheet, $name.' — Contribution au trafic', $context, $headers, $isCampaign ? [45, 30, 24, 21, 21, 29] : [35, 24, 21, 21, 29]);
                $rows = [];
                foreach ($contributions as $entry) {
                    $values = [$this->sourceLabel($entry['source']), $entry['medium'], $entry['views'], $entry['share'] / 100, $report['periodViews']];
                    $rows[] = $isCampaign ? [$entry['campaign'] ?: 'Sans campagne identifiée', ...$values] : $values;
                }
                $this->writeRows($sheet, $rows);
                $shareColumn = $isCampaign ? 'E' : 'D';
                if ([] !== $rows) {
                    $sheet->getStyle($shareColumn.'7:'.$shareColumn.(6 + count($rows)))->getNumberFormat()->setFormatCode('0.0%');
                    $this->addChart($sheet, $name.' — Part du trafic (10 premières lignes)', [$shareColumn => '23A26D'], 6 + min(10, count($rows)), 'H6', 'V27', percentage: true, horizontal: true);
                } else {
                    $sheet->setCellValue('A8', 'Aucune page consultée sur cette période.');
                }
            }

            $book->setActiveSheetIndex(0);
            $writer = new Xlsx($book);
            $writer->setIncludeCharts(true);
            $writer->save($filename);
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function prepareSheet(Worksheet $sheet, string $title, string $context, array $headers, array $widths): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->setShowGridlines(false);
        $sheet->mergeCells('A1:'.$lastColumn.'1')->setCellValue('A1', $title);
        $sheet->getRowDimension(1)->setRowHeight(38);
        $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '11110F']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->mergeCells('A3:'.$lastColumn.'3')->setCellValueExplicit('A3', $context, DataType::TYPE_STRING);
        $sheet->getStyle('A3')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(3)->setRowHeight(32);
        $this->writeRows($sheet, [$headers], 6);
        $sheet->getStyle('A6:'.$lastColumn.'6')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FF5535']],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(6)->setRowHeight(34);
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        $sheet->freezePane('A7');
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
    }

    private function writeRows(Worksheet $sheet, array $rows, int $start = 7): void
    {
        foreach ($rows as $index => $values) {
            $row = $start + $index;
            foreach ($values as $column => $value) {
                $cell = Coordinate::stringFromColumnIndex($column + 1).$row;
                if (is_string($value)) {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($cell, $value);
                }
            }
            $lastColumn = Coordinate::stringFromColumnIndex(count($values));
            $sheet->getRowDimension($row)->setRowHeight(25);
            $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getNumberFormat()->setFormatCode('#,##0');
            if (0 === $index % 2) {
                $sheet->getStyle('A'.$row.':'.$lastColumn.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F1EA');
            }
        }
        if (7 === $start) {
            $lastColumn = $sheet->getHighestDataColumn(6);
            $sheet->setAutoFilter('A6:'.$lastColumn.max(6, $start + count($rows) - 1));
        }
    }

    private function addChart(Worksheet $sheet, string $title, array $columns, int $lastRow, string $start, string $end, bool $percentage = false, bool $horizontal = false): void
    {
        $prefix = "'".$sheet->getTitle()."'!";
        $labels = $values = [];
        $count = $lastRow - 6;
        foreach ($columns as $column => $color) {
            $labels[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, $prefix.'$'.$column.'$6', null, 1);
            $values[] = new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, $prefix.'$'.$column.'$7:$'.$column.'$'.$lastRow, $percentage ? '0.0%' : '#,##0', $count, fillColor: $color);
        }
        $categories = new DataSeriesValues($horizontal ? DataSeriesValues::DATASERIES_TYPE_STRING : DataSeriesValues::DATASERIES_TYPE_NUMBER, $prefix.'$A$7:$A$'.$lastRow, $horizontal ? null : 'dd/mm', $count);
        $line = $percentage && !$horizontal;
        $series = new DataSeries(
            $line ? DataSeries::TYPE_LINECHART : DataSeries::TYPE_BARCHART,
            $line ? DataSeries::GROUPING_STANDARD : DataSeries::GROUPING_CLUSTERED,
            range(0, count($values) - 1),
            $labels,
            [$categories],
            $values,
            $horizontal ? DataSeries::DIRECTION_BAR : DataSeries::DIRECTION_COL,
        );
        $chart = new Chart('chart_'.$sheet->getTitle().'_'.$start, new Title($title), new Legend(Legend::POSITION_BOTTOM), new PlotArea(null, [$series]));
        if (!$horizontal) {
            $chart->getChartAxisX()->setAxisType(Axis::AXIS_TYPE_DATE);
            $chart->getChartAxisX()->setAxisNumberProperties('dd/mm');
        }
        $chart->getChartAxisY()->setAxisNumberProperties($percentage ? '0%' : '#,##0');
        $chart->getChartAxisY()->setAxisOptionsProperties('nextTo', minimum: 0, maximum: $percentage ? 1 : null);
        $chart->setTopLeftPosition($start)->setBottomRightPosition($end);
        $sheet->addChart($chart);
    }

    private function sourceLabel(string $source): string
    {
        return 'direct' === $source ? 'Accès direct' : $source;
    }
}
