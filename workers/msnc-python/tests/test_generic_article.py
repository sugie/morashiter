"""Tests for the generic_article adapter used for real news sites.

The adapter reads metadata from JSON-LD first, then Open Graph / article meta,
and takes the body from the article container with page chrome removed.
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

EXTRACTOR = Path(__file__).resolve().parent.parent / "src" / "extractor.py"
FIXTURE_DIR = Path(__file__).resolve().parent / "fixtures" / "generic"


def run_generic(fixture: str, url: str = "https://news.example.test/articles/1") -> subprocess.CompletedProcess:
    payload = {
        "adapter_code": "generic_article",
        "html": (FIXTURE_DIR / fixture).read_text(encoding="utf-8"),
        "url": url,
    }
    return subprocess.run(
        [sys.executable, str(EXTRACTOR)],
        input=json.dumps(payload).encode("utf-8"),
        capture_output=True,
        timeout=10,
    )


def ok(result: subprocess.CompletedProcess) -> dict:
    assert result.returncode == 0, result.stderr.decode()
    return json.loads(result.stdout.decode())


def test_jsonld_headline_and_published_date_win_over_other_metadata() -> None:
    data = ok(run_generic("jsonld_article.html"))

    assert data["adapter_code"] == "generic_article"
    assert data["extraction_version"] == "generic_article_v2"
    assert data["title"] == "テスト株式会社、不正アクセスで顧客情報が漏えいした可能性"
    # Local date of datePublished (01:30 JST), not converted to UTC, and never dateModified.
    assert data["published_on"] == "2026-10-15"


def _paragraphs_chars(count: int) -> int:
    paragraphs = [
        f"テスト株式会社は、第{i}段落として不正アクセスによる個人情報漏えいの可能性について公表しました。対象者には個別に連絡するとしています。"
        for i in range(1, count + 1)
    ]
    return len("\n".join(paragraphs))


def test_article_body_is_measured_without_page_chrome_but_never_returned() -> None:
    data = ok(run_generic("jsonld_article.html"))

    assert "body_text" not in data
    # Only the four paragraphs count: no menu, share buttons, aside, script, footer or h1.
    assert data["body_chars"] == _paragraphs_chars(4)
    assert data["official_links"] == []


def test_external_links_in_the_article_become_official_link_candidates() -> None:
    data = ok(run_generic("links_article.html", url="https://news.example.test/articles/1"))

    # Same-site, subdomain, social/share, javascript:, mailto:, ftp:, nav and footer links are dropped;
    # fragments are removed and duplicates collapse to the first anchor text.
    assert data["official_links"] == [
        {"url": "https://www.test-corp.example.co.jp/news/20261016.html", "text": "同社の発表"},
        {"url": "https://www.test-corp.example.co.jp/ir/notice.pdf", "text": "お知らせ（PDF）"},
    ]


def test_open_graph_and_article_meta_are_used_without_jsonld() -> None:
    data = ok(run_generic("og_article.html"))

    assert data["title"] == "サンプル社、ランサムウェア被害を公表"
    assert data["published_on"] == "2026-10-14"
    assert data["body_chars"] == _paragraphs_chars(4)


def test_too_short_body_is_rejected() -> None:
    result = run_generic("short_article.html")

    assert result.returncode != 0
    assert b"body_text is too short" in result.stderr


def test_page_without_an_article_container_is_rejected() -> None:
    result = run_generic("no_article.html")

    assert result.returncode != 0
    assert b"article body not found" in result.stderr


def test_fixture_news_adapter_still_works() -> None:
    payload = {
        "adapter_code": "fixture_news",
        "html": (FIXTURE_DIR.parent / "sample_news.html").read_text(encoding="utf-8"),
        "url": "http://fixture-news/incidents/incident-001.html",
    }
    result = subprocess.run(
        [sys.executable, str(EXTRACTOR)],
        input=json.dumps(payload).encode("utf-8"),
        capture_output=True,
        timeout=10,
    )

    assert ok(result)["extraction_version"] == "fixture_news_v2"
