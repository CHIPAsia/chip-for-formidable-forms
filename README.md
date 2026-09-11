# CHIP for Formidable Forms

Accept FPX, cards, e-wallets and DuitNow QR payments in Formidable Forms with
[CHIP](https://www.chip-in.asia).

This add-on registers CHIP as a payment gateway inside Formidable Forms. It works
with Formidable Forms 6.35 and newer, including the free version: CHIP appears
alongside Stripe, Square and PayPal as a gateway on the **Collect a Payment**
action.

## Requirements

- WordPress 6.3 or newer
- PHP 7.4 or newer
- Formidable Forms 6.35 or newer (free or Pro)

## Installation

1. Download the latest release zip.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**.
3. Choose the zip, install, and activate.
4. Go to **Formidable → Global Settings → CHIP** and enter your secret key and
   brand ID.

## Configuration

### Global settings

**Formidable → Global Settings → CHIP**

| Setting | Description |
|---|---|
| Secret Key | Your CHIP secret key. Create a dedicated key for each site. |
| Brand ID | The brand that payments are collected under. |
| Test mode | Simulates payments. Use a test secret key. |
| Send receipt | CHIP emails the payer a receipt once the payment completes. |
| Due strict | Block payment after the due time has passed. |
| Due strict timing | Minutes until the purchase expires. Defaults to 60. |
| Allow refunds | Show a refund action on completed CHIP payments. |
| Limit payment methods | Restrict which methods appear at checkout. Leave off to offer every method enabled for your brand. |

### Per-form settings

Add a **Collect a Payment** action to your form, select **CHIP** as the gateway,
then set the amount and currency. The CHIP panel adds:

- **Product name** — shown on the CHIP checkout. Defaults to the form name.
- **Email**, **First name**, **Last name**, **Address** — map these to form
  fields so the payer's details reach CHIP.
- **Reference** — an optional invoice reference. Defaults to the entry ID.
- **Methods offered on this form** — override the global payment method setting
  for this form only. See below.

### Payment method override per form

The **Methods offered on this form** dropdown controls which methods appear at
checkout for this form:

| Option | Behaviour |
|---|---|
| Use the global settings | Follows the global payment method setting. This is the default. |
| Every method enabled for the brand | Ignores the global setting and offers everything your brand has enabled. |
| Choose methods for this form | Use the checkbox list to pick methods for this form only. |

Selecting no checkboxes under **Choose methods for this form** is the same as
choosing *Every method enabled for the brand*.

## How payments work

CHIP uses a hosted checkout, so the payer leaves your site to pay.

1. The form is submitted and an entry is created.
2. A purchase is created through the CHIP API and recorded as a pending payment.
3. The payer is redirected to the CHIP checkout.
4. The payment is settled when either happens first:
   - the payer returns to your site, where the purchase is re-checked against the
     CHIP API, or
   - CHIP delivers a signed callback to your site.

Both paths are idempotent, and the outcome is always confirmed with the CHIP API
before an entry is marked paid.

Once a payment completes, Formidable's own payment triggers fire, so emails and
other form actions can be set to run on a successful or failed payment.

### Payment methods

CHIP exposes both legacy and modern identifiers for some methods. These are
presented as a single choice and resolved at runtime against what your brand
actually has:

| Choice | Identifiers used |
|---|---|
| DuitNow QR | `duitnow_qr`, `dnqr` |
| ShopeePay | `shopee_pay`, `razer_shopeepay` |
| Card (Visa, Mastercard, Maestro) | `visa`, `mastercard`, `maestro` |

The method list can be set globally, or overridden per form on the payment
action. See **Payment method override per form** above.

### Recurring payments

Set the payment type to **Recurring** to charge a saved card on a schedule.

CHIP does not renew subscriptions automatically. It stores a reusable token on
the first payment, and the renewal is charged from your site on the schedule
configured on the action. Cancelling a subscription removes the token, which
stops all future charges.

Recurring payments require a card method, because only cards can be charged
without the payer present.

## Refunds

Refunds are handled from **Formidable → Payments**. Open a completed CHIP payment
and use the refund action. Refunds are full refunds; a refund that CHIP is still
processing shows as *Processing* until the callback confirms it.

## Filters

| Filter | Purpose |
|---|---|
| `frm_chip_purchase_params` | Modify the purchase payload before it is sent to CHIP. |
| `frm_chip_return_url` | Change the page the payer returns to after checkout. |
| `frm_chip_purchase_timezone` | Change the timezone sent with the purchase. |
| `frm_chip_action_field_options` | Change the fields offered in the action settings. |
| `frm_chip_sslverify` | Disable TLS verification for the CHIP API. Not recommended. |

## Development

```bash
# Lint (WordPress coding standards)
composer install
phpcs --standard=phpcs.xml .
```

The plugin has no build step. `assets/js/action.js` is shipped as-is.

## License

GPLv3 or later. See [LICENSE](./LICENSE).
