<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Resources\translations\fr_FR;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_fr_FR implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'messages.fr-FR';
    }

    public function getPath(): string
    {
        return __DIR__ . '/messages.fr-FR.json';
    }

    public function getIso(): string
    {
        return 'fr-FR';
    }

    public function getAuthor(): string
    {
        return 'CembraPay';
    }

    public function isBase(): bool
    {
        return false;
    }
}
