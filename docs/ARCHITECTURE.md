# Memberkin — Architecture

Date: 2026-11-23 · Phase 2 · **Awaiting approval** — no plugin code has been written.

Conventions: PHP namespace `Memberkin\`, hook/option/function prefix `memberkin_`, text domain `memberkin`, tables `{$wpdb->prefix}memberkin_*`, REST namespace `memberkin/v1`, PHP ≥ 8.1, WP ≥ 6.5, GPLv2+.

---

## 1. Principles

1. **Memberships grant access; orders record money; subscriptions describe billing.** Three separate aggregates, linked by IDs. Access checks never touch orders.
2. **Integer money.** Every amount is `BIGINT` minor units + `CHAR(3)` currency. All arithmetic goes through `Money`.
3. **One write path per aggregate.** Status changes go through a service (`MembershipService`, `SubscriptionService`, `OrderService`) that validates the transition, persists it, writes an activity event and fires hooks. Nothing else updates these tables.
4. **Idempotent, asynchronous side effects.** Webhooks, renewals, emails and integrations run as Action Scheduler jobs, keyed so a job can run twice without double effects.
5. **No gateway SDKs.** Each gateway has a small HTTP client over `wp_remote_request()`. Fewer conflicts, smaller plugin, consistent logging/redaction.
6. **Modules.** Core is always on; feature modules can be switched off in *Settings → Modules*. A disabled module registers no hooks, routes, cron jobs or admin screens. Its tables stay in place.
7. **Native WordPress.** `WP_List_Table`, Settings API, meta boxes, `wp_mail`, `WP_REST_Controller`, core block APIs. No custom admin frameworks.

---

## 2. Directory layout

```
memberkin/
├── memberkin.php            # header, constants, requirement check, boots Plugin
├── uninstall.php                  # deletes data only if memberkin_settings_advanced[delete_data_on_uninstall]
├── composer.json                  # PSR-4 Memberkin\ => src/ ; dev deps only (phpunit, phpcs, wpcs, wp-cli)
├── vendor/                        # composer autoload + woocommerce/action-scheduler (+ scoped dompdf)
├── src/
│   ├── Core/                      # Plugin, Container, ModuleRegistry, Installer, Migrations/, Settings/,
│   │                              # Capabilities, Logger, Money, Currency, Clock, Lock, Crypto, Http,
│   │                              # Jobs (Action Scheduler wrapper), SafeMode, Templates, Assets
│   ├── Domain/                    # entities + value objects (no WP calls):
│   │                              # Level, Membership, Subscription, Order, OrderItem, Refund, Coupon,
│   │                              # PriceSchedule, Quote, Status enums (PHP 8.1 enums), events
│   ├── Repository/                # one per table; all SQL lives here ($wpdb->prepare only)
│   ├── Service/                   # MembershipService, SubscriptionService, OrderService, PricingService,
│   │                              # ProrationCalculator, CheckoutPipeline, AccessService, RenewalService,
│   │                              # DunningService, TaxService, InvoiceService, EmailService, EventBus
│   ├── Gateway/                   # Contracts/, AbstractGateway, WebhookController, Registry,
│   │   ├── Offline/ Stripe/ PayPal/ AuthorizeNet/ Square/ Paddle/ GoCardless/ Adyen/ Paystack/ PayFast/ CCBill/
│   ├── Admin/                     # menus, list tables, screens, meta boxes, notices
│   ├── Frontend/                  # page controllers (checkout, account, login, levels, confirmation, invoice)
│   ├── Rest/                      # controllers for memberkin/v1
│   ├── Blocks/                    # block registration (server render) ; JS in /blocks
│   ├── Shortcodes/
│   ├── Modules/                   # optional feature modules, each with its own Module class:
│   │   ├── Coupons/ Tax/ Invoices/ Fields/ Drip/ ProtectedFiles/ Paywall/ Approvals/ GroupAccounts/
│   │   ├── Gifts/ Directory/ Courses/ Lms/ Reports/ EmailMarketing/ Webhooks/ Community/ Events/
│   │   ├── WooCommerce/ Affiliates/ Security/ Builders/ MultiCurrency/ ImportExport/ Migration/ Unlock/
│   ├── Privacy/  Cli/  Abilities/
├── templates/                     # overridable from {theme}/memberkin/
├── blocks/  (src/, build/)        # @wordpress/scripts; build output committed
├── assets/  (css/, js/, img/)     # plain JS (no build) for frontend/admin behaviour
├── languages/
├── tests/   (Unit/, Integration/, E2E/ fixtures)
└── docs/
```

`Domain` objects are plain PHP and unit-testable without WordPress. `Repository` + `Service` are integration-tested against a real test database.

---

## 3. Modules

### 3.1 Core (always enabled)

| Module | Responsibility |
|---|---|
| Foundation | Bootstrap, container, module registry, installer/migrations, settings, capabilities, logger, money/currency, crypto, HTTP client, jobs, safe mode, templates, multisite |
| Levels | Levels, level groups, upgrade paths, fallback level, per-level settings, role mapping |
| Memberships | Membership records, state machine, admin assignment, suspension, expiry, history |
| Billing | Subscriptions, orders, order items, refunds, payment methods, renewals, dunning, reconciliation |
| Pricing | Price schedules, `Quote` builder, proration |
| Gateways | Interface, registry, webhook endpoint + event log, Offline, Stripe, PayPal, Authorize.net (others are gateway sub-modules, individually toggleable) |
| Checkout | Pipeline, forms, confirmation, consent log, idempotency |
| Accounts | Login/logout/reset, registration, account dashboard, payment-method update, redirects, admin-bar/wp-admin rules |
| Access | Access rules, content assignments, content filters (content, excerpts, feeds, search, REST, comments, sitemaps, oEmbed, XML-RPC, menus), partial content, unauthorized handling |
| Emails | Templates, renderer, queue, log |
| Admin | Dashboard, list tables, member detail, settings screens |
| REST | `memberkin/v1` |
| Privacy | Exporters, erasers, policy text, retention |

### 3.2 Optional modules (default state)

Coupons (on) · Tax (on) · Invoices (on) · Fields (on) · Drip (on) · Protected Files (off) · Paywall (off) · Approvals (off) · Group Accounts (off) · Gifts (off) · Directory (off) · Courses (off) · LMS adapters (auto-offered when an LMS is active) · Reports (on) · Email Marketing (off) · Webhooks & Automation (off) · Community (off) · Events (off) · WooCommerce (off) · Affiliates (off) · Security (on) · Builders (auto) · Multi-currency (off) · Import/Export (on) · Migration (on) · Unlock (off).

Modules declare `id`, `label`, `requires` (other modules), optional `requires_plugin` (for example LearnDash), `register()`, `migrations()`.

---

## 4. Database schema

All tables use `$wpdb->get_charset_collate()`, `BIGINT UNSIGNED` IDs, and UTC `DATETIME` (shown in the site timezone). Amounts are `BIGINT` minor units unless noted. **No foreign-key constraints** (WordPress convention, and MyISAM hosts exist); the repositories enforce integrity, and deletions cascade in the services.

### 4.1 Levels

**`memberkin_levels`**
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(191) | |
| slug | varchar(191) UNIQUE | used in URLs `?level=slug` |
| description | longtext | |
| group_id | bigint | → level_groups.id (each level belongs to exactly one group) |
| status | varchar(20) | `active`, `hidden` (buy by direct link only), `archived` |
| allow_signups | tinyint | |
| display_order | int | |
| currency | char(3) | defaults to the site currency |
| initial_amount | bigint | first payment (covers the first period, or the whole one-time price) |
| signup_fee | bigint | added to the first payment only |
| recurring_amount | bigint | 0 = not recurring |
| billing_interval | smallint | every N… |
| billing_period | varchar(10) | `day`, `week`, `month`, `year` |
| billing_cycles | int | 0 = until cancelled |
| trial_amount | bigint | 0 = free trial |
| trial_length | smallint | 0 = no trial |
| trial_period | varchar(10) | `day`, `week`, `month`, `year`, `cycle` |
| expiration_type | varchar(20) | `never`, `duration`, `fixed_date`, `after_cycles` |
| expiration_length / expiration_period | smallint / varchar(10) | |
| expiration_date | datetime NULL | for `fixed_date` (optional yearly roll-over) |
| tax_class | varchar(40) | `standard`, `reduced`, `exempt`, … |
| created_at, updated_at | datetime | |

Indexes: `slug` (unique), `(status, display_order)`, `group_id`.

**`memberkin_level_meta`** (`meta_id`, `level_id`, `meta_key`, `meta_value`). Registered with `add_metadata()` support (meta type `memberkin_level`), so `get_metadata('memberkin_level', …)` works. Holds per-level extras: confirmation message, thank-you page, login redirect, roles, allowed gateways, prerequisites, cost text, image, benefits, approval required, seats config, and so on.

**`memberkin_level_groups`**: `id`, `name`, `allow_multiple` (tinyint), `is_upgrade_path` (tinyint), `fallback_level_id`, `proration_mode`, `downgrade_timing` (`immediate` / `period_end`), `display_order`.

### 4.2 Memberships

**`memberkin_memberships`** — the single source of truth for access.
| Column | Notes |
|---|---|
| id, user_id, level_id | |
| status | see the state machine in §5.1 |
| source | `checkout`, `admin`, `import`, `gift`, `group_seat`, `api`, `integration` |
| subscription_id | NULL for one-time, free or admin-granted |
| initial_order_id | |
| parent_membership_id | group-seat child → parent |
| coupon_id | |
| price snapshot | `currency, initial_amount, recurring_amount, billing_interval, billing_period, billing_cycles, trial_amount, trial_length, trial_period` (copied at checkout) |
| starts_at | access start (drip anchor) |
| ends_at | NULL = no end; the expiry job ends the membership when `ends_at` ≤ now and status is active-like |
| grace_until | NULL; access is kept while past-due within the grace period |
| paused_at, resume_at, suspended_at | |
| ended_at, end_reason | `expired`, `cancelled`, `changed`, `refunded`, `admin`, `failed_payment`, `group_removed` |
| created_at, updated_at | |

Indexes: `(user_id, status)`, `(level_id, status)`, `(status, ends_at)`, `subscription_id`, `parent_membership_id`.
**Uniqueness rule** (enforced in the service under a lock): at most one *current* membership per (user, level), and at most one per (user, group) when the group has `allow_multiple = 0`.

### 4.3 Billing

**`memberkin_subscriptions`**
| Column | Notes |
|---|---|
| id, user_id, level_id, membership_id | |
| gateway, gateway_mode | `live` / `test` |
| gateway_subscription_id | NULL for site-managed |
| gateway_customer_id, payment_method_id | → payment_methods.id |
| billing_model | `gateway` (gateway runs the schedule) or `site` (we charge a stored token) |
| status | §5.2 |
| currency, recurring_amount, tax_amount, billing_interval, billing_period, billing_cycles, cycles_completed | |
| coupon_id, coupon_cycles_remaining | |
| trial_ends_at, current_period_start, current_period_end, next_payment_at | |
| cancel_at_period_end, cancelled_at, cancel_reason | |
| paused_at, resume_at | |
| retry_count, next_retry_at, last_payment_error | |
| created_at, updated_at | |

Indexes: UNIQUE `(gateway, gateway_mode, gateway_subscription_id)`, `(status, next_payment_at)`, `(status, next_retry_at)`, `user_id`, `membership_id`.

**`memberkin_subscription_meta`** (meta type `memberkin_subscription`).

**`memberkin_orders`**
| Column | Notes |
|---|---|
| id | |
| number | public, random, unique (for example `MK-7K4Q2X9P`); used in URLs instead of the ID (prevents enumeration) |
| invoice_number | NULL until paid; sequential per series (§4.5) |
| user_id, subscription_id | |
| type | `initial`, `renewal`, `upgrade`, `manual`, `gift`, `import` |
| status | §5.3 |
| currency, subtotal, discount_total, tax_total, total, amount_refunded | |
| coupon_id, coupon_code | |
| tax_country, tax_region, vat_number, vat_number_valid, reverse_charge, prices_include_tax | |
| billing_name, billing_company, billing_email, billing_line1, billing_line2, billing_city, billing_region, billing_postcode, billing_country, billing_phone | snapshot |
| gateway, gateway_mode, gateway_transaction_id, gateway_customer_id | |
| payment_method_label | e.g. "Visa •••• 4242" (no PAN, ever) |
| ip_address | stored only when the tax module needs evidence; anonymised by the retention job |
| checkout_session_id | |
| paid_at, refunded_at, created_at, updated_at | |
| notes | admin notes (longtext) |

Indexes: UNIQUE `number`, UNIQUE `invoice_number`, `(user_id, created_at)`, `(status, created_at)`, `subscription_id`, `(gateway, gateway_transaction_id)`, `(paid_at)`.

**`memberkin_order_items`**: `id, order_id, type` (`level`, `signup_fee`, `proration_credit`, `discount`, `fee`), `level_id, description, quantity, unit_amount, discount_amount, tax_rate_id, tax_rate` (decimal(7,4)), `tax_amount, total`. Index `order_id`.

**`memberkin_order_meta`** (meta type `memberkin_order`).

**`memberkin_refunds`**: `id, order_id, amount, reason, gateway_refund_id, status` (`pending`, `succeeded`, `failed`), `credit_note_number, created_by, created_at`. Index `order_id`, UNIQUE `credit_note_number`.

**`memberkin_payment_methods`**: `id, user_id, gateway, gateway_mode, token` (gateway token/ID only), `type, brand, last4, exp_month, exp_year, is_default, created_at, updated_at`. Indexes `(user_id, gateway)`, `(exp_year, exp_month)` (used by card-expiring reminders).

**`memberkin_checkout_sessions`**: `id, token` (char(43), unique, random), `user_id, level_ids, quote` (JSON), `form_data` (JSON, with sensitive fields stripped), `order_id, status, expires_at, created_at`. Replaces PHP sessions for offsite redirects and abandoned-checkout recovery. Index `(status, expires_at)`.

### 4.4 Coupons

**`memberkin_coupons`**: `id, code` (UNIQUE, case-insensitive collation), `description, status, discount_type` (`percent`, `fixed`, `set_price`), `amount` (bigint, or basis points for percent), `applies_to` (`initial`, `recurring`, `both`), `recurring_cycles` (NULL = forever), `first_payment_type, first_payment_amount, trial_override_length, trial_override_period, trial_override_amount, starts_at, expires_at, max_uses, uses_count, max_uses_per_user, per_user_window_days, allow_on_change` (upgrades/downgrades), `auto_apply_group_id, created_at, updated_at`.

**`memberkin_coupon_levels`**: `coupon_id, level_id`, plus an optional full price-schedule override (`override` tinyint and the same pricing columns as levels). PK `(coupon_id, level_id)`.

**`memberkin_coupon_uses`**: `id, coupon_id, user_id, order_id, used_at`. Indexes `(coupon_id, user_id)`, `order_id`.

### 4.5 Tax & invoices

**`memberkin_tax_rates`**: `id, country` (char(2), `*` = any), `region, postcode_pattern, rate` (decimal(7,4)), `name, tax_class, priority, compound, is_eu_vat, valid_from, valid_to`. Index `(country, tax_class)`.
**`memberkin_vat_validations`** (cache): `vat_number` (PK), `country, valid, name, address, consultation_number, checked_at`.
**`memberkin_sequences`**: `name` (PK, e.g. `invoice`, `credit_note`), `prefix, next_value`. Numbers are allocated with an atomic `UPDATE … SET next_value = LAST_INSERT_ID(next_value + 1)` inside the payment transaction, so invoice numbers are gapless and never duplicated.

### 4.6 Access

**`memberkin_content_access`** — direct assignments ("this post/term requires one of these levels"), the fast path.
`object_type` (`post`, `term`), `object_id, level_id, drip_delay_days` (NULL = immediate), `created_at`. PK `(object_type, object_id, level_id)`; index `(level_id, object_type)`.

**`memberkin_access_rules`** — the rule engine.
`id, name, status, priority, target_type` (`post_type_all`, `page_children`, `taxonomy_term`, `uri`, `file`, `everything`), `target_value` (post type, page ID, `taxonomy:term_id`, or a URI pattern), `match_mode` (`exact`, `prefix`, `wildcard`, `regex`), `drip_type` (`none`, `after_start`, `fixed_date`), `drip_amount, drip_unit, drip_date, expire_amount, expire_unit, unauthorized_behavior` (`message`, `excerpt`, `login`, `redirect`), `unauthorized_message, redirect_url, created_at, updated_at`. Index `(status, target_type)`.

**`memberkin_access_rule_conditions`**: `id, rule_id, condition_type` (`level`, `any_member`, `logged_in`, `role`, `capability`, `user`), `condition_value, negate`. Index `rule_id`.

Rule sets are compiled into a cached array (object cache + an autoload-off option keyed by a version counter) so a page view needs no rule queries.

### 4.7 Logs, events, jobs

**`memberkin_events`** (activity / member history): `id, user_id, object_type, object_id, event, data` (JSON), `actor_type` (`user`, `admin`, `system`, `gateway`), `actor_id, created_at`. Indexes `(user_id, created_at)`, `(object_type, object_id)`, `(event, created_at)`.

**`memberkin_webhook_events`**: `id, gateway, gateway_mode, event_id, event_type, payload` (longtext, redacted), `signature_valid, status` (`received`, `processing`, `processed`, `ignored`, `failed`), `attempts, last_error, received_at, processed_at`. UNIQUE `(gateway, gateway_mode, event_id)` gives idempotency; index `(status, received_at)`.

**`memberkin_email_log`**: `id, user_id, template, recipient, subject, body` (only if body logging is enabled), `status, error, attempts, created_at`. Indexes `(user_id, created_at)`, `(template, created_at)`, `status`.

**`memberkin_logs`** (technical log): `id, level, channel, message, context` (JSON, redacted), `created_at`. Index `(channel, created_at)`.

**`memberkin_stats_daily`**: `day` (date), `metric, level_id, value` (bigint). PK `(day, metric, level_id)`. Holds aggregated logins, views and signups, plus MRR snapshots.

Action Scheduler keeps its own tables (`actionscheduler_*`), shared with WooCommerce when both are present.

### 4.8 Module tables (created only when the module is first enabled)

| Module | Tables |
|---|---|
| Fields | `memberkin_fields` (`id, group_name, meta_key` UNIQUE, `type, label, options` JSON, `required, levels` JSON, `locations` JSON, `conditions` JSON, `visibility, validation, sort_order`). Values are stored in usermeta under `memberkin_field_{key}` (searchable, works with `WP_User_Query`). |
| Group Accounts | `memberkin_groups` (`id, owner_user_id, membership_id, level_id, child_level_id, seats, code` UNIQUE, `status, created_at`), `memberkin_group_members` (`group_id, user_id, membership_id, status, joined_at`; PK `(group_id, user_id)`) |
| Gifts | `memberkin_gifts` (`id, code` UNIQUE, `level_id, order_id, purchaser_id, recipient_email, message, send_at, redeemed_by, redeemed_at, expires_at`) |
| Courses | `memberkin_course_progress` (`user_id, course_id, lesson_id, status, completed_at`; PK `(user_id, lesson_id)`, index `(user_id, course_id)`) — courses/lessons are CPTs `memberkin_course`, `memberkin_lesson` |
| Webhooks & Automation | `memberkin_webhook_endpoints` (`id, url, secret` (encrypted), `events` JSON, `status, created_at`), `memberkin_webhook_deliveries` (`id, endpoint_id, event, payload, response_code, response_body` (truncated), `attempts, next_attempt_at, status, created_at`) |
| Email Marketing | `memberkin_integration_sync` (`provider, user_id, remote_id, last_synced_at, last_error`; PK `(provider, user_id)`) |
| Paywall | none (signed cookie + usermeta counter) |
| Approvals | none (membership status `pending_approval` + events) |

### 4.9 Options

`memberkin_settings_general`, `_pages`, `_checkout`, `_access`, `_accounts`, `_emails`, `_gateways` (non-secret), `_tax`, `_invoices`, `_advanced`, `_modules`; `memberkin_db_version`; `memberkin_site_fingerprint`; one `memberkin_gateway_{id}` option per gateway (secrets encrypted). Large or rarely used options are stored with `autoload = no`.

### 4.10 Migrations

`src/Core/Migrations/Migration_YYYYMMDD_NNN.php` classes run in order and record their IDs in `memberkin_db_version`. Schema changes use `dbDelta()`. Data changes run in batches through Action Scheduler, with progress shown in an admin notice. Every migration is idempotent. On multisite, migrations run per site lazily (the first admin request on that site), plus on `wp_initialize_site`.

---

## 5. State machines

### 5.1 Membership

```
pending ──────────────▶ active ◀────────── paused (resume)
pending_approval ─────▶ active          active ─▶ paused
                                        active ─▶ past_due (grace) ─▶ active | ended(failed_payment)
