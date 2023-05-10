<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Resources\translations\it_IT;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_it_IT implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'messages.it-IT';
    }

    public function getPath(): string
    {
        return __DIR__ . '/messages.it-IT.json';
    }

    public function getIso(): string
    {
        return 'it-IT';
    }

    public function getAuthor(): string
    {
        return 'Byjuno AG';
    }

    public function isBase(): bool
    {
        return false;
    }
}
