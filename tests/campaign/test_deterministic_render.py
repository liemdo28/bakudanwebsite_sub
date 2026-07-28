"""Same article + same published_slugs must always render byte-identical HTML."""
import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'scripts', 'campaign'))
import render_template as rt  # noqa: E402


def main():
    errors = []
    with open(os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json'), encoding='utf-8') as f:
        manifest = json.load(f)

    for entry in manifest['articles']:
        article = rt.load_article(entry['seq'], entry['slug'])
        published = {a['slug'] for a in manifest['articles'] if a['seq'] < entry['seq']}
        html1 = rt.render_article_html(article, published_slugs=published)
        html2 = rt.render_article_html(article, published_slugs=published)
        if html1 != html2:
            errors.append(f'seq {entry["seq"]} ({entry["slug"]}): render is NOT deterministic')

    if errors:
        print(f'FAIL: {len(errors)} error(s):')
        for e in errors:
            print(' -', e)
        return 1
    print(f'OK: all {len(manifest["articles"])} articles render deterministically.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
