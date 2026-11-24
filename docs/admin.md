# Admin

Filament 5 at `/admin`, one panel. The people using it are the shop's own staff, pricing cards and
packing orders all day, so the measure of this panel is keystrokes per card, not how many features
it has.

## Panel

```bash
php artisan filament:install --panels --no-interaction
```

Navigation groups, in the order staff use them:

| Group | Resources |
|---|---|
| Catalogue | Games, Sets, Cards, Printings, Variants, Graded items, Sealed products, Pre-orders |
| Orders | Orders, Payments, Shipments |
| Buylist | Buy prices, Submissions |
| Customers | Users, Store credit |
| Content | Alerts, Saved deck lists |

## The problem this panel has to solve

Pricing a new set means touching several hundred variants. A normal Filament resource — a table, a
row, an edit form, a save — is about six interactions per variant. That is the difference between
an afternoon and a week.

So: **the Variants resource is built for bulk, and the Printings resource owns the per-card work.**

### Printings: edit variants inline

The Printing edit page carries a repeater of its variants: condition, finish, price, quantity, note,
real-photo flag. One page, one save, every variant of that printing. This is the screen used when a
customer asks about one card.

### Variants: a spreadsheet, not a form

The Variants list is where a set gets priced:

- Filters that match how stock arrives: by set, by game, by language, by rarity, by condition,
  "price is null", "quantity is zero".
- **Editable table columns** for `price_satang` and `qty`, so a row is priced without opening it.
- Bulk actions: set price, adjust price by a percentage, set quantity, add to quantity, mark real
  photo taken.
- A CSV import that matches on `sku`, or on `(set code, number, language, condition, finish)` when
  the sku is blank, and reports unmatched lines rather than creating rows.

The CSV import is what makes a new set a half-hour job. Write it in the same step as the resource,
not later.

### Generating variants

A new printing has no variants until someone makes them. A `GenerateVariants` action on the Printing
resource creates the five conditions, plus the foil row when `has_foil`, with null prices and zero
quantity. Staff then price the rows that exist in the box and delete the rest.

## Orders

The Orders resource is a desk, not a CRUD screen. An order detail page shows, in this order:

1. status and the timeline, with the action that moves it on as one button
2. the payment: method, amount, the slip image full size when there is one, and **confirm** or
   **reject with a reason**
3. the items, each linking to its variant, with a pick list sorted by storage location
4. the address and the courier, with a field for the tracking number
5. an internal note field

Rejecting a payment asks for the reason in the same modal and shows it to the customer verbatim.
The design's error state is "ยอดในสลิปไม่ตรง · สลิปโอน ฿ 19,300 · ยอดที่ต้องชำระ ฿ 19,350" — the panel
should prefill that sentence from the slip's read amount so staff do not retype it.

Marking an order shipped writes the tracking number, moves the status, stamps `shipped_at` and
notifies the customer on their chosen channel. One action, four effects, inside one transaction, in
`app/Actions/ShipOrder.php` — not spread across Filament hooks.

## The payment desk

A page, not a resource: every payment in `submitted`, oldest first, with the slip beside the order
total and two buttons. Staff clear the queue; they do not search for payments.

## Buylist desk

Two screens.

**Buy prices** is a Variants-like table: printing, condition, cash, credit, weekly quota, taken this
week. Bulk edit and CSV import, same as variants. A printing with no row here is not bought, and
that is how the storefront tells a customer so.

**Submissions** is the re-inspection flow and the one place where care matters more than speed. A
submission shows each line with what the customer claimed, a field for what the shop found, and the
recalculated payout. Changing an assessed condition recalculates the line and the total live.
Nothing is paid until the customer has confirmed a changed total — the resource must not offer
"pay" while `status` is `revised` and the customer has not answered.

## Widgets

Four, on the dashboard, and no more. Each answers a question staff ask every morning:

- payments waiting to be checked
- orders to pack today
- buylist submissions waiting for cards to arrive
- restock alerts that fired overnight and the cards that caused them

No revenue chart. The shop's accountant has one, and a chart nobody acts on is decoration.

## Access

- `users.is_staff`, with a Filament policy on every resource. The customer site and the panel share
  one `users` table; a customer must never reach `/admin`.
- Staff accounts use email and password, not LINE Login. LINE Login is for customers.
- Two-factor comes with Filament 5; turn it on for staff.

## Conventions inside the panel

- Resources use the same enums as the storefront, so a condition badge is the same colour in both.
- Money fields take baht in the form and store satang, through one shared form component. Never
  surface a satang integer to a human.
- Every destructive action is confirmed and names what will be deleted.
- Deleting a card, printing or sealed product soft-deletes it; orders keep their own snapshots, so
  history stays readable.
