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
- **CSV import/export** — bulk address management
- **ARP table import** — reconcile MACs from router output
- **REST API** — read-only and read-write key authentication
- **OIDC/SSO** — PKCE flow, no Composer packages required
- **Audit log** — append-only, full change history
- **Utilisation alerts** — email when subnets exceed thresholds
- **Dark mode** — automatic, light, and dark themes

## Quick start

See the [Installation guide](install.md) to get up and running in minutes.

## Documentation

| Guide | Description |
|-------|-------------|
| [Installation](install.md) | Requirements, deployment, first login |
| [Configuration](configuration.md) | All `config.php` keys explained |
| [Scanning](scanning.md) | Network discovery and scheduled scanning |
| [Advanced Networking](advanced-networking.md) | VRF BGP, VLAN ranges, aggregates, PD pools, DNS export |
| [REST API](api.md) | Endpoint reference, authentication, examples |
| [OIDC / SSO](oidc.md) | PKCE flow setup with Keycloak, Azure AD, Okta, etc. |
| [Security](security.md) | Hardening checklist, CSP, HTTPS, firewall |
| [Upgrading](upgrading.md) | Version upgrade instructions and changelog |

## License

MIT — see [LICENSE](https://github.com/seanmousseau/Simple-PHP-IPAM/blob/main/LICENSE).
