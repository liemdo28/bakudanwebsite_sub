"""
Validates all 30 article content files: presence, required fields, no
placeholder text, hero images exist, unique titles, and -- the important one
-- that every internal link actually resolved at each article's own publish
time points only to already-published siblings (never a forward link).
Also cross-validates factual claims against business-truth.json.
"""
import glob
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import render_template as rt  # noqa: E402

REQUIRED_FIELDS = [
    'seq', 'id', 'slug', 'seo_title', 'h1', 'meta_description', 'image',
    'image_alt', 'cta_url', 'cta_text', 'body_html', 'date_published', 'date_modified',
]
PLACEHOLDER_MARKERS = ['TODO', 'TBD', 'Lorem ipsum', 'placeholder', 'PLACEHOLDER', 'XXX', '[insert', 'FIXME']

# Known verified menu item names/prices (from business-truth.json) -- a crude
# guardrail: any "$NN.NN"-shaped price string in body copy must be one we recognize.
with open(os.path.join(ROOT, 'content', 'campaign', 'business-truth.json'), encoding='utf-8') as f:
    BUSINESS_TRUTH = json.load(f)

VERIFIED_PRICES = set()
for section in ('ramen', 'starters', 'not_ramen', 'dessert'):
    for item in BUSINESS_TRUTH['menu'][section]:
        VERIFIED_PRICES.add(item['price'])
VERIFIED_PRICES.add('0.75')  # Spice Bombs, stated in business-truth notes


def main():
    errors = []

    with open(os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json'), encoding='utf-8') as f:
        manifest = json.load(f)
    manifest_entries = {e['seq']: e for e in manifest['articles']}
    slug_to_seq = {e['slug']: e['seq'] for e in manifest['articles']}

    files_on_disk = glob.glob(os.path.join(ROOT, 'content', 'campaign', 'articles', '*.json'))
    if len(files_on_disk) != 30:
        errors.append(f'expected exactly 30 article files, found {len(files_on_disk)}')

    articles = {}
    for seq, entry in manifest_entries.items():
        path = os.path.join(ROOT, 'content', 'campaign', 'articles', f'{seq:02d}-{entry["slug"]}.json')
        if not os.path.isfile(path):
            errors.append(f'MISSING article file for seq {seq}: {path}')
            continue
        with open(path, encoding='utf-8') as f:
            a = json.load(f)
        articles[seq] = a

        if a['slug'] != entry['slug']:
            errors.append(f'seq {seq}: slug mismatch (manifest={entry["slug"]!r}, article={a["slug"]!r})')
        if a['id'] != entry['id']:
            errors.append(f'seq {seq}: id mismatch (manifest={entry["id"]!r}, article={a["id"]!r})')

        for field in REQUIRED_FIELDS:
            if not a.get(field):
                errors.append(f'seq {seq}: missing required field {field!r}')

        blob = json.dumps(a)
        for marker in PLACEHOLDER_MARKERS:
            if marker in blob:
                errors.append(f'seq {seq}: contains placeholder marker {marker!r}')

        img_path = os.path.join(ROOT, a.get('image', ''))
        if not a.get('image') or not os.path.isfile(img_path):
            errors.append(f'seq {seq}: hero image missing on disk: {a.get("image")}')

        # crude price guardrail
        for price in re.findall(r'\$(\d+\.\d{2})', a.get('body_html', '')):
            if price not in VERIFIED_PRICES:
                errors.append(f'seq {seq}: unverified price ${price} found in body -- not in business-truth.json')

    titles = [a['h1'] for a in articles.values()]
    if len(titles) != len(set(titles)):
        errors.append('duplicate h1 titles found')

    # Link timeline: for each article, simulate the render AT ITS OWN publish
    # time (published_slugs = every strictly-earlier-seq article, since the
    # rollout is sequential) and confirm every resolved link is either an
    # evergreen page or an actually-published sibling -- never a forward link.
    timeline = []
    for seq in sorted(articles):
        a = articles[seq]
        published_at_this_point = {articles[s]['slug'] for s in articles if s < seq}
        resolved = rt.resolve_internal_links(a, published_at_this_point)
        resolved_campaign_slugs = [
            link['href'].replace('.html', '') for link in resolved
            if link['href'].replace('.html', '') in slug_to_seq
        ]
        for target_slug in resolved_campaign_slugs:
            target_seq = slug_to_seq[target_slug]
            if target_seq >= seq:
                errors.append(
                    f'LINK TIMELINE VIOLATION: seq {seq} ({a["slug"]}) resolves a link to '
                    f'seq {target_seq} ({target_slug}) at its own publish time -- not yet published'
                )
        timeline.append({
            'seq': seq, 'slug': a['slug'], 'publish_at': manifest_entries[seq]['publish_at'],
            'resolved_links_at_publish': [link['href'] for link in resolved],
        })

        # also render fully to catch template errors
        try:
            rt.render_article_html(a, published_slugs=published_at_this_point)
        except Exception as e:
            errors.append(f'seq {seq}: render_article_html raised {e!r}')

    with open(os.path.join(ROOT, 'content', 'campaign', 'link-timeline.json'), 'w', encoding='utf-8') as f:
        json.dump(timeline, f, indent=2, ensure_ascii=False)
        f.write('\n')

    if errors:
        print(f'FAIL: {len(errors)} error(s):')
        for e in errors:
            print(' -', e)
        return 1

    print(f'OK: all {len(articles)} articles valid. Link timeline written to content/campaign/link-timeline.json.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
