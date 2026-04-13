# Advanced Networking Guide

This guide covers the advanced networking features introduced in Simple PHP IPAM v2.4.0: VRF BGP attributes, VLAN ranges, IPv6 prefix delegation pools, and network aggregates.

## Contents

- [VRFs with BGP attributes](#vrfs-with-bgp-attributes)
- [VLAN ranges](#vlan-ranges)
- [Aggregates](#aggregates)
- [IPv6 Prefix Delegation pools](#ipv6-prefix-delegation-pools)
- [DNS zone export](#dns-zone-export)

---

## VRFs with BGP attributes

Virtual Routing and Forwarding (VRF) instances allow overlapping address spaces across separate routing domains. Starting in v2.4.0, each VRF can optionally carry BGP metadata:

| Field | Description | Example |
|-------|-------------|---------|
| **ASN** | Autonomous System Number | `65000` or `AS65000` |
| **RT Import** | BGP Route Target import list (free-form) | `65000:100, 65000:200` |
| **RT Export** | BGP Route Target export list (free-form) | `65000:100` |
| **Enforce unique prefixes** | Prevent duplicate CIDRs within this VRF | ✓ (default on) |

These fields are informational — IPAM does not generate BGP configuration. They serve as a reference when cross-referencing router configs.

### When to use the "Enforce unique prefixes" flag

Leave this **on** (the default) for production VRFs where duplicate prefixes would be a misconfiguration. Turn it **off** for VRFs that intentionally host overlapping test or lab blocks.

> **Note:** The UNIQUE constraint in the database operates on `(cidr, vrf_id)` — a CIDR may appear in multiple distinct VRFs. The "enforce unique" flag is stored for documentation purposes; the underlying DB constraint always prevents the same CIDR appearing twice *within* the same VRF.

---

## VLAN ranges

VLAN ranges define named allocation blocks within the 1–4094 802.1Q VLAN ID space. They are purely organisational — they do not restrict which VLAN IDs can be created.

### Use cases

- Segment VLANs by function: Management 1–99, Users 100–499, Server 500–999, Transit 900–999
- Track RIR-style allocation blocks across sites
- Document vendor-reserved ranges

### Managing VLAN ranges

Navigate to **Admin → VLANs** and scroll to the **VLAN Ranges** section. Each range has:

- **Name** — short label, e.g. "Management"
- **Min / Max** — inclusive VLAN ID bounds (1–4094, min ≤ max)
- **Site** — optional scope (Global if unset)
- **Description** — free text

Ranges are display-only; no validation is enforced when creating individual VLANs outside a range.

---

## Aggregates

Aggregates are top-level supernets — typically blocks assigned by a Regional Internet Registry (RIR) or defined by internal policy. They provide a bird's-eye view of address space ownership before it is subdivided into subnets.

### Creating an aggregate

Navigate to **Admin → Aggregates** and fill in:

| Field | Description |
|-------|-------------|
| **CIDR** | The aggregate prefix, e.g. `10.0.0.0/8` or `2001:db8::/32` |
| **RIR** | Assigning registry: ARIN, RIPE, APNIC, LACNIC, AFRINIC, or Internal |
| **Description** | Human-readable label |
| **Notes** | Free-form allocation notes, contract references, etc. |

Both IPv4 and IPv6 aggregates are supported. The CIDR is normalised on save (host bits are zeroed).

### Subnet coverage count

Each aggregate row shows a **Subnets** count — the number of subnets in the database whose prefix length is at least as specific as the aggregate's prefix (same IP version). This is a heuristic; it does not verify that subnets actually fall *within* the aggregate range.

---

## IPv6 Prefix Delegation pools

Prefix Delegation (PD, RFC 3633) allows a delegating router to assign a prefix block to a requesting router (the subscriber). Simple PHP IPAM v2.4.0 adds a PD pool management interface under **Admin → PD Pools**.

### Concepts

| Term | Meaning |
|------|---------|
| **PD Pool** | An IPv6 parent subnet configured to delegate sub-prefixes |
| **Delegation prefix** | The prefix length assigned to each subscriber, e.g. `/48` |
| **Delegation** | A specific CIDR assigned to a subscriber from the pool |

### Workflow

1. **Create a pool** — select an existing IPv6 subnet (e.g. `2001:db8::/32`) and specify the delegation prefix length (e.g. `48` → each subscriber gets a `/48`).
2. **Delegate a prefix** — click the pool's delegation count link to open the delegation view. Enter the specific CIDR to assign, optionally link a contact as the subscriber, and set an expiry date if applicable.
3. **Revoke a delegation** — use the Revoke button in the delegation list. This removes the record; it does not automatically update router configuration.

### Expiry tracking

Delegations with a past expiry date are highlighted in red and marked **Expired**. There is no automatic revocation — expired delegations must be manually reviewed.

---

## DNS zone export

Any subnet can be exported as a BIND-format zone file from the **Addresses** page using the **DNS Export** action.

### Export types

| Type | Records exported |
|------|-----------------|
| `forward` | `A` (IPv4) or `AAAA` (IPv6) records only |
| `reverse` | `PTR` records only |
| `both` (default) | Forward section followed by reverse section |

### URL format

```
/export_dns.php?subnet_id=<ID>&type=<forward|reverse|both>
```

### Forward zone output

Each address with a non-empty **Hostname** field produces one A/AAAA record. The hostname is treated as an FQDN (a trailing `.` is added if absent).

```dns-zone
$ORIGIN .
$TTL 3600

example.host.                            IN  A      10.0.1.5
another.host.                            IN  A      10.0.1.10
```

### Reverse zone output (IPv4)

The PTR `$ORIGIN` is computed from the network address and prefix length. For a `/24`, the origin is `X.Y.Z.in-addr.arpa.`; for a `/16`, it is `X.Y.in-addr.arpa.`

```dns-zone
$ORIGIN 1.0.10.in-addr.arpa.
$TTL 3600

5                              IN  PTR  example.host.
10                             IN  PTR  another.host.
```

### Reverse zone output (IPv6)

IPv6 reverse zones use the nibble format with `.ip6.arpa.` suffix. The `$ORIGIN` covers the network prefix rounded up to the nearest nibble boundary.

### Importing into BIND

The exported file is a partial zone file — it does not include SOA or NS records. Prepend those records before importing:

```dns-zone
$ORIGIN example.com.
@ IN SOA ns1.example.com. admin.example.com. (
    2024010101 ; serial
    3600       ; refresh
    900        ; retry
    604800     ; expire
    300        ; minimum TTL
)
@ IN NS ns1.example.com.

; Include exported records below:
```
