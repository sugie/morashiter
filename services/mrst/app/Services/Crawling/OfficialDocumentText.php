<?php

namespace App\Services\Crawling;

use DOMDocument;
use DOMXPath;

/**
 * Plain text of an official HTML announcement for analysis, without scripts or page chrome.
 */
class OfficialDocumentText
{
    private const CHROME = ['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'template'];

    public function fromHtml(string $html): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        foreach (self::CHROME as $tag) {
            foreach (iterator_to_array($xpath->query("//{$tag}")) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        foreach (['p', 'h1', 'h2', 'h3', 'h4', 'li', 'tr', 'div', 'br', 'dt', 'dd'] as $block) {
            foreach (iterator_to_array($xpath->query("//{$block}")) as $node) {
                $node->appendChild($document->createTextNode("\n"));
            }
        }

        $container = $xpath->query('//main')->item(0)
            ?? $xpath->query('//article')->item(0)
            ?? $xpath->query('//body')->item(0);
        $text = (string) $container?->textContent;
        $lines = array_filter(array_map(
            fn (string $line): string => trim((string) preg_replace('/[ \t\x{3000}]+/u', ' ', $line)),
            preg_split('/\R/u', $text) ?: [],
        ), fn (string $line): bool => $line !== '');

        return implode("\n", $lines);
    }
}
