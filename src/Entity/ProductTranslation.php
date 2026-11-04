<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'shop_product_translation')]
#[ORM\UniqueConstraint(name: 'uniq_shop_product_locale', columns: ['product_id', 'locale'])]
class ProductTranslation
{
    public const LOCALES = ['en', 'de', 'es', 'nb', 'nl'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(name: "character_name", length: 120)]
    #[Assert\Length(max: 120)]
    private string $character = '';

    #[ORM\Column(length: 180)]
    #[Assert\Length(max: 180)]
    private string $flavor = '';

    #[ORM\Column(length: 40)]
    #[Assert\Length(max: 40)]
    private string $format = '';

    #[ORM\Column(length: 120)]
    #[Assert\Length(max: 120)]
    private string $family = '';

    #[ORM\Column(type: 'text')]
    #[Assert\Length(max: 20000)]
    private string $description = '';

    #[ORM\Column(type: 'text')]
    #[Assert\Length(max: 30000)]
    private string $ingredients = '';

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'translations')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Product $product,
        #[ORM\Column(length: 5)]
        #[Assert\Choice(choices: self::LOCALES)]
        private string $locale,
    ) {
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): Product { return $this->product; }
    public function getLocale(): string { return $this->locale; }

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

    public function getFamily(): string
    {
        return $this->family;
    }

    public function setFamily(string $family): self
    {
        $this->family = trim($family);

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

}
