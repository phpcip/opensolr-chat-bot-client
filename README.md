# Opensolr Chat Bot Client

A chatbot for your website that answers your visitors from your own content in your Opensolr Index, crawled or ingested: with links to your pages, products and the exact PDF pages it used, streamed as it is written.

You install it with Composer, mount it on one URL path of your own site, set it up in its own admin page and add one script tag to your pages. Your visitors talk only to your site; your Opensolr credentials stay on your server.

## Install

1. `composer require opensolr/chat-bot-client`
2. Create a folder outside your web root for its data (it keeps a small SQLite database there), writable by the user PHP runs as, for example `/opt/opensolr-chat`.
3. Copy `examples/public/opensolr-chat/index.php` and `examples/apache.htaccess` (as `.htaccess`) into a folder of your web root named `opensolr-chat`, and set `data_dir` in `index.php`.
4. Set the admin password, as the user PHP runs as: `sudo -u www-data php vendor/bin/opensolr-chat-bot password /opt/opensolr-chat`
5. Open `https://your-site/opensolr-chat/admin`, sign in, enter your Opensolr email and API key, choose your index and save.
6. Add `<script src="/opensolr-chat/widget.js" defer></script>` to your pages.

## License

MIT
