"""
Deterministic renderer for campaign articles: same article JSON always
produces byte-identical HTML. Reuses the site's real header/nav/footer
(matching index.html) so campaign pages aren't orphaned doorway pages.
"""
import json
import os

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SITE_URL = 'https://www.bakudanramen.com'

HEADER = '''    <a href="#main-content" class="skip-link">Skip to main content</a>

    <header class="site-header" role="banner">
        <a href="index.html" class="logo" aria-label="Bakudan Ramen - Home">
            <div class="logo-icon" aria-hidden="true">&#29190;</div>
            <span class="logo-text">BAKUDAN RAMEN</span>
        </a>
        <nav aria-label="Main navigation">
            <ul class="nav-links">
                <li><a href="index.html">Home</a></li>
                <li><a href="menu.html">Menu</a></li>
                <li><a href="locations.html">Locations</a></li>
                <li><a href="happy-hour.html">Happy Hour</a></li>
                <li><a href="about.html">Our Story</a></li>
                <li><a href="blog.html" class="active">Blog</a></li>
                <li><a href="order.html" class="nav-cta" onclick="trackEvent('order_click',{{'event_category':'conversion','event_label':'nav_order_now','brand':'bakudan'}})">Order Now</a></li>
            </ul>
        </nav>
        <button class="hamburger" aria-expanded="false" aria-controls="mobile-nav" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
    </header>

    <div class="mobile-nav" id="mobile-nav" role="navigation" aria-label="Mobile navigation">
        <a href="index.html">Home</a>
        <a href="menu.html">Menu</a>
        <a href="locations.html">Locations</a>
        <a href="happy-hour.html">Happy Hour</a>
        <a href="about.html">Our Story</a>
        <a href="blog.html">Blog</a>
        <a href="order.html">Order Now</a>
    </div>
'''

FOOTER = '''    <footer class="site-footer" role="contentinfo">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="index.html" class="logo" aria-label="Bakudan Ramen - Home">
                    <div class="logo-icon" aria-hidden="true">&#29190;</div>
                    <span class="logo-text">BAKUDAN RAMEN</span>
                </a>
                <p>Bold Flavor. Modern Japanese Soul. Texas Spirit. Three locations serving San Antonio.</p>
            </div>
            <div class="footer-col">
                <h4>LOCATIONS</h4>
                <address>
                    <strong>Bandera</strong>
                    11309 Bandera Rd Ste 111<br>
                    San Antonio, TX 78254<br>
                    <a href="tel:+12102777740">(210) 277-7740</a>
                </address>
                <address>
                    <strong>Stone Oak</strong>
                    22506 U.S. Hwy 281 N Ste 106<br>
                    San Antonio, TX 78258<br>
                    <a href="tel:+12104370632">(210) 437-0632</a>
                </address>
                <address>
                    <strong>La Cantera</strong>
                    17619 La Cantera Pkwy #208<br>
                    San Antonio, TX 78256<br>
                    <a href="tel:+12102578080">(210) 257-8080</a>
                </address>
            </div>
            <div class="footer-col">
                <h4>HOURS</h4>
                <ul>
                    <li>Mon&ndash;Thu: 11:00 AM &ndash; 8:30 PM</li>
                    <li>Fri&ndash;Sat: 11:00 AM &ndash; 9:00 PM</li>
                    <li>Sun: 11:00 AM &ndash; 8:00 PM</li>
                </ul>
                <h4 style="margin-top: 1.5rem;">FOLLOW US</h4>
                <div class="footer-social">
                    <a href="https://www.instagram.com/bakudanramen/" aria-label="Follow Bakudan Ramen on Instagram">Instagram</a>
                    <a href="https://www.facebook.com/bakudanSA/" aria-label="Follow Bakudan Ramen on Facebook">Facebook</a>
                    <a href="https://www.yelp.com/biz/bakudan-ramen-san-antonio" aria-label="Review Bakudan Ramen on Yelp">Yelp</a>
                </div>
            </div>
            <div class="footer-col">
                <h4>EXPLORE</h4>
                <ul>
                    <li><a href="menu.html">View Menu</a></li>
                    <li><a href="order.html">Order Online</a></li>
                    <li><a href="happy-hour.html">Happy Hour</a></li>
                    <li><a href="fundraiser.html">Fundraiser Program</a></li>
                    <li><a href="about.html">Our Story</a></li>
                    <li><a href="blog.html">Blog</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; 2026 Bakudan Ramen. All rights reserved. <a href="privacy.html">Privacy Policy</a> &bull; <a href="terms.html">Terms of Service</a> &bull; <a href="accessibility.html">Accessibility</a></p>
        </div>
    </footer>

    <script src="js/consent.js"></script>
    <script src="js/main.js"></script>
</body>
</html>
'''


def render_faq_schema(faq):
    if not faq:
        return ''
    entities = [
        {
            "@type": "Question",
            "name": qa["question"],
            "acceptedAnswer": {"@type": "Answer", "text": qa["answer"]},
        }
        for qa in faq
    ]
    return json.dumps({"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": entities}, ensure_ascii=False)


def render_faq_html(faq):
    if not faq:
        return ''
    items = '\n'.join(
        f'                <details class="faq-item">\n'
        f'                    <summary>{qa["question"]}</summary>\n'
        f'                    <p>{qa["answer"]}</p>\n'
        f'                </details>'
        for qa in faq
    )
    return f'''
            <section class="article-faq" aria-labelledby="faq-heading">
                <h2 id="faq-heading">Frequently Asked Questions</h2>
{items}
            </section>'''


