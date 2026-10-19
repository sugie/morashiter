#!/usr/bin/env python3
"""Extract already-fetched news HTML through a single JSON stdin/stdout exchange."""

import json
import re
import sys
from datetime import date
from urllib.parse import urldefrag, urljoin, urlsplit

from bs4 import BeautifulSoup

MAX_INPUT_BYTES = 2 * 1024 * 1024
MAX_OUTPUT_BYTES = 1024 * 1024
GENERIC_MIN_BODY_CHARS = 200
ARTICLE_TYPES = {"NewsArticle", "Article", "BlogPosting", "ReportageNewsArticle"}
MAX_OFFICIAL_LINKS = 20
NON_OFFICIAL_HOSTS = (
    "twitter.com", "x.com", "facebook.com", "instagram.com", "line.me", "youtube.com", "youtu.be",
    "linkedin.com", "pinterest.com", "b.hatena.ne.jp", "note.com", "doubleclick.net", "amazon.co.jp", "amazon.com",
)
PAGE_CHROME = "script, style, noscript, nav, aside, footer, header, form, iframe, h1, .share, .sns, .related, .ad, .ads"


def _lines(element) -> str:
    return "\n".join(line.strip() for line in element.get_text("\n", strip=True).splitlines() if line.strip())


def _host_matches(host: str, domain: str) -> bool:
    return host == domain or host.endswith("." + domain)


def official_links(container, page_url: str) -> list[dict]:
    """External links inside the article: candidates for the company's own announcement."""
    page_host = (urlsplit(page_url).hostname or "").lower()
    site = page_host[4:] if page_host.startswith("www.") else page_host
    links: dict[str, str] = {}
    for anchor in container.select("a[href]"):
        url, _fragment = urldefrag(urljoin(page_url, anchor["href"].strip()))
        parts = urlsplit(url)
        host = (parts.hostname or "").lower()
        if parts.scheme not in ("http", "https") or not host or _host_matches(host, site):
            continue
        if any(_host_matches(host, domain) for domain in NON_OFFICIAL_HOSTS) or url in links:
            continue
        links[url] = re.sub(r"\s+", " ", anchor.get_text(" ", strip=True))[:200]
        if len(links) >= MAX_OFFICIAL_LINKS:
            break
    return [{"url": url, "text": text} for url, text in links.items()]


def _local_date(value) -> str | None:
    """Date part of an ISO timestamp as written (no timezone conversion)."""
    if not isinstance(value, str):
        return None
    try:
        return date.fromisoformat(value.strip()[:10]).isoformat()
    except ValueError:
        return None


def extract_fixture_news(soup: BeautifulSoup, url: str) -> dict:
    article = soup.select_one("article")
    if article is None:
        raise ValueError("article element is required")
    heading = article.select_one("h1")
    if heading is None:
        raise ValueError("article heading is required")
    title = heading.get_text(" ", strip=True)
    time_element = article.select_one("time[datetime]")
    published_on = _local_date(time_element.get("datetime", "")) if time_element is not None else None

    for element in article.select("script, style, nav, aside, footer, form"):
        element.decompose()
    body = article.select_one(".article-body") or article
    return {
        "extraction_version": "fixture_news_v2",
        "title": title,
        "published_on": published_on,
        "body_chars": len(_lines(body)),
        "official_links": official_links(body, url),
    }


def _jsonld_articles(soup: BeautifulSoup) -> list[dict]:
    found = []
    for script in soup.select('script[type="application/ld+json"]'):
        try:
            data = json.loads(script.string or "")
        except json.JSONDecodeError:
            continue
        stack = [data]
        while stack:
            node = stack.pop()
            if isinstance(node, list):
                stack.extend(node)
            elif isinstance(node, dict):
                kinds = node.get("@type")
                kinds = set(kinds) if isinstance(kinds, list) else {kinds}
                if kinds & ARTICLE_TYPES:
                    found.append(node)
                stack.extend(node.get("@graph", []) if isinstance(node.get("@graph"), list) else [])
    return found


def _meta(soup: BeautifulSoup, key: str) -> str | None:
    tag = soup.select_one(f'meta[property="{key}"], meta[name="{key}"]')
    content = tag.get("content", "").strip() if tag is not None else ""
    return content or None


def extract_generic_article(soup: BeautifulSoup, url: str) -> dict:
    jsonld = _jsonld_articles(soup)
    headline = next((a["headline"].strip() for a in jsonld if isinstance(a.get("headline"), str) and a["headline"].strip()), None)
    published = next((d for d in (_local_date(a.get("datePublished")) for a in jsonld) if d), None)

    container = soup.select_one('[itemprop="articleBody"]') or soup.select_one("article") or soup.select_one("main")
    if container is None:
        raise ValueError("article body not found")

    title = headline or _meta(soup, "og:title")
    if title is None:
        heading = container.select_one("h1") or soup.select_one("h1")
        title = heading.get_text(" ", strip=True) if heading is not None else None
    if not title:
        raise ValueError("title not found")

    if published is None:
        published = _local_date(_meta(soup, "article:published_time"))
    if published is None:
        time_element = container.select_one("time[datetime]")
        published = _local_date(time_element.get("datetime")) if time_element is not None else None

    for element in container.select(PAGE_CHROME):
        element.decompose()
    body_chars = len(_lines(container))
    if body_chars < GENERIC_MIN_BODY_CHARS:
        raise ValueError("body_text is too short")
    return {
        "extraction_version": "generic_article_v2",
        "title": title,
        "published_on": published,
        "body_chars": body_chars,
        "official_links": official_links(container, url),
    }


ADAPTERS = {
    "fixture_news": extract_fixture_news,
    "generic_article": extract_generic_article,
}


def extract(payload: dict) -> dict:
    adapter_code = payload.get("adapter_code")
    adapter = ADAPTERS.get(adapter_code)
    if adapter is None:
        raise ValueError("unsupported adapter_code")
    html = payload.get("html")
    url = payload.get("url")
    if not isinstance(html, str) or not html.strip():
        raise ValueError("html is required")
    if not isinstance(url, str) or not (url.startswith("https://") or url.startswith("http://")):
        raise ValueError("http(s) url is required")

    # The article body is only measured, never returned: msnc keeps no copy of news text.
    result = adapter(BeautifulSoup(html, "lxml"), url)
    if not result["title"] or not result["body_chars"]:
        raise ValueError("title and body_text are required")
    return {"adapter_code": adapter_code, "url": url, **result}


def main() -> None:
    raw = sys.stdin.buffer.read(MAX_INPUT_BYTES + 1)
    if len(raw) > MAX_INPUT_BYTES:
        raise ValueError("input exceeds 2 MiB")
    payload = json.loads(raw)
    if not isinstance(payload, dict):
        raise ValueError("input must be a JSON object")
    result = json.dumps(extract(payload), ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    if len(result) > MAX_OUTPUT_BYTES:
        raise ValueError("output exceeds 1 MiB")
    sys.stdout.buffer.write(result + b"\n")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, UnicodeError, json.JSONDecodeError) as exc:
        print(f"extractor error: {exc}", file=sys.stderr)
        sys.exit(1)
