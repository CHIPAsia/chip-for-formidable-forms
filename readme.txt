=== CHIP for Formidable Forms ===
Contributors: chipinasia
Tags: formidable forms, chip, payment gateway, fpx, duitnow
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Accept FPX, cards, e-wallets and DuitNow QR payments in Formidable Forms with CHIP.

== Description ==

Add CHIP as a payment gateway in Formidable Forms. FPX, FPX B2B1, cards,
DuitNow QR, e-wallets and ShopeePay are all supported through the CHIP hosted
checkout.

= Features =

* Works with the free and Pro versions of Formidable Forms 6.35+
* Appears as a gateway on the Collect a Payment action, alongside Stripe, Square
  and PayPal
* One-time and recurring payments
* Choose which payment methods to offer
* Refunds from the Formidable payments screen
* Payment status triggers so emails can run on a successful or failed payment

= How it works =

The payer is redirected to the CHIP checkout to pay. The result is confirmed
against the CHIP API when they return, and again by a signed server callback, so
an entry is only marked paid once CHIP confirms it.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/chip-for-formidable-forms`, or
   install the plugin through the WordPress plugins screen.
2. Activate the plugin.
3. Go to Formidable -> Global Settings -> CHIP and enter your secret key and
   brand ID.
4. Add a Collect a Payment action to your form and select CHIP as the gateway.

== Frequently Asked Questions ==

= Does this work with the free version of Formidable Forms? =

Yes. Formidable Forms 6.35 and newer includes the payments layer this plugin
builds on, in both the free and Pro versions.

= Do I need a CHIP account? =

Yes. You need a CHIP account with a secret key and brand ID. Sign up at
https://www.chip-in.asia.

= Which currency is supported? =

MYR. CHIP settles Malaysian merchants in Malaysian Ringgit.

= Are recurring payments automatic? =

No. CHIP stores a reusable card token but does not renew subscriptions on its
own. The renewal is charged from your site on the schedule you configure.
Cancelling a subscription removes the token and stops future charges.

== Screenshots ==

1. The CHIP global settings section.
2. The CHIP panel on a payment action.

== Changelog ==

= 1.0.0 =
* First release.
* CHIP registered as a payment gateway for Formidable Forms.
* One-time and recurring payments.
* Payment method selection with runtime resolution of DuitNow QR and ShopeePay.
* Refunds and subscription cancellation from the payments screen.
* Signature verified server callbacks with an API fallback.

== Upgrade Notice ==

= 1.0.0 =
First release.