active ─▶ cancelling (cancel at period end; access continues) ─▶ ended(cancelled)
active|cancelling ─▶ suspended (admin) ─▶ active (unsuspend)
active ─▶ ended(expired|changed|refunded|admin|group_removed)
pending_approval ─▶ ended(denied)
```
States that grant access: `active`, `cancelling`, `past_due` (only while `grace_until` > now). States that deny access: `pending`, `pending_approval`, `paused`, `suspended`, `ended`.

### 5.2 Subscription
`pending → trialing → active → past_due → active | unpaid → cancelled`; `active → paused → active`; `active → cancelled` (immediately, or at period end through `cancel_at_period_end`); `active → completed` (billing cycle limit reached); `expired` (gateway-side end).

### 5.3 Order
`pending → paid`; `pending → failed`; `pending → cancelled` (abandoned or offline payment voided); `paid → partially_refunded → refunded`; `paid → disputed → paid | refunded`; `review` (fraud review held by the gateway).

Every transition: validated by a transition table → persisted with a conditional `UPDATE … WHERE status = :from` (optimistic, so concurrent webhooks cannot double-apply) → recorded in `memberkin_events` → fires `memberkin_{object}_status_changed` and `memberkin_{object}_{to_status}`.

---

## 6. Pricing, checkout and proration

**PriceSchedule** (value object): initial amount, signup fee, recurring amount, interval, cycles, trial, expiration.
**Quote** = `PricingService::quote(user, levels[], coupon?, billing_address?, vat_number?, change_from_membership?)` returns line items, discount, proration credit, tax lines, today's total, and the recurring schedule (amount and dates). The same quote powers the checkout summary (AJAX), REST `/quote`, the order items, and email merge tags. This guarantees the price shown is the price charged.

**Checkout pipeline** (each step is a class; filters can add steps):
1. `ResolveLevels` (level(s), order bumps, gift, group invite) → 2. `ApplyCoupon` → 3. `ValidateEligibility` (signups allowed, prerequisites, group rules, trial-once) → 4. `ValidateForm` (core + custom fields, consent, CAPTCHA, throttling) → 5. `BuildQuote` (tax, VAT/VIES) → 6. `ResolveAccount` (login, or create the user with a pending flag) → 7. `CreateOrder` (status `pending`, idempotency key = form token) → 8. `Pay` (gateway: returns `completed`, `redirect`, `requires_action` (SCA), or `failed`) → 9. `Fulfil` (activate membership(s), create subscription, record coupon use, allocate invoice number, queue emails and integrations) → 10. `Redirect` (confirmation page).

Steps 8–9 for offsite or async gateways finish on the return URL **or** the webhook, whichever arrives first. `Fulfil` is idempotent: it takes a lock on the order and checks status before doing anything.

**Proration** (per level group, `proration_mode`):
- `none` — new level starts now at full price; old one ends.
- `prorate_immediate` (default for upgrades) — credit = unused fraction of the last paid period (seconds-based, net of tax) × amount paid; charge = new first payment − credit; a negative result becomes a free-period extension on the new subscription (credit ÷ new daily rate, in seconds). The new billing anchor is now.
- `keep_anchor` — charge the prorated difference now and keep the existing renewal date (gateway support required; Stripe-native proration is used when the gateway is Stripe).
- Downgrades follow the group's `downgrade_timing`: `period_end` (default) schedules the change at `current_period_end`; `immediate` applies the same credit maths.
- Rounding: banker's rounding to the currency's minor unit. Results under the gateway's minimum charge become an extension instead of a charge.

---

## 7. Payment gateway interface

```php
namespace Memberkin\Gateway\Contracts;

