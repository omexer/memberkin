=== Memberkin ===
Contributors: omexer, amhossain
Tags: membership, subscriptions, stripe, paypal, restrict content
Requires at least: 6.5
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell memberships with Stripe or PayPal: unlimited levels, recurring payments, member accounts, emails and members-only content.

== Description ==

Memberkin turns your WordPress site into a membership site. Create as many membership levels as you like, take one-time or recurring payments with Stripe or PayPal, and show your content only to the right members.

Everything in this plugin works on its own. There are no locked features, trials or license keys.

= Membership levels =

* Unlimited levels: free, one-time and recurring (daily, weekly, monthly or yearly), with an optional sign-up fee and free or paid trial.
* Expiration after a set time, on a fixed date, or never.
* Level groups with upgrade and downgrade paths, proration, and a fallback level when a membership ends.
* Optional WordPress roles per level.
* Coupons and discount codes.

= Checkout and member pages =

* Checkout, confirmation, levels (pricing table), login, lost password, account and profile pages, created for you in one click.
* Members can see their memberships and payments, update their card, and cancel.
* A block and a shortcode for every page.

= Payments =

* Stripe card payments with Strong Customer Authentication (3-D Secure).
* PayPal.
* Renewals, failed payment retries, refunds and webhooks are handled for you.
* Test (sandbox) mode for both gateways.

= Content restriction =

* Restrict posts and pages to one or more levels, or whole categories and tags.
* `[memberkin_restrict]` shortcode and a "Members content" block for parts of a page.
* Restricted content is also hidden from search, archives, feeds, the REST API, comments and embeds.

= Admin =

* Members list with search and filters; view, edit, change level and cancel members.
* Orders and subscriptions lists, refunds and notes.
* Email notifications for checkout, renewals, failed payments, cancellations and expirations, each of which can be switched off.
* A REST API for levels, members, memberships, orders and subscriptions.

= Memberkin Pro =

An optional paid add-on, Memberkin Pro, adds more payment gateways and advanced features such as access rules for custom post types and URLs, dripped content, tax and invoices, reports and integrations. Memberkin works fully without it.

== Installation ==

1. Install and activate Memberkin from **Plugins → Add New**, or upload the plugin folder to `/wp-content/plugins/`.
2. Go to **Memberships → Settings → Pages** and click **Create missing pages**.
3. Under **Memberships → Settings → Payments**, enable Stripe and/or PayPal and enter your API keys. Use test mode first.
4. Add your webhook URL (shown on the Payments tab) to your Stripe and PayPal dashboards.
5. Create your first level under **Memberships → Levels**.
6. Restrict content with the "Membership access" box on any post or page.

== Frequently Asked Questions ==

= Do I need Memberkin Pro? =

No. Memberkin is complete on its own. Pro adds extra gateways and advanced features for larger sites.

= Which payment gateways are included? =

Stripe and PayPal.

= Can I test payments before going live? =

Yes. Both gateways have a test (sandbox) mode with separate API keys.

= Will my data be deleted if I uninstall the plugin? =

Only if you turn on **Memberships → Settings → Advanced → Delete all data when the plugin is deleted**. Otherwise your levels, members and orders are kept.

= Can I change how the member pages look? =

Yes. Copy any file from the plugin's `templates` folder to `yourtheme/memberkin/` and edit it there.

== Screenshots ==

1. Membership levels.
2. The checkout page.
3. The member's account page.
4. Members list.
5. Restricting a post to members.

== External services ==

Memberkin connects to the payment services below only when you enable them under **Memberships → Settings → Payments**. It does not contact any other external service, and it does not collect usage data.

= Stripe =

Used to take card and other payments when you enable the Stripe gateway.

* On the checkout page, Stripe's JavaScript library is loaded from `https://js.stripe.com/v3/`, as Stripe requires. Card details are typed into fields hosted by Stripe and go straight to Stripe; they never reach your site.
* When a member checks out, renews, updates their card, cancels or is refunded, your site sends Stripe the member's name and email address, the amount, currency and description of the purchase, the order number, the member's WordPress user ID and your site address.
* Stripe sends payment events (webhooks) back to your site.

Stripe [Services Agreement](https://stripe.com/legal/ssa) and [Privacy Policy](https://stripe.com/privacy).

= PayPal =

Used to take payments when you enable the PayPal gateway.

* When a member checks out, your site sends PayPal the amount, currency and description of the purchase, the order number and the addresses to return to on your site. The member then logs in to PayPal on PayPal's own pages.
* When a subscription renews, is cancelled or refunded, your site sends PayPal the subscription or payment ID.
* PayPal sends payment events (webhooks) back to your site.

PayPal [User Agreement](https://www.paypal.com/us/legalhub/paypal/useragreement-full) and [Privacy Statement](https://www.paypal.com/us/legalhub/paypal/privacy-full).

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
