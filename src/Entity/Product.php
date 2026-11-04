<?php

declare(strict_types=1);

namespace App\Entity;

use App\Product\ProductPricing;
use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'shop_product')]
#[UniqueEntity(fields: ['reference'], message: 'Cette référence existe déjà.')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    #[Assert\Regex("/^[A-Za-z0-9][A-Za-z0-9_. -]{0,63}$/D")]
    private string $reference = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(name: "character_name", length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $character = '';

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $flavor = '';

    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    private string $format = '';

    #[ORM\Column(type: "text")]
    #[Assert\Length(max: 20000)]
    private string $description = '';

    #[ORM\Column(type: "text")]
    #[Assert\Length(max: 30000)]
    private string $ingredients = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $license = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $family = '';

    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: ["drinks", "snacks", "sweet"])]
    private string $categoryGroup = 'drinks';

    #[ORM\Column(length: 13, nullable: true)]
    #[Assert\Regex("/^(?:[0-9]{8}|[0-9]{13})$/D")]
    private ?string $ean = null;

    #[ORM\Column(type: "decimal", precision: 14, scale: 6)]
    private string $unitPriceExclTax = '0.000000';

    #[ORM\Column]
    #[Assert\Range(min: 0, max: 10000)]
    private int $taxRateBasisPoints = 0;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 10000)]
    private ?int $pcb = null;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(10000000)]
    private ?int $stockCartons = null;

    #[ORM\Column]
    private bool $published = false;

    #[ORM\Column]
    private bool $available = true;

    #[ORM\Column]
    private bool $bestseller = false;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    #[Assert\LessThanOrEqual(1000000)]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductImage::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending, 'id' => \SortDirection::Ascending])]
    #[Assert\Valid]
    private Collection $images;

    /** @var Collection<int, ProductTranslation> */
    #[ORM\OneToMany(mappedBy: 'product', targetEntity: ProductTranslation::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['locale' => \SortDirection::Ascending])]
    #[Assert\Valid]
    private Collection $translations;

    public function __construct()
    {
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->images = new ArrayCollection();
        $this->translations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): self
    {
        $this->reference = trim($reference);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getCharacter(): string
    {
        return $this->character;
    }

    public function setCharacter(string $character): self
    {
        $this->character = trim($character);

        return $this;
    }

    public function getFlavor(): string
    {
        return $this->flavor;
    }

    public function setFlavor(string $flavor): self
    {
        $this->flavor = trim($flavor);

        return $this;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): self
    {
        $this->format = trim($format);

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = trim($description);

        return $this;
    }

    public function getIngredients(): string
    {
        return $this->ingredients;
    }

    public function setIngredients(string $ingredients): self
    {
        $this->ingredients = trim($ingredients);

        return $this;
    }

    public function getLicense(): string
    {
        return $this->license;
    }

    public function setLicense(string $license): self
    {
        $this->license = trim($license);

        return $this;
    }

    public function getFamily(): string
    {
        return $this->family;
    }

    public function setFamily(string $family): self
    {
        $this->family = trim($family);

        return $this;
    }

    public function getCategoryGroup(): string
    {
        return $this->categoryGroup;
    }

    public function setCategoryGroup(string $categoryGroup): self
    {
        $this->categoryGroup = trim($categoryGroup);

        return $this;
    }

    public function getEan(): ?string
    {
        return $this->ean;
    }

    public function setEan(?string $ean): self
    {
        $this->ean = null === $ean || '' === trim($ean) ? null : trim($ean);

        return $this;
    }

    public function getUnitPriceExclTax(): string
    {
        return ProductPricing::normalize($this->unitPriceExclTax);
    }

    public function setUnitPriceExclTax(string $unitPriceExclTax): self
    {
        $this->unitPriceExclTax = ProductPricing::normalize($unitPriceExclTax);

        return $this;
    }

    public function getTaxRateBasisPoints(): int
    {
        return $this->taxRateBasisPoints;
    }

    public function setTaxRateBasisPoints(int $taxRateBasisPoints): self
    {
        $this->taxRateBasisPoints = $taxRateBasisPoints;

        return $this;
    }

    public function getPcb(): ?int
    {
        return $this->pcb;
    }

    public function setPcb(?int $pcb): self
    {
        $this->pcb = $pcb;

        return $this;
    }

    public function getStockCartons(): ?int
    {
        return $this->stockCartons;
    }

    public function setStockCartons(?int $stockCartons): self
    {
        $this->stockCartons = $stockCartons;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): self
    {
        $this->published = $published;

        return $this;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function setAvailable(bool $available): self
    {
        $this->available = $available;

        return $this;
    }

    public function isBestseller(): bool
    {
        return $this->bestseller;
    }

    public function setBestseller(bool $bestseller): self
    {
        $this->bestseller = $bestseller;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isPurchasable(): bool
    {
        return $this->published && $this->available && (null === $this->stockCartons || $this->stockCartons > 0);
    }

    public function getUnitPriceInclTax(): string
    {
        return ProductPricing::includingTax($this->unitPriceExclTax, $this->taxRateBasisPoints);
    }

    public function getPricing(): array
    {
        return ProductPricing::amounts($this->unitPriceExclTax, $this->taxRateBasisPoints, $this->pcb);
    }

    /** @return Collection<int, ProductImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(ProductImage $image): self
    {
        if (!$this->images->contains($image)) $this->images->add($image);

        return $this;
    }

    public function removeImage(ProductImage $image): self
    {
        $this->images->removeElement($image);

        return $this;
    }

    /** @return Collection<int, ProductTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(ProductTranslation $translation): self
    {
        if (!$this->translations->contains($translation)) $this->translations->add($translation);

        return $this;
    }

    public function translation(string $locale): ?ProductTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLocale() === $locale) return $translation;
        }

        return null;
    }
}
