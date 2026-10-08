#!/usr/bin/env python3
"""Inject completed public-harness job evidence into the Badges proof template."""

from __future__ import annotations

import html
import json
import os
import re
import sys
import urllib.request
from pathlib import Path

BADGE_DESIGN_SOURCE = "IamAngusU/Badges"
BADGE_DESIGN_SOURCE_COMMIT = "3ce6d01e32c7422e74755a37f86ba9478e47aafe"
SOURCE_JOB = re.compile(r"^Source ([0-9a-f]{40}) · package$")
TEMPLATE_PATH = Path(__file__).resolve().parents[1] / "docs" / "assets" / "ci-proof-template.svg"


def required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise SystemExit(f"missing {name}")
    return value


def github_json(url: str) -> dict:
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {required('GITHUB_TOKEN')}",
            "User-Agent": "MagicLink-ci-proof",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.load(response)


def load_jobs(repository: str, run_id: str) -> list[dict]:
    api = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")
    jobs: list[dict] = []
    page = 1
    while True:
        payload = github_json(f"{api}/repos/{repository}/actions/runs/{run_id}/jobs?per_page=100&page={page}")
        batch = payload.get("jobs", [])
        if not isinstance(batch, list):
            raise SystemExit("GitHub jobs response is malformed")
        jobs.extend(item for item in batch if isinstance(item, dict))
        if len(batch) < 100:
            break
        page += 1
        if page > 20:
            raise SystemExit("refusing to paginate more than 2000 CI jobs")
    jobs.sort(key=lambda job: (int(job.get("id") or 0), str(job.get("name") or "")))
    return jobs


def source_sha(jobs: list[dict]) -> str:
    matches = [match.group(1) for job in jobs if (match := SOURCE_JOB.fullmatch(str(job.get("name") or "")))]
    if len(matches) != 1:
        raise SystemExit("CI run does not identify exactly one source commit")
    return matches[0]


def proof_status(conclusion: str) -> str:
    value = conclusion.lower().strip()
    if value == "success":
        return "success"
    if value in {"failure", "timed_out", "action_required", "startup_failure"}:
        return "failure"
    if value in {"cancelled", "stale"}:
        return "warning"
    return "neutral"


def render_segments(statuses: list[str]) -> str:
    statuses = statuses or ["neutral"]
    left, right, gap = 101.0, 456.0, 8.0
    segment = (right - left - gap * (len(statuses) - 1)) / len(statuses)
    if segment < 3:
        gap = 3.0
        segment = (right - left - gap * (len(statuses) - 1)) / len(statuses)
    if segment <= 0:
        raise SystemExit("too many CI jobs for the proof rail")
    lines = []
    x = left
    for status in statuses:
        end = x + segment
        lines.append(f'<line class="proof-{status}" x1="{x:.2f}" y1="63" x2="{end:.2f}" y2="63"/>')
        x = end + gap
    return "\n    ".join(lines)


def render_svg(jobs: list[dict], source_commit: str, run_number: str) -> str:
    template = TEMPLATE_PATH.read_text(encoding="utf-8")
    if 'data-badge-system="IamAngusU/Badges"' not in template or 'id="proof-metric"' not in template or 'id="proof-segments"' not in template:
        raise SystemExit("CI proof template is missing badge-system anchors")
    passed = sum(1 for job in jobs if job.get("conclusion") == "success")
    metric = f"{passed}/{len(jobs)}" if jobs else "0/0"
    title = (
        f"MagicLink public CI harness: {metric} jobs passed for source {source_commit[:7]}, "
        f"run #{run_number}; exact public commit, same maintainer, not a third-party audit."
    )
    escaped = html.escape(title, quote=True)
    svg = re.sub(r'aria-label="[^"]*"', f'aria-label="{escaped}"', template, count=1)
    svg = re.sub(r"<title>.*?</title>", f"<title>{escaped}</title>", svg, count=1, flags=re.DOTALL)
    svg = re.sub(r'(<text id="proof-metric"[^>]*>).*?(</text>)', rf"\g<1>{html.escape(metric)}\g<2>", svg, count=1, flags=re.DOTALL)
    statuses = [proof_status(str(job.get("conclusion") or "")) for job in jobs]
    return re.sub(r'(<g id="proof-segments">).*?(</g>)', rf"\g<1>\n    {render_segments(statuses)}\n  \g<2>", svg, count=1, flags=re.DOTALL)


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: render-ci-proof.py OUTPUT.svg OUTPUT.json")
    repository = required("PROOF_REPOSITORY")
    run_id = required("PROOF_RUN_ID")
    run_number = required("PROOF_RUN_NUMBER")
    run_url = required("PROOF_RUN_URL")
    harness_sha = required("PROOF_HARNESS_SHA")
    jobs = load_jobs(repository, run_id)
    source_commit = source_sha(jobs)
    Path(sys.argv[1]).write_text(render_svg(jobs, source_commit, run_number), encoding="utf-8")
    evidence = {
        "claim": "separate public CI harness; exact public commit; same maintainer; not a third-party audit",
        "badge_design_source": BADGE_DESIGN_SOURCE,
        "badge_design_source_commit": BADGE_DESIGN_SOURCE_COMMIT,
        "repository": repository,
        "run_id": int(run_id),
        "run_number": int(run_number),
        "run_url": run_url,
        "source_repository": "IamAngusU/MagicLink",
        "source_sha": source_commit,
        "harness_sha": harness_sha,
        "jobs_total": len(jobs),
        "jobs_success": sum(1 for job in jobs if job.get("conclusion") == "success"),
        "jobs": [{"name": job.get("name"), "status": job.get("status"), "conclusion": job.get("conclusion")} for job in jobs],
    }
    Path(sys.argv[2]).write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
