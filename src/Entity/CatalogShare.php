<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CatalogShareRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CatalogShareRepository::class)]
#[ORM\Table(name: 'catalog_share')]
#[ORM\Index(name: 'idx_catalog_share_catalog', columns: ['catalog_id'])]
#[ORM\UniqueConstraint(name: 'uniq_catalog_share_token', columns: ['token'])]
class CatalogShare
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $token;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Catalog $catalog,
        #[ORM\Column(length: 120)]
        private string $label,
    ) {
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCatalog(): Catalog { return $this->catalog; }
    public function getToken(): string { return $this->token; }
    public function getLabel(): string { return $this->label; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getRevokedAt(): ?\DateTimeImmutable { return $this->revokedAt; }
    public function isRevoked(): bool { return null !== $this->revokedAt; }
    public function revoke(): void { $this->revokedAt ??= new \DateTimeImmutable(); }
}
