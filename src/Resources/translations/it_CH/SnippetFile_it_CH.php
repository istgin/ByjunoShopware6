<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Resources\translations\it_CH;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_it_CH implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'messages.it-CH';
    }

    public function getPath(): string
    {
        return __DIR__ . '/messages.it-CH.json';
    }

    public function getIso(): string
    {
        return 'it-CH';
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
