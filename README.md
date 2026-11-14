# Smarfle Booking & Forms for WordPress

Online booking with real free times and contact forms that send every inquiry to your [Smarfle](https://www.smarfle.com) Inbox. For US home service and local businesses with a Smarfle account (14-day free trial, plans from $19 a month).

- **Booking form**: your Smarfle services and real free times (the next 14 days); bookings land in your Smarfle calendar, confirmed or as requests by your approval setting.
- **Contact form**: every inquiry in your Smarfle Inbox with the page and ad that brought it. Without Smarfle, it emails you.
- **Contact Form 7 and WPForms**: one click sends their entries to Smarfle too.
- Gutenberg blocks, Elementor widgets and shortcodes (`[smarfle_booking]`, `[smarfle_contact_form]`), drawn by one renderer.
- Light: about 9 KB of script and 2 KB of styles, only on pages with a form; no jQuery or React; no tracking or cookies. The Smarfle site key stays on your server.

## Install

Install **Smarfle Booking & Forms** from Plugins > Add New in WordPress (or upload a release zip), open the **Smarfle** menu and press **Connect to Smarfle**.

## External services

The plugin talks to Smarfle (`https://www.smarfle.com/api/plugin/v1/...`): the connection, the weekly business info, booking times when a visitor opens the booking form, and each inquiry or booking a visitor sends. Every address and what is sent is listed in [readme.txt](readme.txt) under "External services".

## Development

`includes/core` is the Smarfle core shared by all Smarfle plugins (one connection per site; the newest copy loads). Code follows the WordPress coding standards (PHPCS) and supports PHP 7.4+ and WordPress 6.4+.

## License

GPLv2 or later. See [LICENSE](LICENSE).
