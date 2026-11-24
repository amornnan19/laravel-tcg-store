# Payments, stock and shipping

Thai card shops lose sales in two places: stock that sells twice, and a payment flow that asks the
customer to leave the page and never brings them back. Everything here is about those two.

## The 15-minute stock lock

A single card is usually one copy. Two people adding it at 20:01 and 20:02 must not both reach
checkout.

```
AddToCart
  → within one transaction:
      lock the variant row            SELECT ... FOR UPDATE
      available = qty - sum(unexpired reservations)
      if available < requested → fail with how many are left
      write stock_reservations (cart_id, purchasable, qty, expires_at = now + 15 min)
      write cart_items
```

- `ReleaseExpiredReservations` runs every minute and deletes expired rows. Nothing else deletes
  them.
- `PlaceOrder` consumes the reservation and decrements `qty` in the same transaction; it does not
  create a second hold.
- The cart page shows the countdown from the soonest `expires_at`. When it runs out the line is
  marked and the customer is told, rather than the page silently emptying.
- A graded item is quantity one by definition: reserving it sets `status = reserved`, and releasing
  it sets `listed` again.

The design's "ล็อกของในตะกร้าไว้อีก 13:48" is this table, nothing else. Do not keep reservations in
the session; a session is invisible to the admin and to the next request from another customer.

## PromptPay

The QR is generated locally. PromptPay is an EMVCo payload — a string the shop builds from its own
PromptPay id and the amount, with a CRC. No gateway is involved in making it, and no customer data
leaves the server.

```
PaymentMethod::PromptPay
  → build the EMVCo payload (shop id, amount in baht, CRC16)
  → store it on payments.qr_payload with qr_expires_at = now + 15 min
  → render it as an SVG QR in <x-qr-block>, with the amount, a copy button and a save button
```

Store the payload, not just the image: the customer who comes back needs the same QR, and a
regenerated one with a different CRC confuses banking apps.

**Confirming the payment** has two paths, and the shop should start with the second:

1. **Automatic** — a bank API or a payment provider that pushes a webhook when the transfer lands.
   This is the one that makes the design's "ยืนยันอัตโนมัติใน 1 นาที" true. Requires a merchant
   agreement, so it is not day one.
2. **Slip upload** — the customer uploads the transfer slip, the payment moves to `submitted`, and
   a staff member clears it from the payment desk. This works from day one and needs nothing from a
   bank.

Until path 1 exists, the page must not promise a minute. Say what is true: the shop checks slips
within its opening hours.

## Slip upload

```
→ image upload, 5 MB cap, jpeg/png/heic, stored on a private disk
→ optional: read the amount, the reference and the time off the slip
→ payments.status = submitted, with slip_amount_satang when it was read
→ the customer sees "กำลังตรวจสลิป" and the order sits at awaiting_payment
```

Reading the slip is optional and worth doing, because it turns the most common failure into a clear
message. When `slip_amount_satang` is short of `amount_satang`, the rejection names the gap — "สลิป
โอน ฿ 19,300 · ยอดที่ต้องชำระ ฿ 19,350 (ขาด ฿ 50)" — and offers to pay the difference rather than
starting over. That sentence is in the design; the data to build it is on the payment row.

Never auto-confirm from a slip alone. A slip is an image; images are forgeable.

## Cards

A card payment needs a Thai gateway. Whichever is chosen, the shape is the same:

```
PlaceOrder → create the payment as pending → redirect or mount the gateway's form
  → the gateway's webhook confirms → payments.status = confirmed, orders.status = paid
```

Rules that hold whatever the gateway is:

- The webhook is the only thing that confirms a payment. A customer returning to the success URL
  proves nothing.
- Verify the webhook signature; reject anything unsigned.
- `gateway_ref` is stored so a refund can be matched later.
- The card fee the design shows (3%) is a real line on the order, not a hidden markup.

## Shipping

Four couriers, chosen at checkout, each a flat rate held in config rather than a table until the
shop needs per-zone pricing:

| Courier | Typical | Note |
|---|---|---|
| Flash Express | 1–2 days | |
| Kerry Express | 1–2 days | |
| J&T Express | 2 days | |
| Thailand Post | 2–3 days | registered mail; the cheapest |

Rates live in `config/shipping.php`. Tracking numbers are entered by staff when the order is
packed; the tracking link is built from a per-courier URL template in the same config.

### Protective packing

An option, not a product, priced in config and stored on the order as `protection_satang`. Sleeve,
rigid toploader, and a waterproof outer. Default it on for any order containing a variant over a
threshold, and say why: a card shop's reputation is made in the packing, and an unprotected ฿ 4,290
card arriving bent is a refund plus a review.

Graded items are different: the rigid box and insurance are **mandatory** and included in the item's
price, not optional. The order shows them as lines so the customer sees what they paid for.

## Order status

```
awaiting_payment → paid → packing → shipped → delivered
                 ↘ cancelled          ↘ refunded
```

Each move is an action in `app/Actions`, stamps its timestamp column, and notifies the customer on
their chosen channel. The tracking timeline on the account page reads those timestamps; it has no
state of its own.

A cancelled order releases nothing — by then the reservation is already consumed and the quantity
decremented, so cancelling returns the quantity explicitly. Write a test for that; it is the kind of
thing that silently loses stock.

## Pre-orders

A pre-order is an order whose payment is split:

- **Deposit** — the deposit is charged now, the balance before the release date. `cancel_until`
  decides whether the deposit is refundable, and the page says so plainly.
- **Full** — charged now, at a small discount, with an earlier shipping slot.

`preorders.reserved_count` increments inside the same transaction as the order and is what the
progress bar reads. Enforce `per_account_limit` there too, not only in the form; a form check is a
suggestion.

Seven days before `release_on`, a job asks everyone on a deposit for the balance, through their
channel, with a link that resumes the same order.
