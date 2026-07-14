#!/usr/bin/env python3
"""
Scraper for Controller EMEA - Cirrus SR22-G6 Turbo listings.
Fetches all pages, parses listings, updates cirrus_data.json.
- New registrations: added
- Price/hours changed: updated
- Not found anymore: deleted
"""

import json
import os
import re
import time
from datetime import datetime, timezone

import requests
from bs4 import BeautifulSoup

BASE_URL = (
    "https://www.controlleremea.co.uk/listings/search"
    "?Model=SR22-G6%20TURBO"
    "&ListingType=For%20Retail"
    "&Manufacturer=CIRRUS"
    "&Price=1%2A900000"
    "&Year=2020%2A"
    "&TotalTime=%2A900"
)
TOTAL_PAGES = 3
DATA_FILE   = "cirrus_data.json"

USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) Gecko/20100101 Firefox/124.0",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15",
]


def make_session() -> requests.Session:
    session = requests.Session()
    # Visit homepage first to collect cookies like a real browser
    try:
        session.get(
            "https://www.controlleremea.co.uk/",
            headers={
                "User-Agent": USER_AGENTS[0],
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
                "Accept-Language": "en-GB,en;q=0.9",
                "Accept-Encoding": "gzip, deflate, br",
                "Connection": "keep-alive",
                "Upgrade-Insecure-Requests": "1",
            },
            timeout=15,
        )
        print("  Homepage prefetch OK")
        time.sleep(2)
    except Exception as e:
        print(f"  Homepage prefetch failed (non-fatal): {e}")
    return session


def fetch_page(session: requests.Session, url: str, retries: int = 4) -> str:
    for attempt in range(retries):
        ua = USER_AGENTS[attempt % len(USER_AGENTS)]
        headers = {
            "User-Agent": ua,
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language": "en-GB,en;q=0.9",
            "Accept-Encoding": "gzip, deflate, br",
            "Connection": "keep-alive",
            "Upgrade-Insecure-Requests": "1",
            "Sec-Fetch-Dest": "document",
            "Sec-Fetch-Mode": "navigate",
            "Sec-Fetch-Site": "same-origin",
            "Sec-Fetch-User": "?1",
            "Referer": "https://www.controlleremea.co.uk/listings/for-sale/cirrus/aircraft",
        }
        try:
            r = session.get(url, headers=headers, timeout=30)
            print(f"  Attempt {attempt+1}: HTTP {r.status_code} ({len(r.text)} bytes)")
            if r.status_code == 200:
                return r.text
            time.sleep(5 + attempt * 3)
        except Exception as e:
            print(f"  Error on attempt {attempt+1}: {e}")
            time.sleep(5)
    return ""


