"""End-to-end tests for the msnc Python extractor.

Each test runs extractor.py as a subprocess, mirroring how Laravel invokes it
through PythonArticleExtractor so stdin/stdout contract and exit codes are
exercised exactly as in production.
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

EXTRACTOR = Path(__file__).resolve().parent.parent / "src" / "extractor.py"
FIXTURE_DIR = Path(__file__).resolve().parent / "fixtures"


def run_extractor(payload: dict) -> subprocess.CompletedProcess:
    return subprocess.run(
        [sys.executable, str(EXTRACTOR)],
        input=json.dumps(payload).encode("utf-8"),
        capture_output=True,
        timeout=10,
    )


def _sample_html() -> str:
    return (FIXTURE_DIR / "sample_news.html").read_text(encoding="utf-8")


def test_sample_news_html_extracts_title_date_and_body() -> None:
    result = run_extractor({
        "adapter_code": "fixture_news",
        "html": _sample_html(),
        "url": "https://news.example.test/incidents/sample",
    })

    assert result.returncode == 0, result.stderr.decode()
    data = json.loads(result.stdout.decode())
    assert data["adapter_code"] == "fixture_news"
    assert data["title"] == "テスト企業、システム障害について発表"
    assert data["published_on"] == "2026-10-02"
    assert data["body_text"] == "テスト企業は、システム障害を公表しました。\n影響範囲は現在調査中です。"
    assert data["extraction_version"] == "fixture_news_v1"


def test_http_url_is_accepted_for_local_fixtures() -> None:
    result = run_extractor({
        "adapter_code": "fixture_news",
        "html": _sample_html(),
        "url": "http://fixture-news/incidents/incident-001.html",
    })

    assert result.returncode == 0, result.stderr.decode()


def test_unknown_adapter_is_rejected() -> None:
    result = run_extractor({
        "adapter_code": "unknown",
        "html": _sample_html(),
        "url": "https://news.example.test/x",
    })

    assert result.returncode != 0
    assert b"unsupported adapter_code" in result.stderr


def test_non_http_scheme_is_rejected() -> None:
    result = run_extractor({
        "adapter_code": "fixture_news",
        "html": _sample_html(),
        "url": "ftp://news.example.test/x",
    })

    assert result.returncode != 0


def test_missing_article_element_fails() -> None:
    result = run_extractor({
        "adapter_code": "fixture_news",
        "html": "<html><body>no article</body></html>",
        "url": "https://news.example.test/x",
    })

    assert result.returncode != 0


def test_input_without_html_fails() -> None:
    result = run_extractor({
        "adapter_code": "fixture_news",
        "html": "",
        "url": "https://news.example.test/x",
    })

    assert result.returncode != 0
    assert b"html is required" in result.stderr