interface Gateway {
    public function id(): string;                         // 'stripe'
    public function label(): string;
    public function capabilities(): Capabilities;         // see below
    public function settings_schema(): array;             // fields, rendered by the Settings framework
    public function is_available( Mode $mode, ?Quote $quote = null ): bool;  // configured + currency supported

    // Checkout
    public function start_payment( PaymentContext $ctx ): PaymentResult;     // one-time or first payment (+ subscription setup)
    public function complete_payment( PaymentContext $ctx, array $return_params ): PaymentResult; // offsite return / SCA confirm
    public function render_payment_fields( PaymentContext $ctx ): string;    // on-site element container

    // Site-managed billing
    public function charge_saved_method( ChargeRequest $req ): PaymentResult; // renewal or retry (off-session)

    // Subscriptions (gateway-managed)
    public function create_subscription( SubscriptionContext $ctx ): SubscriptionResult;
    public function cancel_subscription( Subscription $s, bool $at_period_end ): Result;
    public function pause_subscription( Subscription $s, ?\DateTimeImmutable $resume_at ): Result;
    public function resume_subscription( Subscription $s ): Result;
    public function change_subscription( Subscription $s, PriceSchedule $new, ProrationPlan $plan ): Result;
    public function fetch_subscription( Subscription $s ): ?RemoteSubscription; // reconciliation

