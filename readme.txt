=== CHIP for Formidable Forms ===
Contributors: chipasia, wanzulnet
Tags: chip, formidable forms, payment, fpx, payment gateway
Requires at least: 6.3
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

CHIP - Digital Finance Platform. Securely accept one-time and subscription payments with CHIP for Formidable Forms.

== Description ==

**CHIP for Formidable Forms** is the official payment add-on that connects your Formidable Forms to CHIP's Digital Finance Platform. Accept payments seamlessly with Malaysia's leading payment methods—FPX, cards, DuitNow QR, e-wallets, and more—directly from your forms.

= Why Choose CHIP for Formidable Forms? =

* **Native Formidable Forms integration** - Add CHIP as a payment gateway on the Collect a Payment action; no custom code required
* **Works with free and Pro** - Formidable Forms 6.35 and newer includes the payments layer this plugin builds on
* **Global and form-specific settings** - Set your Brand ID, Secret Key and payment methods globally, or override the methods per form
* **Multiple payment methods** - Accept FPX, FPX B2B1, Credit/Debit Cards, DuitNow QR, e-wallets, ShopeePay, and more via CHIP's hosted checkout
* **One-time and recurring payments** - Charge a saved card on a schedule, with cancellation that stops future charges
* **Flexible client data** - Map form fields to CHIP client metadata (name, email, address) for compliance
* **Due timing control** - Optional due strict and due timing (minutes) for payment links
* **Refund from the payments screen** - Process full refunds from Formidable → Payments when refund is enabled in settings
* **Webhook support** - Reliable payment status updates via signed CHIP callbacks
* **Payment status triggers** - Run Formidable emails and actions on a successful or failed payment

= Supported Payment Methods =

Payment methods are determined by your CHIP brand configuration. Typically available:

* **FPX** - Malaysian online banking
* **FPX B2B1** - Corporate online banking
* **Credit/Debit Cards** - Visa, Mastercard, and Maestro
* **DuitNow QR** - Malaysia's national QR payment
* **E-Wallets** - GrabPay, Touch 'n Go eWallet, Maybank QRPay, ShopeePay, and more (via your CHIP setup)
* **Apple Pay / Google Pay** - Where enabled on your brand
* **Atome** - Buy now, pay later
* **Crypto** - Where enabled on your brand

= How It Works =

The payer is redirected to the CHIP hosted checkout to pay. The result is confirmed against the CHIP API when they return, and again by a signed server callback, so an entry is only marked paid once CHIP confirms it.

= About CHIP =

CHIP is a comprehensive Digital Finance Platform designed to support Micro, Small and Medium Enterprises (MSMEs). We provide payment collection, expense management, risk mitigation, and treasury solutions. With CHIP, you get a financial partner committed to simplifying and digitizing your operations.

= Documentation =

