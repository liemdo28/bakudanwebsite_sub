# Link Hub 2.0 — Implementation Plan

## Current State Assessment (from Phase 0 Audit)

### ✅ Already Built
- **API**: `api/index.php` — 1772 lines, complete Pages/Sections/Buttons CRUD
- **DB Schema**: pages, buttons, link_sections, locations, shortlinks, analytics, page_versions, link_health, audit_logs
- **Auth**: JWT with role-based access (super_admin/admin/marketing/store_manager/viewer)
- **Admin SPA**: `links-admin/app.js` — 2806 lines, login, dashboard, pages editor, blog
- **Public pages**: `links/index.html` (customer hub), `marketing-signup/index.html` (location picker)
- **Phase 1-6**: Core CMS, Draft/Publish/Rollback, Analytics, QR/Shortlinks, Link Health
- **Production Readiness Score**: 92/100

### ❌ Not Yet Built
- **Sidebar**: Only 11 items → needs 18 items per spec
- **Dashboard**: Missing campaign stats, SEO issues, CS alerts, CTR
- **Campaigns**: No dedicated Campaign Manager
- **Templates Library**: No template system
- **Page Builder**: Button/section editor exists, but no drag-drop block builder
- **SEO Manager**: Basic panel only, no structured data builder
- **Customer Service Hub**: Not implemented
- **Forms Builder**: Not implemented
- **Media Library**: Not implemented (upload only)
- **UTM Builder**: Not implemented
- **Automation Rules**: Not implemented
- **Staff Training UI**: Basic page editor only, no video/PDF management
- **Marketing Signup**: Location picker exists, but not accessible via main nav
- **Documentation**: Missing LINK_HUB_2_ADMIN_GUIDE.md, LINK_HUB_2_PAGE_BUILDER.md, etc.

---

## Implementation Roadmap

### Phase 1: Navigation & Dashboard (Critical Path)
1. Extend sidebar to 18 items
2. Improve Dashboard KPIs
3. Add Campaigns section to sidebar
4. Add Customer Service to sidebar
5. Add SEO Manager to sidebar

### Phase 2: New Business Modules
6. Campaigns Manager (API + UI)
7. Templates Library (API + UI)
8. Customer Service Hub (UI)
9. Forms Builder (API + UI)
10. Media Library (UI improvements)

### Phase 3: SEO, UTM, Analytics
11. SEO Manager with structured data builder
12. UTM Builder
13. Advanced analytics reports

### Phase 4: Automation & Staff Training
14. Automation Rules engine
15. Staff Training specialized UI
16. Shortlinks improvements (UTM, source tracking)

### Phase 5: Polish & Documentation
17. All remaining documentation files
18. Screenshot evidence collection
19. Final testing and verification
