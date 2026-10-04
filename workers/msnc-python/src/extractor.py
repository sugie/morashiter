#!/usr/bin/env python3
"""Extract already-fetched news HTML through a single JSON stdin/stdout exchange."""

import json
import sys
from datetime import date

from bs4 import BeautifulSoup

MAX_INPUT_BYTES = 2 * 1024 * 1024
MAX_OUTPUT_BYTES = 1024 * 1024
ADAPTER_CODE = "fixture_news"
EXTRACTION_VERSION = "fixture_news_v1"


def extract(payload: dict) -> dict:
    if payload.get("adapter_code") != ADAPTER_CODE:
        raise ValueError("unsupported adapter_code")
    html = payload.get("html")
    url = payload.get("url")
    if not isinstance(html, str) or not html.strip():
        raise ValueError("html is required")
    if not isinstance(url, str) or not url.startswith("https://"):
        raise ValueError("https url is required")

    soup = BeautifulSoup(html, "lxml")
    article = soup.select_one("article")
    if article is None:
        raise ValueError("article element is required")
    heading = article.select_one("h1")
    if heading is None:
        raise ValueError("article heading is required")
    title = heading.get_text(" ", strip=True)
    time_element = article.select_one("time[datetime]")
    published_on = None
    if time_element is not None:
        candidate = time_element.get("datetime", "")[:10]
        try:
            published_on = date.fromisoformat(candidate).isoformat()
        except ValueError:
            pass

    for element in article.select("script, style, nav, aside, footer, form"):
        element.decompose()
    body = article.select_one(".article-body") or article
    body_text = "\n".join(
        line.strip() for line in body.get_text("\n", strip=True).splitlines() if line.strip()
    )
    if not title or not body_text:
        raise ValueError("title and body_text are required")
    return {
        "adapter_code": ADAPTER_CODE,
        "extraction_version": EXTRACTION_VERSION,
        "url": url,
        "title": title,
        "published_on": published_on,
        "body_text": body_text,
    }


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