Integrate your Formidable Forms with CHIP as documented in our [API Documentation](https://docs.chip-in.asia).

== Installation ==

= Minimum Requirements =

* WordPress 6.3 or greater
* Formidable Forms plugin 6.35 or greater (free or Pro)
* PHP 7.4 or greater (PHP 8.0+ recommended)
* MySQL 5.6 or greater, OR MariaDB 10.1 or greater
* A CHIP account with a Brand ID and Secret Key

= Where to get it =

This plugin is not distributed through the WordPress.org plugin directory. Install
it from the GitHub repository.

= Manual installation =

1. Download the plugin zip from the [GitHub repository](https://github.com/CHIPAsia/chip-for-formidable-forms).
2. In WordPress, go to Plugins → Add New → Upload Plugin.
3. Choose the zip, click Install Now, then activate the plugin.
4. Go to Formidable → Global Settings → CHIP and enter your Secret Key and Brand ID.

If you prefer FTP: upload the `chip-for-formidable-forms` folder to
`wp-content/plugins/`, then activate it from the Plugins screen.

= Updating =

Replace the plugin folder with the newer version and reactivate if prompted. Back
up your site before updating.

= After activation =

1. Go to Formidable → Global Settings → CHIP and enter your Secret Key and Brand ID.
2. Add a **Collect a Payment** action to your form and select **CHIP** as the gateway.
3. Set the amount, currency, and which payment methods to offer.

== Frequently Asked Questions ==

= Where is the Brand ID and Secret Key located? =

Log in to your CHIP dashboard, open your account settings, and create a Secret Key under the API keys section. The Brand ID is shown alongside it. Use the test credentials while you are testing, and the live credentials when you go live.

= Does this work with the free version of Formidable Forms? =

Yes. Formidable Forms 6.35 and newer includes the payments layer this plugin builds on, in both the free and Pro versions.

= Do I need a CHIP account? =

Yes. You need a CHIP account with a Secret Key and Brand ID. Sign up at [CHIP](https://www.chip-in.asia).

= Which currency is supported? =

MYR. CHIP settles Malaysian merchants in Malaysian Ringgit.

= Can I offer different payment methods on different forms? =

Yes. Each payment action has a "Methods offered on this form" dropdown that can follow the global setting, offer every method your brand has enabled, or pick a specific set for that form.

= Are recurring payments automatic? =

No. CHIP stores a reusable card token but does not renew subscriptions on its own. The renewal is charged from your site on the schedule you configure. Cancelling a subscription removes the token and stops future charges. Recurring payments require a card payment method.

= Is a refund initiated through the WordPress Dashboard instant? =

No. A refund is submitted to CHIP immediately, but the payment status updates once CHIP finishes processing it. If CHIP is still working on it, the payment shows as Processing until the callback confirms the final state.

= Can I refund only part of the payment? =

No. Refunds from the payments screen are full refunds. Partial refunds must be issued from your CHIP dashboard.

= How do I disable the refund feature? =

Go to Formidable → Global Settings → CHIP and turn off the refund option. The refund action is then hidden on completed CHIP payments.

= Why don't I see the CHIP payment option on my form? =

Check that the Collect a Payment action has CHIP selected as its gateway, and that your Secret Key and Brand ID are saved under Formidable → Global Settings → CHIP. The account status block on that page tells you whether the credentials were accepted.

= How can I view CHIP plugin debug logs? =

Add the following to your `wp-config.php`, reproduce the issue, then check `wp-content/debug.log`:

```
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

= What CHIP API services are used in this plugin? =

This plugin communicates with the following CHIP API endpoints:

* `POST /purchases/` - create a purchase and receive the hosted checkout URL
* `GET /purchases/{id}/` - verify the payment status
* `POST /purchases/{id}/refund/` - issue a refund
* `GET /payment_methods/` - resolve which methods your brand has enabled
* `POST /purchases/{id}/delete_recurring_token/` - cancel a recurring subscription

== Screenshots ==

1. Global configuration - Enter your Brand ID and Secret Key in the plugin settings to connect with CHIP.
2. Payment methods - Choose which methods to offer globally, or override them per form.
3. Form with CHIP payment - The CHIP panel on a Collect a Payment action, with product name and field mapping.
4. A form on the front end with CHIP selected as its payment gateway.
5. Payments screen - View CHIP payments and process a refund from Formidable → Payments.

== Changelog ==

= 1.0.0 =
* First release.
* CHIP registered as a payment gateway for Formidable Forms, alongside Stripe, Square and PayPal.
* One-time and recurring payments through the CHIP hosted checkout.
* Payment method selection, globally and per form, with runtime resolution of the DuitNow QR and ShopeePay identifier groups.
* Refunds and subscription cancellation from the Formidable payments screen.
* Signature verified server callbacks with an API fallback.

== Upgrade Notice ==

= 1.0.0 =
First release.

== Links ==

[CHIP Website](https://www.chip-in.asia)

[Terms of Service](https://www.chip-in.asia/terms-of-service)

[Privacy Policy](https://www.chip-in.asia/privacy-policy)

[API Documentation](https://docs.chip-in.asia/)

[CHIP Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
