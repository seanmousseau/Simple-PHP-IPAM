---
layout: home
title: Simple PHP IPAM
---

# Simple PHP IPAM

A lightweight, self-hosted IP Address Management (IPAM) tool built with **PHP 8.2+** and **SQLite**. No Composer dependencies, no npm build step — deploy by copying files.

## Features

- **IPv4 and IPv6** subnet and address management
- **Hierarchical subnets** with site and VRF scoping
- **VLANs, VRF, Tags, Contacts** — full network object model
- **BGP metadata** on VRFs (ASN, Route Targets)
- **VLAN ranges** — named allocation blocks
- **Aggregates** — RIR-assigned supernet tracking
- **IPv6 Prefix Delegation pools** (RFC 3633)
- **Network scanning** — ICMP and TCP; scheduled per-subnet
- **DNS zone export** — BIND-format A/AAAA and PTR records
- **DHCP config export** — generate server-ready `dhcpd.conf` (ISC DHCP) or Kea 2.x JSON from subnet options and reservations
- **Custom fields** — admin-defined per-entity metadata (text, number, date, boolean, select) on subnets and addresses, stored as JSON; surfaced in API responses and CSV exports
- **CSV import/export** — bulk address management
- **ARP table import** — reconcile MACs from router output
- **Device & interface tracking** — link addresses to named devices and interfaces
- **Outbound webhooks** — HMAC-signed HTTP callbacks on address/subnet mutations with retry and delivery log
- **REST API** — read-only and read-write key authentication; OpenAPI 3.1 spec at `?resource=spec`
- **OIDC/SSO** — PKCE flow, no Composer packages required
- **Audit log** — append-only, full change history with configurable retention and batch pruning
- **Database backup & restore** — scheduled CLI backup (SQLite/MySQL/PostgreSQL) with SHA-256 verification, retention rotation, and restore with dry-run mode; admin backup history page
- **Operational health dashboard** — real-time metrics (DB size, backup status, scanning health, webhook delivery, auth/security, system info) with color-coded status indicators
- **Utilisation alerts** — email when subnets exceed thresholds
- **Email password recovery** — token-based reset flow with rate limiting
- **Sidebar navigation** — Enterprise Gateway pattern at ≥1024px; mobile hamburger overlay
- **Command palette ⌘K** — keyboard-driven navigation, record creation, and theme toggle
- **Right-side drawer** — slide-in panel for all create/edit flows (subnets, addresses, users)
- **uPlot dashboard charts** — KPI card grid (total subnets, addresses, utilisation, critical alerts) plus time-series growth chart
- **SVG icon system** — Heroicons sprite, consistent sizing across all navigation elements
- **Fira Sans typography** — self-hosted, completes the Fira type family; WCAG AA dark-mode compliant
- **Dark mode** — automatic, light, and dark themes

## Quick start

See the [Installation guide](install.md) to get up and running in minutes.

## Documentation

| Guide | Description |
|-------|-------------|
| [Installation](install.md) | Requirements, deployment, first login |
| [Configuration](configuration.md) | All `config.php` keys explained |
| [Sidebar & Command Palette](sidebar-and-command-palette.md) | Navigation layout, mobile overlay, ⌘K command palette |
| [Scanning](scanning.md) | Network discovery and scheduled scanning |
| [Advanced Networking](advanced-networking.md) | VRF BGP, VLAN ranges, aggregates, PD pools, DNS export |
| [REST API](api.md) | Endpoint reference, authentication, examples, OpenAPI spec |
| [Webhooks](webhooks.md) | Outbound webhooks: events, payload, signing, retry, SSRF protection |
| [DHCP Config Export](dhcp-export.md) | Generate `dhcpd.conf` and Kea JSON from subnet options and reservations |
| [Custom Fields](custom-fields.md) | Admin-defined metadata on subnets and addresses |
| [Devices](devices.md) | Device & interface tracking, CSV import, API |
| [OIDC / SSO](oidc.md) | PKCE flow setup with Keycloak, Azure AD, Okta, etc. |
| [SMTP & Email](smtp.md) | SMTP configuration, alerts, password recovery |
| [Security](security.md) | Hardening checklist, CSP, HTTPS, firewall, TOTP 2FA |
| [Backup & Restore](backup.md) | Database backup CLI, scheduled backups, restore workflow, disaster recovery |
| [Upgrading](upgrading.md) | Version upgrade instructions and changelog |

## License

MIT — see [LICENSE](https://github.com/seanmousseau/Simple-PHP-IPAM/blob/main/LICENSE).
