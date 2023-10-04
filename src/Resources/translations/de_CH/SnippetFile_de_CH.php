<?php

declare(strict_types=1);

namespace Byjuno\ByjunoPayments\Resources\translations\de_CH;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class SnippetFile_de_CH implements SnippetFileInterface
{
    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'messages.de-CH';
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return __DIR__ . '/messages.de-CH.json';
    }

    /**
     * {@inheritdoc}
     */
    public function getIso(): string
    {
        return 'de-CH';
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthor(): string
    {
        return 'CembraPay';
    }

    /**
     * {@inheritdoc}
     */
    public function isBase(): bool
    {
        return false;
    }
}