    // Money back
    public function refund( Order $o, Money $amount, string $reason ): RefundResult;

    // Payment methods
    public function payment_method_update( Subscription|int $target, string $return_url ): PaymentMethodUpdateResult; // client secret, portal URL, or form

    // Webhooks
    public function verify_webhook( \WP_REST_Request $r ): VerifiedWebhook;   // throws InvalidSignature
    /** @return NormalizedEvent[] */
    public function parse_webhook( VerifiedWebhook $w ): array;
}
```

`AbstractGateway` provides HTTP (timeouts, retries with backoff on 429/5xx, idempotency-key headers, redacted logging), settings and mode handling, and throws `UnsupportedOperation` for capabilities the gateway lacks. The UI hides any action whose capability is missing.

**Capabilities flags:** `one_time`, `recurring_gateway`, `recurring_site` (token charging), `trials`, `signup_fee`, `refund_full`, `refund_partial`, `cancel`, `pause`, `change_plan`, `payment_method_update`, `saved_methods`, `onsite_fields`, `offsite_redirect`, `webhooks`, `merchant_of_record` (Paddle: skip our tax), `currencies[]`, `min_amounts[]`.

**Normalized webhook events:** `payment.succeeded`, `payment.failed`, `payment.action_required`, `refund.succeeded`, `dispute.opened`, `dispute.closed`, `subscription.activated`, `subscription.renewed`, `subscription.updated`, `subscription.past_due`, `subscription.paused`, `subscription.resumed`, `subscription.cancelled`, `subscription.expired`, `payment_method.updated`, `mandate.cancelled` (GoCardless). A single `WebhookDispatcher` maps these onto the services, so per-gateway code only parses events.

**Webhook endpoint:** `POST /wp-json/memberkin/v1/webhooks/{gateway}` (PayFast/CCBill legacy-style endpoints accept form posts on the same route).
Flow: verify signature → insert into `memberkin_webhook_events` (unique event ID; a duplicate returns 200 immediately) → queue `memberkin_process_webhook` → respond 200 within milliseconds. In safe mode, events are stored but not processed. A failed job retries with backoff (5 attempts), then is marked `failed` with an admin notice and a "Replay" button.

**Signature verification per gateway**
| Gateway | Method |
|---|---|
| Stripe | `Stripe-Signature` HMAC-SHA256 over `t.payload`, 5-minute tolerance, multiple `v1` signatures (secret rotation) |
| PayPal | `POST /v1/notifications/verify-webhook-signature` with transmission headers + webhook ID |
| Authorize.net | `X-ANET-Signature` HMAC-SHA512 with the Signature Key |
| Square | `x-square-hmacsha256-signature` = HMAC-SHA256(notification URL + body) |
| Paddle | `Paddle-Signature` `ts=…;h1=…` HMAC-SHA256 over `ts:body`, tolerance |
| GoCardless | `Webhook-Signature` HMAC-SHA256 of the body |
| Adyen | HMAC-SHA256 `additionalData.hmacSignature` per notification item + Basic Auth on the endpoint |
| Paystack | `x-paystack-signature` HMAC-SHA512 with the secret key |
| PayFast | MD5 signature with passphrase + valid-host check (DNS of PayFast hosts) + server-side `validate` POST + amount check |
| CCBill | Source IP within CCBill ranges (REMOTE_ADDR only), else confirmation through the DataLink API; amount and subscription cross-check |
| Offline | No webhooks |

All comparisons use `hash_equals()`. Raw bodies are read once via `$request->get_body()`.

**Credentials (wp-config constants override settings):**
`MEMBERKIN_STRIPE_{LIVE|TEST}_SECRET_KEY`, `…_PUBLISHABLE_KEY`, `…_WEBHOOK_SECRET`; `MEMBERKIN_PAYPAL_{LIVE|TEST}_CLIENT_ID`, `…_CLIENT_SECRET`, `…_WEBHOOK_ID`; `MEMBERKIN_AUTHNET_{LIVE|TEST}_LOGIN_ID`, `…_TRANSACTION_KEY`, `…_SIGNATURE_KEY`, `…_CLIENT_KEY`; the same pattern for `SQUARE`, `PADDLE`, `GOCARDLESS`, `ADYEN`, `PAYSTACK`, `PAYFAST`, `CCBILL`; `MEMBERKIN_ENCRYPTION_KEY` (optional; otherwise a key derived from `AUTH_KEY`/`SECURE_AUTH_SALT`); `MEMBERKIN_GATEWAY_MODE` (`test`/`live`) to force test mode on staging.

**Phase order for gateways:** Offline → Stripe → PayPal → Authorize.net (Phase 3); Square → Paddle → GoCardless → Adyen → Paystack → PayFast → CCBill (Phase 4).

---

## 8. Scheduled jobs (Action Scheduler)

Action Scheduler (`woocommerce/action-scheduler`, GPLv3, loaded through Composer; if another plugin loads a newer copy, AS's own version resolver picks it). Group: `memberkin`. A recurring "heartbeat" registers all recurring actions on activation and repairs them if missing.

| Hook | Schedule | Purpose |
|---|---|---|
| `memberkin_process_webhook` (event_id) | async | Process a stored webhook event |
| `memberkin_renewal_charge` (subscription_id, period_key) | single, at `next_payment_at` | Site-managed renewal: create renewal order → `charge_saved_method` → fulfil or start dunning. Unique per (subscription, period). |
| `memberkin_payment_retry` (subscription_id, attempt) | single, at `next_retry_at` | Retry schedule (default +1, +3, +5, +7 days; configurable). After the last failure: cancel or end the subscription and membership as configured. |
| `memberkin_expire_memberships` | every 15 min | Batch (≤ 200 per run) of memberships with `ends_at` ≤ now, or `grace_until` passed → end + fallback level + emails |
| `memberkin_scheduled_changes` | every 15 min | Downgrades at period end, auto-resume pauses, scheduled cancellations, approvals auto-expire |
| `memberkin_reminders` | hourly | Expiring, renewal upcoming, trial ending, card expiring, plus the configurable reminders; each send recorded in `memberkin_events` (dedupe key) |
| `memberkin_drip_notifications` | daily | "New content available" |
| `memberkin_send_email` (log_id) | async | Queued email delivery with 3 retries |
| `memberkin_integration_sync` (provider, user_id) | async, debounced 60 s | CRM/ESP/Discord sync |
| `memberkin_webhook_delivery` (delivery_id) | async + retries (1 m, 5 m, 30 m, 2 h, 12 h) | Outgoing webhooks |
| `memberkin_reconcile_subscriptions` | daily | Compare active gateway-managed subscriptions with the gateway (batched) and log drift |
| `memberkin_stats_rollup` | daily | Aggregate daily metrics and the MRR snapshot |
| `memberkin_cleanup` | daily | Expire checkout sessions, prune logs/webhook payloads per retention, anonymise IPs, delete abandoned signups (if enabled) |

**Concurrency:** renewal and fulfilment take a MySQL named lock (`GET_LOCK('memberkin_sub_{id}', 0)`) and re-check status inside the lock. **Safe mode:** if `memberkin_site_fingerprint` (home URL + DB name hash) changes, renewals, retries, outgoing webhooks and member emails are paused, and an admin notice offers "This is the live site — resume" or "Keep paused (staging)". `MEMBERKIN_GATEWAY_MODE=test` forces sandbox credentials.

---

## 9. Access control engine

`AccessService::decide( int $user_id, AccessTarget $target ): AccessDecision`
→ `{ granted: bool, reason: 'no_rules'|'member'|'not_logged_in'|'wrong_level'|'drip_pending'|'expired_window'|'paused'|…, required_levels: int[], available_at: ?DateTimeImmutable, behavior, message }`.

Resolution order: admin bypass (`memberkin_bypass_restrictions` capability; on by default for admins, can be switched off for "view as") → direct `content_access` rows for the post and its terms → compiled rules matching post type / parent / terms / URI → result. Multiple matching restrictions use OR across levels within one restriction and AND across restrictions (configurable to OR). Drip is evaluated against the matching membership's `starts_at`.

The user's active memberships are loaded once per request with a single indexed query and cached in the object cache (`memberkin_user_memberships_{id}`, invalidated on any membership write).

**Enforcement surfaces**
| Surface | Mechanism |
|---|---|
| Singular views | `template_redirect` → redirect (if configured) else content replacement |
| Content | `the_content` (priority 5, unhook-safe with builders), `get_the_excerpt`, `render_block` for block themes, `post_password_required`-style template parts |
| Archives & search | `posts_where`/`posts_join` with `NOT EXISTS` against `content_access` (indexed) when "hide restricted content" is on; otherwise teaser |
| Feeds | `the_content_feed`, `the_excerpt_rss` → teaser; optional per-member private feed token URL |
| REST API | `rest_prepare_{post_type}` → content/excerpt replaced and `memberkin_restricted: true` added; `rest_{post_type}_query` exclusion when hiding; comments (`rest_comment_query`, `rest_prepare_comment`); search endpoint; attachments of restricted posts |
| Comments | `comments_array`, `comments_open`, `the_comments`, comment feeds |
| Sitemaps | `wp_sitemaps_posts_query_args` excludes hidden content |
| oEmbed / XML-RPC | `oembed_response_data` teaser; XML-RPC `xmlrpc_prepare_post` filter |
| Menus | `wp_nav_menu_objects` per-item visibility settings |
| Files | Rewrite `/memberkin-file/{token}` → handler: decide → stream with `X-Sendfile` / `X-Accel-Redirect` when configured; directory protected with `.htaccess`, plus an nginx snippet in docs |
| Partial content | Block "Members Content" (show/hide for levels, logged-in, non-members, with a fallback message) and `[memberkin_restrict levels="1,2" show_to="members" message="…"]` |

---

## 10. Public developer API

### 10.1 Actions (selection; full list in `DEVELOPER.md`)
`memberkin_loaded`, `memberkin_module_registered`,
`memberkin_membership_created`, `memberkin_membership_status_changed( $membership, $from, $to, $context )`, `memberkin_membership_activated`, `memberkin_membership_ended`, `memberkin_membership_paused`, `memberkin_membership_resumed`, `memberkin_membership_suspended`, `memberkin_membership_changed( $old, $new )`,
`memberkin_subscription_status_changed`, `memberkin_subscription_renewed`, `memberkin_subscription_payment_failed`, `memberkin_subscription_cancelled`,
`memberkin_order_created`, `memberkin_order_paid`, `memberkin_order_refunded( $order, $refund )`, `memberkin_order_failed`,
`memberkin_checkout_before_pay`, `memberkin_checkout_completed`, `memberkin_checkout_failed`,
`memberkin_webhook_received`, `memberkin_webhook_processed`,
`memberkin_access_denied( $decision, $target )`, `memberkin_email_sent`.

### 10.2 Filters (selection)
`memberkin_access_decision`, `memberkin_restrictable_post_types`, `memberkin_restrictable_taxonomies`, `memberkin_unauthorized_message`,
`memberkin_quote`, `memberkin_quote_line_items`, `memberkin_tax_rate`, `memberkin_proration_plan`,
`memberkin_checkout_steps`, `memberkin_checkout_fields`, `memberkin_checkout_validation`,
`memberkin_gateways`, `memberkin_gateway_request_args`, `memberkin_retry_schedule`,
`memberkin_email_templates`, `memberkin_email_merge_tags`, `memberkin_email_content`,
`memberkin_account_sections`, `memberkin_level_cost_text`, `memberkin_template_path`, `memberkin_rest_capability`,
`memberkin_members_list_columns`, `memberkin_export_columns`.

### 10.3 PHP API (stable, documented)
`memberkin()->memberships()->for_user( $user_id )`, `memberkin_has_level( $levels, $user_id = null )`, `memberkin_user_can_access( $post_id, $user_id = null )`, `memberkin_grant_membership( $user_id, $level_id, array $args )`, `memberkin_end_membership( $membership_id, $reason )`, `memberkin_get_level( $id_or_slug )`, `memberkin_format_price( $money )`.

### 10.4 REST API `memberkin/v1`
Authentication: cookie + `X-WP-Nonce`, or WordPress Application Passwords. Every route has a `permission_callback` that checks a capability **and** ownership for user-scoped resources (a member can only read their own orders/subscriptions, and only through `/me/*`).

| Route | Methods | Capability |
|---|---|---|
| `/levels`, `/levels/{id}` | GET (public: active levels, public fields only), POST/PUT/DELETE | `memberkin_manage_levels` |
| `/level-groups` | CRUD | `memberkin_manage_levels` |
| `/members`, `/members/{user_id}` | GET | `memberkin_view_members` |
| `/memberships` | GET, POST (grant) | `memberkin_manage_members` |
| `/memberships/{id}` | GET, PATCH (status / ends_at), POST `/cancel`, `/pause`, `/resume`, `/suspend` | `memberkin_manage_members` |
| `/subscriptions`, `/subscriptions/{id}` (+ `/cancel`, `/pause`, `/resume`) | GET/POST | `memberkin_manage_orders` |
| `/orders`, `/orders/{id}`, `/orders/{id}/refunds` | GET, POST (manual order, mark paid), POST refund | `memberkin_manage_orders` / `memberkin_refund_orders` |
| `/coupons` | CRUD | `memberkin_manage_coupons` |
| `/rules`, `/content-access` | CRUD | `memberkin_manage_rules` |
| `/access/check?user_id&post_id` | GET | `memberkin_view_members` (or own user) |
| `/quote` | POST | public (rate-limited) |
| `/me`, `/me/memberships`, `/me/subscriptions`, `/me/orders`, `/me/invoices/{number}` | GET, plus member actions | logged in, own data only |
| `/webhooks/{gateway}` | POST | public, signature-verified |
| `/reports/{report}` | GET | `memberkin_view_reports` |
| `/exports`, `/imports` | POST + status | `memberkin_export` / `memberkin_import` |

Schemas are declared on each controller (`get_item_schema`), so the API is self-describing through `OPTIONS`. Pagination uses the standard `X-WP-Total` and `X-WP-TotalPages` headers.

### 10.5 Templates
`templates/{checkout,account,login,levels,confirmation,invoice,directory,…}/*.php` are located through `memberkin_locate_template()`: child theme `memberkin/`, then parent theme `memberkin/`, then the plugin. Each template has a `@version` header; outdated overrides are flagged in Site Health.

### 10.6 Blocks and shortcodes
One server-rendered block per frontend feature (levels/pricing table, checkout, account and its sections, login, registration, profile edit, members content, member directory, single level price/name/button, courses lists, invoice). Each has a matching shortcode (`[memberkin_levels]`, `[memberkin_checkout]`, `[memberkin_account]`, `[memberkin_login]`, `[memberkin_restrict]`, `[memberkin_directory]`, …). Blocks are built with `@wordpress/scripts`; the `build/` output is committed so no Node build is needed to install.

---

## 11. Admin UI (plain PHP)

Top-level menu **Memberships**: Dashboard · Members · Memberships · Subscriptions · Orders · Levels (with groups) · Access Rules · Coupons · Reports · Emails · Settings (tabs: General, Pages, Checkout, Payments, Tax & Invoices, Access, Accounts, Emails, Modules, Advanced) · Tools (Import/Export, Migration, Logs, Webhook Events, Jobs → Action Scheduler screen, System status).

Post/term editors get a "Membership access" meta box / term field. Users list gets a level column and a level filter. All screens follow the core admin markup (`wrap`, `wp-heading-inline`, `form-table`, `postbox`, `notice`), nonces on every form and row action, and capability checks on every screen.

---

## 12. Security model

- **Capabilities:** `memberkin_manage_settings`, `memberkin_manage_levels`, `memberkin_view_members`, `memberkin_manage_members`, `memberkin_manage_orders`, `memberkin_refund_orders`, `memberkin_manage_coupons`, `memberkin_manage_rules`, `memberkin_manage_emails`, `memberkin_view_reports`, `memberkin_export`, `memberkin_import`, `memberkin_bypass_restrictions`. Administrators get all; the "Membership Manager" role gets everything except settings, import and refunds (configurable).
- Nonces on every state-changing request; `check_admin_referer` / `wp_verify_nonce`; REST nonces.
- Input: per-field sanitisers in the settings schema and form definitions; output escaped at render (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for admin-authored HTML).
- SQL only in repositories through `$wpdb->prepare`; `ORDER BY` and column names come from allow-lists.
- IDOR: public URLs use random order numbers and checkout tokens; member endpoints scope by `get_current_user_id()`.
- Throttling: checkout and login failures per IP and per email (transients, sliding window); card-testing lockout after N declines.
- Secrets: never output into HTML; settings fields show "•••• last 4"; logs redact keys, tokens, card data, emails (optional).
- PCI: card data never touches the server (Stripe Elements, PayPal, Accept.js, Square SDK, Adyen Drop-in, hosted pages).
- File protection directory with deny rules; protected-file streaming checks MIME types against an allow-list and blocks path traversal.
- Uploads (custom file fields): restricted MIME types and size limits, stored in the protected directory.

---

## 13. Performance

- Per-request membership cache + object-cache groups; compiled access rules; no per-pageview writes (stats buffered in the object cache or transients and flushed by the rollup job; opt-in).
- Every list screen query uses an index (documented per table); member search uses `users` + memberships joins with LIMIT/OFFSET or keyset pagination over 50k rows.
- Background exports and imports in chunks; reports read `stats_daily` plus indexed aggregates.
- Target budget: ≤ 3 extra queries on a cached restricted singular view; ≤ 1 on unrestricted views. Verified in Phase 5 with Query Monitor and a seeded 100k-member dataset.

---

## 14. Multisite (per-site)

Network activation loops over sites in batches (Action Scheduler on large networks) and runs the installer per site; `wp_initialize_site` installs on new sites; `wp_uninitialize_site` drops tables if the site opted in. Tables use the per-site `$wpdb->prefix`. Capabilities are per site. Super admins get bypass by default. No network-shared data.

---

## 15. Third-party dependencies

| Dependency | Why | License |
|---|---|---|
| `woocommerce/action-scheduler` | Reliable queue with retries, locking, admin UI; the de-facto WP standard | GPL-3.0 |
| `dompdf/dompdf` (+ `phenx/php-font-lib`, `phenx/php-svg-lib`, `masterminds/html5`), PHP-Scoper-prefixed under `Memberkin\Vendor\` | PDF invoices with UTF-8 fonts (DejaVu) for international names and addresses | LGPL-2.1 / MIT |
| Dev only: `phpunit/phpunit`, `yoast/phpunit-polyfills`, `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp`, `wp-cli/wp-cli-bundle`, `@wordpress/scripts` | Testing, standards, block build | various |

No gateway SDKs. JavaScript libraries are loaded from the gateways' own required origins only where the gateway mandates it (Stripe.js, PayPal JS SDK, Accept.js, Square Web Payments SDK, Adyen Web Drop-in, Paddle.js, Paystack inline).

---

## 16. Testing strategy (Phase 5, built up from Phase 3)

- **Unit** (no WP): Money, PriceSchedule, Quote, proration, tax resolution, state machines, signature verifiers (with recorded vectors).
- **Integration** (WP test suite + MySQL test DB): repositories, services, checkout pipeline with the Offline gateway and a **fake gateway** implementing every capability, webhook dispatcher with recorded payloads for each real gateway, access engine across all surfaces, REST permission matrix, emails, jobs (run synchronously in tests).
- **E2E flows:** free/paid/trial signup, renewal (site + gateway managed), failure → retries → recovery/termination, refund (full/partial), cancel now/at period end, pause/resume, upgrade/downgrade with proration, expiration + fallback, coupon limits.
- **Sandbox runs** against real gateway test modes once credentials are present; otherwise features are marked `implemented – untested (needs credentials)`.
- PHPCS (WordPress-Extra + PHPCompatibilityWP 8.1+), query-count assertions, seeded large dataset performance checks.

---

## 17. Implementation order

### Phase 3 — Core
1. **Foundation:** bootstrap, Composer, container, module registry, installer + migrations (all core tables), settings framework, capabilities/role, logger, Money/Currency, Crypto, HTTP client, Action Scheduler wiring, safe mode, template loader, multisite hooks, test harness + CI scripts.
2. **Levels:** repository/service, level groups, admin list + edit screens, pricing schedule validation.
3. **Memberships:** service + state machine, events/history, admin grant/change/suspend/end, expiry job, user-memberships cache.
4. **Pricing:** Quote builder, coupons (module), proration calculator.
5. **Billing:** orders, order items, refunds, subscriptions, payment methods; Orders and Subscriptions admin screens.
6. **Gateway framework + Offline gateway + fake test gateway;** webhook endpoint + event log + dispatcher.
7. **Checkout + Accounts:** pipeline, checkout page, confirmation, login/logout/reset, registration, account dashboard (memberships, subscriptions, history), page setup.
8. **Access control:** content assignments, rules engine, all enforcement surfaces, partial content (shortcode; block in Phase 4), unauthorized handling.
9. **Emails:** templates, renderer, queue, log, core member/admin notifications.
10. **Renewals & dunning:** site-managed renewal job, retries, past-due/grace, reminders (fixed set).
11. **Stripe** → 12. **PayPal** → 13. **Authorize.net**.
14. **Tax & EU VAT** (rates, VIES, reverse charge, evidence) → 15. **Invoices** (sequences, PDF, credit notes, account downloads).
16. **Members admin** polish (list filters, bulk actions, member detail with all panels).
17. **REST API** v1 (all core routes + permission matrix tests).

### Phase 4 — Advanced
18. Custom fields · 19. Drip + content expiry + drip emails · 20. Protected files (+ Download Monitor) · 21. Approvals · 22. Group accounts (+ sponsored) · 23. Gifts · 24. Pause/resume UI polish, change-plan UI, auto-renew checkbox, order bumps, variable pricing · 25. Configurable reminders, abandoned checkout recovery, admin digest · 26. Reports (revenue, signups, churn, MRR, LTV, OSS tax report) · 27. Blocks for all frontend features · 28. Security module (CAPTCHAs, Akismet, strong passwords) · 29. Remaining gateways: Square, Paddle, GoCardless, Adyen, Paystack, PayFast, CCBill · 30. Email marketing adapters (9) · 31. Webhooks & Automation (+ Slack) · 32. Courses + LMS adapters (5) · 33. Directory & profiles, avatars, membership card · 34. Community (bbPress, BuddyPress/BuddyBoss, Discord), Events, WooCommerce, Affiliates, SSP feeds, WP Activity Log, WP Fusion, Unlock · 35. Page-builder compatibility, menus, "view as" · 36. Multi-currency · 37. Import/Export CSV · 38. Migration importers (PMPro, MemberPress) · 39. Privacy exporters/erasers · 40. WP-CLI, Abilities API, Site Health, setup wizard.

### Phase 5–6
Test completion, end-to-end flows, security/performance audit, PHPCS clean-up, `QA_REPORT.md`, `DEVELOPER.md`, `readme.txt`.

`PROGRESS.md` and `FEATURE_CHECKLIST.md` are updated after each numbered step.
