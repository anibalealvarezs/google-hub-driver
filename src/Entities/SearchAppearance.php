<?php

    namespace Anibalealvarezs\GoogleHubDriver\Entities;

    use DateTime;
    use Doctrine\ORM\Mapping as ORM;
    use Anibalealvarezs\GoogleApi\Services\SearchConsole\Enums\SearchAppearance as SearchAppearanceEnum;

    #[ORM\Entity]
    #[ORM\Table(name: 'search_appearances')]
    #[ORM\UniqueConstraint(name: 'search_appearance_type_unique', columns: ['type'])]
    #[ORM\HasLifecycleCallbacks]
    class SearchAppearance
    {
        #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue(strategy: 'IDENTITY')]
        protected ?int $id = null;

        #[ORM\Column(type: 'string', enumType: SearchAppearanceEnum::class)]
        protected SearchAppearanceEnum $type;

        #[ORM\Column(name: 'created_at', type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
        protected DateTime $createdAt;

        #[ORM\Column(name: 'updated_at', type: 'datetime', options: ['default' => 'CURRENT_TIMESTAMP'])]
        protected DateTime $updatedAt;

        public function getId(): ?int
        {
            return $this->id;
        }

        public function addType(SearchAppearanceEnum $type): self
        {
            $this->type = $type;

            return $this;
        }

        public function getType(): SearchAppearanceEnum
        {
            return $this->type;
        }

        #[ORM\PrePersist]
        public function onPrePersist(): void
        {
            $this->createdAt = new DateTime();
            $this->updatedAt = new DateTime();
        }

        #[ORM\PreUpdate]
        public function onPreUpdate(): void
        {
            $this->updatedAt = new DateTime();
        }
    }
