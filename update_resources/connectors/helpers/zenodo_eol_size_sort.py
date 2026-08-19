#!/usr/bin/env python3
"""
List Zenodo records in the 'eol' community tagged with keywords
'textmining' or 'traits', sorted descending by total attached-file size.

Zenodo's search API has no server-side "sort by file size" option, so this
fetches all matching records (paginated) and sorts them client-side.

Usage:
    pip install requests
    python zenodo_eol_size_sort.py

Config is in the CONFIG block below.
"""

import csv
import sys
import time

import requests

# ---- CONFIG ----------------------------------------------------------
COMMUNITY = "eol"
KEYWORDS = ["textmining", "traits"]   # OR match: record kept if ANY keyword matches
MATCH_MODE = "any"                    # "any" = OR, "all" = AND
PAGE_SIZE = 25                        # results per API page; unauthenticated requests
                                       # are capped at 25 (set ACCESS_TOKEN to raise to 100)
ACCESS_TOKEN = None                   # set your Zenodo token string if you need
                                       # access to restricted/embargoed records, or to
                                       # raise PAGE_SIZE above 25
OUTPUT_CSV = "zenodo_eol_by_size.csv"
BASE_URL = "https://zenodo.org/api/records"
# ------------------------------------------------------------------------


def fetch_community_records(community):
    """Fetch all records for a community, following cursor pagination."""
    headers = {}
    if ACCESS_TOKEN:
        headers["Authorization"] = f"Bearer {ACCESS_TOKEN}"

    params = {
        "communities": community,
        "size": PAGE_SIZE,
        "sort": "mostrecent",
    }

    url = BASE_URL
    all_hits = []
    page_num = 1

    while url:
        resp = requests.get(url, params=params if url == BASE_URL else None, headers=headers)
        if resp.status_code != 200:
            print(f"Error {resp.status_code} on page {page_num}: {resp.text[:300]}", file=sys.stderr)
            break

        data = resp.json()
        hits = data.get("hits", {}).get("hits", [])
        if not hits:
            break

        all_hits.extend(hits)
        print(f"  fetched page {page_num}: {len(hits)} records (running total: {len(all_hits)})")

        url = data.get("links", {}).get("next")  # None once there's no next page
        page_num += 1
        time.sleep(0.2)  # be polite to the API

    return all_hits


def matches_keywords(rec, keywords, mode="any"):
    rec_keywords = rec.get("metadata", {}).get("keywords") or []
    rec_keywords_lower = {kw.lower() for kw in rec_keywords}
    wanted = {kw.lower() for kw in keywords}

    if mode == "all":
        return wanted.issubset(rec_keywords_lower)
    return bool(wanted & rec_keywords_lower)  # any overlap


def main():
    print(f"Fetching records for community='{COMMUNITY}' ...")
    hits = fetch_community_records(COMMUNITY)
    print(f"\nTotal records in community: {len(hits)}")

    filtered = [r for r in hits if matches_keywords(r, KEYWORDS, MATCH_MODE)]
    print(f"Records matching keywords {KEYWORDS} ({MATCH_MODE}): {len(filtered)}\n")

    records = []
    for rec in filtered:
        files = rec.get("files") or []
        total_size = sum(f.get("size", 0) for f in files)
        records.append({
            "id": rec.get("id"),
            "doi": rec.get("doi") or rec.get("metadata", {}).get("doi", ""),
            "title": rec.get("metadata", {}).get("title", "(no title)"),
            "keywords": ", ".join(rec.get("metadata", {}).get("keywords") or []),
            "total_size": total_size,
            "n_files": len(files),
            "url": (rec.get("links", {}) or {}).get("self_html")
                   or (rec.get("links", {}) or {}).get("html", ""),
        })

    records.sort(key=lambda r: r["total_size"], reverse=True)

    # ---- print table ----
    print(f"{'Size (bytes)':>15}  {'Files':>5}  {'ID':>10}  Title")
    print("-" * 100)
    for r in records:
        print(f"{r['total_size']:>15,}  {r['n_files']:>5}  {r['id']:>10}  {r['title'][:65]}")

    # ---- write CSV ----
    with open(OUTPUT_CSV, "w", newline="", encoding="utf-8") as f:
        writer = csv.writer(f)
        writer.writerow(["rank", "id", "doi", "total_size_bytes", "n_files", "keywords", "title", "url"])
        for i, r in enumerate(records, 1):
            writer.writerow([i, r["id"], r["doi"], r["total_size"], r["n_files"],
                              r["keywords"], r["title"], r["url"]])

    print(f"\nWrote {len(records)} records to {OUTPUT_CSV}")


if __name__ == "__main__":
    main()
