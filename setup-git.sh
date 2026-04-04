#!/bin/bash
# Run this script from the BakudanWebsite folder on your computer
# to initialize git version control.
#
# Usage: cd BakudanWebsite && bash setup-git.sh

# Remove the corrupted .git directory if it exists
rm -rf .git

# Initialize fresh git repo
git init
git branch -m main
git config user.email "hoang.d.le@gmail.com"
git config user.name "Hoang Le"

# Stage all website files
git add .gitignore README.md \
    css/ js/ images/ \
    index.html order.html menu.html locations.html \
    about.html happy-hour.html \
    blog.html blog-tonkotsu.html blog-ramen-101.html blog-journey.html \
    blog-chashu.html blog-authentic.html ramen-guide.html \
    privacy.html terms.html

# Create initial commit
git commit -m "Bakudan Ramen website redesign - North Italia-inspired

Complete multi-page restaurant website with:
- Homepage with hero, stats, signature bowls, blog teaser
- Full menu page with categorized items
- Locations page for 3 San Antonio locations
- Unified order page with Toast integration per location
- About/Our Story page with timeline
- Happy Hour specials page
- Blog listing and 5 article pages
- Privacy Policy (CCPA compliant) and Terms of Service
- Cookie consent banner with accept/essential-only options
- WCAG 2.1 AA accessibility (skip links, ARIA, focus management)
- Mobile responsive with hamburger navigation
- Warm dark theme with refined crimson/gold accents

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"

echo ""
echo "Git initialized! Run 'git log' to verify."
