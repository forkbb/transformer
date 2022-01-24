# Transformer

## About

Transformer is a script to convert/merge another forum's database to/with ForkBB.

## Note

### For now, just a sketch. On development stage.

Supports (convert/merge):
1. ForkBB rev.48

## Requirements

* PHP 7.3+
* PHP extensions: pdo, intl, json, mbstring, fileinfo
* PHP extensions (suggests): imagick or gd (for upload avatars and other images), openssl (for send email via smtp server using SSL/TLS)
* A database such as MySQL 5.5.3+, SQLite 3.25+, PostgreSQL 10+(?)

## Install

### For Apache:

Two options
1. Document Root != **public** folder:
    * Rename **.dist.htaccess** to **.htaccess**,
    * Rename **index.dist.php** to **index.php**.
2. Document Root == **public** folder (recommended):
    * Rename public/**.dist.htaccess** to public/**.htaccess**,
    * Rename public/**index.dist.php** to public/**index.php**;

**Note**

To determine which of these two options is yours, then immediately after uploading the engine to your site (before these changes), make two requests:
1. your.site/public/robots.txt
2. your.site/robots.txt

On one of the requests, you should see the content of the robots.txt file. Similar to:
```
User-agent: *
Disallow: /adm
Disallow: /log
Disallow: /mod
Disallow: /reg
Disallow: /search
Disallow: /userlist
Disallow: /post
```
On which option you see the contents of the file, choose the option for changing the file names above.
P.S. If you see the contents of the file in both cases, then something went wrong or you have already changed the names of the files **.dist.htaccess** and **index.dist.php**.

### For NGINX:

* [Example](https://github.com/forkbb/transformer/blob/main/nginx.dist.conf) nginx configuration.
* Note: Root must point to the [**public/**](https://github.com/forkbb/transformer/tree/main/public) directory.
* Note: The **index.dist.php** file does not need to be renamed.

## Links

* Development: https://github.com/forkbb/transformer

## License

This project is under MIT license. Please see the [license file](LICENSE) for details.