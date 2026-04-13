# Fixed Multicurrency Prices for Dolibarr

A Dolibarr module that lets you set **fixed selling prices per currency per product**, overriding the automatic exchange-rate conversion provided by the built-in multicurrency module.

## The Problem

Dolibarr's multicurrency module auto-converts your base currency price using the current exchange rate. If you sell a product for **$3,750 USD** and the CAD rate is 1.38, Dolibarr calculates **$5,175 CAD** automatically. But your actual Canadian MSRP might be **$5,000 CAD** -- a round number that doesn't move with the exchange rate.

## The Solution

This module adds a **fixed price override** per currency on each product. When a user creates a proposal, sales order, or invoice for a customer in that currency, the fixed price is used instead of the auto-converted amount.

## Features

### Fixed Price Management
- Set a fixed selling price (excl. tax) for any product in any active multicurrency
- Enable/disable individual fixed prices without deleting them
- Per-product divergence threshold overrides (or inherit from parent product / global default)

### List View (Products > Fixed Prices)
- Pivoted layout: one row per product, one column group per active currency (CAD, EUR, etc.)
- Shows fixed price, auto-converted price, and divergence % side by side
- Inline editing: click the pencil to edit all currencies for a product in one row
- Toggle switches to enable/disable each currency's override
- Sortable and filterable by product ref and label
- Tooltips throughout explaining each column and showing current exchange rates

### Price Override on Documents
- **Proposals**: injects the fixed multicurrency price directly -- exact to the cent
- **Invoices**: same direct injection as proposals
- **Sales Orders**: back-calculates the base currency price from the fixed price (Dolibarr's order card handles multicurrency differently)
- Products without a fixed price use normal auto-conversion (no interference)
- Documents in the base currency are never affected

### Dashboard Widget
- "Fixed Price Divergence Alerts" box for the home dashboard
- Shows products whose fixed prices have drifted past their configured threshold
- Sorted by worst divergence first

### Product Price Page Integration
- "Fixed Multicurrency Prices" section appears on each product's Selling Prices tab
- Set/edit/toggle/delete fixed prices per currency with divergence indicators

### Admin Settings
- **Default divergence threshold %** -- triggers color-coded warnings (green/amber/red)
- **Warn on apply** -- toggle for per-line notifications when fixed prices are used
- **Debug mode** -- exposes a diagnostic endpoint for troubleshooting

## Requirements

- Dolibarr 16.0+ (tested on 22.0.4)
- PHP 7.0+
- Multicurrency module enabled with at least one non-base currency configured

## Installation

1. Download the latest `fixedprice-X.Y.Z.zip`
2. In Dolibarr: **Home > Setup > Modules > Deploy external module** -- upload the zip
3. Search for "Fixed Multicurrency Prices" in the module list and **enable** it
4. Go to **Home > Setup > Modules > Fixed Multicurrency Prices** to configure settings

After enabling, the "Fixed Prices" entry appears under the **Products** menu in the left sidebar.

## Configuration

Navigate to the module setup page (**Fixed Multicurrency Prices > Setup**):

| Setting | Default | Description |
|---------|---------|-------------|
| Divergence threshold | 10% | Fixed prices diverging more than this from auto-converted trigger warnings |
| Warn on apply | Yes | Show notifications when fixed prices override auto-conversion on documents |
| Debug mode | Off | Exposes `/custom/fixedprice/ajax/debug.php` for diagnostics |

### Per-Product Thresholds

Each fixed price can have its own divergence threshold. The resolution order is:
1. Per-product override (set on the product's price page)
2. Parent product override (for variants, inherited from the parent)
3. Global default (from module settings)

## File Structure

```
fixedprice/
  admin/setup.php                          # Settings page
  ajax/debug.php                           # Diagnostic endpoint
  class/actions_fixedprice.class.php       # Hooks: price page UI + addline interception
  class/fixedprice.class.php               # CRUD class for llx_product_fixed_price
  core/boxes/box_fixedprice_divergence.php  # Dashboard widget
  core/modules/modFixedprice.class.php     # Module descriptor
  fixedprice_list.php                      # List page with inline editing
  langs/en_US/fixedprice.lang              # Translation strings
  lib/fixedprice.lib.php                   # Shared helpers
  sql/llx_product_fixed_price.sql          # Table creation
  sql/llx_product_fixed_price.key.sql      # Indexes
```

## How It Works

### Price Interception

The module hooks into the `doActions` event on proposal, order, and invoice card pages. When a user adds a product line:

1. The hook checks if the document is in a non-base currency
2. Queries `llx_product_fixed_price` for an enabled fixed price matching the product + currency
3. If found, injects the fixed price into `$_POST['multicurrency_price_ht']` before Dolibarr's standard addline processing
4. Dolibarr's `calcul_price_total()` then back-calculates the base currency amount from the fixed price

For sales orders (which handle multicurrency differently in Dolibarr core), the base currency price is back-calculated and injected into `$_POST['price_ht']` instead.

### No Core Modifications

The module uses only Dolibarr's hook system -- no core files are modified. It sits in `htdocs/custom/fixedprice/` and can be removed cleanly by disabling the module.

## Development

### Local Development with Docker

```bash
cd fixedprice
docker compose up -d
# Open http://localhost:8080
# Login: admin / admin
# Enable the module in Home > Setup > Modules
```

### Debug Endpoint

With debug mode enabled, visit:
```
/custom/fixedprice/ajax/debug.php?mode=all
```

Available modes: `overview`, `prices`, `settings`, `sql`, `hooks`, `all`

## License

GPLv3 or later

## Credits

Built by DPG Supply for managing fixed CAD/EUR selling prices alongside USD base pricing in Dolibarr.
