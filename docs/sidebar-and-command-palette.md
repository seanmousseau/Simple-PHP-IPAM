# Sidebar Navigation & Command Palette

*(Added in v3.8.0)*

## Contents

- [Sidebar navigation](#sidebar-navigation)
  - [Desktop layout](#desktop-layout)
  - [Mobile layout](#mobile-layout)
  - [Nav sections](#nav-sections)
  - [User block](#user-block)
- [Command palette](#command-palette)
  - [Opening the palette](#opening-the-palette)
  - [Available commands](#available-commands)
  - [Keyboard navigation](#keyboard-navigation)

---

## Sidebar navigation

The sidebar is the primary navigation element from v3.8.0 onward. It replaces the previous top navigation bar.

### Desktop layout

On viewports ≥1024px the sidebar is always visible on the left side of every page. It is 220px wide and uses the full viewport height, with the main content area filling the remainder.

There is no toggle to hide the sidebar on desktop — it is always present.

### Mobile layout

On viewports <1024px the sidebar is hidden by default. A **hamburger button** (☰) in the top-left corner of the page opens the sidebar as a full-height overlay. To close it:

- Click the **×** button inside the sidebar
- Click anywhere on the dimmed backdrop overlay
- Press **Escape**

### Nav sections

The sidebar is divided into two sections:

**Main navigation** (available to all roles):

| Link | Page |
|------|------|
| Dashboard | `dashboard.php` |
| Subnets | `subnets.php` |
| Addresses | `addresses.php` |
| Search | `search.php` |
| Audit | `audit.php` |
| Unassigned | `unassigned.php` |

**Admin section** (visible to `admin` role only):

| Link | Page |
|------|------|
| DHCP Pools | `dhcp_pool.php` |
| Sites | `sites.php` |
| VRFs | `vrfs.php` |
| VLANs | `vlans.php` |
| Aggregates | `aggregates.php` |
| PD Pools | `pd_pools.php` |
| Tags | `tags.php` |
| Devices | `devices.php` |
| Contacts | `contacts.php` |
| Custom Fields | `custom_fields.php` |
| Users | `users.php` |
| API Keys | `api_keys.php` |
| Webhooks | `webhooks.php` |
| Import CSV | `import_csv.php` |
| ARP Import | `import_arp.php` |
| Reports | `reports.php` |
| Database | `db_tools.php` — backup history (all engines), SQL export/import and manual WAL backup (SQLite only) |
| Health | `health.php` |
| Settings | `settings.php` |

### User block

At the bottom of the sidebar is a user block showing the logged-in username and role badge. Clicking it reveals a small menu with:

- **Theme** — cycle between Auto, Light, and Dark
- **Account** — change password, update email, manage 2FA
- **Logout**

---

## Command palette

The command palette is a keyboard-driven overlay for fast navigation and record creation without reaching for the mouse.

### Opening the palette

| Platform | Shortcut |
|----------|----------|
| Mac | **⌘K** |
| Windows / Linux | **Ctrl+K** |

The palette can be opened from any page. It opens as a centred modal overlay. Press **Escape** or click outside the palette to close it.

### Available commands

Type any part of a command name or description to filter the list. Available commands include:

**Pages**
- Dashboard
- Subnets
- Addresses
- Search
- Audit Log

**Actions**
- New Subnet — opens the add-subnet drawer

**Preferences**
- Toggle Theme — cycles Auto → Light → Dark → Auto

Typing two or more characters also performs a live address search and shows matching results inline.

### Keyboard navigation

Once the palette is open:

| Key | Action |
|-----|--------|
| Type | Filter commands |
| ↑ / ↓ | Move selection up/down |
| Enter | Execute selected command |
| Escape | Close palette |

The selected command is highlighted. Commands that open a page navigate immediately on Enter. Commands that open a drawer or form close the palette first and then open the target.
