# Coinsnap for Drupal Commerce

![Coinsnap for Drupal Commerce](https://resources.coinsnap.org/products/drupal/images/cover.png)

**Bitcoin payment module for Drupal Commerce — accept Bitcoin and Lightning with Coinsnap.**

Coinsnap for Drupal Commerce is an open-source Bitcoin payment module for Drupal sites using Drupal Commerce. It integrates Bitcoin payments into the existing Drupal Commerce checkout and payment workflow.

Customers can pay with **Bitcoin via Lightning or on-chain**. When using Coinsnap, merchants do not need to operate their own Bitcoin or Lightning payment infrastructure.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

## Key features

- Accept **Bitcoin and Lightning payments** in Drupal Commerce
- Support for **Lightning and Bitcoin on-chain**
- Native integration with **Drupal Commerce**
- Payment gateway configuration through Drupal Commerce
- No own Lightning node or Bitcoin payment server required
- **Non-custodial / self-custody** approach
- Settlement to a compatible Bitcoin or Lightning wallet
- Open source under the GPL-2.0 License
- Available through the Drupal module ecosystem

## Requirements

The module metadata currently identifies version **2.0.8**.

Supported Drupal core versions:

- **Drupal 8**
- **Drupal 9**
- **Drupal 10**

Additional requirements:

- **Drupal Commerce 2.x**
- **PHP 8.1 or newer**
- A **Coinsnap merchant account**

For Bitcoin settlement, you can use a compatible Bitcoin or Lightning wallet. One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/), which combines a self-custodial Bitcoin wallet with merchant and point-of-sale functionality.

## How it works

1. Create a [Coinsnap merchant account](https://app.coinsnap.io/register).
2. Install the Coinsnap Commerce Payment module in Drupal.
3. Enable the module.
4. Go to the Drupal Commerce payment gateway settings.
5. Add or edit the **Coinsnap Payment** gateway.
6. Enter your Coinsnap **Store ID** and **API key**.
7. Save the gateway configuration.
8. Perform a test transaction before going live.

## Installation

### Install from Drupal.org

The module is available in the Drupal module ecosystem:

[Coinsnap Commerce Payment on Drupal.org](https://www.drupal.org/project/drupalcommerce_coinsnap)

You can install it through the standard Drupal module workflow or Composer.

### Install with Composer

From the root directory of your Drupal project, use the package instructions provided on the Drupal.org project page.

### Install from GitHub

You can also download the module source from this repository and install it through Drupal's standard module installation process.

## Configuration

After installation:

1. Log in to the Drupal administration.
2. Go to **Commerce → Configuration → Payment → Payment gateways**.
3. Find or add **Coinsnap Payment**.
4. Enter the desired gateway name and display name.
5. Enter your **Coinsnap Store ID**.
6. Enter your **Coinsnap API key**.
7. Save the gateway configuration.
8. Test the checkout flow.

Your Store ID and API key are available in your Coinsnap merchant account.

## Resources

- [Coinsnap for Drupal product page](https://coinsnap.io/shop-systems/drupal/)
- [Coinsnap Commerce Payment on Drupal.org](https://www.drupal.org/project/drupalcommerce_coinsnap)
- [Coinsnap Wallet](https://coinsnap.io/wallet/)
- [Coinsnap API documentation](https://docs.coinsnap.io/)
- [Coinsnap merchant account](https://app.coinsnap.io/register)
- [Coinsnap website](https://coinsnap.io/)
- [Telegram community and support](https://t.me/coinsnap)

> **Brand note:** Coinsnap is Bitcoin payment software for merchants and is not the provider of the similarly named coin identifier app "CoinSnap".

## Frequently Asked Questions

### What do I need to accept Bitcoin in Drupal Commerce?

You need a supported Drupal installation, Drupal Commerce 2.x, PHP 8.1 or newer, and a Coinsnap merchant account.

### Which Drupal versions are supported?

The current module metadata declares support for Drupal **8, 9 and 10**.

### Can customers pay with both Lightning and Bitcoin on-chain?

Yes. Coinsnap supports Bitcoin payments via both the Lightning Network and Bitcoin on-chain.

### Which Bitcoin or Lightning wallets can I use?

You can use a compatible Bitcoin or Lightning wallet for settlement. One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

Other compatible wallets may include solutions such as Wallet of Satoshi, Blink and Zeus, depending on the settlement setup you choose.

### Does Coinsnap hold my Bitcoin?

Coinsnap follows a non-custodial approach and does not hold merchant Bitcoin. Payments are settled to the merchant's configured wallet or payment destination.

### Do I need my own Lightning node?

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

### Where do I find my Store ID and API key?

They are available in your Coinsnap merchant account and are entered in the Drupal Commerce payment gateway configuration.

### Is the module open source?

Yes. The module is open source and available both on GitHub and through Drupal.org.

### Where can I get support?

Use the support area in your Coinsnap merchant account or join the official [Coinsnap Telegram channel](https://t.me/coinsnap).

## Module metadata

- Module: **Coinsnap Commerce Payment**
- Drupal project: **drupalcommerce_coinsnap**
- Module version: **2.0.8**
- Drupal core requirement: **^8 || ^9 || ^10**
- Drupal Commerce requirement: **^2.0**
