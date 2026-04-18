# Devices

Simple PHP IPAM v3.2.0 adds a first-class **Devices** model. A device represents a physical or virtual piece of network infrastructure (router, switch, server, VM, firewall, or other). Each device can have named interfaces, and each IP address record can be linked to a device and optionally to one of its interfaces.

---

## Data model

```
devices
  id, name (unique), type, site_id (FK → sites), vendor, model, serial, note
  created_at, updated_at

device_interfaces
  id, device_id (FK → devices, CASCADE), name, description
  UNIQUE(device_id, name)
  created_at, updated_at

addresses
  ...existing columns...
  device_id    (FK → devices, SET NULL on delete)
  interface_id (FK → device_interfaces, SET NULL on delete)
```

Deleting a device cascades to its interfaces and sets `device_id`/`interface_id` to NULL on any linked addresses.

---

## Admin workflow

### Managing devices

Navigate to **⚙ Admin → Devices**. The page requires admin role.

- **Add Device** — fill in name (required), type, site, vendor, model, serial, and note.
- **Edit / Interfaces** — expand a row to update the device fields, manage its interfaces (add or delete), or delete the device entirely.
- **Type badges** — each type renders a colour-coded badge: router (blue), switch (cyan), server (green), vm (purple), firewall (red), other (grey).
- **Filter bar** — filter by type, site, or free-text search across name, vendor, model, and serial.

### Linking an address to a device

Open **Addresses** for any subnet. In the **Add address** form or the per-row **Edit/Delete** form, use the **Device** and **Interface** dropdowns. Selecting a device dynamically populates the Interface dropdown with that device's interfaces.

The **Device** column in the address table is hidden by default — enable it via the column chooser (the ⚙ icon above the table).

### CSV import

The CSV import wizard (`import_csv.php`) accepts two optional columns:

| Column | Description |
|--------|-------------|
| `device_name` | Name of the device to link. Created automatically if it does not exist (type defaults to `other`). |
| `interface_name` | Interface on the device (requires `device_name`). Created automatically if it does not exist. |

If `interface_name` is set without `device_name`, the row is rejected with a validation error.

---

## Global search

Device names and interface names are searchable from the global **Search** page. Matching devices appear in a dedicated "Matching Devices" section below the address and subnet results.

---

## API

See [api.md](api.md) for full endpoint documentation. Quick reference:

| Method | Resource | Description |
|--------|----------|-------------|
| `GET` | `?resource=devices` | List devices (`?type=`, `?site_id=`, `?q=`, pagination) |
| `GET` | `?resource=devices&id=N` | Single device |
| `POST` | `?resource=devices` | Create device (write key) |
| `PUT` | `?resource=devices&id=N` | Update device (write key) |
| `DELETE` | `?resource=devices&id=N` | Delete device (write key) |
| `GET` | `?resource=device_interfaces&device_id=N` | Interfaces for a device |
| `GET` | `?resource=device_interfaces&id=N` | Single interface |
| `POST` | `?resource=device_interfaces` | Create interface (write key) |
| `PUT` | `?resource=device_interfaces&id=N` | Update interface (write key) |
| `DELETE` | `?resource=device_interfaces&id=N` | Delete interface (write key) |

Addresses returned by `?resource=addresses` include `device_id` and `interface_id` fields when set.
