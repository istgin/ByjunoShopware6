<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Resources\translations\en_GB;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_en_GB implements SnippetFileInterface
{
    public function getName(): string
    {
        return 'messages.en-GB';
    }

    public function getPath(): string
    {
        return __DIR__ . '/messages.en-GB.json';
    }

    public function getIso(): string
    {
        return 'en-GB';
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
