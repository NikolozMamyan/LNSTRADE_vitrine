<?php

declare(strict_types=1);

namespace App\Prospect;

use App\Entity\Prospect;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final readonly class ProspectExporter
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function write(string $filename): void
    {
        $book = new Spreadsheet();
        try {
            $book->getProperties()->setCreator('LNSTRADE')->setTitle('Prospects synchronisés avec HubSpot');
            $sheet = $book->getActiveSheet()->setTitle('Prospects HubSpot');
            $sheet->fromArray(['Nom', 'Prénom', 'E-mail', 'Entreprise', 'Téléphone', 'Message']);
            $sheet->getStyle('A1:F1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            foreach (['A' => 24, 'B' => 24, 'C' => 36, 'D' => 36, 'E' => 24, 'F' => 80] as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }

            $prospects = $this->entityManager->createQueryBuilder()
                ->select('prospect.name', 'prospect.email', 'prospect.company', 'prospect.phone', 'prospect.message')
                ->from(Prospect::class, 'prospect')
                ->where('prospect.hubSpotSyncedAt IS NOT NULL')
                ->orderBy('prospect.lastSubmittedAt', 'DESC')
                ->addOrderBy('prospect.id', 'DESC')
                ->getQuery()->toIterable([], Query::HYDRATE_ARRAY);

            $row = 2;
            foreach ($prospects as $prospect) {
                [$firstname, $lastname] = array_pad(preg_split('/\s+/', $prospect['name'], 2) ?: [], 2, '');
                foreach ([$lastname, $firstname, $prospect['email'], $prospect['company'], $prospect['phone'] ?? '', $prospect['message'] ?? ''] as $column => $value) {
                    $sheet->setCellValueExplicit([$column + 1, $row], $value, DataType::TYPE_STRING);
                }
                ++$row;
            }

            $sheet->setAutoFilter('A1:F'.($row - 1));
            $sheet->getStyle('A1:F'.($row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            (new Xlsx($book))->save($filename);
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