def parse_listings(html: str) -> list:
    if not html:
        return []

    soup = BeautifulSoup(html, "lxml")
    listings = []

    # Find all listing blocks
    blocks = []
    for h2 in soup.find_all("h2"):
        link = h2.find("a", href=re.compile(r"/listing/for-retail/"))
        if link and h2.parent:
            blocks.append(h2.parent)

    print(f"  Found {len(blocks)} listing blocks")

    for block in blocks:
        text = block.get_text(" ", strip=True)

        # Year
        h2 = block.find("h2")
        year = ""
        if h2:
            m = re.search(r"\b(20\d{2})\b", h2.get_text())
            year = m.group(1) if m else ""

        # Registration
        m = re.search(r"Registration\s*#\s*:?\s*([A-Z0-9\-]+(?:\s*\([^)]+\))?)", text, re.I)
        reg = re.sub(r"\s+", " ", m.group(1).strip()) if m else ""

        # Hours
        m = re.search(r"Total\s*Time\s*:?\s*([\d,.]+)", text, re.I)
        hours = float(m.group(1).replace(",", "")) if m else None

        # Price USD
        m = re.search(r"Price\s*:?\s*USD\s*\$?([\d,]+)", text, re.I)
        price_usd = int(m.group(1).replace(",", "")) if m else None

        # Price display
        if price_usd:
            price_display = f"${price_usd:,}"
        else:
            m = re.search(r"GBP\s*£([\d,]+)", text, re.I)
            price_display = f"£{m.group(1)} GBP" if m else "Call"

        # Location
        m = re.search(r"Location\s*:\s*([^\n\r<]+)", text, re.I)
        location = m.group(1).strip() if m else ""

        # Serial
        m = re.search(r"Serial\s*(?:Number)?\s*:?\s*(\d+)", text, re.I)
        serial = m.group(1) if m else ""

        # FIKI
        if re.search(r"\bFIKI\b", text, re.I):
            fiki = "Confirmed"
        elif re.search(r"\b(Platinum|GTS|fully\s*loaded|all\s*(available\s*)?options|all\s*desirable|fully\s*equipped|every\s*option)\b", text, re.I):
            fiki = "Likely"
        else:
            fiki = "No mention"

        # Warranty
        warranty = "No"
        warranty_note = ""
        patterns = [
            r"(spinner.to.tail\s*warrant\w*\s*(?:through|thru|until|till)?\s*[\w\s,]+?\d{4})",
            r"(\d+\s*(?:yr|year)\s*warrant\w*)",
            r"(warrant\w*\s*(?:through|thru|until|till|remaining)\s*[\w\s,\/]+)",
            r"(CMX\s*&\s*WARRANTY\s*THRU\s*[\d\/]+)",
        ]
        for p in patterns:
            m = re.search(p, text, re.I)
            if m:
                warranty = "Yes"
                warranty_note = m.group(1).strip()
                break
        if warranty == "No" and re.search(r"MX\s*COVERED", text, re.I):
            warranty = "Yes"
            warranty_note = "MX Covered"

        # URL
        link = block.find("a", href=re.compile(r"/listing/for-retail/"))
        href = link["href"] if link else ""
        if href and not href.startswith("http"):
            href = "https://www.controlleremea.co.uk" + href

        if year or reg:
            listings.append({
                "year": year,
                "reg": reg,
                "serial": serial,
                "hours": hours,
                "price_usd": price_usd,
                "price_display": price_display,
                "location": location,
                "fiki": fiki,
                "warranty": warranty,
                "warranty_note": warranty_note,
                "url": href,
            })

    return listings


def main():
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

    # Load existing data
    existing = {}
    if os.path.exists(DATA_FILE):
        with open(DATA_FILE, "r") as f:
            data = json.load(f)
        for entry in data:
            if entry.get("reg"):
                existing[entry["reg"]] = entry
        print(f"Loaded {len(existing)} existing listings from {DATA_FILE}")

    # Single session with cookie prefetch
    session = make_session()

    # Fetch all pages
    all_fetched = []
    for page in range(1, TOTAL_PAGES + 1):
        url = BASE_URL + (f"&page={page}" if page > 1 else "")
        print(f"\nFetching page {page}...")
        html = fetch_page(session, url)
        parsed = parse_listings(html)
        print(f"  Parsed {len(parsed)} listings on page {page}")
        all_fetched.extend(parsed)
        if page < TOTAL_PAGES:
            time.sleep(3)

    # Deduplicate by reg
    fetched_by_reg = {}
    for listing in all_fetched:
        reg = listing["reg"]
        if reg and reg not in fetched_by_reg:
            fetched_by_reg[reg] = listing

    print(f"\nTotal unique fetched: {len(fetched_by_reg)}")

    # Compare and update
    added = updated = deleted = 0
    result = {}

    for reg, listing in fetched_by_reg.items():
        if reg not in existing:
            listing["first_seen"]   = now
            listing["last_updated"] = now
            result[reg] = listing
            added += 1
        else:
            ex = existing[reg]
            price_changed = ex.get("price_usd") != listing.get("price_usd")
            hours_changed = float(ex.get("hours") or 0) != float(listing.get("hours") or 0)
            if price_changed or hours_changed:
                listing["first_seen"]   = ex.get("first_seen", now)
                listing["last_updated"] = now
                result[reg] = listing
                updated += 1
            else:
                result[reg] = ex

    for reg in existing:
        if reg not in fetched_by_reg:
            deleted += 1
            print(f"  Removed (sold): {reg}")

    print(f"\nSummary: +{added} new, ~{updated} updated, -{deleted} removed")
    print(f"Total in store: {len(result)}")

    output = list(result.values())
    output.sort(key=lambda x: x.get("price_usd") or 9999999)
    with open(DATA_FILE, "w") as f:
        json.dump(output, f, indent=2)
    print(f"Saved to {DATA_FILE}")


if __name__ == "__main__":
    main()
