<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'catalog_page_element')]
#[ORM\Index(name: 'idx_catalog_element_page', columns: ['page_id'])]
class CatalogPageElement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'elements')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private CatalogPage $page,
        #[ORM\Column(length: 10)]
        private string $kind,
        #[ORM\Column(length: 2048)]
        private string $url,
        #[ORM\Column(length: 160)]
        private string $label,
        #[ORM\Column]
        private float $x,
        #[ORM\Column]
        private float $y,
        #[ORM\Column]
        private float $width,
        #[ORM\Column]
        private float $height,
        #[ORM\Column]
        private int $position,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getPage(): CatalogPage { return $this->page; }
    public function getKind(): string { return $this->kind; }
    public function getUrl(): string { return $this->url; }
    public function getLabel(): string { return $this->label; }
    public function getX(): float { return $this->x; }
    public function getY(): float { return $this->y; }
    public function getWidth(): float { return $this->width; }
    public function getHeight(): float { return $this->height; }
    public function getPosition(): int { return $this->position; }
}
