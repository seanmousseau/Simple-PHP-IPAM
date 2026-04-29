# Contacts

Contacts are reusable people / org records you can attach to a single IP address as the **owner**. Use them when the same person owns dozens of addresses and you don't want to retype "Jane Doe (jane@example.com)" on every row, or when you want a clickable jump from an address back to its owner's full details.

The free-text **Owner** field on every address still works exactly as before — contacts are an *optional* upgrade. You can mix and match: some addresses use the typeahead-linked contact, others use a free-text owner string, and the page renders both consistently.

## When to use a contact vs. the free-text Owner field

| Use a linked contact when… | Use the free-text Owner field when… |
|---|---|
| The same person owns multiple addresses | The owner is a one-off ("temp lab box") |
| You want one place to update name/email/phone | You don't have an email or care to track one |
| You need a clickable owner card in the address list | You want to keep notes inline with the address |

You can switch between the two at any time per-address — there is no migration step.

## Creating a contact

1. Open **Admin → Contacts** (`contacts.php`).
2. Click **New contact**.
3. Fill in **Name** (required) plus any of **Email**, **Phone**, **Org**, **Note** (all optional).
4. Save.

The contact is now eligible to be linked from any address.

## Linking a contact to an address

On the address create or edit form (in the right-side drawer on `addresses.php`):

1. Start typing into the **Owner** field. The input is wired to a contact typeahead (`data-contact-typeahead` on the input — fuzzy-matches name and email).
2. Pick a contact from the suggestion list. The address now stores both the displayed text **and** a hidden `owner_contact_id` pointing at the contact row.
3. Save.

In the address list, linked owners render as a clickable contact card (name + email badge) instead of plain text.

### Inline edit clears the link

If you double-click the **Owner** cell directly in the address table (the inline edit shortcut, not the drawer form), the new value is treated as free text and the `owner_contact_id` link is cleared automatically. This keeps the displayed owner and the linked contact in sync — when you go off-script with free text, the system trusts that you mean it.

To re-link, open the full edit drawer and use the typeahead again.

## Removing a contact link from an address

Two ways:

- Open the edit drawer, blank the Owner field, save. Both the text and the `owner_contact_id` clear.
- Use the inline edit on the Owner cell (see above) to overwrite the value with free text.

## Deleting a contact

From **Admin → Contacts**, deleting a contact is a soft-cascade: the contact row is removed and any addresses linked to it have their `owner_contact_id` set to `NULL` (the schema's `ON DELETE SET NULL`). The free-text **Owner** value on those addresses is preserved — no data is lost on the address side.

## API alternative

The contacts API is documented in [REST API](api.md#contacts). Highlights for IP-address linking:

- Pass `owner_contact_id` in the address create/update body to link or unlink (`null` to clear).
- Filter addresses by linked contact: `GET /api.php?resource=addresses&contact_id=42`.
- Address responses include `owner_contact_name` and `owner_contact_email` when a link is set, so clients don't need a second roundtrip.

For the contact resource itself (CRUD, search), see the same section.
