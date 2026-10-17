<?php

namespace App\Services\Crawling;

class DiscoveredArticle
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly ?string $publishedAt,
        public readonly ?string $rawId,
    ) {
    }
}