def resolve_internal_links(article, published_slugs):
    """
    evergreen_links (permanent site pages) are always included. related_campaign_slugs
    are candidate links to OTHER campaign articles -- only included if that sibling is
    already published (publish_at has passed AND it was verified live), so an article
    never links forward to a campaign page that doesn't exist yet.
    """
    links = list(article.get('evergreen_links', []))
    manifest_path = os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json')
    with open(manifest_path, encoding='utf-8') as f:
        manifest = json.load(f)
    by_slug = {a['slug']: a for a in manifest['articles']}
    for slug in article.get('related_campaign_slugs', []):
        if slug in published_slugs and slug in by_slug:
            links.append({'href': f'{slug}.html', 'text': by_slug[slug]['title']})
    return links


def render_article_html(article, published_slugs=frozenset()):
    """
    article: dict loaded from content/campaign/articles/<seq>-<slug>.json
    published_slugs: slugs of OTHER campaign articles already live, used to
    time-gate related-article links (see resolve_internal_links).
    """
    canonical = f'{SITE_URL}/{article["slug"]}.html'
    article_id_marker = f'<!-- ARTICLE-ID: {article["id"]} -->'

    breadcrumb_schema = json.dumps({
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Home", "item": SITE_URL + "/"},
            {"@type": "ListItem", "position": 2, "name": "Blog", "item": SITE_URL + "/blog.html"},
            {"@type": "ListItem", "position": 3, "name": article["h1"], "item": canonical},
        ],
    }, ensure_ascii=False)

    article_schema = json.dumps({
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": article["h1"],
        "description": article["meta_description"],
        "image": f'{SITE_URL}/{article["image"]}',
        "datePublished": article["date_published"],
        "dateModified": article["date_modified"],
        "author": {"@type": "Organization", "name": "Bakudan Ramen"},
        "publisher": {
            "@type": "Organization",
            "name": "Bakudan Ramen",
            "logo": {"@type": "ImageObject", "url": f'{SITE_URL}/images/hero-bakudan-logo.webp'},
        },
        "mainEntityOfPage": {"@type": "WebPage", "@id": canonical},
    }, ensure_ascii=False)

    internal_links_html = '\n'.join(
        f'                <li><a href="{link["href"]}">{link["text"]}</a></li>'
        for link in resolve_internal_links(article, published_slugs)
    )

    faq_schema_block = ''
    faq = article.get('faq')
    if faq:
        faq_schema_block = f'''
    <script type="application/ld+json">{render_faq_schema(faq)}</script>'''

    faq_html = render_faq_html(faq)

    body_html = article['body_html']

    html = f'''<!DOCTYPE html>
<html lang="en">
<head>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-3GZ2RYDR6M"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){{dataLayer.push(arguments);}}
      gtag('js', new Date());
      gtag('config', 'G-3GZ2RYDR6M');
    </script>
    <script>
    function trackEvent(eventName, params) {{
      if (typeof gtag !== 'undefined') {{ gtag('event', eventName, params); }}
    }}
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{article["seo_title"]}</title>
    <meta name="description" content="{article["meta_description"]}">
    <link rel="canonical" href="{canonical}">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="Bakudan Ramen">
    <meta property="og:title" content="{article["seo_title"]}">
    <meta property="og:description" content="{article["meta_description"]}">
    <meta property="og:url" content="{canonical}">
    <meta property="og:image" content="{SITE_URL}/{article["image"]}">
    <meta property="og:locale" content="en_US">
    <link rel="stylesheet" href="css/styles.css">
    <script type="application/ld+json">{article_schema}</script>
    <script type="application/ld+json">{breadcrumb_schema}</script>{faq_schema_block}
</head>
<body>
{article_id_marker}
{HEADER}
    <main id="main-content">
        <article class="blog-post">
            <header class="blog-post-header">
                <p class="blog-post-eyebrow"><a href="blog.html">&larr; Back to Blog</a></p>
                <h1>{article["h1"]}</h1>
            </header>

            <img src="{article["image"]}" alt="{article["image_alt"]}" width="1200" height="630" loading="eager" class="blog-post-hero">

            <div class="blog-post-content">
{body_html}
            </div>
{faq_html}

            <div class="blog-post-cta">
                <a href="{article["cta_url"]}" class="btn btn-primary" onclick="trackEvent('order_click',{{'event_category':'conversion','event_label':'campaign_article_cta','brand':'bakudan'}})">{article["cta_text"]}</a>
            </div>

            <nav class="related-posts" aria-label="Related links">
                <h2>Explore More</h2>
                <ul>
{internal_links_html}
                </ul>
            </nav>
        </article>
    </main>

{FOOTER}'''
    return html


def load_article(seq, slug):
    path = os.path.join(ROOT, 'content', 'campaign', 'articles', f'{seq:02d}-{slug}.json')
    with open(path, encoding='utf-8') as f:
        return json.load(f)


if __name__ == '__main__':
    import sys
    seq = int(sys.argv[1])
    with open(os.path.join(ROOT, 'content', 'campaign', 'campaign-manifest.json'), encoding='utf-8') as f:
        manifest = json.load(f)
    entry = next(a for a in manifest['articles'] if a['seq'] == seq)
    article = load_article(seq, entry['slug'])
    # CLI preview: treat all *earlier*-seq articles as published for link-checking purposes
    published = {a['slug'] for a in manifest['articles'] if a['seq'] < seq}
    print(render_article_html(article, published_slugs=published))
