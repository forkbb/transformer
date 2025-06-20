# Transformer

## About

Transformer is a script to convert/merge another forum's database to/with ForkBB.

## Note

Supports (convert/merge):
1. ForkBB rev.88
2. FluxBB_by_Visman rev.51 - rev.87
3. FluxBB 1.5.0 - 1.5.11
4. PunBB 1.4.4 - 1.4.6

For FluxBB_by_Visman, FluxBB and PunBB: Before starting the conversion, copy the avatar files to the **/public/img/avatars/** folder.

## Requirements

* PHP 8.0+
* PHP extensions: pdo, intl, json, mbstring, fileinfo
* PHP extensions (suggests): imagick or gd (for upload avatars and other images), openssl (for send email via smtp server using SSL/TLS), curl (for OAuth)
* A database such as MySQL 5.5.3+ (an extension using the mysqlnd driver must be enabled), SQLite 3.25+, PostgreSQL 10+

## Install

### For Apache:

Apache must have **mod_rewrite** and **mod_headers** enabled. Also, the **AllowOverride** directive must be set to **All**.

Two options
1. Shared hosting or site folder (Document Root != [**/public/**](https://github.com/forkbb/forkbb/tree/master/public) folder):
    * Rename **.dist.htaccess** to **.htaccess**,
    * Rename **index.dist.php** to **index.php**.
    * (If you install the forum engine in the site folder, there may be conflicts between the forum's .htaccess rules and the site's .htaccess rules.)
2. VPS/VDS with access to Apache configuration (Document Root == [**/public/**](https://github.com/forkbb/forkbb/tree/master/public) folder):
    * Rename /public/**.dist.htaccess** to /public/**.htaccess**,
    * Rename /public/**index.dist.php** to /public/**index.php**;

### For NGINX:

* [Example](https://github.com/forkbb/forkbb/blob/master/nginx.dist.conf) nginx configuration.
* Note: Root must point to the [**/public/**](https://github.com/forkbb/forkbb/tree/master/public) directory.
* Note: The **index.dist.php** file does not need to be renamed.

## Before starting

Configure the **FRIENDLY_URL** section in the files /app/config/install.php and /app/config/main.dist.php ( [topic](https://forkbb.ru/topic/95/transliteratsiya-translitation) ).

## Links

* Homepage: https://forkbb.ru/
* GitHub: https://github.com/forkbb/transformer

## License

This project is under MIT license. Please see the [license file](LICENSE) for details.
