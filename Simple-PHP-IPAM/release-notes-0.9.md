### 0.9 — Site Grouping, UX Refresh, and Performance Tuning

#### New: Site Grouping
- Added a proper **Sites** data model:
  - new `sites` table
  - `subnets.site_id`
- Added **Sites** management page (`sites.php`) for admins
- Subnets can now be assigned to a site during create/update
- Subnets page now groups subnet hierarchies by **site**
- Unassigned subnets are shown under an **Ungrouped** section

#### UX / Navigation Improvements
- Cleaner top navigation with improved organization
- Added **Sites** to the nav for admins
- Moved task-specific workflows closer to where they are used
- Added contextual links on subnet cards:
  - **View Addresses**
  - **Unassigned** (IPv4 only)
  - **Bulk Update**
- Addresses and Unassigned pages now include contextual links to related workflows

#### UI Refresh / Dark Mode Polish
- Refreshed styling with improved layout, spacing, cards, metrics, and tables
- Improved form readability and table legibility
- Enhanced dark mode styling while keeping:
  - system preference default
  - manual toggle
  - reset to system mode

#### Performance / Memory Improvements
- Default page size increased to **254** to match a typical `/24`
- Applied `254` default page size to:
  - addresses
  - search
  - unassigned IPv4 listing
- Reduced unnecessary work on several pages by:
  - keeping paginated queries consistent
  - avoiding larger-than-needed result loading in common views
  - keeping housekeeping checks lightweight
- Added/used query patterns that reduce extra lookups in common UI flows

#### Notes
- This release lays the groundwork for future site-based filtering and permission scoping.
- Existing subnets remain valid after upgrade; newly added `site_id` is optional and can be assigned later.
