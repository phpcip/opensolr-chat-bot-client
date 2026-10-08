# Opensolr Chat Bot Client

A chatbot for your website. It answers your visitors from your own content in your Opensolr Index, through the Opensolr API.

You install it with Composer, mount it on one URL path of your own site, set it up in its own admin page and add one script tag to your pages. Visitors talk only to your site. Your Opensolr credentials stay on your server.

- [What it is](#what-it-is)
- [Requirements](#requirements)
- [Install](#install)
- [The admin](#the-admin)
- [Add the chat to your pages](#add-the-chat-to-your-pages)
- [What visitors get](#what-visitors-get)
- [Commands](#commands)
- [Captcha and limits](#captcha-and-limits)
- [Streaming through your server](#streaming-through-your-server)
- [Updating](#updating)
- [Security notes](#security-notes)
- [Translating the admin](#translating-the-admin)
- [License](#license)

## What it is

- A chat window for your site that answers questions from the content of your Opensolr Index: pages you crawled or documents you ingested.
- The answer is streamed: the visitor sees it as it is written, with a progress line ("Searching for: …") while the assistant searches.
- Answers link to what they use: your pages, your products with their prices, and PDFs at the exact page.
- The assistant knows the current date and time in your time zone, and can look for your latest content.
- Every visitor is answered in their own language.
- Commands such as `/search`, `/time`, `/rate` or `/translate` give a direct answer without waiting for the assistant.
- It runs on your server as one PHP front controller. Its data (a SQLite database and the logo) stays in a folder you choose: no database server.
- Open source, MIT license.

## Requirements

- PHP 8.1 or newer, with the extensions `pdo_sqlite`, `curl`, `json` and `mbstring`.
- Composer.
- An Opensolr account whose plan includes AI, and an Opensolr Index with your content.
- A web server that can send every request of one URL path to one PHP file (Apache with `mod_rewrite`, nginx, or similar).
- HTTPS on your site is recommended: the admin and captcha cookies are marked `Secure` when the request is HTTPS.
- Optional: a Google reCAPTCHA v2 ("I'm not a robot" checkbox) site key and secret key, for the captcha.

## Install

The examples use the URL path `/opensolr-chat`, the data folder `/opt/opensolr-chat` and a project laid out like this:

```text
your-project/
    composer.json
    vendor/
    public/                 <- your web root
        opensolr-chat/
            index.php
            .htaccess
```

### 1. Install the package

In your project folder:

```sh
composer require opensolr/chat-bot-client
```

### 2. Create the data folder

The chat keeps its settings, the admin password hash, the counters of the limits and the logo in this folder. It must be outside the web root and writable by the user PHP runs as (`www-data` here):

```sh
sudo mkdir -p /opt/opensolr-chat
sudo chown www-data:www-data /opt/opensolr-chat
sudo chmod 700 /opt/opensolr-chat
```

### 3. Add the front controller

Copy the two example files into a folder of your web root named like the URL path:

```sh
mkdir -p public/opensolr-chat
cp vendor/opensolr/chat-bot-client/examples/public/opensolr-chat/index.php public/opensolr-chat/index.php
cp vendor/opensolr/chat-bot-client/examples/apache.htaccess public/opensolr-chat/.htaccess
```

`public/opensolr-chat/index.php`:

```php
<?php
require __DIR__ . '/../../vendor/autoload.php';
(new Opensolr\ChatBot\App([
    'data_dir'  => '/opt/opensolr-chat',   // outside the web root, writable by PHP; the SQLite file lives here
    'base_path' => '/opensolr-chat',       // the URL path this file answers on
]))->run();
```

Change the `require` path if your `vendor/` folder is somewhere else.

| Option | What it is |
| --- | --- |
| `data_dir` | Required. The data folder from step 2. |
| `base_path` | The URL path the chat answers on. It must be the URL path of the folder that holds `index.php`. |
| `trusted_proxies` | Optional. A list of IP addresses or CIDR ranges of your own reverse proxies, load balancers or CDN. Only requests from them have their `X-Forwarded-For` (the visitor's IP address) and `X-Forwarded-Proto` (HTTPS) believed. Leave it out when visitors reach your web server directly. |

With a reverse proxy in front of PHP, set `trusted_proxies`, otherwise every visitor is counted under the proxy's IP address and shares one set of limits:

```php
(new Opensolr\ChatBot\App([
    'data_dir'        => '/opt/opensolr-chat',
    'base_path'       => '/opensolr-chat',
    'trusted_proxies' => ['127.0.0.1', '10.0.0.0/8'],
]))->run();
```

`public/opensolr-chat/.htaccess` (Apache):

```apache
Options -Indexes

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L,QSA]
</IfModule>

<IfModule mod_setenvif.c>
    SetEnvIf Request_URI "/chat$" no-gzip=1 dont-vary=1
</IfModule>
```

It sends every path of the folder to `index.php`, turns off directory listings and turns off compression for the streamed answer. The folder needs `AllowOverride FileInfo Options` (or `AllowOverride All`) in your Apache configuration.

#### nginx

nginx does not read `.htaccess`. Add this to the `server` block of your site, with your own PHP-FPM socket:

```nginx
location ^~ /opensolr-chat/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/opensolr-chat/index.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_buffering off;
    gzip off;
}
```

Every request under `/opensolr-chat/` goes to the front controller, and the streamed answer is neither buffered nor compressed. Reload nginx:

```sh
sudo nginx -t && sudo systemctl reload nginx
```

### 4. Set the admin password

In your project folder, as the user PHP runs as, so that the web server can write the database the command creates:

```sh
sudo -u www-data php vendor/bin/opensolr-chat-bot password /opt/opensolr-chat
```

It asks for the password twice (at least 12 characters). Run it again at any time to change the password; every admin session is signed out.

### 5. Open the admin

```text
https://your-site.example/opensolr-chat/admin
```

Sign in, then in the Account tab enter your Opensolr email and API key, click Test connection, choose your index and click Save settings.

If something does not work, your PHP error log has a line starting with `Opensolr Chat Bot:`.

## The admin

### Opening it

1. Until the admin password is set, the admin does not open: it shows the command of step 4.
2. Once both reCAPTCHA keys are saved (Captcha tab), a captcha page comes before any admin page. A solved captcha is valid for "Hours a solved captcha is valid", for that IP address and browser. The sign-in form then also has a captcha.
3. Sign in with the password. Five failed sign-ins from one IP address in 15 minutes block sign-ins from that address for up to 15 minutes.
4. The session ends with Sign out, when the browser is closed, or after 2 hours without activity.

All tabs are one form: Save settings saves every tab at once. Each tab and its settings follow.

### Account

| Setting | Default | Allowed |
| --- | --- | --- |
| Email | empty | the email of your Opensolr account, up to 254 characters |
| API key | empty | 8 to 200 printable characters. Once saved it is never shown again; leave the field empty to keep it. |
| Opensolr Index | none | one of the indexes listed by Test connection |

- Test connection checks the email and the API key with the Opensolr API, saves them when they work and lists the indexes of the account. With the API key field empty, it tests the saved key.
- Choose the index the chat answers from, then Save settings.
- When you save a different email or API key, the index is cleared: test the connection again and choose the index.
- Use a scoped API key. Create it in your Opensolr account and limit it to what this chat uses (listing your indexes for Test connection, the assistant, the lookups of the commands and the translation) and to the index the chat answers from. If Test connection says the key may not list the indexes, allow that in the key's scope.

### Assistant

| Setting | Default | Allowed |
| --- | --- | --- |
| Instructions | empty | up to 4,000 characters, line breaks allowed |
| Time zone | the time zone PHP runs in, else UTC | any time zone of the list |

- Instructions are optional: your own rules for the assistant, such as its tone, what to recommend and what to avoid. They are added after its built-in rules, which include: search this site, answer only from what the search finds, link every page, product and PDF page it mentions.
- Time zone is the assistant's "now", and the time zone of the dates in the answers of the commands.

### Chat window

| Setting | Default | Allowed |
| --- | --- | --- |
| Title | `Ask this site` | required, up to 100 characters |
| Text of the chat button | empty | up to 40 characters |
| Greeting | empty | up to 1,000 characters, line breaks allowed |
| Placeholder | `Ask a question…` | up to 200 characters |
| Logo | none | PNG, JPEG, GIF or WebP, up to 1 MB and 4,096 pixels on each side |
| Accent colour | empty, which means `#c05520` | a colour written like `#c05520` |

- Title: at the top of the chat window.
- Text of the chat button: shown next to the icon of the button that opens the chat. Empty: the icon only. On screens up to 480 pixels wide the button shows the icon only.
- Greeting: the first message of every conversation.
- Placeholder: the grey text in the empty message box.
- Logo: shown on the chat button, in place of the chat icon, and in the header of the chat window. The image type is read from the file's content. Tick "Remove the logo" to go back to the icon.
- Accent colour: the colour of the chat button, the header and the links.

### Limits

| Setting | Default | Allowed |
| --- | --- | --- |
| Characters per message | 1000 | 50 to 4,000 |
| Characters of a text to translate | 10000 | 50 to 20,000 |
| Questions per visitor | 30 | 1 to 100 |
| In this many seconds | 3600 | 60 to 2,592,000 (30 days) |
| Questions per conversation | 20 | 1 to 100 |

What each limit does is in [Captcha and limits](#captcha-and-limits).

### Captcha

| Setting | Default | Allowed |
| --- | --- | --- |
| Site key | empty | 10 to 100 characters: letters, digits, `_` and `-` |
| Secret key | empty | the same. Once saved it is never shown again; leave the field empty to keep it. |
| Hours a solved captcha is valid | 240 | 1 to 8,760 (a year) |

- The keys are Google reCAPTCHA v2 keys of the checkbox type, created for your site's domain.
- With both keys saved, visitors solve the captcha before their first question, and the admin asks for it before any page.
- To turn the captcha off, empty the Site key and save.

### Language

| Setting | Default | Allowed |
| --- | --- | --- |
| Language of this admin | the language of the browser | English, Română, Français, Deutsch, Español, 中文, 日本語 |

This is the language of the admin only. The chat answers every visitor in the visitor's own language.

### Add to your pages

Shows the script tag for your URL path, ready to copy. See the next section.

## Add the chat to your pages

Paste the script tag before `</body>` on every page that shows the chat:

```html
<script src="/opensolr-chat/widget.js" defer></script>
```

- A chat button appears at the bottom right of the page. It opens the chat window in the same corner.
- The pages and the chat must be on the same host name: the chat answers only requests sent from its own site.
- The widget is drawn in its own shadow DOM: your site's CSS does not change it, and its CSS does not change your site.
- Until the Account tab is set up, the chat answers "This chat is not set up yet."
- If your pages send a `Content-Security-Policy`, it must allow the script and requests to your own site (`script-src 'self'`, `connect-src 'self'`, `img-src 'self'` for the logo) and inline styles (`style-src 'unsafe-inline'`, the widget's styles). With the captcha on, also allow Google reCAPTCHA: `script-src https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/` and `frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/`.

## What visitors get

- **Streamed answers.** The answer appears as it is written. Before the first words, a progress line shows what the assistant is doing: "Reading your question…", "Searching for: …", "Reading the document …", "Answering…".
- **Formatted answers.** Paragraphs, lists, tables and code. Links open in a new tab. Only `http` and `https` links become links, and no HTML from an answer is ever put into your page.
- **Following the answer.** The window scrolls with the answer as it is written. Scrolling up stops that, so the visitor can read; back at the bottom, it follows again.
- **One question at a time.** While an answer is written, the message box, Send and New chat are locked.
- **New chat.** Starts a new conversation.
- **History in the browser.** The conversation (its last 100 messages) is kept in the visitor's browser, survives page loads and is the same in every tab of your site. A chat window left open stays open on the next page of the same tab. With each question the assistant receives up to the 10 messages before it, without the commands.
- **Arrow Up / Arrow Down.** Bring back the questions sent before, like a shell. Arrow Down past the newest brings back what was being written.
- **Enter** sends, **Shift+Enter** starts a new line.
- **"/" commands with autocomplete.** Typing `/` lists the commands that match what is typed. Arrow Up and Down move in the list, Enter or Tab picks one, Escape closes it.
- **The `?` button.** Lists every command with what it does and an example. Clicking an example puts it in the message box, to change or send. The `/translate` entry opens a table of the language codes, with a search box.
- **Resize.** Drag the corner at the top left of the window, or focus it and use the arrow keys. The size is kept in the browser.
- **Phones.** On screens up to 480 pixels wide the chat fills the screen and stays above the on-screen keyboard.
- **Escape** steps back: from the table of language codes to the command list, then closes the list, then the chat window.
- **Clear errors.** A message that is too long, a full conversation, a lost connection, an answer that stops coming for 45 seconds or takes more than 3 minutes: each is said in the chat window.

The buttons and messages of the widget, and the answers of the commands, are in English. The title, the button text, the greeting and the placeholder are the ones you write in the admin.

## Commands

A message that starts with `/` runs a command: a direct lookup through the Opensolr API, answered at once, without the assistant.

| Command | What it does | Example |
| --- | --- | --- |
| `/search <words>` | Searches this site and lists the pages, products and PDF pages that match, each with its link. | `/search robotic lawn mower` |
| `/time <place, coordinates or IP address>` | The local date and time right now at a place, at coordinates or where an IP address is. Without anything after it: your own local time. | `/time Tokyo` |
| `/rate <amount> <currency> to <currency>` | Today's exchange rate between currencies (three-letter codes), and the amount converted. Add a date as YYYY-MM-DD for a past day. Also `/currency`, `/exchange`. | `/rate 100 EUR to RON` |
| `/vat <country codes>` | The standard and the reduced VAT rates of one or more European countries or the United Kingdom (two-letter codes, separated by commas). | `/vat RO, DE` |
| `/vatcheck <VAT number>` | Checks whether an EU or UK VAT number is valid, and gives the company name and address it is registered to. | `/vatcheck IE6388047V` |
| `/distance <place> to <place>` | The straight-line distance between two places, addresses or coordinates, in kilometres and miles. | `/distance Paris to Berlin` |
| `/place <place, address or coordinates>` | Finds a place or a street address and gives its coordinates, region and country; give coordinates (latitude,longitude) to learn what is there. Each answer has a map link. Also `/geocode`, `/find`. | `/place Santa Clara, California, USA` |
| `/postal <postal code or town>` | Finds postal codes and the street addresses that have them: a code, a code and a town, or a town and a country. Also `/zip`. | `/postal 10115 Berlin` |
| `/ip <IP address>` | Where an IP address is: country, region, city, time zone and a map link. Without anything after it: your own approximate location. | `/ip 8.8.8.8` |
| `/language <text>` | Detects the language of a text and measures its sentiment (positive, negative or neutral; measured on English text). Also `/lang`, `/sentiment`. | `/language Acest produs este excelent` |
| `/translate <from>-<to> <text>` | Translates a text of up to "Characters of a text to translate" characters (10,000 by default) into another language. Write the two languages as codes from the table of language codes, joined by a hyphen (en-es: from English into Spanish), or only the language to translate into (es), and the language of the text is recognized. The translation is streamed. | `/translate en-es What is this?` |
| `/help` | This list of commands. Also `/?`, `/commands`. | `/help` |

- `/search` lists up to 6 results, each with its price and date when the page has them (dates in the admin's time zone) and up to 3 of its PDF pages.
- A command that is not in the list answers with the list.
- Commands do not count toward "Questions per conversation". They do count toward "Questions per visitor".

## Captcha and limits

### Captcha

With both reCAPTCHA keys saved:

1. The first time a visitor opens the chat, the reCAPTCHA checkbox appears in the chat window. A visitor who cancels can still read the chat; their first question brings the checkbox back, and the question is sent as soon as it is ticked.
2. The browser sends the solved captcha to your server, which checks it with Google, including that it was solved for your host name.
3. Your server then gives the browser a signed pass cookie (`HttpOnly`), valid for "Hours a solved captcha is valid" and tied to the visitor's IP address and browser.
4. Every question is checked against the pass. A missing, expired or foreign pass brings the checkbox back.

### Limits

Every limit is checked on your server before anything is sent to the Opensolr API.

| Limit | What it does |
| --- | --- |
| Characters per message | A longer question is refused, with its length and the limit. The browser checks it first, the server again. |
| Characters of a text to translate | The same, for the text after `/translate`, counted on the text alone. |
| Questions per visitor, in this many seconds | Counted per IP address, commands included. Past the limit the visitor is asked to try again later. |
| Questions per conversation | Commands are not counted. Past the limit the visitor is asked to start a new chat with the New chat button. The count of a conversation is kept for 24 hours after its last question. |
| One question at a time | A second question from the same IP address while an answer is being written is refused: "Please wait for the answer to your last question." |

IP addresses are never stored: the counters are kept under keyed hashes, and old rows are deleted automatically.

## Streaming through your server

The chat answer is a stream (`text/event-stream`) sent from `/opensolr-chat/chat` as it is written. Every layer between PHP and the browser must pass it through as it comes: no buffering, no compression, no caching. If anything buffers it, the visitor sees the answer only at the end, or the widget stops waiting after 45 seconds without data.

The package already does its part for that request: it ends PHP's output buffers, turns off `zlib.output_compression`, flushes after every event and sends these headers:

```text
Content-Type: text/event-stream; charset=utf-8
Cache-Control: no-cache, no-transform
X-Accel-Buffering: no
```

### Apache with PHP-FPM

`mod_proxy_fcgi` buffers PHP-FPM's output unless `flushpackets` is on. Add this to your virtual host or server configuration (not to `.htaccess`). The name in `<Proxy>` must be the `fcgi://` part of your `SetHandler` line:

```apache
<Proxy "fcgi://localhost">
    ProxySet flushpackets=on
</Proxy>
<FilesMatch \.php$>
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
</FilesMatch>
```

Compression must be off for the chat path. The example `.htaccess` already does it:

```apache
SetEnvIf Request_URI "/chat$" no-gzip=1 dont-vary=1
```

Then reload Apache:

```sh
sudo apachectl configtest && sudo systemctl reload apache2
```

### Apache with mod_php

Nothing more than the example `.htaccess`.

### nginx

nginx honours the `X-Accel-Buffering: no` header the chat sends. The `location` block of the install also turns buffering and compression off for the whole path:

```nginx
fastcgi_buffering off;
gzip off;
```

### Proxies and CDNs

A reverse proxy, load balancer, Varnish or CDN in front of your site must not cache, buffer or compress `/opensolr-chat/chat`, and must not cache `/opensolr-chat/admin` or `/opensolr-chat/config`. Add its addresses to `trusted_proxies`, so that the limits count each visitor's own IP address.

## Updating

```sh
composer update opensolr/chat-bot-client
```

- Your settings stay in the data folder. The database is upgraded on its own at the first request after an update.
- Browsers check `widget.js` again on every page load, so visitors get the new widget at once.
- Compare your `index.php` and `.htaccess` with the files in `vendor/opensolr/chat-bot-client/examples/` after an update.
- If your PHP opcache does not check file times (`opcache.validate_timestamps=0`), reload PHP-FPM after the update.

## Security notes

- **Credentials only on your server.** The Opensolr email and API key and the reCAPTCHA secret key are kept in the SQLite database of the data folder and used only by your server. They never reach the browser, and the admin never shows the API key or the secret key again once saved.
- **Data folder outside the web root.** Nothing in it can be downloaded. If the chat creates the folder itself, it creates it readable only by the PHP user.
- **Admin behind captcha, password and throttling.** A reCAPTCHA page before any admin page (once the keys are saved), a captcha on the sign-in form, a password of at least 12 characters stored as an Argon2id hash (bcrypt where Argon2id is not available), and at most 5 failed sign-ins per IP address in 15 minutes.
- **Admin sessions.** The session cookie is `HttpOnly`, `SameSite=Strict` and `Secure` over HTTPS. A new session starts at every sign-in, it ends after 2 hours without activity, and setting the password signs out every session.
- **CSRF.** Every form of the signed-in admin carries a token tied to the session; the sign-in form carries a token tied to the browser.
- **Same-origin check.** Every form of the admin, the captcha check and every chat question must come from a page of the same host name (their `Origin` or `Referer`), or they are refused.
- **No secrets in `/config`.** The widget's public configuration holds only what the widget shows: the title, the button text, the logo address, the accent colour, the greeting, the placeholder, the message and conversation limits, whether the captcha is on and its site key, the commands and the language codes.
- **Admin pages** are sent with a strict `Content-Security-Policy`, `X-Frame-Options: DENY` and `noindex`.
- **The logo** is accepted only when its content is a PNG, JPEG, GIF or WebP image, and served with `X-Content-Type-Options: nosniff`.
- **Visitor data.** IP addresses are kept only as keyed hashes in the counters of the limits. With each question to the assistant, your server sends the Opensolr API the question, the messages before it, your instructions and time zone, and the visitor's IP address. A command sends what is written after it; `/time` and `/ip` with nothing after them send the visitor's IP address. With the captcha on, Google receives the captcha answer and the visitor's IP address.

## Translating the admin

The admin is in English, Română, Français, Deutsch, Español, 中文 and 日本語. To fix a translation or add a language, see [docs/TRANSLATING.md](docs/TRANSLATING.md).

## License

MIT. See [LICENSE](LICENSE).
